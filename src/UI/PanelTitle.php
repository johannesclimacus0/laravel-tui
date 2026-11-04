<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

class PanelTitle extends TextWidget
{
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();

        if ($columns < 2) {
            return [str_repeat('─', max(0, $columns))];
        }

        $title = StringUtils::stripControlBytes($this->getText());
        $middle = $title === '' ? '' : '─ '.$title.' ';
        $middle = AnsiUtils::truncateToWidth($middle, $columns - 2, '');
        $line = '╭'.$middle.str_repeat('─', max(0, $columns - 2 - AnsiUtils::visibleWidth($middle))).'╮';

        return [$line];
    }
}
