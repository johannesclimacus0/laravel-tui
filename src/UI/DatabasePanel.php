<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use JsonException;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Database\DatabaseSnapshot;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class DatabasePanel extends ContainerWidget
{
    /** @var list<string> */
    private array $connections = [];

    private ?string $connection = null;

    private string $table = '';

    private string $screen = 'connections';

    private ?DatabaseSnapshot $tables = null;

    private ?DatabaseSnapshot $result = null;

    private ?SearchableList $list = null;

    private ?DatabaseGrid $grid = null;

    private TextWidget $summary;

    private StatusLine $status;

    private string $query = '';

    private ?string $selection = null;

    private bool $searchOpen = false;

    private int $selectedRow = 0;

    private int $columnOffset = 0;

    private ?Process $process = null;

    private ?string $tickId = null;

    private string $stdout = '';

    private string $stderr = '';

    private string $requestedView = '';

    private string $requestedTable = '';

    private int $requestedPage = 0;

    public function __construct(private ArtisanRunner $runner)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->summary = new TextWidget;
        $this->status = new StatusLine;
        $configured = config('database.connections', []);
        $allowed = config('laravel-tui.database.connections', []);
        foreach (is_array($configured) ? $configured : [] as $name => $settings) {
            if (is_string($name) && is_array($settings) && is_array($allowed) && ($allowed === [] || in_array($name, $allowed, true))) {
                $this->connections[] = $name;
            }
        }
        $default = config('database.default');
        usort($this->connections, static fn (string $a, string $b): int => [$a !== $default, $a] <=> [$b !== $default, $b]);

        if (count($this->connections) === 1) {
            $this->connection = $this->connections[0];
            $this->showTables();
        } else {
            $this->showConnections();
        }
    }

    private function frame(): void
    {
        $this->clear();
        $this->list = null;
        $this->grid = null;
        $this->summary->setText($this->heading());
        $this->add($this->summary);
        $this->add($this->status);
    }

    private function showConnections(): void
    {
        $this->stopRequest();
        $this->connection = null;
        $this->table = '';
        $this->screen = 'connections';
        $this->status->hide();
        $this->frame();
        $this->list = new SearchableList(array_map(fn (string $name): array => ['value' => $name, 'label' => $this->plain($name)], $this->connections), emptyMessage: 'No database connections configured');
        $this->list->onSelect(function (SelectEvent $event): void {
            $this->connection = $event->getValue();
            $this->tables = null;
            $this->query = '';
            $this->selection = null;
            $this->searchOpen = false;
            $this->showTables();
            $this->request('tables');
        });
        $this->add($this->list);
        $this->list->focus();
    }

    private function saveTableState(): void
    {
        if ($this->screen === 'tables' && $this->list !== null) {
            $this->query = $this->list->query();
            $this->selection = $this->list->selectedValue();
            $this->searchOpen = $this->list->isSearching();
        }
    }

    private function showTables(): void
    {
        $this->stopRequest();
        $this->saveTableState();
        $this->screen = 'tables';
        $this->table = '';
        $this->frame();
        $items = array_map(fn (array $row): array => ['value' => $row[0], 'label' => $this->paint("\u{f0ce} ", 'muted').$this->plain($row[0])], $this->tables->rows ?? []);
        $this->list = new SearchableList($items, emptyMessage: $this->tables === null ? '' : 'No tables found');
        $this->list->setQuery($this->query, $this->selection);
        $this->list->onSelect(function (SelectEvent $event): void {
            $this->saveTableState();
            $this->showMenu($event->getValue());
        });
        $this->list->onCancel(function (): void {
            if ($this->list?->isSearching() || $this->list?->query() !== '') {
                $this->list?->closeSearch();
            } elseif (count($this->connections) > 1) {
                $this->showConnections();
            }
        });
        $this->list->onInput(fn (string $data): bool => $this->input($data));

        if ($this->searchOpen) {
            $this->list->openSearch();
        }
        $this->add($this->list);
        $this->list->focus();
    }

    private function showMenu(string $table): void
    {
        $this->stopRequest();
        $this->table = $table;
        $this->screen = 'menu';
        $this->frame();
        $menu = new MenuList([
            ['value' => 'rows', 'label' => 'Browse records'],
            ['value' => 'structure', 'label' => 'Structure and indexes'],
        ], maxVisible: 2);
        $menu->setStyle(new Style(color: config('laravel-tui.theme.muted', '#65727A')));
        $menu->addStyleClass('module-menu');
        $menu->onSelect(function (SelectEvent $event): void {
            $this->selectedRow = 0;
            $this->columnOffset = 0;
            $this->request($event->getValue(), $this->table);
        });
        $menu->onCancel(fn () => $this->showTables());
        $menu->onInput(fn (string $data): bool => $this->input($data));
        $this->add($menu);
        $this->getContext()?->getFocusManager()->setFocus($menu);
    }

    private function showData(): void
    {
        if ($this->result === null) {
            return;
        }
        $this->screen = $this->result->kind;
        $this->frame();
        $meta = $this->screen === 'rows'
            ? 'Page '.($this->result->page + 1).'  /  '.count($this->result->rows).' rows  /  '.count($this->result->columns).' columns'
            : count($this->result->rows).' columns  /  '.count($this->result->indexes).' indexes';
        $this->summary->setText($this->heading()."\n".$this->paint($meta, 'muted'));
        $this->grid = new DatabaseGrid($this->result->columns, $this->result->rows);
        $this->grid->setColumnOffset($this->columnOffset);
        $this->grid->onInput(fn (string $data): bool => $this->input($data));
        $this->grid->onCancel(fn () => $this->showMenu($this->table));
        $this->grid->onSelect(function (SelectEvent $event): void {
            $this->selectedRow = (int) $event->getValue();
            $this->columnOffset = $this->grid?->columnOffset() ?? 0;
            $this->showRecord();
        });
        $this->add($this->grid);

        if ($this->screen === 'structure' && $this->result->indexes !== []) {
            $indexes = new MenuList([['value' => 'indexes', 'label' => 'View indexes']], maxVisible: 1);
            $indexes->onSelect(fn () => $this->showIndexes());
            $indexes->onCancel(fn () => $this->showMenu($this->table));
            $indexes->onInput(fn (string $data): bool => $this->input($data));
            $this->add($indexes);
        }

        if ($this->screen === 'rows') {
            $buttons = [];

            if ($this->result->page > 0) {
                $buttons[] = ['value' => 'previous', 'label' => 'Previous page'];
            }

            if ($this->result->hasNext) {
                $buttons[] = ['value' => 'next', 'label' => 'Next page'];
            }

            if ($buttons !== []) {
                $pager = new MenuList($buttons, maxVisible: 2);
                $pager->onSelect(fn (SelectEvent $event) => $this->page($event->getValue() === 'next' ? 1 : -1));
                $pager->onCancel(fn () => $this->showMenu($this->table));
                $pager->onInput(fn (string $data): bool => $this->input($data));
                $this->add($pager);
            }
        }
        $this->grid->setSelectedIndex($this->selectedRow);
        $this->getContext()?->getFocusManager()->setFocus($this->grid);
    }

    private function showIndexes(): void
    {
        $this->columnOffset = $this->grid?->columnOffset() ?? $this->columnOffset;
        $this->selectedRow = (int) ($this->grid?->getSelectedItem()['value'] ?? '0');
        $this->screen = 'indexes';
        $this->frame();
        $detail = new ScrollableText(implode("\n\n", $this->result->indexes ?? []));
        $detail->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showData();

                return true;
            }

            return $this->input($data);
        });
        $this->add($detail);
        $this->getContext()?->getFocusManager()->setFocus($detail);
    }

    private function showRecord(): void
    {
        $record = $this->result->rows[$this->selectedRow] ?? null;

        if ($record === null) {
            return;
        }
        $this->screen = 'record';
        $this->frame();
        $lines = [];
        foreach ($this->result->columns as $index => $name) {
            $lines[] = $this->paint($name, 'workspace_border')."\n".$this->plain($record[$index] ?? '')."\n";
        }
        $detail = new ScrollableText(implode("\n", $lines));
        $detail->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showData();

                return true;
            }

            return $this->input($data);
        });
        $this->add($detail);
        $this->getContext()?->getFocusManager()->setFocus($detail);
    }

    private function page(int $direction): void
    {
        if ($this->result?->kind !== 'rows' || ($direction > 0 && ! $this->result->hasNext) || ($direction < 0 && $this->result->page === 0)) {
            return;
        }
        $this->selectedRow = 0;
        $this->columnOffset = $this->grid?->columnOffset() ?? $this->columnOffset;
        $this->request('rows', $this->table, $this->result->page + $direction);
    }

    private function input(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $key = strtr($keys?->getParser()->decodePrintable($data) ?? $data, config('laravel-tui.key_aliases', []));

        if ($keys?->matches($key, 'database_refresh')) {
            if ($this->grid !== null) {
                $this->selectedRow = (int) ($this->grid->getSelectedItem()['value'] ?? '0');
            }
            $kind = in_array($this->screen, ['rows', 'structure', 'record', 'indexes'], true) ? ($this->result->kind ?? 'tables') : 'tables';
            $this->columnOffset = $this->grid?->columnOffset() ?? $this->columnOffset;
            $this->request($kind, $kind === 'tables' ? '' : $this->table, $kind === 'tables' ? 0 : ($this->result->page ?? 0));
        } elseif ($this->screen === 'rows' && ($keys?->matches($key, 'database_next') || $keys?->matches($key, 'database_previous'))) {
            $this->page($keys->matches($key, 'database_next') ? 1 : -1);
        } else {
            return false;
        }

        return true;
    }

    private function request(string $view, string $table = '', int $page = 0): void
    {
        if ($this->process !== null || $this->connection === null) {
            return;
        }

        try {
            $this->requestedView = $view;
            $this->requestedTable = $table;
            $this->requestedPage = $page;
            $this->stdout = '';
            $this->stderr = '';
            $this->process = $this->runner->start('ui:database', ['--view='.$view, '--table='.$table, '--page='.$page, '--', $this->connection]);
            $this->process->setTimeout(max(1, min(60, (float) config('laravel-tui.database.timeout', 8))));
            $this->status->setStatus('Loading', 'Reading database', 'info');
        } catch (Throwable $error) {
            $this->fail($error->getMessage());
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
                throw new RuntimeException('Database response exceeded the size limit');
            }

            if ($running) {
                return;
            }
            $code = $this->process->getExitCode();
            $this->stdout .= $this->process->getIncrementalOutput();
            $this->stderr .= $this->process->getIncrementalErrorOutput();

            if (strlen($this->stdout) > 2097152 || strlen($this->stderr) > 65536) {
                throw new RuntimeException('Database response exceeded the size limit');
            }
            $this->process = null;
            $reply = json_decode($this->stdout, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($reply) || ($reply['version'] ?? null) !== 1 || ($reply['ok'] ?? null) !== true || $code !== 0) {
                throw new RuntimeException(is_array($reply) && is_string($reply['error'] ?? null) ? $reply['error'] : 'Invalid database response');
            }

            if (! is_array($reply['data'] ?? null)) {
                throw new RuntimeException('Invalid database snapshot');
            }
            $result = DatabaseSnapshot::fromArray($reply['data']);

            if ($result->kind !== $this->requestedView || $result->connection !== $this->connection
                || $result->table !== $this->requestedTable || $result->page !== $this->requestedPage) {
                throw new RuntimeException('Database response does not match the request');
            }
            $focused = $this->getContext()?->getFocusManager()->getFocus();
            $parent = $focused;
            while ($parent !== null && $parent !== $this) {
                $parent = $parent->getParent();
            }

            if ($result->kind === 'tables') {
                $this->tables = $result;

                if ($this->screen === 'tables' && $this->list?->isSearching()) {
                    $this->list->setItems(array_map(fn (array $row): array => ['value' => $row[0], 'label' => $this->paint("\u{f0ce} ", 'muted').$this->plain($row[0])], $result->rows));
                } else {
                    $this->showTables();
                }
            } else {
                $this->result = $result;
                $this->showData();
            }

            if ($parent !== $this && $focused !== null) {
                $this->getContext()?->getFocusManager()->setFocus($focused);
            }

            if ($result->note !== '') {
                $this->status->setStatus('Info', $result->note);
            } else {
                $this->status->hide();
            }
        } catch (JsonException) {
            $this->fail('Database command returned invalid JSON');
        } catch (ProcessTimedOutException) {
            $this->fail('Reading database timed out');
        } catch (Throwable $error) {
            $this->fail($error->getMessage());
        }
    }

    private function fail(string $message): void
    {
        $this->stopRequest();
        $this->status->setStatus('Error', $this->plain($message), 'error');
    }

    private function stopRequest(): void
    {
        if ($this->process !== null) {
            $this->status->hide();
        }
        $this->process?->stop(0);
        $this->process = null;
        $this->stdout = '';
        $this->stderr = '';
    }

    private function heading(): string
    {
        if ($this->connection === null) {
            return $this->paint('Connections', 'workspace_border');
        }

        return $this->paint($this->connection, 'border')
            .($this->table === '' ? '' : $this->paint(' / ', 'muted').$this->paint($this->table, 'workspace_border'));
    }

    private function paint(string $text, string $tone): string
    {
        $fallback = match ($tone) {
            'muted' => '#65727A',
            'workspace_border' => '#E879A9',
            default => '#39C5BB',
        };

        return Color::from(config('laravel-tui.theme.'.$tone, $fallback))->toForegroundCode().$this->plain($text)."\x1b[0m";
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(str_replace(["\r", "\n", "\t"], ' ', $text))));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->list?->focus();
        $this->tickId = $context->scheduleTick($this->poll(...), 0.1);

        if ($this->connection !== null) {
            $this->request('tables');
        }
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
