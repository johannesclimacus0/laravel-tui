<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Contracts\Module;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class OverviewModule implements Module
{
    public function id(): string
    {
        return 'overview';
    }

    public function label(): string
    {
        return 'Overview';
    }

    public function widget(): AbstractWidget
    {
        return new TextWidget('halo this is meeekoooo');
    }
}
