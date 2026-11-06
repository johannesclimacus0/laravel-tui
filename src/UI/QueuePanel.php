<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Queue\FailedJob;
use LaravelTui\LaravelTui\Queue\QueueSnapshot;
use LaravelTui\LaravelTui\Queue\QueueTarget;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Symfony\Component\Tui\Widget\WidgetContext;
use Throwable;

class QueuePanel extends ContainerWidget
{
    private ?SearchableList $sources = null;

    private ?SearchableList $jobs = null;

    private ?ScrollableText $detail = null;

    private ?QueueTarget $target = null;

    private ?QueueSnapshot $snapshot = null;

    private ?QueueSnapshot $deferredSnapshot = null;

    private ?FailedJob $opened = null;

    private TextWidget $summary;

    private StatusLine $status;

    private string $sourceQuery = '';

    private ?string $sourceSelection = null;

    private bool $sourceSearchOpen = false;

    private string $jobQuery = '';

    private ?string $jobSelection = null;

    private bool $jobSearchOpen = false;

    private ?Process $process = null;

    private string $request = '';

    private string $requestTarget = '';

    private ?string $requestJob = null;

    private string $stdout = '';

    private string $stderr = '';

    private ?string $pendingAction = null;

    private ?string $tickId = null;

    private ?Closure $modalListener = null;

    private float $nextRefresh = 0;

    private string $updatedAt = '';

    private ?string $lastError = null;

    private string $notice = '';

    private string $noticeTone = 'muted';

    private float $noticeUntil = 0;

    /** @param list<QueueTarget> $targets */
    public function __construct(
        private array $targets,
        private ArtisanRunner $runner,
    ) {
        $this->setStyle(new Style(gap: 1));
        $this->expandVertically(true);

        if (count($this->targets) === 1) {
            $this->target = $this->targets[0];
            $this->showJobs();
        } else {
            $this->showSources();
        }
    }

    private function showSources(): void
    {
        $this->stopRequest();
        $this->clear();
        $this->target = null;
        $this->snapshot = null;
        $this->deferredSnapshot = null;
        $this->opened = null;
        $this->jobs = null;
        $this->detail = null;
        $this->pendingAction = null;
        $this->lastError = null;
        $this->notice = '';
        $this->status = new StatusLine;
        $items = array_map(fn (QueueTarget $target): array => [
            'value' => $target->id(),
            'label' => $this->plain($target->connection.' / '.$target->queue.' ['.$target->driver.']'),
        ], $this->targets);
        $this->sources = new SearchableList($items, emptyMessage: 'No queue connections configured');
        $this->sources->setQuery($this->sourceQuery, $this->sourceSelection);
        $this->sources->onSelect(function (SelectEvent $event): void {
            foreach ($this->targets as $target) {
                if ($target->id() === $event->getValue()) {
                    $this->sourceQuery = $this->sources?->query() ?? '';
                    $this->sourceSelection = $target->id();
                    $this->sourceSearchOpen = $this->sources?->isSearching() ?? false;
                    $this->target = $target;
                    $this->snapshot = null;
                    $this->jobQuery = '';
                    $this->jobSelection = null;
                    $this->jobSearchOpen = false;
                    $this->showJobs();
                    $this->startRequest('snapshot');

                    return;
                }
            }
        });
        $this->add($this->status);
        $this->add($this->sources);

        if ($this->sourceSearchOpen) {
            $this->sources->openSearch();
        } else {
            $this->sources->focus();
        }
    }

