<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Contracts;

use Symfony\Component\Tui\Widget\AbstractWidget;

interface Module
{
    public function id(): string;

    public function label(): string;

    public function widget(): AbstractWidget;
}
