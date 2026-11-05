<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\TextDescriptor;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
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

    /** @var array<string, list<string>> */
    private array $history = [];

    private int $historyIndex = 0;

    private string $draft = '';

    private string $browserQuery = '';

    private ?string $browserSelection = null;

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
        $list = new SearchableList($items, footerPrompt: $this->commandPrompt());
        $list->setQuery($this->browserQuery, $this->browserSelection);
        $list->onSelect(function (SelectEvent $event) use ($list): void {
            $this->browserQuery = $list->query();
            $this->browserSelection = $event->getValue();
            $this->showCommand($event->getValue());
        });
        $this->add($list);
        $list->focus();
    }

    private function showCommand(string $name): void
    {
        $command = $this->commands[$name];
        $this->clear();
        $this->historyIndex = count($this->history[$name] ?? []);
        $this->draft = '';
        $this->arguments = new InputWidget;
        $this->arguments->setPrompt($this->commandPrompt($name));
        $this->arguments->onSubmit(fn () => $this->start($name));
        $this->arguments->onCancel(function (): void {
            $this->cancel();
            $this->showBrowser();
        });
        $this->arguments->onInput(function (string $data) use ($name): bool {
            $keys = $this->arguments->getKeybindings();

            if ($keys->matches($data, 'output_up') || $keys->matches($data, 'output_down')) {
                $this->output->scrollPages($keys->matches($data, 'output_up') ? -1 : 1);
                $this->getContext()?->requestRender();

                return true;
            }

            if (
                ! ($this->process?->isRunning() ?? false)
                && ($keys->matches($data, 'history_previous') || $keys->matches($data, 'history_next'))
            ) {
                $this->restoreHistory($name, $keys->matches($data, 'history_previous') ? -1 : 1);

                return true;
            }

            return ! $keys->matches($data, 'select_cancel') && ($this->process?->isRunning() ?? false);
        });
        $this->status = new TextWidget;

        $this->output = new ScrollableText;
        $this->output->addStyleClass('command-output');
        $this->output->onInput(function (string $data): bool {
            if (! $this->output->getKeybindings()->matches($data, 'output_back')) {
                return false;
            }

            $this->getContext()?->getFocusManager()->setFocus($this->arguments);

            return true;
        });
        $this->output->setText($this->commandHelp($command));
        $this->add($this->output);
        $this->add($this->status);

        $entry = new Panel('', '#39C5BB');
        $entry->content()->add($this->arguments);
        $this->add($entry);
        $this->getContext()?->getFocusManager()->setFocus($this->arguments);
    }

    private function commandPrompt(string $name = ''): string
    {
        $prefix = (new Style(color: '#65727A'))->apply('php artisan');

        return '> '.$prefix.($name === '' ? ' ' : ' '.$name.' ');
    }

    private function commandHelp(Command $command): string
    {
        $command->mergeApplicationDefinition(false);
        $definition = new InputDefinition;

        foreach ($command->getDefinition()->getArguments() as $argument) {
            $mode = $argument->isRequired() ? InputArgument::REQUIRED : InputArgument::OPTIONAL;
            $definition->addArgument(new InputArgument(
                $argument->getName(),
                $mode | ($argument->isArray() ? InputArgument::IS_ARRAY : 0),
                ($argument->isRequired() ? '[required] ' : '[optional] ').$argument->getDescription(),
                $argument->isRequired() ? null : $argument->getDefault(),
            ));
        }
        $definition->addOptions($command->getDefinition()->getOptions());

        $output = new BufferedOutput(decorated: true);
        $output->writeln('Usage: php artisan '.OutputFormatter::escape($command->getSynopsis()));
        $output->writeln('');
        (new TextDescriptor)->describe($output, $definition);

        $help = $command->getProcessedHelp();

        if ($help !== '') {
            $output->writeln("\n\n".$help);
        }

        return $output->fetch();
    }

    private function start(string $name): void
    {
        if ($this->process?->isRunning()) {
            return;
        }

        $this->stopPolling();

        try {
            $this->process = $this->runner->start($name, $this->arguments->getValue());
            $history = $this->history[$name] ?? [];
            $arguments = $this->arguments->getValue();

            if ($history === [] || $history[array_key_last($history)] !== $arguments) {
                $history[] = $arguments;
            }
            $this->history[$name] = array_slice($history, -50);
            $this->historyIndex = count($this->history[$name]);
            $this->buffer = '';
            $this->output->setText('');
            $this->output->followOutput();
            $this->status->setText('Running…');
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

    private function restoreHistory(string $name, int $direction): void
    {
        $history = $this->history[$name] ?? [];

        if ($this->historyIndex === count($history)) {
            $this->draft = $this->arguments->getValue();
        }
        $this->historyIndex = max(0, min(count($history), $this->historyIndex + $direction));
        $this->arguments->setValue($history[$this->historyIndex] ?? $this->draft);
    }

    private function cancel(): bool
    {
        if (! ($this->process?->isRunning() ?? false)) {
            $this->stopPolling();

            return false;
        }

        $this->process->stop(0.2);
        $this->poll();
        $this->status->setText('Cancelled.');

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
