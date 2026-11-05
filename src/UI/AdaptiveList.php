<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

class AdaptiveList extends MenuList implements VerticallyExpandableInterface
{
    private bool $expanded = false;

    private int $visibleRows = 1;

    private ?Closure $inputCallback = null;

    /** @param list<array{value: string, label: string}> $items */
    public function __construct(private array $items)
    {
        parent::__construct($items, max(1, count($items)));
    }

    public function render(RenderContext $context): array
    {
        if ($this->items === []) {
            return parent::render($context);
        }

        $rows = max(1, (int) floor($context->getRows() * ($this->expanded ? 1 : 0.85)));
        $count = count($this->items);
        $scrolling = $count > $rows;
        $visible = max(1, $rows - ($scrolling && $rows > 1 ? 1 : 0));
        $this->visibleRows = $visible;
        $selected = array_search($this->getSelectedItem()['value'] ?? '', array_column($this->items, 'value'), true);
        $index = $selected === false ? 0 : $selected;
        $start = max(0, min($index - intdiv($visible, 2), $count - $visible));
        $window = [];

        foreach (array_slice($this->items, $start, $visible) as $offset => $item) {
            $line = AnsiUtils::truncateToWidth($item['label'], $context->getColumns(), '');
            $window[] = $start + $offset === $index ? $this->applyElement('selected', AnsiUtils::stripAnsiCodes($line)) : $line;
        }

        if ($scrolling && $rows > 1) {
            $window[] = $this->applyElement('scroll-info', AnsiUtils::truncateToWidth(
                '('.($index + 1).'/'.$count.')',
                $context->getColumns(),
                '',
            ));
        }

        return $window;
    }

    public function handleInput(string $data): void
    {
        $keys = $this->getKeybindings();
        $up = $keys->matches($data, 'select_page_up') || $keys->matches($data, 'cursor_left');
        $down = $keys->matches($data, 'select_page_down') || $keys->matches($data, 'cursor_right');

        if (! $up && ! $down) {
            parent::handleInput($data);

            return;
        }

        if ($this->inputCallback !== null && ($this->inputCallback)($data)) {
            return;
        }

        $index = array_search($this->getSelectedItem()['value'] ?? '', array_column($this->items, 'value'), true);
        $this->setSelectedIndex(($index === false ? 0 : $index) + ($up ? -1 : 1) * $this->visibleRows);
        $selected = $this->getSelectedItem();

        if ($selected !== null) {
            $this->dispatch(new SelectionChangeEvent($this, $selected));
        }
    }

    /** @param (callable(string): bool)|null $callback */
    public function onInput(?callable $callback): static
    {
        $this->inputCallback = $callback === null ? null : $callback(...);

        return parent::onInput($callback);
    }

    /** @param list<array{value: string, label: string}> $items */
    public function setItems(array $items): static
    {
        $this->items = $items;

        return parent::setItems($this->items);
    }

    public function expandVertically(bool $expand): static
    {
        $this->expanded = $expand;
        $this->invalidate();

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }
}
