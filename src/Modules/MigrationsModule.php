<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\MigrationsPanel;
use Symfony\Component\Tui\Widget\AbstractWidget;

class MigrationsModule implements Module
{
    public function __construct(private ArtisanRunner $runner) {}

    public function id(): string
    {
        return 'migrations';
    }

    public function label(): string
    {
        return 'Migrations';
    }

    public function widget(): AbstractWidget
    {
        return new MigrationsPanel($this->runner);
    }
}
