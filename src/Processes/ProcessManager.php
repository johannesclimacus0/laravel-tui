<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Processes;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class ProcessManager
{
    private const int OUTPUT_LIMIT = 262144;

    /** @var array<string, ProcessDefinition> */
    private array $definitions = [];

    /** @var array<string, ProcessState> */
    private array $states = [];

    /** @var array<string, Process> */
    private array $processes = [];

    /** @var array<string, int> */
    private array $groups = [];

    /** @var array<string, float> */
    private array $startedAt = [];

    private ?string $setsid = null;

    private int $revision = 0;

    public function __construct(Repository $config, Application $app)
    {
        if (DIRECTORY_SEPARATOR !== '\\' && function_exists('posix_kill') && function_exists('posix_getpgid') && function_exists('posix_getpgrp')) {
            $this->setsid = (new ExecutableFinder)->find('setsid');
        }

        $settings = $config->get('laravel-tui.processes', []);

        if (! is_array($settings) || (array_key_exists('commands', $settings) && ! is_array($settings['commands']))) {
            throw new InvalidArgumentException('Processes configuration and commands must be arrays.');
        }

        $root = $this->directory(array_key_exists('working_directory', $settings) ? $settings['working_directory'] : $app->basePath(), $app->basePath());
        $timeout = $this->timeout(array_key_exists('timeout', $settings) ? $settings['timeout'] : null);

        foreach ($settings['commands'] ?? [] as $label => $entry) {
            if (! is_string($label) || trim($label) === '' || ! is_array($entry)) {
                throw new InvalidArgumentException('Each process must have a nonempty label and an array command.');
            }

            $options = array_is_list($entry) ? ['command' => $entry] : $entry;
            $command = $options['command'] ?? null;

            if (! is_array($command) || ! array_is_list($command) || $command === []) {
                throw new InvalidArgumentException("Process [{$label}] command must be a nonempty argv list.");
            }

            foreach ($command as $index => $argument) {
                if (! is_string($argument) || str_contains($argument, "\0") || ($index === 0 && trim($argument) === '')) {
                    throw new InvalidArgumentException("Process [{$label}] arguments must be strings with a nonempty executable.");
                }
            }

            $this->definitions[$label] = new ProcessDefinition(
                $label,
                $label,
                $command,
                $this->directory(array_key_exists('working_directory', $options) ? $options['working_directory'] : $root, $root),
                array_key_exists('timeout', $options) ? $this->timeout($options['timeout']) : $timeout,
            );
            $this->states[$label] = new ProcessState;
        }
    }

    /** @return list<ProcessDefinition> */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    public function state(string $id): ProcessState
    {
        return $this->states[$id] ?? throw new InvalidArgumentException("Unknown process [{$id}].");
    }

    public function start(string $id): void
    {
        $this->state($id);

        if (isset($this->processes[$id])) {
            return;
        }

        $definition = $this->definitions[$id];
        $state = $this->states[$id] = new ProcessState;
        $state->status = 'running';
        $this->startedAt[$id] = $this->now();
        $this->revision++;

        try {
            $command = $this->setsid === null ? $definition->command : [$this->setsid, '--', ...$definition->command];
            $process = $this->processes[$id] = new Process($command, $definition->workingDirectory, timeout: null);
            $process->disableOutput();
            $process->start(function (string $type, string $output) use ($id): void {
                $this->append($id, $output);
            });
            $state->pid = $process->getPid();

            if ($this->setsid !== null && $state->pid !== null) {
                $this->groups[$id] = $state->pid;
            }
        } catch (Throwable $exception) {
            $this->append($id, $exception->getMessage()."\n");
            $this->terminate($id);
            $this->finish($id, 'failed');
        }
    }

    public function stop(string $id): void
    {
        $this->state($id);

        if (! isset($this->processes[$id])) {
            return;
        }

        $this->terminate($id);
        $this->finish($id, 'stopped');
    }

    public function restart(string $id): void
    {
        $this->stop($id);
        $this->start($id);
    }

    public function startAll(): void
    {
        foreach ($this->definitions as $definition) {
            $this->start($definition->id);
        }
    }

    public function stopAll(): void
    {
        foreach (array_keys($this->processes) as $id) {
            $this->stop($id);
        }
    }

    public function tick(): void
    {
        foreach ($this->processes as $id => $process) {
            try {
                $running = $process->isRunning();
                $state = $this->states[$id];
                $state->elapsed = max(0.0, $this->now() - $this->startedAt[$id]);
                $this->revision++;
                $timeout = $this->definitions[$id]->timeout;

                if (! $running) {
                    $status = $process->getExitCode() === 0 ? 'exited' : 'failed';
                    $this->terminate($id);
                    $this->finish($id, $status);
                } elseif ($timeout !== null && $state->elapsed >= $timeout) {
                    $this->terminate($id);
                    $this->finish($id, 'timeout');
                }
            } catch (Throwable $exception) {
                $this->append($id, $exception->getMessage()."\n");
                $this->terminate($id);
                $this->finish($id, 'failed');
            }
        }
    }

    public function runningCount(): int
    {
        return count($this->processes);
    }

    public function revision(): int
    {
        return $this->revision;
    }

    private function finish(string $id, string $status): void
    {
        $state = $this->states[$id];
        $state->status = $status;
        $state->elapsed = max(0.0, $this->now() - $this->startedAt[$id]);
        $state->pid = null;

        try {
            $state->exitCode = ($this->processes[$id] ?? null)?->getExitCode();
        } catch (Throwable $exception) {
            $this->append($id, $exception->getMessage()."\n");
        }

        unset($this->processes[$id], $this->groups[$id], $this->startedAt[$id]);
        $this->revision++;
    }

    private function append(string $id, string $output): void
    {
        $state = $this->states[$id];
        $state->output .= $output;

        if (strlen($state->output) > self::OUTPUT_LIMIT) {
            $state->output = substr($state->output, -self::OUTPUT_LIMIT);
            $state->truncated = true;
        }

        $this->revision++;
    }

    private function terminate(string $id): void
    {
        $group = $this->groups[$id] ?? null;
        $isolated = $group !== null && $group > 1 && $group !== posix_getpgrp()
            && (posix_getpgid($group) === $group || (posix_getpgid($group) === false && @posix_kill(-$group, 0)));

        if ($isolated) {
            @posix_kill(-$group, 15);
        }

        try {
            ($this->processes[$id] ?? null)?->stop(0.1);
        } catch (Throwable $exception) {
            $this->append($id, $exception->getMessage()."\n");
        } finally {
            if ($isolated) {
                @posix_kill(-$group, 9);
            }
        }
    }

    private function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function directory(mixed $value, string $root): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Process working directory must be a nonempty path.');
        }

        $absolute = str_starts_with($value, '/') || str_starts_with($value, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $value) === 1;
        $directory = realpath($absolute ? $value : $root.DIRECTORY_SEPARATOR.$value);

        if ($directory === false || ! is_dir($directory)) {
            throw new InvalidArgumentException("Process working directory [{$value}] does not exist.");
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
            throw new InvalidArgumentException('Process timeout must be a positive finite number or null.');
        }

        return (float) $value;
    }
}
