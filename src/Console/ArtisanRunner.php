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

    /** @param string|list<string> $arguments */
    public function start(string $name, string|array $arguments = ''): Process
    {
        if ($this->command === [] || $name === '' || $name === 'ui') {
            throw new InvalidArgumentException('Invalid Artisan command.');
        }

        $tokens = is_array($arguments) ? $arguments : (new StringInput($arguments))->getRawTokens();
        $process = new Process(
            [
                ...$this->command,
                '--no-interaction',
                '--no-ansi',
                $name,
                ...$tokens,
            ],
            cwd: $this->workingDirectory,
        );
        $process->setTimeout(null);
        $process->start();

        return $process;
    }
}
