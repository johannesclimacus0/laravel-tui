<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use LaravelTui\LaravelTui\Git\GitCommit;
use LaravelTui\LaravelTui\Git\GitFile;
use LaravelTui\LaravelTui\Git\GitRepository;
use LaravelTui\LaravelTui\Git\GitSnapshot;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;

class GitPanel extends ContainerWidget
{
    private TextWidget $summary;

    private TextWidget $metadata;

    private TextWidget $heading;

    private StatusLine $status;

    private MenuList $navigation;

    private ContainerWidget $content;

    private SearchableList $list;

    private ?ScrollableText $detail = null;

    private ?GitSnapshot $snapshot = null;

    private string $view = 'files';

    private ?string $opened = null;

    /** @var array<string, array{query: string, selected: ?string, searching: bool}> */
    private array $filters = [];

    private bool $refreshing = false;

    private ?string $tickId = null;

    private int $revision = -1;

    public function __construct(private GitRepository $repository)
    {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);
        $this->summary = new TextWidget('Git');
        $this->summary->setStyle(new Style(color: config('laravel-tui.theme.workspace_focus', '#F4A6C8'), bold: true));
        $this->metadata = new TextWidget('', truncate: true);
        $this->metadata->setStyle(new Style(color: config('laravel-tui.theme.muted', '#65727A')));
        $this->heading = new TextWidget('', truncate: true);
        $this->heading->setStyle(new Style(color: config('laravel-tui.theme.focus', '#6DE2D5')));
        $this->status = new StatusLine;
        $this->navigation = new MenuList([
            ['value' => 'files', 'label' => 'Changes'],
            ['value' => 'history', 'label' => 'History'],
        ], 2);
        $this->navigation->addStyleClass('module-menu');
        $this->navigation->onSelect(fn (SelectEvent $event) => $this->showList($event->getValue()));
        $this->navigation->onInput($this->handleKey(...));
        $this->navigation->onCancel(function (): void {
            if ($this->opened !== null) {
                $this->showList($this->view);
            } else {
                $this->list->focus();
            }
        });
        $this->content = new ContainerWidget;
        $this->content->expandVertically(true);
        $this->list = new SearchableList([]);
        $this->add($this->summary);
        $this->add($this->metadata);
        $this->add($this->status);
        $this->add($this->content);
        $this->add($this->navigation);
        $this->showList('files', false);
    }

    private function rememberFilter(): void
    {
        if ($this->opened === null) {
            $this->filters[$this->view] = [
                'query' => $this->list->query(),
                'selected' => $this->list->selectedValue(),
                'searching' => $this->list->isSearching(),
            ];
        }
    }

    private function showList(string $view, bool $focus = true): void
    {
        $this->rememberFilter();

        if ($this->opened !== null && ! $this->refreshing) {
            $this->repository->cancel();
        }
        $this->view = $view;
        $this->opened = null;
        $this->detail = null;
        $this->content->clear();
        $empty = $this->snapshot === null ? '' : ($view === 'files' ? 'Working tree clean' : 'No commits yet');
        $this->list = new SearchableList($this->items(), emptyMessage: $empty, literalSearchCharacters: ['.', '/']);
        $filter = $this->filters[$view] ?? ['query' => '', 'selected' => null, 'searching' => false];
        $this->list->setQuery($filter['query'], $filter['selected']);
        $this->list->onSelect(fn (SelectEvent $event) => $this->showDetail($event->getValue()));
        $this->list->onInput($this->handleKey(...));
        $this->content->add($this->list);
        $this->navigation->setSelectedIndex($view === 'files' ? 0 : 1);

        if ($focus) {
            if ($filter['searching']) {
                $this->list->openSearch();
            } else {
                $this->list->focus();
            }
        }
        $this->getContext()?->requestRender();
    }

    /** @return list<array{value: string, label: string, search: string}> */
    private function items(): array
    {
        if ($this->snapshot === null) {
            return [];
        }

        if ($this->view === 'history') {
            return array_map(fn (GitCommit $commit): array => [
                'value' => $commit->hash,
                'label' => $this->colored($commit->shortHash, 'focus').'  '.$this->oneLine($commit->subject),
                'search' => $commit->author.' '.$commit->date.' '.$commit->hash,
            ], $this->snapshot->commits);
        }

        return array_map(function (GitFile $file): array {
            $index = $file->untracked ? '?' : str_replace(['.', ' '], '-', $file->indexStatus);
            $worktree = $file->untracked ? '?' : str_replace(['.', ' '], '-', $file->worktreeStatus);
            $tone = $file->conflicted ? 'error' : 'warning';

            return [
                'value' => $file->id,
                'label' => $this->colored($index, $file->conflicted ? 'error' : 'border').' '
                    .$this->colored($worktree, $file->untracked ? 'muted' : $tone).'  '.$this->oneLine($file->path)
                    .($file->originalPath === null ? '' : $this->colored('  <- '.$this->oneLine($file->originalPath), 'muted')),
                'search' => ($file->untracked ? 'untracked' : '')
                    .($file->conflicted ? ' conflict' : '')
                    .($index !== '-' && ! $file->untracked ? ' staged' : '')
                    .($worktree !== '-' && ! $file->untracked ? ' unstaged' : ''),
            ];
        }, $this->snapshot->files);
    }

    private function showDetail(string $id, bool $focus = true): void
    {
        $this->rememberFilter();
        $this->refreshing = false;
        $this->opened = $id;
        $this->content->clear();
        $this->detail = new ScrollableText;
        $this->detail->addStyleClass('command-output');
        $this->detail->onInput(function (string $data): bool {
            if ($this->getContext()?->keybindings()->matches($data, 'output_back')) {
                $this->showList($this->view);

                return true;
            }

            return $this->handleKey($data);
        });
        $this->heading->setText($this->detailTitle($id));
        $this->content->add($this->heading);
        $this->content->add($this->detail);

        if ($this->view === 'files') {
            $this->repository->openFile($id);
        } else {
            $this->repository->openCommit($id);
        }
        $this->status->setStatus('Loading', 'Reading '.($this->view === 'files' ? 'changes' : 'commit'), 'info');

        if ($focus) {
            $this->getContext()?->getFocusManager()->setFocus($this->detail);
        }
        $this->getContext()?->requestRender();
    }

    private function detailTitle(string $id): string
    {
        if ($this->snapshot === null) {
            return '';
        }

        foreach ($this->snapshot->files as $file) {
            if ($this->view === 'files' && $file->id === $id) {
                return $this->oneLine($file->path);
            }
        }
        foreach ($this->snapshot->commits as $commit) {
            if ($this->view === 'history' && $commit->hash === $id) {
                return $commit->shortHash.'  '.$this->oneLine($commit->subject);
            }
        }

        return '';
    }

    private function refresh(): void
    {
        $this->refreshing = true;
        $this->repository->refresh();
        $this->status->setStatus('Loading', 'Reading repository', 'info');
        $this->getContext()?->requestRender();
    }

    private function poll(): void
    {
        $this->repository->tick();

        if ($this->revision === $this->repository->revision()) {
            return;
        }
        $this->revision = $this->repository->revision();
        $this->getContext()?->requestRender();

        if ($this->repository->error !== null) {
            $this->refreshing = false;
            $this->status->setStatus('Error', $this->plain($this->repository->error), 'error');

            return;
        }

        if ($this->repository->snapshot !== $this->snapshot) {
            $this->snapshot = $this->repository->snapshot;
            $this->updateSummary();

            if ($this->opened === null) {
                $this->list->setItems($this->items());
                $this->list->setEmptyMessage($this->view === 'files' ? 'Working tree clean' : 'No commits yet');
            }
        }

        if ($this->repository->busy()) {
            return;
        }

        if ($this->refreshing && $this->opened !== null) {
            $id = $this->opened;
            $focused = $this->getContext()?->getFocusManager()->getFocus();
            $wasDetail = $focused === $this->detail;

            if ($this->detailTitle($id) !== '') {
                $this->showDetail($id, $wasDetail);
            } else {
                $this->refreshing = false;
                $this->showList($this->view, $wasDetail);
                $this->status->hide();
            }

            return;
        }
        $this->refreshing = false;
        $this->status->hide();

        if ($this->detail !== null) {
            $text = $this->diff($this->repository->detail);
            $this->detail->setText($text === '' ? 'No changes to display' : $text);
        }
    }

    private function updateSummary(): void
    {
        if ($this->snapshot === null) {
            return;
        }
        $snapshot = $this->snapshot;
        $this->summary->setText($this->oneLine($snapshot->branch)
            .($snapshot->head === null ? '  (no commits yet)' : '  '.substr($snapshot->head, 0, 8)));
        $this->metadata->setText($this->oneLine($snapshot->root)."\n"
            .count($snapshot->files).' changed files'
            .($snapshot->upstream === null ? '' : '  |  '.$this->oneLine($snapshot->upstream).'  ahead '.$snapshot->ahead.'  behind '.$snapshot->behind));
    }

    private function handleKey(string $data): bool
    {
        $keys = $this->getContext()?->keybindings();
        $key = strtr($keys?->getParser()->decodePrintable($data) ?? $data, config('laravel-tui.key_aliases', []));

        if (! ($keys?->matches($key, 'git_refresh') ?? false)) {
            return false;
        }
        $this->refresh();

        return true;
    }

    private function colored(string $text, string $tone): string
    {
        return Color::from(config('laravel-tui.theme.'.$tone, '#65727A'))->toForegroundCode().$text."\x1b[0m";
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(str_replace("\r", '', $text))));
    }

    private function oneLine(string $text): string
    {
        return str_replace(["\n", "\t"], ' ', $this->plain($text));
    }

    private function diff(string $text): string
    {
        return implode("\n", array_map(function (string $line): string {
            $tone = match (true) {
                str_starts_with($line, 'diff --git'), str_starts_with($line, '+++'), str_starts_with($line, '---') => 'focus',
                str_starts_with($line, '@@') => 'workspace_focus',
                str_starts_with($line, '+') => 'border',
                str_starts_with($line, '-') => 'error',
                default => 'muted',
            };

            return $this->colored($line, $tone);
        }, explode("\n", $this->plain($text))));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->list->focus();
        $this->tickId = $context->scheduleTick($this->poll(...), 0.1);
        $this->refresh();
    }

    protected function onDetach(): void
    {
        $this->repository->cancel();

        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }
    }
}
