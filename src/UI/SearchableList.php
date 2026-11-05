<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\WidgetContext;

class SearchableList extends ContainerWidget
{
    private InputWidget $input;

    private AdaptiveList $list;

    private ContainerWidget $searchBar;

    private bool $searching = false;

    /**
     * @param  list<array{value: string, label: string, search?: string}>  $items
     */
    public function __construct(
        private array $items,
        private ?string $footerPrompt = null,
    ) {
        $this->input = new InputWidget;
        $this->input->setPrompt($footerPrompt ?? '/ ');

        $this->list = new AdaptiveList($this->listItems($items));
        $this->list->addStyleClass('module-menu');
        $this->list->onInput(function (string $data): bool {
            if ($this->footerPrompt !== null && $this->list->getKeybindings()->matches($data, 'complete_command')) {
                $this->completeCommand();

                return true;
            }

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

        if ($footerPrompt !== null) {
            $this->configureInput();
            $this->list->expandVertically(true);
            $frame = new Panel('', '#39C5BB');
            $frame->content()->add($this->input);
            $this->searchBar->add($frame);
            $this->searching = true;
            $this->add($this->list);
            $this->add($this->searchBar);

            return;
        }

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
        $this->getContext()?->getFocusManager()->setFocus($this->footerPrompt === null ? $this->list : $this->input);
    }

    public function query(): string
    {
        return $this->input->getValue();
    }

    public function selectedValue(): ?string
    {
        return $this->list->getSelectedItem()['value'] ?? null;
    }

    public function setQuery(string $query, ?string $selected = null): void
    {
        $this->input->setValue($query);
        $this->filter($query, $selected);
    }

    private function filter(string $query, ?string $selected = null): void
    {
        $query = trim($query);
        $filtered = array_values(array_filter(
            $this->items,
            static fn (array $item): bool => $query === ''
                || mb_stripos($item['label'].' '.($item['search'] ?? ''), $query) !== false,
        ));
        $this->list->setItems($this->listItems($filtered));
        $index = array_search($selected, array_column($filtered, 'value'), true);

        if ($index !== false) {
            $this->list->setSelectedIndex($index);
        }
    }

    private function completeCommand(): void
    {
        $selected = $this->selectedValue();

        if ($selected !== null) {
            $this->setQuery($selected, $selected);
            $this->getContext()?->requestRender();
        }
    }

    private function configureInput(): void
    {
        $this->input->onInput(function (string $data): bool {
            if ($this->footerPrompt !== null) {
                $keys = $this->list->getKeybindings();

                if ($keys->matches($data, 'complete_command')) {
                    $this->completeCommand();

                    return true;
                }

                if ($keys->matches($data, 'select_up') || $keys->matches($data, 'select_down')) {
                    $this->list->handleInput($data);
                    $this->getContext()?->requestRender();

                    return true;
                }

                return false;
            }

            if (! $this->input->getKeybindings()->matches($data, 'toggle_search')) {
                return false;
            }

            $this->toggleSearch();

            return true;
        });

        $this->input->onChange(function (ChangeEvent $event): void {
            $this->filter($event->getValue());
        });

        $this->input->onSubmit(function (): void {
            if ($this->footerPrompt !== null) {
                $this->list->handleInput("\r");

                return;
            }

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

        if ($this->footerPrompt !== null) {
            $this->focus();

            return;
        }

        $this->searchBar->remove($this->input);
        $this->searchBar->setStyle(new Style(hidden: true));
        $this->searching = false;
        $this->getContext()?->getFocusManager()->setFocus($this->list);
    }

    protected function onAttach(WidgetContext $context): void
    {
        if ($this->footerPrompt !== null) {
            $context->getFocusManager()->setFocus($this->input);
        }
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
