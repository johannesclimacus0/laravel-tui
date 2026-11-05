<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use LaravelTui\LaravelTui\Art\ArtRepository;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Console\Commands\LaravelTuiCommand;
use LaravelTui\LaravelTui\UI\Miku;

class LaravelTuiServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-tui.php', 'laravel-tui');

        $this->app->singleton(ArtRepository::class, fn (Application $app): ArtRepository => new ArtRepository(
            dirname(__DIR__).'/resources',
            (string) $app->make(Repository::class)->get('laravel-tui.art.path'),
            $app->make(Filesystem::class),
        ));
        $this->app->singleton(Miku::class);
        $this->app->bind(ArtisanRunner::class, fn (): ArtisanRunner => new ArtisanRunner(
            config('laravel-tui.artisan.command'),
            config('laravel-tui.artisan.working_directory'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__).'/resources/views', 'laravel-tui');
        $this->loadRoutesFrom(dirname(__DIR__).'/routes/art-editor.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-tui.php' => config_path('laravel-tui.php'),
        ], ['laravel-tui', 'laravel-tui-config']);

        $this->publishes([
            dirname(__DIR__).'/resources/art' => config('laravel-tui.art.path').'/art',
            dirname(__DIR__).'/resources/colors.json' => config('laravel-tui.art.path').'/colors.json',
        ], ['laravel-tui', 'laravel-tui-art']);

        $this->commands([
            LaravelTuiCommand::class,
        ]);
    }
}
