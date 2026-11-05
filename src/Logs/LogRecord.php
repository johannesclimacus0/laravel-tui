<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Logs;

final readonly class LogRecord
{
    public function __construct(
        public string $id,
        public string $level,
        public string $message,
        public string $details,
        public string $raw,
    ) {}
}
