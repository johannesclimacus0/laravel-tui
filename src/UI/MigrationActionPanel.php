<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class MigrationActionPanel extends ContainerWidget
{
    private InputWidget $confirmation;

    private ScrollableText $output;

    private StatusLine $status;

    private ?Process $process = null;

    private ?string $tickId = null;

    private string $buffer = '';

    private bool $started = false;

    private bool $confirmActions;

    private bool $cancelArmed = false;

    private ?Closure $inputGuard = null;

    /** @param Closure(): void $back */
    public function __construct(
        private ArtisanRunner $runner,
        private string $action,
        private string $connection,
        private Closure $back,
    ) {
        $this->confirmActions = (bool) config('laravel-tui.migrations.confirm_actions', true);
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $command = $this->command();
        $flags = match ($action) {
            'fresh' => ' --seed',
            'rollback' => ' --step=1',
            default => '',
        };
        $this->add(new TextWidget('php artisan '.$command.$flags));
        $this->add(new TextWidget('Connection: '.$connection));
        $warning = new TextWidget(match ($action) {
            'fresh' => 'Deletes ALL tables on this connection, then migrates and seeds. Data will be lost.',
            'reset' => 'Rolls back ALL recorded migrations using their down() methods. Data may be lost.',
            'rollback' => 'Rolls back ONE most recently applied migration using down(). Data may be lost.',
            'seed' => 'Runs DatabaseSeeder. Seeders may insert, update or delete application data.',
            default => 'Runs pending migrations. Migration code may modify or delete application data.',
        });
        $warning->setStyle(new Style(color: config('laravel-tui.theme.'.(in_array($action, ['fresh', 'reset'], true) ? 'error' : 'warning'))));
        $this->add($warning);
        $this->status = new StatusLine;
        $this->output = new ScrollableText;
        $this->output->addStyleClass('command-output');
        $this->output->onInput(function (string $data): bool {
            if ($this->output->getKeybindings()->matches($data, 'output_back')) {
                if ($this->process === null) {
                    ($this->back)();
                }

                return true;
            }

            return false;
        });
        $this->confirmation = new InputWidget;
        $this->confirmation->setPrompt('Type "'.$this->phrase().'" > ');
        $this->confirmation->onSubmit(fn () => $this->execute());
        $this->confirmation->onCancel(function (): void {
            if ($this->process === null) {
                ($this->back)();
            }
        });
        $entry = new Panel('Confirm', config('laravel-tui.theme.'.(in_array($action, ['fresh', 'reset'], true) ? 'error' : 'focus')));
        $entry->content()->add($this->confirmation);
        $this->add($this->status);
        $this->add($this->output);

        if ($this->confirmActions) {
            $this->add($entry);
        }
    }

    private function phrase(): string
    {
        return $this->action.' '.$this->connection;
    }

    private function command(): string
    {
        return match ($this->action) {
            'fresh' => 'migrate:fresh',
            'seed' => 'db:seed',
            'reset' => 'migrate:reset',
            'rollback' => 'migrate:rollback',
            default => 'migrate',
        };
    }

    private function execute(): void
    {
        if ($this->started || $this->process !== null || ! config('laravel-tui.migrations.allow_actions', true)) {
            return;
        }

        if ($this->confirmActions && $this->confirmation->getValue() !== $this->phrase()) {
            $this->status->setStatus('Confirm', 'Enter the exact phrase shown below', 'warning');

            return;
        }

        try {
            $arguments = ['--database='.$this->connection];

            if ($this->action === 'fresh') {
                $arguments[] = '--seed';
            } elseif ($this->action === 'rollback') {
                $arguments[] = '--step=1';
            }
            $this->process = $this->runner->start($this->command(), $arguments);
            $this->started = true;
            $this->output->followOutput();
            $this->status->setStatus('Running', $this->command(), 'info');
            $this->getContext()?->getFocusManager()->setFocus($this->output);
            $this->tickId = $this->getContext()?->scheduleTick($this->poll(...), 0.1);
        } catch (Throwable $error) {
            $this->status->setStatus('Error', $error->getMessage(), 'error');
        }
    }

    private function poll(): void
    {
        if ($this->process === null) {
            return;
        }
        $running = $this->process->isRunning();
        $this->buffer = substr($this->buffer.$this->process->getIncrementalOutput().$this->process->getIncrementalErrorOutput(), -262144);
        $this->process->clearOutput();
        $this->process->clearErrorOutput();
        $this->output->setText(StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($this->buffer))));

        if (! $running) {
            $code = $this->process->getExitCode();
            $this->process = null;
            $this->cancelTick();
            $this->status->setStatus($code === 0 ? 'Done' : 'Failed', 'Exit code: '.($code ?? 'unknown'), $code === 0 ? 'success' : 'error');
        }
    }

    private function cancelTick(): void
    {
        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }
    }

    protected function onAttach(WidgetContext $context): void
    {
        $context->getFocusManager()->setFocus($this->confirmActions ? $this->confirmation : $this->output);
        $this->inputGuard = function (InputEvent $event) use ($context): void {
            if ($this->process === null) {
                return;
            }
            $keys = $context->keybindings();
            $data = $event->getData();

            if ($context->getFocusManager()->getFocus() === $this->output
                && ($keys->matches($data, 'output_up') || $keys->matches($data, 'output_down')
                    || $keys->matches($data, 'output_line_up') || $keys->matches($data, 'output_line_down'))) {
                return;
            }
            $event->stopPropagation();

            if ($keys->getParser()->matches($data, 'ctrl+c')) {
                if ($this->cancelArmed) {
                    $this->process->stop(1);
                    $this->process = null;
                    $this->cancelTick();
                    $this->status->setStatus('Stopped', 'Outcome unknown; refresh before retrying', 'warning');
                } else {
                    $this->cancelArmed = true;
                    $this->status->setStatus('Cancel?', 'Ctrl+C again to stop; database changes may already be applied', 'warning');
                }
            } elseif ($keys->getParser()->matches($data, 'escape')) {
                $this->cancelArmed = false;
                $this->status->setStatus('Running', $this->command(), 'info');
            }
            $context->requestRender();
        };
        $context->getEventDispatcher()->addListener(InputEvent::class, $this->inputGuard, 20);

        if (! $this->confirmActions) {
            $this->execute();
        }
    }

    protected function onDetach(): void
    {
        $this->process?->stop(0);
        $this->process = null;
        $this->cancelTick();

        if ($this->inputGuard !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(InputEvent::class, $this->inputGuard);
            $this->inputGuard = null;
        }
    }
}
