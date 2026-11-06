<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\TextAlign;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

class MikuWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;

    public function expandVertically(bool $expand = true): static
    {
        $this->expanded = $expand;
        $this->invalidate();

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->expanded;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->invalidate();
    }

    public function __construct(
        private Miku $art,
        private string $name,
    ) {
        $this->setStyle(new Style(textAlign: TextAlign::Center));
    }

    public function render(RenderContext $context): array
    {
        $pic = $this->art->colored(
            $this->name,
            $context->getColumns(),
            $context->getRows(),
        );

        return $pic === '' ? [] : explode("\n", $pic);
    }
}
