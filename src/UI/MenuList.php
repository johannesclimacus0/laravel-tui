<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\SelectListWidget;

class MenuList extends SelectListWidget
{
    public function render(RenderContext $context): array
    {
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
