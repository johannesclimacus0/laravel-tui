<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Console\Command;
use LaravelTui\LaravelTui\Queue\QueueRepository;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Throwable;

class QueueDataCommand extends Command
{
    protected $signature = 'ui:queue {connection} {queue} {--job=} {--action=} {--fingerprint=}';

    protected $description = 'Read queue data for the TUI';

    protected $hidden = true;

    public function handle(QueueRepository $queues): int
    {
        try {
            $connection = $this->argument('connection');
            $queue = $this->argument('queue');
            $job = $this->option('job');
            $action = $this->option('action');
            $fingerprint = $this->option('fingerprint');

            if (is_string($action)) {
                if (! is_string($job) || ! is_string($fingerprint) || ! preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)) {
                    throw new RuntimeException('A failed job and its confirmation fingerprint are required');
                }
                $data = ['message' => $queues->action($action, $connection, $queue, $job, $fingerprint)];
                $kind = 'action';
            } elseif (is_string($job)) {
                $data = $queues->job($connection, $queue, $job);
                $kind = 'detail';
            } else {
                $data = $queues->snapshot($connection, $queue);
                $kind = 'snapshot';
            }

            $response = ['version' => 1, 'ok' => true, 'kind' => $kind, 'data' => $data];
            $code = self::SUCCESS;
        } catch (Throwable $error) {
            $message = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(substr($error->getMessage(), 0, 2000))));
            $response = ['version' => 1, 'ok' => false, 'error' => $message ?: 'Queue operation failed', 'uncertain' => $queues->mutationStarted()];
            $code = self::FAILURE;
        }

        $this->output->writeln(json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
