<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

class CommandList extends MenuList
{
    /** @param list<array{value: string, label: string}> $items */
    public function __construct(private array $items)
    {
        parent::__construct($items, max(1, count($items)));
    }

    public function render(RenderContext $context): array
    {
        $lines = parent::render($context);

        if ($this->items === []) {
            return $lines;
        }

        $rows = max(1, (int) floor($context->getRows() * 0.85));
        $count = count($this->items);
        $scrolling = $count > $rows;
        $visible = max(1, $rows - ($scrolling && $rows > 1 ? 1 : 0));
        $selected = array_search($this->getSelectedItem()['value'] ?? '', array_column($this->items, 'value'), true);
        $index = $selected === false ? 0 : $selected;
        $start = max(0, min($index - intdiv($visible, 2), $count - $visible));
        $window = array_slice($lines, $start, $visible);

        if ($scrolling && $rows > 1) {
            $window[] = $this->applyElement('scroll-info', AnsiUtils::truncateToWidth(
                '('.($index + 1).'/'.$count.')',
                $context->getColumns(),
                '',
            ));
        }

        return $window;
    }
}
