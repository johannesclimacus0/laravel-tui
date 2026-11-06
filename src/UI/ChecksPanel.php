<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Checks\CheckDefinition;
use LaravelTui\LaravelTui\Checks\CheckRunner;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;

class ChecksPanel extends ContainerWidget
{
    private SearchableList $list;

    private TextWidget $summary;

    private ?ScrollableText $output = null;

    private ?string $opened = null;

    private ?string $tickId = null;

    private ?Closure $interruptListener = null;

    private int $revision = -1;

    public function __construct(private CheckRunner $runner)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->summary = new TextWidget;
        $this->list = new SearchableList([]);
        $this->showList();
        $this->update();
    }

    /** @return list<array{value: string, label: string, search: string}> */
    private function items(): array
    {
        return array_map(function (CheckDefinition $check): array {
            $result = $this->runner->result($check->id);
            $tone = match ($result->status) {
                'passed' => 'border',
                'failed', 'timeout' => 'error',
                'running' => 'focus',
                'queued', 'cancelled' => 'warning',
                default => 'muted',
            };
            $color = Color::from(config('laravel-tui.theme.'.$tone, '#65727A'))->toForegroundCode();

            return [
                'value' => $check->id,
                'label' => $color.str_pad(strtoupper($result->status), 11)."\x1b[0m".$this->plain($check->label)
                    .($result->status === 'idle' ? '' : sprintf('  %.1fs', $result->elapsed)),
                'search' => implode(' ', $check->command),
            ];
        }, $this->runner->definitions());
    }

    private function actions(): Panel
    {
        $actions = new MenuList([
            ['value' => 'all', 'label' => 'Run all'],
            ['value' => 'rerun', 'label' => 'Rerun selected'],
            ['value' => 'stop', 'label' => 'Stop'],
        ], 3);
        $actions->addStyleClass('module-menu');
        $actions->onSelect(function (SelectEvent $event): void {
            match ($event->getValue()) {
                'all' => $this->runner->startAll(),
                'stop' => $this->runner->stop(),
                default => $this->runSelected(),
            };
            $this->update();
        });

        $panel = new Panel('Actions', config('laravel-tui.theme.focus', '#6DE2D5'), inactiveColor: config('laravel-tui.theme.muted', '#65727A'));
        $panel->content()->add($actions);

        return $panel;
    }

    private function showList(): void
    {
        $query = $this->list->query();
        $selected = $this->list->selectedValue();
        $searching = $this->list->isSearching();
        $this->clear();
        $this->opened = null;
        $this->output = null;
        $this->list = new SearchableList($this->items(), emptyMessage: 'No checks configured');
        $this->list->setQuery($query, $selected);
        $this->list->onInput($this->handleKey(...));
        $this->list->onSelect(function (SelectEvent $event): void {
            $id = $event->getValue();

            if ($this->runner->result($id)->status === 'idle') {
                $this->runner->start($id);
            }
            $this->showOutput($id);
            $this->update();
        });
        $this->add($this->summary);
        $this->add($this->list);
        $this->add($this->actions());

        if ($searching) {
            $this->list->openSearch();
        } else {
            $this->list->focus();
        }
    }

    private function showOutput(string $id): void
    {
        $this->clear();
        $this->opened = $id;
        $this->output = new ScrollableText;
        $this->output->followOutput();
        $this->output->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showList();

                return true;
            }

            return $this->handleKey($data);
        });
        $this->add($this->summary);
        $this->add($this->output);
        $this->add($this->actions());
        $this->getContext()?->getFocusManager()->setFocus($this->output);
        $this->revision = -1;
    }

    private function runSelected(): void
    {
        $id = $this->opened ?? $this->list->selectedValue();

        if ($id === null || $this->runner->running() !== null) {
            return;
        }
        $this->runner->start($id);
        $this->showOutput($id);
    }

    private function handleKey(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $key = strtr($keys?->getParser()->decodePrintable($data) ?? $data, config('laravel-tui.key_aliases', []));

        if (! ($keys?->matches($key, 'checks_run') ?? false)) {
            return false;
        }
        $this->runSelected();
        $this->update();

        return true;
    }

    private function update(): void
    {
        $this->runner->tick();

        if ($this->revision === $this->runner->revision()) {
            return;
        }
        $this->revision = $this->runner->revision();
        $this->getContext()?->requestRender();
        $counts = array_count_values(array_map(fn (CheckDefinition $check): string => $this->runner->result($check->id)->status, $this->runner->definitions()));
        $this->summary->setText(count($this->runner->definitions()).' checks  |  '.($counts['passed'] ?? 0).' passed  |  '
            .(($counts['failed'] ?? 0) + ($counts['timeout'] ?? 0)).' failed  |  '.$this->runner->queuedCount().' queued'
            .($this->runner->running() === null ? '' : '  |  Running: '.$this->plain($this->runner->running())));

        if ($this->opened === null) {
            $this->list->setItems($this->items());

            return;
        }
        foreach ($this->runner->definitions() as $check) {
            if ($check->id !== $this->opened) {
                continue;
            }
            $result = $this->runner->result($check->id);
            $text = $this->plain($check->label).'  ['.strtoupper($result->status).']  '.sprintf('%.1fs', $result->elapsed)
                .($result->exitCode === null ? '' : '  exit '.$result->exitCode)."\n"
                .$this->plain((new Process($check->command))->getCommandLine())."\n"
                .$this->plain($check->workingDirectory).'  |  Timeout: '.($check->timeout === null ? 'none' : $check->timeout.'s')."\n\n"
                .($result->truncated ? "[Earlier output removed]\n" : '').$this->plain($result->output);

            if ($this->output?->getText() !== $text) {
                $this->output?->setText($text);
            }
        }
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(str_replace("\r", '', $text))));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->list->focus();
        $this->interruptListener = function (InputEvent $event) use ($context): void {
            if ($this->runner->running() !== null && $context->keybindings()->matches($event->getData(), 'interrupt')) {
                $event->stopPropagation();
                $this->runner->stop();
                $this->update();
            }
        };
        $context->getEventDispatcher()->addListener(InputEvent::class, $this->interruptListener, 10);
        $this->tickId = $context->scheduleTick($this->update(...), 0.1);
    }

    protected function onDetach(): void
    {
        $this->runner->stop();

        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }

        if ($this->interruptListener !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(InputEvent::class, $this->interruptListener);
        }
    }
}
