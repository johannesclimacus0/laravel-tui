<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Processes\ProcessDefinition;
use LaravelTui\LaravelTui\Processes\ProcessManager;
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

class ProcessesPanel extends ContainerWidget
{
    private SearchableList $list;

    private TextWidget $summary;

    private TextWidget $metadata;

    private StatusLine $status;

    private ?ScrollableText $output = null;

    private ?string $opened = null;

    private ?string $tickId = null;

    private ?Closure $interruptListener = null;

    private int $revision = -1;

    public function __construct(private ProcessManager $manager)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->summary = new TextWidget;
        $this->summary->setStyle(new Style(color: config('laravel-tui.theme.muted', '#65727A')));
        $this->metadata = new TextWidget('', truncate: true);
        $this->metadata->setStyle(new Style(color: config('laravel-tui.theme.muted', '#65727A')));
        $this->status = new StatusLine;
        $this->list = new SearchableList([]);
        $this->showList();
        $this->update();
    }

    /** @return list<array{value: string, label: string, search: string}> */
    private function items(): array
    {
        return array_map(function (ProcessDefinition $definition): array {
            $state = $this->manager->state($definition->id);
            $tone = match ($state->status) {
                'running' => 'focus',
                'exited' => 'border',
                'failed', 'timeout' => 'error',
                'stopped' => 'warning',
                default => 'muted',
            };
            $color = Color::from(config('laravel-tui.theme.'.$tone, '#65727A'))->toForegroundCode();

            return [
                'value' => $definition->id,
                'label' => $color.str_pad(strtoupper($state->status), 10)."\x1b[0m".$this->plain($definition->label)
                    .($state->status === 'idle' ? '' : sprintf('  %.1fs', $state->elapsed))
                    .($state->pid === null ? '' : '  PID '.$state->pid),
                'search' => implode(' ', $definition->command),
            ];
        }, $this->manager->definitions());
    }

    private function actions(): Panel
    {
        $actions = new MenuList([
            ['value' => 'start', 'label' => 'Start selected'],
            ['value' => 'restart', 'label' => 'Restart selected'],
            ['value' => 'stop', 'label' => 'Stop selected'],
            ['value' => 'start_all', 'label' => 'Start all'],
            ['value' => 'stop_all', 'label' => 'Stop all'],
        ], 5);
        $actions->addStyleClass('module-menu');
        $actions->onSelect(function (SelectEvent $event): void {
            $action = $event->getValue();
            $id = $this->selected();

            if ($action === 'start_all') {
                $this->manager->startAll();
            } elseif ($action === 'stop_all') {
                $this->manager->stopAll();
            } elseif ($id !== null) {
                match ($action) {
                    'stop' => $this->manager->stop($id),
                    'restart' => $this->manager->restart($id),
                    default => $this->manager->start($id),
                };

                if ($action !== 'stop') {
                    $this->showOutput($id);
                }
            }
            $this->update();
        });
        $actions->onInput($this->handleKey(...));
        $actions->onCancel(function (): void {
            if ($this->opened !== null) {
                $this->showList();
                $this->update();
            } else {
                $this->list->focus();
            }
        });
        $panel = new Panel('Actions', config('laravel-tui.theme.focus', '#6DE2D5'), inactiveColor: config('laravel-tui.theme.muted', '#65727A'));
        $panel->content()->add($actions);

        return $panel;
    }

    private function selected(): ?string
    {
        return $this->opened ?? $this->list->selectedValue();
    }

    private function showList(): void
    {
        $query = $this->list->query();
        $selected = $this->list->selectedValue();
        $searching = $this->list->isSearching();
        $this->clear();
        $this->opened = null;
        $this->output = null;
        $this->list = new SearchableList($this->items(), emptyMessage: 'No processes configured');
        $this->list->setQuery($query, $selected);
        $this->list->onInput($this->handleKey(...));
        $this->list->onSelect(function (SelectEvent $event): void {
            $id = $event->getValue();

            if ($this->manager->state($id)->status === 'idle') {
                $this->manager->start($id);
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
        $this->revision = -1;
    }

    private function showOutput(string $id): void
    {
        $this->clear();
        $this->opened = $id;
        $this->output = new ScrollableText;
        $this->output->addStyleClass('command-output');
        $this->output->followOutput();
        $this->output->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showList();
                $this->update();

                return true;
            }

            return $this->handleKey($data);
        });
        $this->add($this->summary);
        $this->add($this->status);
        $this->add($this->metadata);
        $this->add($this->output);
        $this->add($this->actions());
        $this->getContext()?->getFocusManager()->setFocus($this->output);
        $this->revision = -1;
    }

    private function handleKey(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $key = strtr($keys?->getParser()->decodePrintable($data) ?? $data, config('laravel-tui.key_aliases', []));
        $id = $this->selected();

        if ($id === null) {
            return false;
        }

        if ($keys?->matches($key, 'processes_restart')) {
            $this->manager->restart($id);
            $this->showOutput($id);
        } elseif ($keys?->matches($key, 'processes_stop')) {
            $this->manager->stop($id);
        } else {
            return false;
        }
        $this->update();

        return true;
    }

    private function update(): void
    {
        $this->manager->tick();

        if ($this->revision === $this->manager->revision()) {
            return;
        }
        $this->revision = $this->manager->revision();
        $this->getContext()?->requestRender();
        $failed = count(array_filter($this->manager->definitions(), fn (ProcessDefinition $definition): bool => in_array($this->manager->state($definition->id)->status, ['failed', 'timeout'], true)));
        $this->summary->setText($this->manager->runningCount().' / '.count($this->manager->definitions()).' running  |  '.$failed.' failed');

        if ($this->opened === null) {
            $this->list->setItems($this->items());

            return;
        }
        foreach ($this->manager->definitions() as $definition) {
            if ($definition->id !== $this->opened) {
                continue;
            }
            $state = $this->manager->state($definition->id);
            $tone = match ($state->status) {
                'running' => 'info',
                'exited' => 'success',
                'failed', 'timeout' => 'error',
                'stopped' => 'warning',
                default => 'muted',
            };
            $this->status->setStatus(strtoupper($state->status), $definition->label.sprintf('  %.1fs', $state->elapsed)
                .($state->pid === null ? '' : '  PID '.$state->pid)
                .($state->exitCode === null ? '' : '  exit '.$state->exitCode), $tone);
            $this->metadata->setText($this->plain((new Process($definition->command))->getCommandLine())."\n"
                .$this->plain($definition->workingDirectory).($definition->timeout === null ? '' : '  |  Timeout: '.$definition->timeout.'s'));
            $text = ($state->truncated ? "[Earlier output removed]\n" : '').$this->plain($state->output);

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
            if (! $context->keybindings()->matches($event->getData(), 'interrupt') || $this->manager->runningCount() === 0) {
                return;
            }
            $event->stopPropagation();
            $id = $this->selected();

            if ($id !== null && $this->manager->state($id)->status === 'running') {
                $this->manager->stop($id);
            } else {
                $this->manager->stopAll();
            }
            $this->update();
        };
        $context->getEventDispatcher()->addListener(InputEvent::class, $this->interruptListener, 10);
        $this->tickId = $context->scheduleTick($this->update(...), 0.1);
    }

    protected function onDetach(): void
    {
        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }

        if ($this->interruptListener !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(InputEvent::class, $this->interruptListener);
        }
    }
}
