<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Contracts\Module;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class ExampleModule implements Module
{
    public function id(): string
    {
        return 'example';
    }

    public function label(): string
    {
        return 'Example';
    }

    public function widget(): AbstractWidget
    {
        return new TextWidget('haloo');
    }
}
