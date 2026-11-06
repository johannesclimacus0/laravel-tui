<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\SelectListWidget;

class MenuList extends SelectListWidget
{
    /** @var list<array{value: string, label: string, description?: string}> */
    private array $sourceItems;

    /** @var list<int> */
    private array $mouseRows = [];

    /** @var list<array{value: string, label: string, description?: string}> */
    private array $mouseItems;

    /** @param list<array{value: string, label: string, description?: string}> $items */
    public function __construct(array $items, private int $maxVisible = 5, ?Keybindings $keybindings = null)
    {
        $this->sourceItems = $items;
        $this->mouseItems = $items;
        parent::__construct($items, $maxVisible, $keybindings);
    }

    /** @param list<array{value: string, label: string}> $items */
    public function setItems(array $items): static
    {
        $this->sourceItems = $items;
        $this->mouseItems = $items;
        $this->mouseRows = [];

        return parent::setItems($items);
    }

    public function setFilter(string $filter): static
    {
        $this->mouseItems = array_values(array_filter(
            $this->sourceItems,
            static fn (array $item): bool => str_starts_with(strtolower($item['value']), strtolower($filter)),
        ));
        $this->mouseRows = [];

        return parent::setFilter($filter);
    }

    public function mouseClick(int $row): void
    {
        $index = $this->mouseRows[$row] ?? null;

        if ($index === null || ! isset($this->mouseItems[$index])) {
            return;
        }
        $this->setSelectedIndex($index);
        $selected = $this->mouseItems[$index];
        $this->dispatch(new SelectionChangeEvent($this, $selected));
        $this->dispatch(new SelectEvent($this, $selected));
    }

    public function mouseScroll(int $lines): void
    {
        $index = array_search($this->getSelectedItem()['value'] ?? '', array_column($this->mouseItems, 'value'), true);

        if ($index === false) {
            return;
        }
        $this->setSelectedIndex($index + $lines);
        $selected = $this->getSelectedItem();

        if ($selected !== null && $selected !== $this->mouseItems[$index]) {
            $this->dispatch(new SelectionChangeEvent($this, $selected));
        }
    }

    /** @param list<int> $rows */
    protected function setMouseRows(array $rows): void
    {
        $this->mouseRows = $rows;
    }

    public function render(RenderContext $context): array
    {
        $index = array_search($this->getSelectedItem()['value'] ?? '', array_column($this->mouseItems, 'value'), true);
        $start = max(0, min(($index === false ? 0 : $index) - (int) floor($this->maxVisible / 2), count($this->mouseItems) - $this->maxVisible));
        $this->setMouseRows(array_keys(array_slice($this->mouseItems, $start, $this->maxVisible, true)));

        return array_map(
            static fn (string $line): string => preg_replace(
                '/^((?:\x1b\[[0-?]*[ -\/]*[@-~])*)(?:→ | {2})/u',
                '$1',
                $line,
                1,
            ) ?? $line,
            parent::render($context),
        );
    }
}
