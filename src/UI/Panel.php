<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;

class Panel extends ContainerWidget
{
    private PanelTitle $heading;

    private ContainerWidget $content;

    public function __construct(string $title, string $color, bool $expanded = false)
    {
        $this->heading = new PanelTitle($title);
        $this->heading->setStyle(new Style(color: $color));

        $this->content = new ContainerWidget;
        $this->content->setStyle(new Style(
            padding: Padding::xy(1, 0),
            border: Border::from([0, 1, 1, 1], 'rounded', $color),
        ));
        $this->content->expandVertically($expanded);

        $this->add($this->heading);
        $this->add($this->content);
    }

    public function content(): ContainerWidget
    {
        return $this->content;
    }

    public function setTitle(string $title): void
    {
        $this->heading->setText($title);
    }
}
