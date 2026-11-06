<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Processes;

final class ProcessState
{
    public string $status = 'idle';

    public string $output = '';

    public float $elapsed = 0.0;

    public ?int $exitCode = null;

    public ?int $pid = null;

    public bool $truncated = false;
}
