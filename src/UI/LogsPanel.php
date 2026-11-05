<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Illuminate\Filesystem\Filesystem;
use LaravelTui\LaravelTui\Logs\LogBuffer;
use LaravelTui\LaravelTui\Logs\LogRecord;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class LogsPanel extends ContainerWidget
{
    private string $query = '';

    private ?string $selected = null;

    private bool $fileSearchOpen = false;

    private ?string $currentFile = null;

    private ?SearchableList $list = null;

    private ?SearchableList $records = null;

    private ?ScrollableText $detail = null;

    private ?LogRecord $opened = null;

    private StatusLine $status;

    private LogBuffer $buffer;

    private string $recordQuery = '';

    private ?string $recordSelection = null;

    private bool $recordSearchOpen = false;

    private bool $paused = false;

    private bool $following = true;

    private string $text = '';

    private string $fileSignature = '';

    private string $identity = '';

    private int $size = 0;

    private ?string $readError = null;

    private ?string $tickId = null;

    public function __construct(
        private Filesystem $files,
        private string $path,
        private int $maxBytes = 262144,
        private float $refreshInterval = 0.5,
    ) {
        $this->maxBytes = max(1024, min(8388608, $maxBytes));
        $this->refreshInterval = max(0.1, min(60.0, $refreshInterval));
        $this->buffer = new LogBuffer;
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->showFiles();
    }

    private function showFiles(): void
    {
        $this->currentFile = null;
        $this->clear();
        $this->records = null;
        $this->detail = null;
        $this->opened = null;
        $this->fileSignature = '';
        $this->status = $this->makeStatus();
        $this->list = new SearchableList([]);
        $this->list->onSelect(function (SelectEvent $event): void {
            $this->query = $this->list?->query() ?? '';
            $this->fileSearchOpen = $this->list?->isSearching() ?? false;
            $this->selected = $event->getValue();
            $this->showFile($event->getValue());
        });
        $this->add(new TextWidget($this->plain($this->path), truncate: true));
        $this->add($this->status);
        $this->add($this->list);
        $this->refreshFiles();
        $this->list->setQuery($this->query, $this->selected);

        if ($this->fileSearchOpen) {
            $this->list->openSearch();
        } else {
            $this->list->focus();
        }
    }

    private function refreshFiles(): void
    {
        clearstatcache();
        $items = [];
        $available = true;

        try {
            if (! $this->files->isDirectory($this->path) || ! is_readable($this->path)) {
                throw new RuntimeException;
            }

            foreach ($this->files->files($this->path) as $file) {
                if (strtolower($file->getExtension()) !== 'log' || ! $file->isFile() || ! $file->isReadable()) {
                    continue;
                }

                $items[] = ['value' => $file->getFilename(), 'label' => $this->plain($file->getFilename())];
            }
            usort($items, static fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
            $message = count($items).' log files';
        } catch (Throwable) {
            $items = [];
            $available = false;
            $message = 'Log directory is unavailable';
        }

        $signature = serialize([$items, $message]);

        if ($signature !== $this->fileSignature) {
            $this->fileSignature = $signature;
            $this->list?->setEmptyMessage($available ? 'No log files found' : '');
            $this->list?->setItems($items);

            if ($available) {
                $this->status->hide();
            } else {
                $this->status->setStatus('Error', $message, 'error');
            }
            $this->getContext()?->requestRender();
        }
    }

    private function showFile(string $name): void
    {
        $this->currentFile = $name;
        $this->recordQuery = '';
        $this->recordSelection = null;
        $this->recordSearchOpen = false;
        $this->paused = false;
        $this->following = true;
        $this->text = '';
        $this->identity = '';
        $this->size = 0;
        $this->readError = null;
        $this->buffer = new LogBuffer;
        $this->showRecords();
        $this->refreshFile(force: true);
    }

    private function showRecords(): void
    {
        $this->clear();
        $this->opened = null;
        $this->list = null;
        $this->detail = null;
        $this->status = $this->makeStatus();
        $this->records = new SearchableList([]);
        $this->records->onInput($this->handleRecordInput(...));
        $this->records->onCancel(function (): void {
            if ($this->records?->isSearching() || $this->records?->query() !== '') {
                $this->records?->closeSearch();
            } else {
                $this->showFiles();
            }
        });
        $this->records->onSelect(function (SelectEvent $event): void {
            $record = $this->buffer->find($event->getValue());

            if ($record === null) {
                return;
            }
            $this->recordQuery = $this->records?->query() ?? '';
            $this->recordSelection = $record->id;
            $this->recordSearchOpen = $this->records?->isSearching() ?? false;
            $this->following = false;
            $this->showRecord($record);
        });
        $this->add(new TextWidget($this->plain($this->currentFile ?? ''), truncate: true));
        $this->add($this->status);
        $this->add($this->records);
        $this->renderRecords();
        $this->records->setQuery($this->recordQuery, $this->recordSelection);

        if ($this->recordSearchOpen) {
            $this->records->openSearch();
        } else {
            $this->records->focus();
        }
        $this->updateStatus();
    }

    private function handleRecordInput(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();

        if ($keys === null) {
            return false;
        }

        if ($this->matchesKey($data, 'logs_pause')) {
            $this->paused = ! $this->paused;

            if (! $this->paused) {
                $this->refreshFile(force: true);
            }
        } elseif ($this->matchesKey($data, 'logs_level')) {
            $this->buffer->cycleLevel();
            $this->renderRecords();
        } elseif ($keys->matches($data, 'logs_start')) {
            $this->following = false;
            $this->records?->selectIndex(0);
        } elseif ($keys->matches($data, 'logs_follow')) {
            $this->paused = false;
            $this->following = true;
            $this->refreshFile(force: true);
        } else {
            if ($keys->matches($data, 'output_line_up') || $keys->matches($data, 'output_line_down')
                || $keys->matches($data, 'output_up') || $keys->matches($data, 'output_down')) {
                $this->following = false;
                $this->updateStatus();
            }

            return false;
        }
        $this->updateStatus();
        $this->getContext()?->requestRender();

        return true;
    }

    private function showRecord(LogRecord $record): void
    {
        $this->clear();
        $this->records = null;
        $this->opened = $record;
        $this->status = $this->makeStatus();
        $this->detail = new ScrollableText;
        $this->detail->addStyleClass('log-output');
        $this->detail->setText($record->details);
        $this->detail->onInput(function (string $data): bool {
            $keys = $this->detail?->getKeybindings();

            if ($keys?->matches($data, 'output_back')) {
                $this->showRecords();
            } elseif ($keys?->matches($data, 'logs_start')) {
                $this->detail->scrollToStart();
            } elseif ($keys?->matches($data, 'logs_follow')) {
                $this->detail->followOutput();
            } else {
                return false;
            }

            return true;
        });
        $this->add(new TextWidget($this->plain($this->currentFile ?? '').' ['.$record->level.']', truncate: true));
        $this->add($this->detail);
        $this->add($this->status);
        $this->updateStatus();
        $this->getContext()?->getFocusManager()->setFocus($this->detail);
    }

    private function matchesKey(string $data, string $action): bool
    {
        $keys = $this->getContext()?->keybindings();

        if ($keys === null) {
            return false;
        }

        $printable = $keys->getParser()->decodePrintable($data) ?? $data;
        $key = strtr($printable, config('laravel-tui.key_aliases', []));

        return $keys->matches($key, $action);
    }

    private function refreshFile(bool $force = false): void
    {
        if ($this->frozen()) {
            $this->updateStatus();

            return;
        }

        $oldSize = $this->size;
        $oldIdentity = $this->identity;
        $oldError = $this->readError;

        try {
            $text = $this->readTail();
            $this->readError = null;
        } catch (Throwable) {
            $text = '';
            $this->readError = 'Could not read log file';
        }

        if ($force || $text !== $this->text || $oldError !== $this->readError || $oldSize !== $this->size || $oldIdentity !== $this->identity) {
            $this->text = $text;
            $this->buffer->ingest($text);
            $this->renderRecords();
        }
        $this->updateStatus();
    }

    private function frozen(): bool
    {
        return $this->paused || $this->opened !== null || ($this->records?->isSearching()
            && $this->getContext()?->getFocusManager()->getFocus() instanceof InputWidget);
    }

    private function readTail(): string
    {
        $path = $this->path.DIRECTORY_SEPARATOR.$this->currentFile;
        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException;
        }

        try {
            $stat = fstat($handle);

            if ($stat === false) {
                throw new RuntimeException;
            }
            $this->identity = $stat['dev'].':'.$stat['ino'];
            $this->size = $stat['size'];
            $offset = max(0, $this->size - $this->maxBytes);

            if (fseek($handle, max(0, $offset - 1)) !== 0) {
                throw new RuntimeException;
            }
            $previous = $offset > 0 ? fread($handle, 1) : "\n";
            $text = stream_get_contents($handle, $this->maxBytes);

            if ($text === false) {
                throw new RuntimeException;
            }

            if ($offset > 0 && $previous !== "\n") {
                $newline = strpos($text, "\n");
                $text = $newline === false ? '' : substr($text, $newline + 1);
            }

            return $this->plain($text);
        } finally {
            fclose($handle);
        }
    }

    private function renderRecords(): void
    {
        $items = [];
        $emptyMessage = match (true) {
            $this->readError !== null => '',
            $this->buffer->count() === 0 && $this->size === 0 => 'Log file is empty',
            $this->buffer->count() === 0 && $this->size > $this->maxBytes => 'No complete records in the loaded tail',
            $this->buffer->count() === 0 => 'No records in the loaded tail',
            default => 'No entries at level '.$this->buffer->level().' or higher',
        };
        $this->records?->setEmptyMessage($emptyMessage);

        foreach ($this->buffer->records() as $record) {
            $color = match ($record->level) {
                'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => config('laravel-tui.theme.error', '#E879A9'),
                'WARNING' => config('laravel-tui.theme.warning', '#E5C07B'),
                'NOTICE' => '#B4A7D6',
                'INFO' => '#39C5BB',
                default => '#65727A',
            };
            $items[] = [
                'value' => $record->id,
                'label' => new Style(color: $color)->apply('['.$record->level.'] '.$record->message),
                'search' => $record->raw."\n".$record->details,
            ];
        }
        $this->records?->setItems($items);

        if ($this->following) {
            $this->records?->selectIndex(max(0, count($items) - 1));
        }
        $this->updateStatus();
        $this->getContext()?->requestRender();
    }

    private function makeStatus(): StatusLine
    {
        return new StatusLine;
    }

    private function updateStatus(): void
    {
        if ($this->currentFile === null) {
            return;
        }

        if ($this->opened !== null) {
            $this->status->setStatus('Record', 'Snapshot | updates paused');

            return;
        }

        if ($this->readError !== null) {
            $retry = $this->frozen() ? 'updates paused' : 'retrying';
            $this->status->setStatus('Error', $this->readError.' | '.$retry, 'error');

            return;
        }

        $mode = match (true) {
            $this->paused => 'Paused',
            $this->frozen() => 'Searching',
            $this->following => 'Live',
            default => 'Browsing',
        };
        $tone = match ($mode) {
            'Live' => 'success',
            'Paused' => 'warning',
            'Searching' => 'info',
            default => 'muted',
        };
        $count = ($this->records?->count() ?? 0).'/'.$this->buffer->count().' entries';
        $tail = $this->size > $this->maxBytes ? ' | last '.(int) ceil($this->maxBytes / 1024).' KiB' : '';
        $this->status->setStatus($mode, $this->buffer->level().' | '.$count.$tail, $tone);
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(str_replace("\r", '', $text))));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->tickId = $context->scheduleTick(function (): void {
            if ($this->currentFile === null) {
                $this->refreshFiles();
            } else {
                $this->refreshFile();
            }
        }, $this->refreshInterval);
    }

    protected function onDetach(): void
    {
        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }
    }
}
