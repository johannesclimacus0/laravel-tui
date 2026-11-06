<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Queue;

use RuntimeException;

final readonly class QueueSnapshot
{
    /**
     * @param  array<string, int|null>  $counts
     * @param  list<FailedJob>  $jobs
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public string $driver,
        public array $counts,
        public ?int $failedCount,
        public array $jobs,
        public string $note = '',
        public ?string $queueError = null,
        public ?string $failedError = null,
        public bool $limited = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['connection', 'queue', 'driver', 'note'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new RuntimeException('Invalid queue snapshot');
            }
        }

        if (! isset($data['counts'], $data['jobs']) || ! is_array($data['counts']) || ! is_array($data['jobs'])
            || ! isset($data['limited']) || ! is_bool($data['limited'])) {
            throw new RuntimeException('Invalid queue snapshot');
        }

        $counts = [];
        foreach (['total', 'pending', 'delayed', 'reserved'] as $key) {
            $value = $data['counts'][$key] ?? null;

            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw new RuntimeException('Invalid queue count');
            }
            $counts[$key] = $value;
        }

        $failedCount = $data['failedCount'] ?? null;

        if ($failedCount !== null && (! is_int($failedCount) || $failedCount < 0)) {
            throw new RuntimeException('Invalid failed-job count');
        }

        foreach (['queueError', 'failedError'] as $key) {
            if (isset($data[$key]) && ! is_string($data[$key])) {
                throw new RuntimeException('Invalid queue error');
            }
        }

        $jobs = [];
        foreach ($data['jobs'] as $job) {
            if (! is_array($job)) {
                throw new RuntimeException('Invalid failed-job response');
            }
            $jobs[] = FailedJob::fromArray($job);
        }

        return new self(
            $data['connection'], $data['queue'], $data['driver'], $counts, $failedCount, $jobs,
            $data['note'], $data['queueError'] ?? null, $data['failedError'] ?? null, $data['limited'],
        );
    }
}