    private function showJobs(bool $restoreSearchFocus = true): void
    {
        $this->clear();
        $this->sources = null;
        $this->detail = null;
        $this->opened = null;
        $this->pendingAction = null;
        $this->summary = new TextWidget;
        $this->status = new StatusLine;
        $this->jobs = new SearchableList([], emptyMessage: '');
        $this->jobs->onInput(function (string $data): bool {
            if ($this->matches($data, 'queue_refresh')) {
                $this->startRequest('snapshot');

                return true;
            }

            return false;
        });
        $this->jobs->onCancel(function (): void {
            if ($this->request === 'detail') {
                $this->stopRequest();
                $this->updateStatus();
            } elseif ($this->jobs?->isSearching() || $this->jobs?->query() !== '') {
                $this->jobs?->closeSearch();
            } elseif (count($this->targets) > 1) {
                $this->showSources();
            }
        });
        $this->jobs->onSelect(function (SelectEvent $event): void {
            $this->saveJobState();
            $this->startRequest('detail', $event->getValue());
        });
        $this->add($this->summary);
        $this->add($this->status);
        $this->add($this->jobs);
        $this->renderSnapshot();
        $this->jobs->setQuery($this->jobQuery, $this->jobSelection);

        if ($this->jobSearchOpen) {
            $this->jobs->openSearch();

            if (! $restoreSearchFocus) {
                $this->jobs->focus();
            }
        } else {
            $this->jobs->focus();
        }
        $this->updateStatus();
    }

    private function saveJobState(): void
    {
        $this->jobQuery = $this->jobs?->query() ?? '';
        $this->jobSelection = $this->jobs?->selectedValue();
        $this->jobSearchOpen = $this->jobs?->isSearching() ?? false;
    }

    private function showDetail(FailedJob $job): void
    {
        $this->clear();
        $this->jobs = null;
        $this->opened = $job;
        $this->status = new StatusLine;
        $this->detail = new ScrollableText;
        $this->detail->addStyleClass('command-output');
        $payload = json_decode($job->payload, true);
        $payloadText = json_last_error() === JSON_ERROR_NONE
            ? (json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: $job->payload)
            : $job->payload;
        $text = 'ID: '.$job->id."\nConnection: ".$job->connection."\nQueue: ".$job->queue."\nFailed at: ".$job->failedAt
            ."\n\nPayload:\n".$payloadText."\n\nException:\n".($job->exception === '' ? 'No exception recorded' : $job->exception);

        if ($job->truncated) {
            $text .= "\n\nDetails were truncated to the configured limit";
        }
        $this->detail->setText($this->plain($text));
        $this->detail->onInput(function (string $data): bool {
            if ($this->matches($data, 'queue_retry')) {
                $this->confirmAction('retry');
            } elseif ($this->matches($data, 'queue_forget')) {
                $this->confirmAction('forget');
            } elseif ($this->matches($data, 'output_back')) {
                $this->stopRequest();
                $this->showJobs();
                $this->nextRefresh = 0;
            } elseif ($this->matches($data, 'queue_refresh') && $this->opened !== null) {
                $this->startRequest('detail', $this->opened->id);
            } else {
                return false;
            }

            return true;
        });
        $this->add(new TextWidget($this->plain($job->name), truncate: true));
        $this->add($this->detail);
        $this->add($this->status);

        if (config('laravel-tui.queue.allow_actions', true)) {
            $actions = new MenuList([
                ['value' => 'retry', 'label' => 'Retry job'],
                ['value' => 'forget', 'label' => 'Delete failed job'],
            ]);
            $actions->addStyleClass('module-menu');
            $actions->onSelect(fn (SelectEvent $event) => $this->confirmAction($event->getValue()));
            $frame = new Panel('Actions', config('laravel-tui.theme.focus', '#6DE2D5'));
            $frame->content()->add($actions);
            $this->add($frame);
        }
        $this->updateStatus();
        $this->getContext()?->getFocusManager()->setFocus($this->detail);
    }

    private function confirmAction(string $action): void
    {
        if ($this->opened === null || $this->process !== null || ! in_array($action, ['retry', 'forget'], true)) {
            return;
        }

        if (! config('laravel-tui.queue.allow_actions', true)) {
            $this->setNotice('Queue actions are disabled', 'warning');

            return;
        }
        $this->pendingAction = $action;
        $this->getContext()?->getFocusManager()->setFocus($this->detail);
        $this->updateStatus();
    }

