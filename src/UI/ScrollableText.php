<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

class ScrollableText extends TextWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private bool $expanded = true;

    private bool $following = false;

    private int $offset = 0;

    private int $selected = 0;

    private int $lastLine = 0;

    private int $rows = 1;

    private int $maxOffset = 0;

    public function __construct(string $text = '', bool $truncate = false, private bool $highlightCurrentLine = true)
    {
        parent::__construct($text, $truncate);
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

    public function followOutput(): void
    {
        $this->following = true;
        $this->invalidate();
    }

    public function isFollowingOutput(): bool
    {
        return $this->following;
    }

    public function scrollToStart(): void
    {
        $this->following = false;
        $this->offset = 0;
        $this->selected = 0;
        $this->invalidate();
    }

    public function scrollPages(int $pages): void
    {
        $this->scrollLines($pages * $this->rows);
    }

    public function scrollLines(int $lines): void
    {
        $this->selected = max(0, min($this->lastLine, $this->selected + $lines));
        $this->following = false;
        $this->invalidate();
    }

    public function handleInput(string $data): void
    {
        if ($this->onInput !== null && ($this->onInput)($data)) {
            return;
        }

        $keys = $this->getKeybindings();

        if ($keys->matches($data, 'output_line_up')) {
            $this->scrollLines(-1);
        } elseif ($keys->matches($data, 'output_line_down')) {
            $this->scrollLines(1);
        } elseif ($keys->matches($data, 'output_up')) {
            $this->scrollPages(-1);
        } elseif ($keys->matches($data, 'output_down')) {
            $this->scrollPages(1);
        }
    }

    public function render(RenderContext $context): array
    {
        $lines = parent::render($context);
        $this->rows = max(1, $context->getRows());
        $this->lastLine = max(0, count($lines) - 1);
        $this->maxOffset = max(0, count($lines) - $this->rows);
        $this->selected = $this->following ? $this->lastLine : min($this->selected, $this->lastLine);
        $this->offset = min($this->offset, $this->maxOffset);

        if ($this->selected < $this->offset) {
            $this->offset = $this->selected;
        } elseif ($this->selected >= $this->offset + $this->rows) {
            $this->offset = $this->selected - $this->rows + 1;
        }

        $window = array_slice($lines, $this->offset, $this->rows);
        $index = $this->selected - $this->offset;

        if ($this->highlightCurrentLine && $this->isFocused() && isset($window[$index])) {
            $window[$index] = $this->applyElement('selected', AnsiUtils::stripAnsiCodes($window[$index]));
        }

        return $window;
    }
}
