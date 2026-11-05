<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Console\Kernel;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\ArtisanPanel;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class ArtisanModule implements Module
{
    public function __construct(
        private Kernel $artisan,
        private ArtisanRunner $runner,
    ) {}

    public function id(): string
    {
        return 'artisan';
    }

    public function label(): string
    {
        return 'Artisan';
    }

    public function widget(): AbstractWidget
    {
        $commands = $this->artisan->all();
        ksort($commands);

        $available = [];
        $excluded = config('laravel-tui.artisan.exclude', []);

        foreach ($commands as $name => $command) {
            if ($command->isHidden() || $name === 'ui' || in_array($name, $excluded, true)) {
                continue;
            }

            $available[$name] = $command;
        }

        if ($available === []) {
            return new TextWidget('No commands available.');
        }

        return new ArtisanPanel($available, $this->runner);
    }
}
