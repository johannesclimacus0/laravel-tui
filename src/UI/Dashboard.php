<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use InvalidArgumentException;
use LaravelTui\LaravelTui\Contracts\Module;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Align;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\VerticalAlign;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class Dashboard
{
    /**
     * @param  list<Module>  $modules
     * @param  array<string, list<string>>  $keybindings
     * @param  array<string, string>  $keyAliases
     * @param  array{project?: string, default_module?: string, modules?: array<string, string>}  $icons
     * @param  array<string, list<string>>  $captions
     * @param  array<string, string>  $moduleArt
     */
    public function run(
        array $modules,
        array $keybindings,
        array $keyAliases,
        string $appName,
        array $icons,
        array $captions,
        Miku $pictures,
        array $moduleArt,
        string $defaultArt,
    ): void {
        if ($modules === []) {
            throw new InvalidArgumentException('At least one module is required');
        }

        $indexed = [];
        $items = [];
        $labels = [];

        foreach ($modules as $module) {
            $id = $module->id();

            if (isset($indexed[$id])) {
                throw new InvalidArgumentException('Duplicate id found at: '.$id);
            }

            $indexed[$id] = $module;
            $icon = $icons['modules'][$id] ?? $icons['default_module'] ?? '';
            $labels[$id] = ($icon === '' ? '' : $icon.' ').$module->label();
            $items[] = ['value' => $id, 'label' => $labels[$id]];
        }

        // TUI init
        $keys = new Keybindings($keybindings);
        $tui = new Tui(keybindings: $keys);

        $accent = config('laravel-tui.theme.focus', '#6DE2D5');
        $muted = config('laravel-tui.theme.muted', '#65727A');
        $selection = config('laravel-tui.theme.selection', '#39C5BB');

        $tui->addStyleSheet(new StyleSheet([
            '*' => new Style(color: config('laravel-tui.theme.text', 'default')),
            '.module-menu::selected' => new Style(color: config('laravel-tui.theme.selection_inactive', '#39C5BB'), bold: false),
            '.module-menu::selected:focus' => new Style(color: $selection, bold: true),
            '.command-output::selected' => new Style(color: $selection, bold: true),
            '.log-output::selected' => new Style(color: $selection, bold: true),
        ]));

        // Project panel
        $projectIcon = $icons['project'] ?? '';
        $project = new Panel(($projectIcon === '' ? '' : $projectIcon.' ').'Project', $muted);
        $projectName = new TextWidget($appName, truncate: true);
        $project->content()->add($projectName);

        // Module menu
        $menu = new MenuList($items, maxVisible: count($items));
        $menu->addStyleClass('module-menu');
        $navigation = new Panel('Modules', $accent);
        $navigation->content()->add($menu);

        // Art loading
        $artNames = $pictures->names();
        $activeArt = $moduleArt[$modules[0]->id()] ?? $defaultArt;
        foreach ([$defaultArt, ...array_values($moduleArt)] as $name) {
            if (! in_array($name, $artNames, true)) {
                throw new InvalidArgumentException('Unknown configured art: '.$name);
            }
        }
        $art = new MikuWidget($pictures, $activeArt);
        $art->expandVertically(false);

        $artArea = new ContainerWidget;
        $artArea->setStyle(new Style(align: Align::Center, verticalAlign: VerticalAlign::Center));
        $artArea->expandVertically(true);
        $artArea->add($art);

        $artPanel = new Panel('', $muted, expanded: true);
        $artPanel->content()->add($artArea);

        // Art captions
        $caption = new TextWidget($this->caption($modules[0]->id(), $captions));
        $caption->setStyle(new Style(color: $muted));

        $artPanel->content()->add($caption);

        // Sidebar layout
        $sidebar = new ContainerWidget;
        $sidebar->setStyle(new Style(gap: 1, maxColumns: 30, flex: 0));
        $sidebar->expandVertically(true);
        $sidebar->add($project);
        $sidebar->add($navigation);
        $sidebar->add($artPanel);

        // Initial module content
        $workspace = new Panel(
            $labels[$modules[0]->id()],
            config('laravel-tui.theme.workspace_focus', '#F4A6C8'),
            expanded: true,
            inactiveColor: config('laravel-tui.theme.workspace_border', '#E879A9'),
        );
        $workspace->setStyle(new Style(flex: 1));
        $content = $workspace->content();
        $content->add($modules[0]->widget());

        // Main layout
        $panels = new ContainerWidget;
        $panels->setStyle(new Style(direction: Direction::Horizontal, gap: 1));
        $panels->expandVertically(true);
        $panels->add($sidebar);
        $panels->add($workspace);
        $tui->add($panels);

        // Resize sidebar and art
        $lastSize = null;
        $resize = function () use ($tui, $pictures, $sidebar, $projectName, $appName, $artPanel, $modules, &$lastSize): void {
            $terminal = $tui->getTerminal();
            $columns = $terminal->getColumns();
            $rows = $terminal->getRows();

            if ($lastSize === [$columns, $rows]) {
                return;
            }

            $lastSize = [$columns, $rows];

            $budget = min(48, max(24, (int) floor($columns * 0.34)));
            $artRows = max(0, $rows - count($modules) - 11);
            $width = max(24, $pictures->maxWidth($budget - 4, $artRows) + 4);

            $sidebar->setStyle(new Style(gap: 1, maxColumns: $width, flex: 0));
            $projectName->setText(str_pad($appName, $width - 4));
            $artPanel->setStyle(new Style(hidden: $columns < 70));
        };

        $resize();
        $tui->onTick($resize);

        // Open selected module
        $menu->onSelect(function (SelectEvent $event) use ($tui, $menu, $indexed, $labels, $content, $workspace, $caption, $captions, $art, $moduleArt, $defaultArt, &$activeArt): void {
            $module = $indexed[$event->getValue()];
            $widget = $module->widget();

            $content->clear();
            $content->add($widget);
            $workspace->setTitle($labels[$event->getValue()]);
            $caption->setText($this->caption($module->id(), $captions));
            $activeArt = $moduleArt[$module->id()] ?? $defaultArt;
            $art->setName($activeArt);

            if ($tui->getFocus() === $menu) {
                $tui->getFocusManager()->focusNext();
            }
        });

        // Keybindings
        $tui->addListener(function (InputEvent $event) use ($keys, $tui, $keyAliases, $art, $artNames, &$activeArt): void {
            if (
                $tui->getFocus() instanceof InputWidget
                && ! $keys->getParser()->matches($event->getData(), 'ctrl+c')
            ) {
                return;
            }

            $printable = $keys->getParser()->decodePrintable($event->getData()) ?? $event->getData();
            $key = strtr($printable, $keyAliases);

            if ($keys->matches($key, 'quit')) {
                $event->stopPropagation();
                $tui->stop();

                return;
            }

            if ($keys->matches($key, 'cycle_art')) {
                $event->stopPropagation();
                $index = array_search($activeArt, $artNames, true);
                $activeArt = $artNames[((int) $index + 1) % count($artNames)];
                $art->setName($activeArt);
                $tui->requestRender();
            }
        });

        $tui->setFocus($menu);

        try {
            $tui->run();
        } finally {
            $content->clear();
        }
    }

    /**
     * @param  array<string, list<string>>  $captions
     */
    private function caption(string $moduleId, array $captions): string
    {
        $quotes = $captions[$moduleId] ?? $captions['default'] ?? [];

        return $quotes === [] ? '' : $quotes[random_int(0, count($quotes) - 1)];
    }
}
