<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use JsonException;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Migrations\MigrationSnapshot;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class MigrationsPanel extends ContainerWidget
{
    private ?MigrationSnapshot $snapshot = null;

    private SearchableList $list;

    private TextWidget $summary;

    private StatusLine $status;

    private ?ScrollableText $detail = null;

    private bool $actionView = false;

    private ?string $opened = null;

    private ?Process $process = null;

    private ?string $tickId = null;

    private string $stdout = '';

    private string $stderr = '';

    public function __construct(private ArtisanRunner $runner)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->summary = new TextWidget;
        $this->status = new StatusLine;
        $this->list = new SearchableList([]);
        $this->showList();
    }

    private function refresh(): void
    {
        if ($this->process !== null || $this->actionView) {
            return;
        }

        try {
            $this->stdout = '';
            $this->stderr = '';
            $this->process = $this->runner->start('ui:migrations');
            $this->process->setTimeout(max(1, min(60, (float) config('laravel-tui.migrations.timeout', 8))));
            $this->status->setStatus('Loading', 'Reading migrations', 'info');
        } catch (Throwable $error) {
            $this->stopRequest();
            $this->status->setStatus('Error', $error->getMessage(), 'error');
        }
    }

    private function poll(): void
    {
        if ($this->process === null) {
            return;
        }

        try {
            $this->process->checkTimeout();
            $running = $this->process->isRunning();
            $this->stdout .= $this->process->getIncrementalOutput();
            $this->stderr .= $this->process->getIncrementalErrorOutput();

            if (strlen($this->stdout) > 2097152 || strlen($this->stderr) > 65536) {
                throw new RuntimeException('Migration response exceeded the size limit');
            }

            if ($running) {
                return;
            }
            $exitCode = $this->process->getExitCode();
            $this->stdout .= $this->process->getIncrementalOutput();
            $this->stderr .= $this->process->getIncrementalErrorOutput();

            if (strlen($this->stdout) > 2097152 || strlen($this->stderr) > 65536) {
                throw new RuntimeException('Migration response exceeded the size limit');
            }
            $this->process = null;
            $response = json_decode($this->stdout, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($response) || ($response['version'] ?? null) !== 1 || ! is_bool($response['ok'] ?? null)) {
                throw new RuntimeException('Invalid migration response');
            }

            if ($exitCode !== 0 || ! $response['ok']) {
                throw new RuntimeException(is_string($response['error'] ?? null) ? $response['error'] : 'Could not read migrations');
            }

            if (! is_array($response['data'] ?? null)) {
                throw new RuntimeException('Invalid migration snapshot');
            }
            $this->snapshot = MigrationSnapshot::fromArray($response['data']);
            $this->updateSummary();
            $focused = $this->getContext()?->getFocusManager()->getFocus();
            $parent = $focused;
            while ($parent !== null && $parent !== $this) {
                $parent = $parent->getParent();
            }

            if ($this->opened !== null) {
                $this->showDetail($this->opened);
            } elseif (! $this->list->isSearching()) {
                $this->showList();
            } else {
                $this->list->setItems($this->items());
            }

            if ($parent !== $this && $focused !== null) {
                $this->getContext()?->getFocusManager()->setFocus($focused);
            }

            if ($this->snapshot->installed) {
                $this->status->hide();
            } else {
                $this->status->setStatus('Setup', 'Migration table not found', 'warning');
            }
        } catch (JsonException) {
            $this->fail('Migration command returned invalid JSON');
        } catch (ProcessTimedOutException) {
            $this->fail('Reading migrations timed out');
        } catch (Throwable $error) {
            $this->fail($error->getMessage());
        }
    }

    private function fail(string $message): void
    {
        $this->stopRequest();
        $this->status->setStatus('Error', $this->plain($message), 'error');
    }

    private function updateSummary(): void
    {
        if ($this->snapshot === null) {
            return;
        }
        $ran = count(array_filter($this->snapshot->records, static fn (array $record): bool => $record['batch'] !== null));
        $pending = count($this->snapshot->records) - $ran;
        $this->summary->setText('Connection: '.$this->plain($this->snapshot->connection).' | Ran: '.$ran.' | Pending: '.$pending);
    }

    /** @return list<array{value: string, label: string, search: string}> */
    private function items(): array
    {
        return array_map(fn (array $record): array => [
            'value' => hash('sha256', $record['name']),
            'label' => ($record['batch'] === null ? '[Pending] ' : '[Ran #'.$record['batch'].'] ').$this->plain($record['name']),
            'search' => $this->plain($record['file'] ?? 'Missing file'),
        ], $this->snapshot->records ?? []);
    }

    private function showList(): void
    {
        $query = $this->list->query();
        $selected = $this->list->selectedValue();
        $searching = $this->list->isSearching();
        $this->clear();
        $this->opened = null;
        $this->actionView = false;
        $this->detail = null;
        $this->list = new SearchableList($this->items(), emptyMessage: $this->snapshot === null ? '' : 'No migrations found');
        $this->list->setQuery($query, $selected);
        $this->list->onSelect(fn (SelectEvent $event) => $this->showDetail($event->getValue()));
        $this->list->onInput(fn (string $data): bool => $this->refreshInput($data));

        if ($searching) {
            $this->list->openSearch();
        }
        $this->add($this->summary);
        $this->add($this->status);
        $this->add($this->list);

        if ($this->snapshot !== null && config('laravel-tui.migrations.allow_actions', true)) {
            $actions = new MenuList([
                ['value' => 'migrate', 'label' => 'Migrate pending'],
                ['value' => 'seed', 'label' => 'Seed database'],
                ['value' => 'rollback', 'label' => 'Rollback last migration'],
                ['value' => 'reset', 'label' => 'Rollback all migrations'],
                ['value' => 'fresh', 'label' => 'Fresh + seed  [deletes all tables]'],
            ], maxVisible: 5);
            $actions->onSelect(fn (SelectEvent $event) => $this->showAction($event->getValue()));
            $buttons = new Panel('Actions', config('laravel-tui.theme.focus', '#6DE2D5'));
            $buttons->content()->add($actions);
            $this->add($buttons);
        }
        $this->list->focus();
    }

    private function showAction(string $action): void
    {
        if ($this->process !== null || $this->snapshot === null || ! in_array($action, ['migrate', 'seed', 'fresh', 'reset', 'rollback'], true)
            || ! config('laravel-tui.migrations.allow_actions', true)) {
            return;
        }
        $this->actionView = true;
        $this->clear();
        $this->add(new MigrationActionPanel($this->runner, $action, $this->snapshot->connection, function (): void {
            $this->showList();
            $this->refresh();
        }));
    }

    private function showDetail(string $id): void
    {
        $record = null;
        foreach ($this->snapshot->records ?? [] as $candidate) {
            if (hash('sha256', $candidate['name']) === $id) {
                $record = $candidate;

                break;
            }
        }

        if ($record === null) {
            $this->showList();

            return;
        }
        $this->clear();
        $this->opened = $id;
        $this->detail = new ScrollableText(implode("\n", array_map($this->plain(...), [
            $record['name'],
            '',
            'Status: '.($record['batch'] === null ? 'Pending' : 'Ran'),
            'Batch: '.($record['batch'] === null ? 'None' : (string) $record['batch']),
            'Connection: '.($this->snapshot->connection ?? ''),
            'File: '.($record['file'] ?? 'Not found in registered migration paths'),
        ])));
        $this->detail->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showList();

                return true;
            }

            return $this->refreshInput($data);
        });
        $this->add($this->summary);
        $this->add($this->status);
        $this->add($this->detail);
        $this->getContext()?->getFocusManager()->setFocus($this->detail);
    }

    private function refreshInput(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $printable = $keys?->getParser()->decodePrintable($data) ?? $data;

        if (! ($keys?->matches(strtr($printable, config('laravel-tui.key_aliases', [])), 'migrations_refresh') ?? false)) {
            return false;
        }
        $this->refresh();

        return true;
    }

    private function plain(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);

        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }

    private function stopRequest(): void
    {
        $this->process?->stop(0);
        $this->process = null;
        $this->stdout = '';
        $this->stderr = '';
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->list->focus();
        $this->tickId = $context->scheduleTick($this->poll(...), 0.1);
        $this->refresh();
    }

    protected function onDetach(): void
    {
        $this->stopRequest();

        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }
    }
}
