<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use JsonException;
use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Routes\RouteInfo;
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

class RoutesPanel extends ContainerWidget
{
    /** @var array<string, RouteInfo> */
    private array $routes = [];

    private bool $loaded = false;

    private SearchableList $list;

    private TextWidget $summary;

    private StatusLine $status;

    private ?ScrollableText $detail = null;

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
        if ($this->process !== null) {
            return;
        }

        try {
            $this->stdout = '';
            $this->stderr = '';
            $this->process = $this->runner->start('ui:routes');
            $this->process->setTimeout(max(1, min(60, (float) config('laravel-tui.routes.timeout', 8))));
            $this->status->setStatus('Loading', 'Reading routes', 'info');
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
                throw new RuntimeException('Route response exceeded the size limit');
            }

            if ($running) {
                return;
            }
            $exitCode = $this->process->getExitCode();
            $this->stdout .= $this->process->getIncrementalOutput();
            $this->stderr .= $this->process->getIncrementalErrorOutput();

            if (strlen($this->stdout) > 2097152 || strlen($this->stderr) > 65536) {
                throw new RuntimeException('Route response exceeded the size limit');
            }
            $this->process = null;
            $response = json_decode($this->stdout, true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($response) || ($response['version'] ?? null) !== 1 || ! is_bool($response['ok'] ?? null)) {
                throw new RuntimeException('Invalid route response');
            }

            if ($exitCode !== 0 || ! $response['ok']) {
                throw new RuntimeException(is_string($response['error'] ?? null) ? $response['error'] : 'Could not read routes');
            }

            if (! is_array($response['data'] ?? null)) {
                throw new RuntimeException('Invalid route snapshot');
            }

            if (! array_is_list($response['data']) || count($response['data']) > 5000) {
                throw new RuntimeException('Invalid route list');
            }
            $routes = [];
            foreach ($response['data'] as $data) {
                if (! is_array($data)) {
                    throw new RuntimeException('Invalid route record');
                }
                $route = RouteInfo::fromArray($data);

                if (isset($routes[$route->id])) {
                    throw new RuntimeException('Duplicate route identity');
                }
                $routes[$route->id] = $route;
            }
            $this->routes = $routes;
            $this->loaded = true;
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

            $this->status->hide();
        } catch (JsonException) {
            $this->fail('Route command returned invalid JSON');
        } catch (ProcessTimedOutException) {
            $this->fail('Reading routes timed out');
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
        $this->summary->setText(count($this->routes).' routes');
    }

    /** @return list<array{value: string, label: string, search: string}> */
    private function items(): array
    {
        return array_values(array_map(function (RouteInfo $route): array {
            $methods = array_values(array_filter($route->methods, static fn (string $method): bool => $method !== 'HEAD' || ! in_array('GET', $route->methods, true)));
            $tone = match ($methods[0] ?? '') {
                'GET' => 'border',
                'POST' => 'workspace_focus',
                'PUT', 'PATCH' => 'warning',
                'DELETE' => 'error',
                default => 'muted',
            };
            $color = Color::from(config('laravel-tui.theme.'.$tone, '#65727A'))->toForegroundCode();

            return [
                'value' => $route->id,
                'label' => $color.str_pad(implode('|', $methods), 10)."\x1b[0m".$this->plain($route->uri)
                    .($route->name === '' ? '' : '  '.$this->plain($route->name)),
                'search' => implode(' ', $route->details),
            ];
        }, $this->routes));
    }

    private function showList(): void
    {
        $query = $this->list->query();
        $selected = $this->list->selectedValue();
        $searching = $this->list->isSearching();
        $this->clear();
        $this->opened = null;
        $this->detail = null;
        $this->list = new SearchableList($this->items(), emptyMessage: $this->loaded ? 'No routes found' : '', literalSearchCharacters: ['.', '/']);
        $this->list->setQuery($query, $selected);
        $this->list->onSelect(fn (SelectEvent $event) => $this->showDetail($event->getValue()));
        $this->list->onInput(fn (string $data): bool => $this->refreshInput($data));

        if ($searching) {
            $this->list->openSearch();
        }
        $this->add($this->summary);
        $this->add($this->status);
        $this->add($this->list);

        $this->list->focus();
    }

    private function showDetail(string $id): void
    {
        $route = $this->routes[$id] ?? null;

        if ($route === null) {
            $this->showList();

            return;
        }
        $this->clear();
        $this->opened = $id;
        $this->detail = new ScrollableText(implode("\n", array_map($this->plain(...), $route->details)));
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

        if (! ($keys?->matches(strtr($printable, config('laravel-tui.key_aliases', [])), 'routes_refresh') ?? false)) {
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
