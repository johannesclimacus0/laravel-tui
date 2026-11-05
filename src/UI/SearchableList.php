<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;

class SearchableList extends ContainerWidget
{
    private InputWidget $input;

    private AdaptiveList $list;

    private ContainerWidget $searchBar;

    private bool $searching = false;

    /**
     * @param  list<array{value: string, label: string, search?: string}>  $items
     */
    public function __construct(private array $items)
    {
        $this->input = new InputWidget;
        $this->input->setPrompt('/ ');

        $this->list = new AdaptiveList($this->listItems($items));
        $this->list->addStyleClass('module-menu');
        $this->list->onInput(function (string $data): bool {
            if (! $this->list->getKeybindings()->matches($data, 'toggle_search')) {
                return false;
            }

            $this->toggleSearch();

            return true;
        });
        $this->list->onCancel(fn () => $this->closeSearch());

        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->searchBar = new ContainerWidget;
        $this->searchBar->setStyle(new Style(hidden: true));
        $this->add($this->searchBar);
        $this->add($this->list);
    }

    /** @param callable(SelectEvent): void $callback */
    public function onSelect(callable $callback): static
    {
        $this->list->onSelect($callback);

        return $this;
    }

    public function focus(): void
    {
        $this->getContext()?->getFocusManager()->setFocus($this->list);
    }

    private function configureInput(): void
    {
        $this->input->onInput(function (string $data): bool {
            if (! $this->input->getKeybindings()->matches($data, 'toggle_search')) {
                return false;
            }

            $this->toggleSearch();

            return true;
        });

        $this->input->onChange(function (ChangeEvent $event): void {
            $query = trim($event->getValue());

            $filtered = array_filter(
                $this->items,
                static fn (array $item): bool => $query === ''
                    || mb_stripos(
                        $item['label'].' '.($item['search'] ?? ''),
                        $query,
                    ) !== false,
            );

            $this->list->setItems(
                $this->listItems(array_values($filtered)),
            );
        });

        $this->input->onSubmit(function (): void {
            $this->getContext()?->getFocusManager()->setFocus($this->list);
        });

        $this->input->onCancel(fn () => $this->closeSearch());
    }

    private function toggleSearch(): void
    {
        if ($this->searching) {
            $this->closeSearch(reset: false);

            return;
        }

        $this->openSearch();
    }

    private function openSearch(): void
    {
        if (! $this->searching) {
            $this->configureInput();
            $this->searchBar->add($this->input);
            $this->searchBar->setStyle(new Style(hidden: false));
            $this->searching = true;
        }

        $this->getContext()?->getFocusManager()->setFocus($this->input);
    }

    private function closeSearch(bool $reset = true): void
    {
        if ($reset) {
            $this->input->setValue('');
            $this->list->setItems($this->listItems($this->items));
        }
        $this->searchBar->remove($this->input);
        $this->searchBar->setStyle(new Style(hidden: true));
        $this->searching = false;
        $this->getContext()?->getFocusManager()->setFocus($this->list);
    }

    /**
     * @param  list<array{value: string, label: string, search?: string}>  $items
     * @return list<array{value: string, label: string}>
     */
    private function listItems(array $items): array
    {
        return array_map(
            static fn (array $item): array => [
                'value' => $item['value'],
                'label' => $item['label'],
            ],
            $items,
        );
    }
}
