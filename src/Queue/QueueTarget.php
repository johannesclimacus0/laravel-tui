<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Queue;

final readonly class QueueTarget
{
    public function __construct(
        public string $connection,
        public string $queue,
        public string $driver,
    ) {}

    public function id(): string
    {
        return hash('sha256', $this->connection."\0".$this->queue);
    }
}
