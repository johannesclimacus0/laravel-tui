<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class ArtisanPanel extends ContainerWidget
{
    private ?Process $process = null;

    private ?string $tickId = null;

    private ?Closure $interruptListener = null;

    private InputWidget $arguments;

    private TextWidget $status;

    private ScrollableText $output;

    private string $buffer = '';

    /** @param array<string, Command> $commands */
    public function __construct(
        private array $commands,
        private ArtisanRunner $runner,
    ) {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->showBrowser();
    }

    private function showBrowser(): void
    {
        $this->clear();
        $items = [];
        foreach ($this->commands as $name => $command) {
            $items[] = ['value' => $name, 'label' => $name, 'search' => $command->getDescription()];
        }
        $list = new SearchableList($items);
        $list->onSelect(fn (SelectEvent $event) => $this->showCommand($event->getValue()));
        $this->add($list);
        $list->focus();
    }

    private function showCommand(string $name): void
    {
        $command = $this->commands[$name];
        $this->clear();
        $this->add(new TextWidget('php artisan '.$command->getSynopsis()));

        $this->arguments = new InputWidget;
        $this->arguments->setPrompt('arguments > ');
        $this->arguments->onSubmit(fn () => $this->start($name));
        $this->arguments->onCancel(function (): void {
            $this->cancel();
            $this->showBrowser();
        });
        $this->arguments->onInput(function (string $data): bool {
            $keys = $this->arguments->getKeybindings();

            if ($keys->matches($data, 'output_up') || $keys->matches($data, 'output_down')) {
                $this->output->scrollPages($keys->matches($data, 'output_up') ? -1 : 1);
                $this->getContext()?->requestRender();

                return true;
            }

            return ! $keys->matches($data, 'select_cancel') && ($this->process?->isRunning() ?? false);
        });
        $this->add($this->arguments);

        $this->status = new TextWidget('Enter arguments and press Enter to run. Escape returns to commands.');
        $this->add($this->status);

        $this->output = new ScrollableText;
        $this->output->addStyleClass('command-output');
        $this->output->onInput(function (string $data): bool {
            if (! $this->output->getKeybindings()->matches($data, 'output_back')) {
                return false;
            }

            $this->getContext()?->getFocusManager()->setFocus($this->arguments);

            return true;
        });
        $help = $command->getProcessedHelp();
        $this->output->setText((new OutputFormatter(decorated: true))->format($help) ?? '');
        $this->add($this->output);
        $this->getContext()?->getFocusManager()->setFocus($this->arguments);
    }

    private function start(string $name): void
    {
        if ($this->process?->isRunning()) {
            return;
        }

        $this->stopPolling();

        try {
            $this->process = $this->runner->start($name, $this->arguments->getValue());
            $this->buffer = '';
            $this->output->setText('');
            $this->output->followOutput();
            $this->status->setText('Running. Ctrl+C cancels; arrows/PageUp/PageDown scroll.');
            $this->tickId = $this->getContext()?->scheduleTick($this->poll(...), 0.1);
            $this->getContext()?->getFocusManager()->setFocus($this->output);
            $this->poll();
        } catch (Throwable $error) {
            $this->status->setText('Could not start command.');
            $this->output->setText($this->plain($error->getMessage()));
            $this->getContext()?->getFocusManager()->setFocus($this->output);
        }
    }

    private function poll(): void
    {
        if ($this->process === null) {
            return;
        }

        $running = $this->process->isRunning();
        $chunk = $this->process->getIncrementalOutput().$this->process->getIncrementalErrorOutput();
        $this->process->clearOutput();
        $this->process->clearErrorOutput();

        if ($chunk !== '') {
            $this->buffer = substr($this->buffer.$chunk, -262144);
            $this->output->setText($this->plain($this->buffer));
        }

        if (! $running) {
            $this->stopPolling();
            $this->status->setText('Finished. Exit code: '.($this->process->getExitCode() ?? 'unknown'));
        }
        $this->getContext()?->requestRender();
    }

    private function cancel(): bool
    {
        if (! ($this->process?->isRunning() ?? false)) {
            $this->stopPolling();

            return false;
        }

        $this->process->stop(0.2);
        $this->poll();
        $this->status->setText('Cancelled. Edit arguments and press Enter to run again.');

        return true;
    }

    private function stopPolling(): void
    {
        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(str_replace("\r", '', $text)));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->interruptListener = function (InputEvent $event) use ($context): void {
            if ($this->process !== null && $context->keybindings()->matches($event->getData(), 'interrupt') && $this->cancel()) {
                $event->stopPropagation();
                $context->requestRender();
            }
        };
        $context->getEventDispatcher()->addListener(InputEvent::class, $this->interruptListener, 10);
    }

    protected function onDetach(): void
    {
        $this->cancel();

        if ($this->interruptListener !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(InputEvent::class, $this->interruptListener);
        }
    }
}
