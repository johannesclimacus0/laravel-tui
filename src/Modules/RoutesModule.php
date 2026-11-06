<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\RoutesPanel;
use Symfony\Component\Tui\Widget\AbstractWidget;

class RoutesModule implements Module
{
    public function __construct(private ArtisanRunner $runner) {}

    public function id(): string
    {
        return 'routes';
    }

    public function label(): string
    {
        return 'Routes';
    }

    public function widget(): AbstractWidget
    {
        return new RoutesPanel($this->runner);
    }
}
