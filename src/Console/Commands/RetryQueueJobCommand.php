<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Console\RetryCommand;
use Illuminate\Queue\Events\JobRetryRequested;
use LaravelTui\LaravelTui\Queue\QueueRepository;
use RuntimeException;

class RetryQueueJobCommand extends RetryCommand
{
    protected $signature = 'ui:queue:retry {id} {--source-connection=} {--source-queue=} {--fingerprint=}';

    protected $description = 'Retry one confirmed failed job for the TUI';

    protected $hidden = true;

    public function handle(): void
    {
        $connection = $this->option('source-connection');
        $queue = $this->option('source-queue');
        $fingerprint = $this->option('fingerprint');

        if (! is_string($connection) || ! is_string($queue) || ! is_string($fingerprint)) {
            throw new RuntimeException('A queue and confirmation fingerprint are required');
        }
        $id = $this->argument('id');
        $repository = $this->laravel->make(QueueRepository::class);
        $job = $repository->retryRecord($connection, $queue, $id, $fingerprint);
        $this->laravel->make(Dispatcher::class)->dispatch(new JobRetryRequested($job));
        $repository->verifyRetry($job, $connection, $queue, $id, $fingerprint);
        $repository->retryRecord($connection, $queue, $id, $fingerprint);
        parent::retryJob($job);
        $repository->forgetRetried($id);
    }
}
