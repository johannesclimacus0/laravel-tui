<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Foundation\Application;
use LaravelTui\LaravelTui\Checks\CheckRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\ChecksPanel;
use LaravelTui\LaravelTui\UI\StatusLine;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Throwable;

class ChecksModule implements Module
{
    public function __construct(private Application $app) {}

    public function id(): string
    {
        return 'checks';
    }

    public function label(): string
    {
        return 'Checks';
    }

    public function widget(): AbstractWidget
    {
        try {
            return new ChecksPanel($this->app->make(CheckRunner::class));
        } catch (Throwable $error) {
            $status = new StatusLine;
            $status->setStatus('Error', $error->getMessage(), 'error');

            return $status;
        }
    }
}
