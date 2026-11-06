<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app['config']->set('laravel-tui.art.path', dirname(__DIR__, 2).'/resources/laravel-tui');
        $root = dirname(__DIR__, 3);
        $this->app['config']->set('laravel-tui.checks.working_directory', $root);
        $this->app['config']->set('laravel-tui.processes.working_directory', $root);
        $this->app['config']->set('laravel-tui.processes.commands.Queue worker', [PHP_BINARY, $root.'/vendor/bin/testbench', 'queue:work', '--sleep=1', '--tries=1']);
        $this->app['config']->set('laravel-tui.processes.commands.Scheduler', [PHP_BINARY, $root.'/vendor/bin/testbench', 'schedule:work']);
        $connection = $this->app['config']->get('database.default');
        $key = 'database.connections.'.$connection;

        if ($this->app['config']->get($key.'.driver') === 'sqlite'
            && $this->app['config']->get($key.'.database') === ':memory:') {
            $this->app['files']->ensureDirectoryExists($root.'/build');
            $database = $root.'/build/workbench.sqlite';

            if (! is_file($database)) {
                touch($database);
            }
            $this->app['config']->set($key.'.database', $database);
        }
        $this->app['config']->set('laravel-tui.artisan.command', [PHP_BINARY, $root.'/vendor/bin/testbench']);
        $this->app['config']->set('laravel-tui.artisan.working_directory', $root);
    }
}
