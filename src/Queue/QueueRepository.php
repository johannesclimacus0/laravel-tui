<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Queue;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Enumerable;
use ReflectionObject;
use RuntimeException;
use stdClass;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Throwable;

class QueueRepository
{
    private bool $mutationStarted = false;

    public function __construct(
        private Factory $queues,
        private FailedJobProviderInterface $failures,
        private Repository $config,
        private Kernel $artisan,
    ) {}

    /** @return list<QueueTarget> */
    public function targets(): array
    {
        $connections = $this->config->get('queue.connections', []);
        $allowed = $this->config->get('laravel-tui.queue.connections', []);
        $names = $this->config->get('laravel-tui.queue.queues', []);

        if (! is_array($connections) || ! is_array($allowed) || ! is_array($names)) {
            throw new RuntimeException('Invalid queue configuration');
        }

        $targets = [];
        foreach ($connections as $name => $connection) {
            if (! is_string($name) || $name === '' || strlen($name) > 256 || $name !== $this->plain($name)
                || ! is_array($connection) || ! is_string($connection['driver'] ?? null)
                || ($allowed !== [] && ! in_array($name, $allowed, true))) {
                continue;
            }
            $queueNames = $names[$name] ?? [$connection['queue'] ?? 'default'];

            if (! is_array($queueNames)) {
                throw new RuntimeException('Queue names must be configured as a list');
            }

            $seen = [];
            foreach ($queueNames as $queue) {
                if (! is_string($queue) || trim($queue) === '' || strlen($queue) > 2048 || $queue !== $this->plain($queue)) {
                    throw new RuntimeException('Invalid configured queue name');
                }

                if (in_array($queue, $seen, true)) {
                    continue;
                }
                $seen[] = $queue;
                $targets[] = new QueueTarget($name, $queue, $connection['driver']);
            }
        }

        $default = $this->config->get('queue.default');
        usort($targets, static fn (QueueTarget $a, QueueTarget $b): int => [$a->connection !== $default, $a->connection, $a->queue] <=> [$b->connection !== $default, $b->connection, $b->queue]);

        return $targets;
    }

    public function snapshot(string $connection, string $queue): QueueSnapshot
    {
        $target = $this->target($connection, $queue);
        $counts = ['total' => null, 'pending' => null, 'delayed' => null, 'reserved' => null];
        $queueError = null;
        $failedError = null;
        $jobs = [];
        $failedCount = null;
        $limited = false;
        $note = match ($target->driver) {
            'sync', 'deferred', 'background' => 'This driver executes jobs without a persistent backlog',
            'null' => 'This driver discards queued jobs',
            default => '',
        };

        try {
            $driver = $this->queues->connection($connection);
            foreach (['total' => 'size', 'pending' => 'pendingSize', 'delayed' => 'delayedSize', 'reserved' => 'reservedSize'] as $key => $method) {
                if (method_exists($driver, $method)) {
                    $value = $driver->{$method}($queue);
                    $counts[$key] = max(0, $value);
                }
            }
        } catch (Throwable $error) {
            $queueError = $this->clip($error->getMessage(), 1000);
        }

        try {
            $limit = max(1, min(100, (int) $this->config->get('laravel-tui.queue.failed_limit', 50)));

            if ($this->failures instanceof DatabaseFailedJobProvider || $this->failures instanceof DatabaseUuidFailedJobProvider) {
                $query = $this->failures->getTable()->where('connection', $connection)->where('queue', $queue);
                $failedCount = (clone $query)->count();
                $columns = ['id', 'connection', 'queue', 'failed_at', 'payload'];

                if ($this->failures instanceof DatabaseUuidFailedJobProvider) {
                    $columns[] = 'uuid';
                }
                foreach ($query->orderByDesc('id')->limit($limit)->get($columns) as $row) {
                    if ($this->failures instanceof DatabaseUuidFailedJobProvider) {
                        $row->id = $row->uuid;
                    }
                    $jobs[] = $this->record($row, summary: true);
                }
                $limited = $failedCount > count($jobs);
            } elseif ($this->failures instanceof NullFailedJobProvider) {
                $failedCount = 0;
                $note = trim($note.' Failed-job storage is disabled');
            } else {
                $ids = $this->failedIds($queue);

                if ($ids === null) {
                    throw new RuntimeException('This failed-job provider does not support listing IDs');
                }
                $scanLimit = max(100, $limit * 5);
                $scanned = 0;
                foreach (array_slice($ids, 0, $scanLimit) as $id) {
                    $scanned++;
                    $row = $this->failures->find($id);

                    if ($row !== null && ($row->connection ?? null) === $connection && ($row->queue ?? null) === $queue) {
                        $jobs[] = $this->record($row, summary: true);

                        if (count($jobs) >= $limit) {
                            break;
                        }
                    }
                }
                $failedCount = $this->failures instanceof CountableFailedJobProvider ? $this->failures->count($connection, $queue) : null;
                $limited = count($ids) > $scanned || ($failedCount !== null && $failedCount > count($jobs));
            }
        } catch (Throwable $error) {
            $jobs = [];
            $failedCount = null;
            $failedError = $this->clip($error->getMessage(), 1000);
        }

        return new QueueSnapshot($connection, $queue, $target->driver, $counts, $failedCount, $jobs, $note, $queueError, $failedError, $limited);
    }

    public function job(string $connection, string $queue, string $id): FailedJob
    {
        $this->target($connection, $queue);
        $this->validateId($id);
        $row = $this->failures->find($id);

        if ($row === null || ($row->connection ?? null) !== $connection || ($row->queue ?? null) !== $queue) {
            throw new RuntimeException('Failed job no longer exists in this queue');
        }

        return $this->record($row);
    }

