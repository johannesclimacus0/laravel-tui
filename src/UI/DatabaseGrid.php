<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Color;

class DatabaseGrid extends AdaptiveList
{
    private int $columnOffset = 0;

    private string $layoutKey = '';

    private string $header = '';

    private string $divider = '';

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $records
     */
    public function __construct(private array $columns, private array $records)
    {
        $items = [];
        foreach ($records as $index => $record) {
            $items[] = ['value' => (string) $index, 'label' => implode(' ', $record)];
        }
        parent::__construct($items);
        $this->expandVertically(true);
        $this->setEmptyMessage('No records');
    }

    public function columnOffset(): int
    {
        return $this->columnOffset;
    }

    public function setColumnOffset(int $offset): void
    {
        $this->columnOffset = max(0, min(max(0, count($this->columns) - 1), $offset));
        $this->layoutKey = '';
        $this->invalidate();
    }

    public function handleInput(string $data): void
    {
        $keys = $this->getKeybindings();

        if ($keys->matches($data, 'database_column_next') || $keys->matches($data, 'database_column_previous')) {
            $this->setColumnOffset($this->columnOffset + ($keys->matches($data, 'database_column_next') ? 1 : -1));

            return;
        }
        parent::handleInput($data);
    }

    public function mouseClick(int $row): void
    {
        if ($row >= 2) {
            parent::mouseClick($row - 2);
        }
    }

    public function render(RenderContext $context): array
    {
        $width = max(1, $context->getColumns());
        $key = $width.':'.$this->columnOffset;

        if ($this->layoutKey !== $key) {
            $selected = $this->getSelectedItem()['value'] ?? '0';
            $gutter = $width >= 16 ? strlen((string) max(1, count($this->records))) + 2 : 0;
            $available = max(1, $width - $gutter);
            $widths = [];
            $used = 0;
            for ($index = $this->columnOffset; $index < count($this->columns); $index++) {
                $size = AnsiUtils::visibleWidth($this->columns[$index]);
                foreach ($this->records as $record) {
                    $size = max($size, AnsiUtils::visibleWidth($record[$index] ?? ''));
                }
                $size = min(24, max(6, $size));
                $gap = $widths === [] ? 0 : 3;

                if ($used + $gap + $size > $available && $widths !== []) {
                    break;
                }
                $widths[$index] = min($size, max(1, $available - $used - $gap));
                $used += $gap + $widths[$index];
            }
            $this->header = ($gutter === 0 ? '' : str_pad('#', $gutter)).$this->line($this->columns, $widths, true);
            $this->divider = str_repeat('─', $gutter).implode('─┼─', array_map(static fn (int $size): string => str_repeat('─', $size), $widths));
            $items = [];
            foreach ($this->records as $index => $record) {
                $items[] = ['value' => (string) $index, 'label' => ($gutter === 0 ? '' : $this->tone(str_pad((string) ($index + 1), $gutter - 1, ' ', STR_PAD_LEFT).' ', 'muted')).$this->line($record, $widths)];
            }
            $this->setItems($items);
            $this->setSelectedIndex((int) $selected);
            $this->layoutKey = $key;
        }
        $heading = Color::from(config('laravel-tui.theme.workspace_border', '#E879A9'))->toForegroundCode().$this->header."\x1b[0m";

        if ($context->getRows() < 3) {
            $this->setMouseRows([]);

            return $context->getRows() < 1 ? [] : [$heading];
        }

        return [$heading, $this->tone($this->divider, 'muted'), ...parent::render($context->withRows($context->getRows() - 2))];
    }

    /**
     * @param  list<string>  $values
     * @param  array<int, int>  $widths
     */
    private function line(array $values, array $widths, bool $header = false): string
    {
        $cells = [];
        foreach ($widths as $index => $width) {
            $cell = AnsiUtils::truncateToWidth($values[$index] ?? '', $width);
            $padding = str_repeat(' ', max(0, $width - AnsiUtils::visibleWidth($cell)));

            if (! $header) {
                $value = $values[$index] ?? '';
                $cell = match (true) {
                    $value === 'NULL', str_starts_with($value, '0x') => $this->tone($cell, 'muted'),
                    $value === 'true', $value === 'false' => $this->tone($cell, 'workspace_border'),
                    is_numeric($value) => $this->tone($cell, 'border'),
                    default => $this->tone($cell, 'text'),
                };
            }
            $cells[] = $cell.$padding;
        }

        return implode($header ? ' │ ' : $this->tone(' │ ', 'muted'), $cells);
    }

    private function tone(string $text, string $tone): string
    {
        $fallback = match ($tone) {
            'muted' => '#65727A',
            'border' => '#39C5BB',
            'workspace_border' => '#E879A9',
            default => 'default',
        };

        return Color::from(config('laravel-tui.theme.'.$tone, $fallback))->toForegroundCode().$text."\x1b[0m";
    }
}
