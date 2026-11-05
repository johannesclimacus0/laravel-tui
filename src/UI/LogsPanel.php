<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\ChangeEvent;
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

    private ?ScrollableText $output = null;

    private TextWidget $status;

    private ?ContainerWidget $searchBar = null;

    private ?InputWidget $search = null;

    private string $contentQuery = '';

    private string $text = '';

    private string $fileSignature = '';

    private string $identity = '';

    private int $size = 0;

    private int $entries = 0;

    private int $matches = 0;

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
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->showFiles();
    }

    private function showFiles(): void
    {
        $this->currentFile = null;
        $this->clear();
        $this->output = null;
        $this->searchBar = null;
        $this->search = null;
        $this->fileSignature = '';
        $this->status = new TextWidget;
        $this->status->setStyle(new Style(color: '#65727A'));
        $this->list = new SearchableList([]);
        $this->list->onSelect(function (SelectEvent $event): void {
            $this->query = $this->list?->query() ?? '';
            $this->fileSearchOpen = $this->list?->isSearching() ?? false;
            $this->selected = $event->getValue();
            $this->showFile($event->getValue());
        });
        $this->add(new TextWidget($this->plain($this->path), truncate: true));
        $this->add($this->list);
        $this->add($this->status);
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
            $message = $items === [] ? 'No log files found' : count($items).' log files';
        } catch (Throwable) {
            $items = [];
            $message = 'Log directory is unavailable';
        }

        $signature = serialize([$items, $message]);

        if ($signature !== $this->fileSignature) {
            $this->fileSignature = $signature;
            $this->list?->setItems($items);
            $this->status->setText($message);
            $this->getContext()?->requestRender();
        }
    }

    private function showFile(string $name): void
    {
        $this->clear();
        $this->list = null;
        $this->currentFile = $name;
        $this->contentQuery = '';
        $this->text = '';
        $this->identity = '';
        $this->size = 0;
        $this->readError = null;
        $this->search = null;
        $this->status = new TextWidget;
        $this->status->setStyle(new Style(color: '#65727A'));
        $this->output = new ScrollableText;
        $this->output->addStyleClass('log-output');
        $this->output->onInput($this->handleOutputInput(...));
        $this->searchBar = new ContainerWidget;
        $this->searchBar->setStyle(new Style(hidden: true));
        $this->add(new TextWidget($this->plain($name), truncate: true));
        $this->add($this->output);
        $this->add($this->status);
        $this->add($this->searchBar);
        $this->output->followOutput();
        $this->refreshFile(force: true);
        $this->getContext()?->getFocusManager()->setFocus($this->output);
    }

    private function handleOutputInput(string $data): bool
    {
        if ($this->output === null) {
            return false;
        }

        $keys = $this->output->getKeybindings();

        if ($keys->matches($data, 'toggle_search')) {
            $this->toggleContentSearch();
        } elseif ($keys->matches($data, 'output_back')) {
            if ($this->search !== null || $this->contentQuery !== '') {
                $this->closeContentSearch(reset: true);
            } else {
                $this->showFiles();
            }
        } elseif ($keys->matches($data, 'logs_start')) {
            $this->output->scrollToStart();
        } elseif ($keys->matches($data, 'logs_follow')) {
            $this->output->followOutput();
        } else {
            return false;
        }
        $this->updateStatus();

        return true;
    }

    private function toggleContentSearch(): void
    {
        if ($this->search !== null) {
            $this->closeContentSearch();

            return;
        }

        $this->search = new InputWidget;
        $this->search->setPrompt('/ ');
        $this->search->setValue($this->contentQuery);
        $this->search->onChange(function (ChangeEvent $event): void {
            $this->contentQuery = $event->getValue();
            $this->renderOutput(reset: true);
        });
        $this->search->onSubmit(fn () => $this->closeContentSearch());
        $this->search->onCancel(fn () => $this->closeContentSearch(reset: true));
        $this->search->onInput(function (string $data): bool {
            if (! ($this->search?->getKeybindings()->matches($data, 'toggle_search') ?? false)) {
                return false;
            }

            $this->closeContentSearch();

            return true;
        });
        $frame = new Panel('', '#39C5BB');
        $frame->content()->add($this->search);
        $this->searchBar?->add($frame);
        $this->searchBar?->setStyle(new Style(hidden: false));
        $this->getContext()?->getFocusManager()->setFocus($this->search);
    }

    private function closeContentSearch(bool $reset = false): void
    {
        $this->searchBar?->clear();
        $this->searchBar?->setStyle(new Style(hidden: true));
        $this->search = null;

        if ($reset) {
            $this->contentQuery = '';
            $this->renderOutput(reset: true);
        }
        $this->getContext()?->getFocusManager()->setFocus($this->output);
    }

    private function refreshFile(bool $force = false): void
    {
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
            $replaced = $oldIdentity !== '' && ($oldIdentity !== $this->identity || $this->size < $oldSize);
            $reset = $replaced && ! ($this->output?->isFollowingOutput() ?? false);
            $this->renderOutput(reset: $reset);
        }
        $this->updateStatus();
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

    private function renderOutput(bool $reset = false): void
    {
        $pattern = '/(?=^\[\d{4}-\d{2}-\d{2}[^\r\n]*\]\s+\S+\.(?:EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):)/mi';
        $entries = trim($this->text) === '' ? [] : (preg_match($pattern, $this->text)
            ? (preg_split($pattern, $this->text, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : explode("\n", rtrim($this->text, "\n")));
        $this->entries = count($entries);
        $query = trim($this->contentQuery);
        $filtered = array_values(array_filter($entries, static fn (string $entry): bool => $query === '' || mb_stripos($entry, $query) !== false));
        $this->matches = count($filtered);
        $text = implode("\n", array_map(static fn (string $entry): string => rtrim($entry, "\n"), $filtered));

        if ($this->readError !== null) {
            $text = $this->readError;
        } elseif ($text === '') {
            $text = match (true) {
                $query !== '' => 'No matching entries in the loaded tail',
                $this->size > $this->maxBytes => 'No complete lines in the loaded tail',
                default => 'Log file is empty',
            };
        }

        $this->output?->setText($this->colorize($text));

        if ($reset) {
            $this->output?->scrollToStart();
        }
        $this->updateStatus();
        $this->getContext()?->requestRender();
    }

    private function colorize(string $text): string
    {
        return implode("\n", array_map(static function (string $line): string {
            if (! preg_match('/\b[\w-]+\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):/i', $line, $match)) {
                return $line;
            }

            $color = match (strtoupper($match[1])) {
                'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR' => '#E879A9',
                'WARNING' => '#E5C07B',
                'NOTICE' => '#B4A7D6',
                'INFO' => '#39C5BB',
                default => '#65727A',
            };

            return new Style(color: $color)->apply($line);
        }, explode("\n", $text)));
    }

    private function updateStatus(): void
    {
        if ($this->currentFile === null) {
            return;
        }

        $mode = ($this->output?->isFollowingOutput() ?? false) ? 'Live' : 'Browsing';
        $count = trim($this->contentQuery) === '' ? $this->entries.' entries' : $this->matches.'/'.$this->entries.' entries';
        $tail = $this->size > $this->maxBytes ? ' | last '.(int) ceil($this->maxBytes / 1024).' KiB' : '';
        $this->status->setText($this->readError === null ? $mode.' | '.$count.$tail : 'File unavailable  retrying');
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
