<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\Scheduler\ScheduleRepository;
use LaravelTui\LaravelTui\UI\SchedulerPanel;
use LaravelTui\LaravelTui\UI\StatusLine;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Throwable;

class SchedulerModule implements Module
{
    public function __construct(private Kernel $artisan, private Application $app) {}

    public function id(): string
    {
        return 'scheduler';
    }

    public function label(): string
    {
        return 'Scheduler';
    }

    public function widget(): AbstractWidget
    {
        try {
            $this->artisan->all();

            return new SchedulerPanel($this->app->make(ScheduleRepository::class));
        } catch (Throwable $error) {
            $status = new StatusLine;
            $status->setStatus('Error', $error->getMessage(), 'error');

            return $status;
        }
    }
}