    private function startRequest(string $kind, ?string $job = null): void
    {
        if ($this->target === null || $this->actionRunning()) {
            return;
        }
        $this->stopRequest();
        $this->deferredSnapshot = null;
        $this->stdout = '';
        $this->stderr = '';
        $this->request = $kind;
        $this->requestTarget = $this->target->id();
        $this->requestJob = $job;
        $this->lastError = null;
        $arguments = [];

        if ($job !== null) {
            $arguments[] = '--job='.$job;
        }

        if (in_array($kind, ['retry', 'forget'], true) && $this->opened !== null) {
            $arguments[] = '--action='.$kind;
            $arguments[] = '--fingerprint='.$this->opened->fingerprint;
        }
        $arguments[] = '--';
        $arguments[] = $this->target->connection;
        $arguments[] = $this->target->queue;

        try {
            $this->process = $this->runner->start('ui:queue', $arguments);
            $timeout = in_array($kind, ['retry', 'forget'], true)
                ? (float) config('laravel-tui.queue.action_timeout', 30)
                : (float) config('laravel-tui.queue.timeout', 8);
            $this->process->setTimeout(max(1, min(300, $timeout)));
        } catch (Throwable $error) {
            $this->failRequest($error->getMessage());
        }
        $this->updateStatus();
    }

    private function poll(): void
    {
        if ($this->process !== null) {
            try {
                $this->process->checkTimeout();
                $running = $this->process->isRunning();
                $this->stdout .= $this->process->getIncrementalOutput();
                $this->stderr .= $this->process->getIncrementalErrorOutput();
                $this->process->clearOutput();
                $this->process->clearErrorOutput();

                if (strlen($this->stdout) > 2097152 || strlen($this->stderr) > 65536) {
                    throw new RuntimeException('Queue response exceeded the size limit');
                }

                if (! $running) {
                    $exitCode = $this->process->getExitCode();
                    $this->process = null;
                    $this->acceptResponse($exitCode);
                }
            } catch (ProcessTimedOutException) {
                $this->failRequest('Queue operation timed out', uncertain: true);
            } catch (Throwable $error) {
                $this->failRequest($error->getMessage(), uncertain: true);
            }
        }

        if ($this->deferredSnapshot !== null && ! $this->editing() && $this->opened === null) {
            $this->applySnapshot($this->deferredSnapshot);
            $this->deferredSnapshot = null;
        }

        if ($this->target !== null && $this->opened === null && $this->pendingAction === null
            && ! $this->editing() && $this->process === null && microtime(true) >= $this->nextRefresh) {
            $this->startRequest('snapshot');
        }
        $this->updateStatus();
    }

    private function acceptResponse(?int $exitCode): void
    {
        $completedAction = in_array($this->request, ['retry', 'forget'], true);
        $response = json_decode($this->stdout, true);

        if (! is_array($response) || ($response['version'] ?? null) !== 1 || ! is_bool($response['ok'] ?? null)) {
            $this->failRequest('Invalid queue response'.($this->stderr === '' ? '' : ': '.$this->stderr), uncertain: true);

            return;
        }

        if (! $response['ok']) {
            $this->failRequest(is_string($response['error'] ?? null) ? $response['error'] : 'Queue operation failed', uncertain: ($response['uncertain'] ?? false) === true);

            return;
        }

        if ($exitCode !== 0 || ! is_array($response['data'] ?? null)) {
            $this->failRequest('Queue process exited unsuccessfully', uncertain: true);

            return;
        }

        if ($this->target?->id() !== $this->requestTarget) {
            $this->request = '';

            return;
        }

        $data = $response['data'];

        if ($this->request === 'snapshot' && ($response['kind'] ?? null) === 'snapshot') {
            $snapshot = QueueSnapshot::fromArray($data);

            if ($this->target->connection !== $snapshot->connection || $this->target->queue !== $snapshot->queue) {
                throw new RuntimeException('Queue response belongs to another connection');
            }
            foreach ($snapshot->jobs as $job) {
                if ($job->connection !== $snapshot->connection || $job->queue !== $snapshot->queue) {
                    throw new RuntimeException('Failed-job response belongs to another queue');
                }
            }

            if ($this->editing()) {
                $this->deferredSnapshot = $snapshot;
            } else {
                $this->applySnapshot($snapshot);
            }
        } elseif ($this->request === 'detail' && ($response['kind'] ?? null) === 'detail') {
            $job = FailedJob::fromArray($data);

            if ($job->id !== $this->requestJob || $job->connection !== $this->target->connection
                || $job->queue !== $this->target->queue || ! preg_match('/\A[a-f0-9]{64}\z/', $job->fingerprint)) {
                throw new RuntimeException('Failed-job details do not match the selected job');
            }
            $this->showDetail($job);
        } elseif (in_array($this->request, ['retry', 'forget'], true) && ($response['kind'] ?? null) === 'action' && is_string($data['message'] ?? null)) {
            $this->setNotice($data['message'], 'success');
            $this->showJobs(restoreSearchFocus: false);
            $this->nextRefresh = 0;
        } else {
            throw new RuntimeException('Unexpected queue response');
        }
        $this->request = '';
        $this->nextRefresh = $completedAction ? 0 : microtime(true) + $this->refreshInterval();
    }

