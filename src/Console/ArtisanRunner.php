<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console;

use InvalidArgumentException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Process\Process;

class ArtisanRunner
{
    /** @param list<string> $command */
    public function __construct(
        private array $command,
        private string $workingDirectory,
    ) {}

    public function start(string $name, string $arguments = ''): Process
    {
        if ($this->command === [] || $name === '' || $name === 'ui') {
            throw new InvalidArgumentException('Invalid Artisan command.');
        }

        $input = new StringInput($arguments);
        $process = new Process(
            [
                ...$this->command,
                '--no-interaction',
                '--no-ansi',
                $name,
                ...$input->getRawTokens(),
            ],
            cwd: $this->workingDirectory,
        );
        $process->setTimeout(null);
        $process->start();

        return $process;
    }
}
