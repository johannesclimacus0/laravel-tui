<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Processes;

final readonly class ProcessDefinition
{
    /** @param list<string> $command */
    public function __construct(
        public string $id,
        public string $label,
        public array $command,
        public string $workingDirectory,
        public ?float $timeout,
    ) {}
}
