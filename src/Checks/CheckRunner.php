<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Checks;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class CheckRunner
{
    private const int OUTPUT_LIMIT = 262144;

    /** @var array<string, CheckDefinition> */
    private array $definitions = [];

    /** @var array<string, CheckResult> */
    private array $results = [];

    /** @var list<string> */
    private array $queue = [];

    private ?Process $process = null;

    private ?string $active = null;

    private ?string $setsid = null;

    private ?int $processGroup = null;

    private float $startedAt = 0.0;

    private int $revision = 0;

    public function __construct(Repository $config, Application $app)
    {
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('posix_kill') && function_exists('posix_getpgid') && function_exists('posix_getpgrp')) {
            $this->setsid = (new ExecutableFinder)->find('setsid');
        }

        $settings = $config->get('laravel-tui.checks', []);

        if (! is_array($settings) || (array_key_exists('commands', $settings) && ! is_array($settings['commands']))) {
            throw new InvalidArgumentException('Checks configuration and commands must be arrays.');
        }

        $root = $this->directory(array_key_exists('working_directory', $settings) ? $settings['working_directory'] : $app->basePath(), $app->basePath());
        $timeout = $this->timeout(array_key_exists('timeout', $settings) ? $settings['timeout'] : 300);

        foreach ($settings['commands'] ?? [] as $label => $entry) {
            if (! is_string($label) || trim($label) === '' || ! is_array($entry)) {
                throw new InvalidArgumentException('Each check must have a nonempty label and an array command.');
            }

            $options = array_is_list($entry) ? ['command' => $entry] : $entry;
            $command = $options['command'] ?? null;

            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw new InvalidArgumentException("Check [{$label}] command must be a nonempty argv list.");
            }

            foreach ($command as $index => $argument) {
                if (! is_string($argument) || str_contains($argument, "\0") || ($index === 0 && trim($argument) === '')) {
                    throw new InvalidArgumentException("Check [{$label}] arguments must be strings with a nonempty executable.");
                }
            }

            $includeInAll = array_key_exists('include_in_all', $options) ? $options['include_in_all'] : true;

            if (! is_bool($includeInAll)) {
                throw new InvalidArgumentException("Check [{$label}] include_in_all must be a boolean.");
            }

            $this->definitions[$label] = new CheckDefinition(
                $label,
                $label,
                $command,
                $this->directory(array_key_exists('working_directory', $options) ? $options['working_directory'] : $root, $root),
                array_key_exists('timeout', $options) ? $this->timeout($options['timeout']) : $timeout,
                $includeInAll,
            );
            $this->results[$label] = new CheckResult;
        }
    }

    /** @return list<CheckDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function result(string $id): CheckResult
    {
        return $this->results[$id] ?? throw new InvalidArgumentException("Unknown check [{$id}].");
    }

    public function start(string $id): void
    {
        $this->result($id);
        $this->enqueue($id);
        $this->startNext();
    }

    public function startAll(): void
    {
        foreach ($this->definitions as $definition) {
            if ($definition->includeInAll) {
                $this->enqueue($definition->id);
            }
        }

        $this->startNext();
    }

    public function stop(): void
    {
        foreach ($this->queue as $id) {
            $this->results[$id]->status = 'cancelled';
            $this->revision++;
        }

        $this->queue = [];

        if ($this->process !== null && $this->active !== null) {
            $this->terminate();
            $this->finish('cancelled');
        }
    }

    public function tick(): void
    {
        if ($this->process !== null && $this->active !== null) {
            $running = $this->process->isRunning();
            $result = $this->results[$this->active];
            $result->elapsed = max(0.0, $this->now() - $this->startedAt);
            $this->revision++;
            $timeout = $this->definitions[$this->active]->timeout;

            if (! $running) {
                $this->finish($this->process->getExitCode() === 0 ? 'passed' : 'failed');
            } elseif ($timeout !== null && $result->elapsed >= $timeout) {
                $this->terminate();
                $this->finish('timeout');
            }
        }

        $this->startNext();
    }

    public function running(): ?string
    {
        return $this->active;
    }

    public function queuedCount(): int
    {
        return count($this->queue);
    }

    public function revision(): int
    {
        return $this->revision;
    }

    private function enqueue(string $id): void
    {
        if ($id === $this->active || in_array($id, $this->queue, true)) {
            return;
        }

        $this->results[$id] = new CheckResult;
        $this->results[$id]->status = 'queued';
        $this->queue[] = $id;
        $this->revision++;
    }

    private function startNext(): void
    {
        while ($this->process === null && ($id = array_shift($this->queue)) !== null) {
            $definition = $this->definitions[$id];
            $this->active = $id;
            $this->startedAt = $this->now();
            $this->results[$id]->status = 'running';
            $this->revision++;

            try {
                $command = $this->setsid === null ? $definition->command : [$this->setsid, '--', ...$definition->command];
                $this->process = new Process($command, $definition->workingDirectory, timeout: null);
                $this->process->disableOutput();
                $this->process->start(function (string $type, string $output) use ($id): void {
                    $this->append($id, $output);
                });
                $this->processGroup = $this->setsid === null ? null : $this->process->getPid();
            } catch (Throwable $exception) {
                $this->append($id, $exception->getMessage()."\n");
                $this->finish('failed');
            }
        }
    }

    private function finish(string $status): void
    {
        if ($this->active === null) {
            return;
        }

        $result = $this->results[$this->active];
        $result->status = $status;
        $result->elapsed = max(0.0, $this->now() - $this->startedAt);
        $result->exitCode = $this->process?->getExitCode();

        if ($this->processGroup !== null) {
            $this->terminate();
        }

        $this->process = null;
        $this->processGroup = null;
        $this->active = null;
        $this->revision++;
    }

    private function append(string $id, string $output): void
    {
        $result = $this->results[$id];
        $result->output .= $output;

        if (strlen($result->output) > self::OUTPUT_LIMIT) {
            $result->output = substr($result->output, -self::OUTPUT_LIMIT);
            $result->truncated = true;
        }

        $this->revision++;
    }

    private function terminate(): void
    {
        $group = $this->processGroup;
        $isolated = $group !== null && $group > 1 && $group !== posix_getpgrp()
            && (posix_getpgid($group) === $group || (posix_getpgid($group) === false && @posix_kill(-$group, 0)));

        if ($isolated) {
            @posix_kill(-$group, 15);
        }

        $this->process?->stop(0.1);

        if ($isolated) {
            @posix_kill(-$group, 9);
        }
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function directory(mixed $value, string $root): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Check working directory must be a nonempty path.');
        }

        $absolute = str_starts_with($value, '/') || str_starts_with($value, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1;
        $directory = realpath($absolute ? $value : $root.DIRECTORY_SEPARATOR.$value);

        if ($directory === false || ! is_dir($directory)) {
            throw new InvalidArgumentException("Check working directory [{$value}] does not exist.");
        }

        return $directory;
    }

    private function timeout(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ((! is_int($value) && ! is_float($value) && ! is_string($value)) || ! is_numeric($value)
            || ! is_finite((float) $value) || (float) $value <= 0) {
            throw new InvalidArgumentException('Check timeout must be a positive finite number or null.');
        }

        return (float) $value;
    }
}
