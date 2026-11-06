<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Checks;

final readonly class CheckDefinition
{
    /** @param list<string> $command */
    public function __construct(
        public string $id,
        public string $label,
        public array $command,
        public string $workingDirectory,
        public ?float $timeout,
        public bool $includeInAll,
    ) {}
}