    private function applySnapshot(QueueSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
        $this->lastError = null;
        $this->updatedAt = date('H:i:s');
        $this->renderSnapshot();
    }

    private function renderSnapshot(): void
    {
        $target = $this->target;

        if ($target === null) {
            return;
        }
        $snapshot = $this->snapshot;
        $counts = $snapshot === null ? [] : $snapshot->counts;
        $value = static fn (?int $count): string => $count === null ? 'n/a' : (string) $count;
        $text = $target->connection.' / '.$target->queue.' ['.$target->driver."]\n"
            .'Pending: '.$value($counts['pending'] ?? null).' | Delayed: '.$value($counts['delayed'] ?? null)
            .' | Reserved: '.$value($counts['reserved'] ?? null).' | Total: '.$value($counts['total'] ?? null)
            ."\nFailed: ".$value($this->snapshot?->failedCount);

        if ($this->snapshot?->limited) {
            $text .= ' | showing a limited set';
        }

        if ($snapshot !== null && $snapshot->note !== '') {
            $text .= "\n".$snapshot->note;
        }
        $this->summary->setText($this->plain($text));
        $items = [];
        foreach ($snapshot === null ? [] : $snapshot->jobs as $job) {
            $items[] = [
                'value' => $job->id,
                'label' => $this->plain($job->failedAt.' '.$job->name.' ['.$job->id.']'),
                'search' => $this->plain($job->connection.' '.$job->queue),
            ];
        }
        $empty = $this->snapshot === null || $this->snapshot->failedError !== null ? '' : 'No failed jobs';
        $this->jobs?->setEmptyMessage($empty);
        $this->jobs?->setItems($items);
        $this->updateStatus();
    }

    private function failRequest(string $message, bool $uncertain = false): void
    {
        $action = in_array($this->request, ['retry', 'forget'], true);
        $this->stopRequest();
        $message = $this->plain(substr($message, 0, 2000));

        if ($action && $uncertain) {
            $message = 'Action outcome unknown; refresh before retrying. '.$message;
        }
        $this->lastError = $message ?: 'Queue operation failed';

        if ($action) {
            $this->pendingAction = null;
            $this->showJobs(restoreSearchFocus: false);
            $this->setNotice($this->lastError, 'error');
        }
        $this->nextRefresh = $action ? 0 : microtime(true) + $this->refreshInterval();
        $this->updateStatus();
    }

    private function stopRequest(): void
    {
        if ($this->process?->isRunning()) {
            $this->process->stop(0.1);
        }
        $this->process = null;
        $this->request = '';
    }

    private function actionRunning(): bool
    {
        return $this->process !== null && in_array($this->request, ['retry', 'forget'], true);
    }

    private function editing(): bool
    {
        return ($this->jobs?->isSearching() ?? false)
            && $this->getContext()?->getFocusManager()->getFocus() instanceof InputWidget;
    }

