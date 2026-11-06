<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use LaravelTui\LaravelTui\Scheduler\ScheduledTask;
use LaravelTui\LaravelTui\Scheduler\ScheduleRepository;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class SchedulerPanel extends ContainerWidget
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    private SearchableList $list;

    private StatusLine $status;

    private ?ScrollableText $detail = null;

    private ?string $opened = null;

    public function __construct(private ScheduleRepository $repository)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->status = new StatusLine;
        $this->list = new SearchableList([], emptyMessage: 'No scheduled tasks configured');
        $this->refresh();
        $this->showList();
    }

    private function refresh(): void
    {
        try {
            $tasks = $this->repository->tasks();
            $this->tasks = [];
            $items = [];
            foreach ($tasks as $task) {
                $this->tasks[$task->id] = $task;
                $items[] = [
                    'value' => $task->id,
                    'label' => $task->label.($task->environmentNotice === '' ? '' : ' ['.$task->environmentNotice.']').' | '.$task->frequency.' | '.$task->next,
                    'search' => implode(' ', $task->details),
                ];
            }
            $this->list->setItems($items);
            $this->status->hide();
        } catch (Throwable $error) {
            $this->status->setStatus('Error', $error->getMessage(), 'error');
        }
    }

    private function refreshInput(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $printable = $keys?->getParser()->decodePrintable($data) ?? $data;

        if (! ($keys?->matches(strtr($printable, config('laravel-tui.key_aliases', [])), 'scheduler_refresh') ?? false)) {
            return false;
        }
        $this->refresh();

        if ($this->opened !== null) {
            $this->showDetail($this->opened);
        }

        return true;
    }

    private function showList(): void
    {
        $query = $this->list->query();
        $selected = $this->list->selectedValue();
        $searching = $this->list->isSearching();
        $this->clear();
        $this->list = new SearchableList(array_values(array_map(
            static fn (ScheduledTask $task): array => [
                'value' => $task->id,
                'label' => $task->label.($task->environmentNotice === '' ? '' : ' ['.$task->environmentNotice.']').' | '.$task->frequency.' | '.$task->next,
                'search' => implode(' ', $task->details),
            ],
            $this->tasks,
        )), emptyMessage: 'No scheduled tasks configured');
        $this->list->setQuery($query, $selected);
        $this->list->onSelect(function (SelectEvent $event): void {
            $this->showDetail($event->getValue());
        });
        $this->list->onInput(fn (string $data): bool => $this->refreshInput($data));

        if ($searching) {
            $this->list->openSearch();
        }
        $this->detail = null;
        $this->opened = null;
        $this->add($this->status);
        $this->add($this->list);
        $this->list->focus();
    }

    private function showDetail(string $id): void
    {
        $task = $this->tasks[$id] ?? null;

        if ($task === null) {
            $this->showList();

            return;
        }
        $this->clear();
        $this->opened = $id;
        $this->detail = new ScrollableText(implode("\n", $task->details));
        $this->detail->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showList();

                return true;
            }

            return $this->refreshInput($data);
        });
        $this->add($this->status);
        $this->add($this->detail);
        $this->getContext()?->getFocusManager()->setFocus($this->detail);
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->list->focus();
    }
}
