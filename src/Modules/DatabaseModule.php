<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\DatabasePanel;
use Symfony\Component\Tui\Widget\AbstractWidget;

class DatabaseModule implements Module
{
    public function __construct(private ArtisanRunner $runner) {}

    public function id(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'Database';
    }

    public function widget(): AbstractWidget
    {
        return new DatabasePanel($this->runner);
    }
}