    private function refreshInterval(): float
    {
        return max(0.5, min(60, (float) config('laravel-tui.queue.refresh_interval', 2)));
    }

    private function setNotice(string $message, string $tone): void
    {
        $this->notice = $this->plain($message);
        $this->noticeTone = $tone;
        $this->noticeUntil = microtime(true) + 5;
        $this->updateStatus();
        $this->getContext()?->requestRender();
    }

    private function updateStatus(): void
    {
        if ($this->pendingAction !== null && ! $this->actionRunning()) {
            $verb = $this->pendingAction === 'retry' ? 'Retry this job' : 'Delete this failed job';
            $this->status->setStatus('Confirm', $verb.'? [y/n]', 'warning');
        } elseif ($this->actionRunning()) {
            $this->status->setStatus('Running', 'Queue action in progress', 'info');
        } elseif ($this->notice !== '' && microtime(true) < $this->noticeUntil) {
            $this->status->setStatus($this->noticeTone === 'error' ? 'Error' : ($this->noticeTone === 'success' ? 'Done' : 'Cancelled'), $this->notice, $this->noticeTone);
        } elseif ($this->lastError !== null) {
            $this->status->setStatus('Error', $this->lastError, 'error');
        } elseif ($this->opened !== null) {
            $this->status->hide();
        } elseif ($this->target !== null && $this->snapshot === null) {
            $this->status->setStatus('Loading', 'Reading queue data', 'info');
        } elseif ($this->snapshot !== null) {
            $errors = array_filter([$this->snapshot->queueError, $this->snapshot->failedError]);

            if ($errors !== []) {
                $this->status->setStatus('Error', implode(' | ', $errors), 'error');
            } else {
                $this->status->setStatus('Live', 'Updated '.$this->updatedAt, 'success');
            }
        } else {
            $this->status->hide();
        }
    }

    private function matches(string $data, string $action): bool
    {
        $keys = $this->getContext()?->keybindings();

        if ($keys === null) {
            return false;
        }
        $printable = $keys->getParser()->decodePrintable($data) ?? $data;

        return $keys->matches(strtr($printable, config('laravel-tui.key_aliases', [])), $action);
    }

    private function modalInput(InputEvent $event): void
    {
        if ($this->pendingAction === null && ! $this->actionRunning()) {
            return;
        }
        $data = $event->getData();
        $scroll = $this->matches($data, 'output_line_up') || $this->matches($data, 'output_line_down')
            || $this->matches($data, 'output_up') || $this->matches($data, 'output_down');

        if ($scroll && $this->getContext()?->getFocusManager()->getFocus() === $this->detail) {
            return;
        }
        $event->stopPropagation();

        if ($this->actionRunning()) {
            return;
        }

        if ($this->matches($data, 'queue_cancel')) {
            $this->pendingAction = null;
            $this->setNotice('Queue action cancelled', 'warning');
        } elseif ($this->matches($data, 'queue_confirm') && $this->opened !== null) {
            $this->startRequest($this->pendingAction ?? '', $this->opened->id);
            $this->getContext()?->getFocusManager()->setFocus($this->detail);
        } elseif ($this->matches($data, 'quit')) {
            $this->pendingAction = null;
            $event->stopPropagation();
            $this->setNotice('Queue action cancelled', 'warning');
        }
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }

    protected function onAttach(WidgetContext $context): void
    {
        $this->modalListener = $this->modalInput(...);
        $context->getEventDispatcher()->addListener(InputEvent::class, $this->modalListener, 20);
        $this->tickId = $context->scheduleTick($this->poll(...), 0.1);
    }

    protected function onDetach(): void
    {
        $this->stopRequest();

        if ($this->tickId !== null) {
            $this->getContext()?->cancelTick($this->tickId);
            $this->tickId = null;
        }

        if ($this->modalListener !== null) {
            $this->getContext()?->getEventDispatcher()->removeListener(InputEvent::class, $this->modalListener);
            $this->modalListener = null;
        }
    }
}
