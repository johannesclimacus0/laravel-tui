<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Console\Kernel;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\SearchableList;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class ArtisanModule implements Module
{
    public function __construct(private Kernel $artisan) {}

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

        $items = [];

        foreach ($commands as $name => $command) {
            if ($command->isHidden() || $name === 'ui') {
                continue;
            }

            $items[] = [
                'value' => $name,
                'label' => $name,
                'search' => $command->getDescription(),
            ];
        }

        if ($items === []) {
            return new TextWidget('No commands available.');
        }

        $list = new SearchableList($items);

        $details = new TextWidget;

        $list->onSelect(function (SelectEvent $event) use ($commands, $details): void {
            $command = $commands[$event->getValue()];
            $formatter = new OutputFormatter(decorated: true);

            $text = implode("\n\n", [
                $command->getDescription(),
                'php artisan '.$command->getSynopsis(),
                $command->getProcessedHelp(),
            ]);

            $details->setText($formatter->format($text) ?? '');
        });

        $panel = new ContainerWidget;
        $panel->expandVertically(true);
        $panel->add($list);
        $panel->add($details);

        return $panel;
    }
}
