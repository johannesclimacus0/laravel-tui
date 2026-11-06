<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Checks;

final class CheckResult
{
    public string $status = 'idle';

    public string $output = '';

    public float $elapsed = 0.0;

    public ?int $exitCode = null;

    public bool $truncated = false;
}
