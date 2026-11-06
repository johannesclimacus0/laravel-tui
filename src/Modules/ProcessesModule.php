<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Foundation\Application;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\Processes\ProcessManager;
use LaravelTui\LaravelTui\UI\ProcessesPanel;
use LaravelTui\LaravelTui\UI\StatusLine;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Throwable;

class ProcessesModule implements Module
{
    public function __construct(private Application $app) {}

    public function id(): string
    {
        return 'processes';
    }

    public function label(): string
    {
        return 'Processes';
    }

    public function widget(): AbstractWidget
    {
        try {
            return new ProcessesPanel($this->app->make(ProcessManager::class));
        } catch (Throwable $error) {
            $status = new StatusLine;
            $status->setStatus('Error', $error->getMessage(), 'error');

            return $status;
        }
    }
}
