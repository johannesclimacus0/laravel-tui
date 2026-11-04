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
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
