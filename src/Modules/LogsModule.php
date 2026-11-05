<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Filesystem\Filesystem;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\LogsPanel;
use Symfony\Component\Tui\Widget\AbstractWidget;

class LogsModule implements Module
{
    public function __construct(private Filesystem $files) {}

    public function id(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return 'Logs';
    }

    public function widget(): AbstractWidget
    {
        return new LogsPanel(
            $this->files,
            config('laravel-tui.logs.path'),
            (int) config('laravel-tui.logs.max_bytes', 262144),
            (float) config('laravel-tui.logs.refresh_interval', 0.5),
        );
    }
}
