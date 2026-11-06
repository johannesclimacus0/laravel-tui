<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

class StatusLine extends TextWidget
{
    public function __construct()
    {
        parent::__construct('', truncate: true);
        $this->setStyle(new Style(hidden: true));
    }

    public function setStatus(string $label, string $details = '', string $tone = 'muted'): void
    {
        $color = match ($tone) {
            'info' => config('laravel-tui.theme.focus', '#6DE2D5'),
            'success' => config('laravel-tui.theme.border', '#39C5BB'),
            'warning' => config('laravel-tui.theme.warning', '#E5C07B'),
            'error' => config('laravel-tui.theme.error', '#E879A9'),
            default => config('laravel-tui.theme.muted', '#65727A'),
        };
        $text = '['.$label.']'.($details === '' ? '' : ' '.$details);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        $text = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($text));
        $style = new Style(color: $color, hidden: false);

        if ($this->getText() === $text && $this->getStyle() == $style) {
            return;
        }
        $this->setText($text);
        $this->setStyle($style);
    }

    public function hide(): void
    {
        if ($this->getStyle()?->getHidden() === true) {
            return;
        }
        $this->setStyle(new Style(hidden: true));
    }
}
