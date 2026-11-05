<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use Symfony\Component\Tui\Event\FocusEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\WidgetContext;

class Panel extends ContainerWidget
{
    private PanelTitle $heading;

    private ContainerWidget $content;

    private ?Closure $focusListener = null;

    private ?bool $active = null;

    public function __construct(
        string $title,
        private string $color,
        bool $expanded = false,
        private ?string $inactiveColor = null,
    ) {
        $this->heading = new PanelTitle($title);

        $this->content = new ContainerWidget;
        $this->content->expandVertically($expanded);

        $this->add($this->heading);
        $this->add($this->content);
        $this->setActive(false);
    }

    public function content(): ContainerWidget
    {
        return $this->content;
    }

    public function setTitle(string $title): void
    {
        $this->heading->setText($title);
    }

    private function updateFocus(): void
    {
        $focused = $this->getContext()?->getFocusManager()->getFocus();

        while ($focused !== null && ! $focused instanceof self) {
            $focused = $focused->getParent();
        }

        $this->setActive($focused === $this);
    }

    private function setActive(bool $active): void
    {
        if ($this->active === $active) {
            return;
        }

        $this->active = $active;
        $color = $active ? $this->color : ($this->inactiveColor ?? config('laravel-tui.theme.border', '#39C5BB'));
        $this->heading->setStyle(new Style(color: $color, bold: $active));
        $this->content->setStyle(new Style(
            padding: Padding::xy(1, 0),
            border: Border::from([0, 1, 1, 1], 'rounded', $color),
        ));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->focusListener = fn (FocusEvent $event) => $this->updateFocus();
        $context->getEventDispatcher()->addListener(FocusEvent::class, $this->focusListener);
        $this->updateFocus();
    }

    protected function onDetach(): void
    {
        if ($this->focusListener !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(FocusEvent::class, $this->focusListener);
            $this->focusListener = null;
        }
        $this->setActive(false);
    }
}
