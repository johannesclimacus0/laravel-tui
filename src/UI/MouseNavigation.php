<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\ParentInterface;
use Symfony\Component\Tui\Widget\TextWidget;
use WeakMap;

class MouseNavigation
{
    private bool $reporting = false;

    /** @var WeakMap<AbstractWidget, Closure(): void> */
    private WeakMap $clicks;

    public function __construct(private Tui $tui, private Renderer $renderer, private AbstractWidget $root)
    {
        $this->clicks = new WeakMap;
    }

    /** @param callable(): void $callback */
    public function onClick(AbstractWidget $widget, callable $callback): void
    {
        $this->clicks[$widget] = $callback(...);
    }

    public function enable(): void
    {
        if (! $this->tui->getTerminal()->isVirtual()) {
            $this->reporting = true;
            $this->tui->getTerminal()->write("\x1b[?1000h\x1b[?1006h");
        }
    }

    public function disable(): void
    {
        if ($this->reporting) {
            $this->tui->getTerminal()->write("\x1b[?1000l\x1b[?1006l");
            $this->reporting = false;
        }
    }

    public function handle(InputEvent $event): void
    {
        if (! preg_match('/\A\x1b\[<(\d{1,5});(\d{1,5});(\d{1,5})([Mm])\z/', $event->getData(), $match)) {
            return;
        }
        $event->stopPropagation();
        $button = (int) $match[1];
        $col = (int) $match[2] - 1;
        $row = (int) $match[3] - 1;

        if ($match[4] !== 'M' || ! in_array($button, [0, 2, 64, 65], true)
            || $col < 0 || $row < 0 || $col >= $this->tui->getTerminal()->getColumns() || $row >= $this->tui->getTerminal()->getRows()) {
            return;
        }
        $this->tui->processRender();
        $widget = $this->find($this->root, $row, $col);

        if ($button === 2) {
            $rect = $widget === null ? null : $this->renderer->getWidgetRect($widget);

            if ($widget instanceof InputWidget || ($widget instanceof TextWidget && ! $widget instanceof ScrollableText) || ($widget instanceof ScrollableText && $rect !== null
                && $widget->hasTextAt($row - $rect->row, $col - $rect->col))) {
                return;
            }
            $this->tui->handleInput("\x1b");
            $this->tui->requestRender();

            return;
        }

        if ($widget === null) {
            return;
        }

        if ($button === 0 && isset($this->clicks[$widget])) {
            ($this->clicks[$widget])();
            $this->tui->requestRender();

            return;
        }

        if (! $widget instanceof FocusableInterface) {
            return;
        }
        $this->tui->setFocus($widget);

        if ($button === 0) {
            $rect = $this->renderer->getWidgetRect($widget);

            if ($rect !== null && ($widget instanceof MenuList || $widget instanceof ScrollableText)) {
                $widget->mouseClick($row - $rect->row);
            }
        } elseif ($widget instanceof MenuList || $widget instanceof ScrollableText) {
            $lines = max(1, min(10, (int) config('laravel-tui.mouse.wheel_lines', 3)));
            $lines *= $button === 64 ? -1 : 1;

            $widget->mouseScroll($lines);
        }
        $this->tui->requestRender();
    }

    private function find(AbstractWidget $widget, int $row, int $col): ?AbstractWidget
    {
        $rect = $this->renderer->getWidgetRect($widget);

        if ($rect === null || ! $rect->contains($row, $col) || $widget->getStyle()?->getHidden() === true) {
            return null;
        }

        if ($widget instanceof ParentInterface) {
            foreach (array_reverse($widget->all()) as $child) {
                $target = $this->find($child, $row, $col);

                if ($target !== null) {
                    return $target;
                }
            }
        }

        return isset($this->clicks[$widget]) || $widget instanceof MenuList || $widget instanceof ScrollableText || $widget instanceof InputWidget ? $widget : null;
    }
}
