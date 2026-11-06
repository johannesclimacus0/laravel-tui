<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Console\Command;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\Processes\ProcessManager;
use LaravelTui\LaravelTui\UI\Dashboard;
use LaravelTui\LaravelTui\UI\Miku;

class LaravelTuiCommand extends Command
{
    protected $signature = 'ui';

    protected $description = 'Open the Laravel TUI';

    public function handle(Dashboard $dashboard, Miku $pictures): int
    {
        if (! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            $this->error('UI needs an interactive terminal');

            return self::FAILURE;
        }

        $modules = [];

        foreach (config('laravel-tui.modules') as $class) {
            $module = $this->laravel->make($class);

            if (! $module instanceof Module) {
                $this->error('Module '.$class.' is not an instance of '.Module::class);

                return self::FAILURE;
            }

            $modules[] = $module;
        }

        try {
            $dashboard->run(
                $modules,
                config('laravel-tui.keybindings', []),
                config('laravel-tui.key_aliases', []),
                (string) config('app.name', 'Laravel'),
                config('laravel-tui.icons', []),
                config('laravel-tui.captions', []),
                $pictures,
                config('laravel-tui.art.modules', []),
                (string) config('laravel-tui.art.default', 'mliku'),
            );
        } finally {
            if ($this->laravel->resolved(ProcessManager::class)) {
                $this->laravel->make(ProcessManager::class)->stopAll();
            }
        }

        return self::SUCCESS;
    }
}
