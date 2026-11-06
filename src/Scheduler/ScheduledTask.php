<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Scheduler;

readonly class ScheduledTask
{
    /** @param list<string> $details */
    public function __construct(
        public string $id,
        public string $label,
        public string $expression,
        public string $next,
        public array $details,
        public string $frequency,
        public string $environmentNotice,
    ) {}
}
