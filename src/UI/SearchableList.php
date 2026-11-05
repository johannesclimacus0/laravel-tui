<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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

    private Panel $searchBar;

    private bool $searching = false;

    private int $filteredCount = 0;

    private ?Closure $inputListener = null;

    /**
     * @param  list<array{value: string, label: string, search?: string}>  $items
     */
    public function __construct(
        private array $items,
        private ?string $footerPrompt = null,
    ) {
        $this->filteredCount = count($items);
        $this->input = new InputWidget;
        $this->input->setPrompt($footerPrompt ?? '/ ');

        $this->list = new AdaptiveList($this->listItems($items));
        $this->list->expandVertically(true);
        $this->list->addStyleClass('module-menu');
        $this->list->onInput(function (string $data): bool {
            if ($this->inputListener !== null && ($this->inputListener)($data)) {
                return true;
            }

            if ($this->footerPrompt !== null && $this->list->getKeybindings()->matches($data, 'complete_command')) {
                $this->completeCommand();

                return true;
            }

            if (! $this->isSearchToggle($data)) {
                return false;
            }

            $this->toggleSearch();

            return true;
        });
        $this->list->onCancel(fn () => $this->closeSearch());

        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->searchBar = new Panel('', '#39C5BB');

        if ($footerPrompt !== null) {
            $this->configureInput();
            $this->searchBar->content()->add($this->input);
            $this->searching = true;
        } else {
            $this->searchBar->setStyle(new Style(hidden: true));
        }

        $this->add($this->list);
        $this->add($this->searchBar);
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

    /** @param callable(string): bool $callback */
    public function onInput(callable $callback): static
    {
        $this->inputListener = $callback(...);

        return $this;
    }

    /** @param callable(): void $callback */
    public function onCancel(callable $callback): static
    {
        $this->list->onCancel($callback);

        return $this;
    }

    public function selectIndex(int $index): void
    {
        $this->list->setSelectedIndex($index);
    }

    public function count(): int
    {
        return $this->filteredCount;
    }

    public function selectedValue(): ?string
    {
        return $this->list->getSelectedItem()['value'] ?? null;
    }

    public function isSearching(): bool
    {
        return $this->searching;
    }

    /** @param list<array{value: string, label: string, search?: string}> $items */
    public function setItems(array $items): void
    {
        $selected = $this->selectedValue();
        $this->items = $items;
        $this->filter($this->query(), $selected);
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
                || mb_stripos(AnsiUtils::stripAnsiCodes($item['label']).' '.($item['search'] ?? ''), $query) !== false,
        ));
        $this->list->setItems($this->listItems($filtered));
        $this->filteredCount = count($filtered);
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

            if (! $this->isSearchToggle($data)) {
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

    private function isSearchToggle(string $data): bool
    {
        $keys = $this->list->getKeybindings();
        $printable = $keys->getParser()->decodePrintable($data) ?? $data;
        $key = strtr($printable, config('laravel-tui.key_aliases', []));

        return $keys->matches($key, 'toggle_search');
    }

    public function openSearch(): void
    {
        if (! $this->searching) {
            $this->configureInput();
            $this->searchBar->content()->add($this->input);
            $this->searchBar->setStyle(new Style(hidden: false));
            $this->searching = true;
        }

        $this->getContext()?->getFocusManager()->setFocus($this->input);
    }

    public function closeSearch(bool $reset = true): void
    {
        if ($reset) {
            $this->setQuery('');
        }

        if ($this->footerPrompt !== null) {
            $this->focus();

            return;
        }

        $this->searchBar->content()->remove($this->input);
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