    public function action(string $action, string $connection, string $queue, string $id, string $fingerprint): string
    {
        $this->mutationStarted = false;

        if (! $this->config->get('laravel-tui.queue.allow_actions', true)) {
            throw new RuntimeException('Queue actions are disabled');
        }

        if (! in_array($action, ['retry', 'forget'], true)) {
            throw new RuntimeException('Unknown queue action');
        }

        $record = $this->job($connection, $queue, $id);

        if (! hash_equals($record->fingerprint, $fingerprint)) {
            throw new RuntimeException('Failed job changed. Open it again before confirming');
        }

        $this->mutationStarted = true;
        $command = $action === 'retry' ? 'ui:queue:retry' : 'queue:forget';
        $arguments = $action === 'retry'
            ? ['id' => $id, '--source-connection' => $connection, '--source-queue' => $queue, '--fingerprint' => $fingerprint]
            : ['id' => [$id]];
        $exitCode = $this->artisan->call($command, $arguments);
        $output = $this->plain($this->artisan->output());

        if ($exitCode !== 0 || $this->failures->find($id) !== null) {
            throw new RuntimeException($this->clip(trim($output) ?: 'Queue action did not complete', 2000));
        }

        return $action === 'retry' ? 'Job returned to its original queue' : 'Failed job deleted';
    }

    public function mutationStarted(): bool
    {
        return $this->mutationStarted;
    }

    public function retryRecord(string $connection, string $queue, string $id, string $fingerprint): stdClass
    {
        $this->job($connection, $queue, $id);
        $row = $this->failures->find($id);

        if ($row === null) {
            throw new RuntimeException('Failed job no longer exists');
        }

        if (! $row instanceof stdClass) {
            throw new RuntimeException('This provider does not expose a retryable job record');
        }
        $this->verifyRetry($row, $connection, $queue, $id, $fingerprint);

        return $row;
    }

    public function verifyRetry(object $row, string $connection, string $queue, string $id, string $fingerprint): void
    {
        $this->target($connection, $queue);
        $this->validateId($id);

        if (! $this->config->get('laravel-tui.queue.allow_actions', true)) {
            throw new RuntimeException('Queue actions are disabled');
        }
        $record = $this->record($row);

        if ($record->id !== $id || $record->connection !== $connection || $record->queue !== $queue
            || ! hash_equals($record->fingerprint, $fingerprint)) {
            throw new RuntimeException('Failed job changed. Open it again before confirming');
        }
    }

    public function forgetRetried(string $id): void
    {
        if (! $this->failures->forget($id)) {
            throw new RuntimeException('Job was queued but its failed record could not be deleted');
        }
    }

    /** @return list<string|int>|null */
    private function failedIds(string $queue): ?array
    {
        $provider = new ReflectionObject($this->failures);

        if (! $provider->hasMethod('ids') || ! $provider->getMethod('ids')->isPublic()) {
            return null;
        }
        $values = $provider->getMethod('ids')->invoke($this->failures, $queue);

        if (! is_array($values)) {
            throw new RuntimeException('Invalid failed-job IDs');
        }
        $ids = [];
        foreach ($values as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw new RuntimeException('Invalid failed-job ID');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    private function target(string $connection, string $queue): QueueTarget
    {
        foreach ($this->targets() as $target) {
            if ($target->connection === $connection && $target->queue === $queue) {
                return $target;
            }
        }

        throw new RuntimeException('Queue connection or name is not configured for this panel');
    }

    private function validateId(string $id): void
    {
        if ($id === '' || strtolower($id) === 'all' || strlen($id) > 256 || $id !== $this->plain($id)) {
            throw new RuntimeException('A single failed-job ID is required');
        }
    }

    private function record(object $row, bool $summary = false): FailedJob
    {
        if ($row instanceof Enumerable) {
            throw new RuntimeException('A single failed-job record is required');
        }
        $data = (array) $row;
        $id = $data['id'] ?? null;
        $connection = $data['connection'] ?? null;
        $queue = $data['queue'] ?? null;

        if ((! is_int($id) && ! is_string($id)) || ! is_string($connection) || ! is_string($queue)) {
            throw new RuntimeException('Invalid failed-job record');
        }

        $payload = is_string($data['payload'] ?? null) ? $data['payload'] : '';
        $exception = is_string($data['exception'] ?? null) ? $data['exception'] : '';
        $date = $data['failed_at'] ?? '';
        $failedAt = $date instanceof DateTimeInterface ? $date->format(DATE_ATOM) : (is_string($date) ? $date : '');
        $decoded = strlen($payload) <= 131072 ? json_decode($payload, true) : null;
        $name = is_array($decoded) ? ($decoded['displayName'] ?? $decoded['job'] ?? 'Unknown job') : 'Unknown job';
        $name = is_string($name) ? $name : 'Unknown job';
        $fingerprint = hash('sha256', implode("\0", [(string) $id, $connection, $queue, $failedAt, $payload, $exception]));
        $max = max(1024, min(131072, (int) $this->config->get('laravel-tui.queue.max_detail_bytes', 65536)));

        return new FailedJob(
            (string) $id, $connection, $queue, $this->clip($name, 512), $this->clip($failedAt, 128),
            $summary ? '' : $this->clip($payload, $max), $summary ? '' : $this->clip($exception, $max),
            $summary ? '' : $fingerprint, ! $summary && (strlen($payload) > $max || strlen($exception) > $max),
        );
    }

    private function clip(string $text, int $bytes): string
    {
        return $this->plain(substr($text, 0, $bytes));
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }
}
