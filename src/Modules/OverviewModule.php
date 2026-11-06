<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\ScrollableText;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

class OverviewModule implements Module
{
    public function __construct(private Application $app, private Repository $config) {}

    public function id(): string
    {
        return 'overview';
    }

    public function label(): string
    {
        return 'Overview';
    }

    public function widget(): AbstractWidget
    {
        $text = new ScrollableText($this->summary());
        $text->onInput(function (string $data) use ($text): bool {
            $keys = $text->getKeybindings();
            $printable = $keys->getParser()->decodePrintable($data) ?? $data;

            if (! $keys->matches(strtr($printable, $this->config->get('laravel-tui.key_aliases', [])), 'overview_refresh')) {
                return false;
            }
            $text->setText($this->summary());

            return true;
        });

        return $text;
    }

    private function summary(): string
    {
        $database = $this->config->get('database.default');
        $cache = $this->config->get('cache.default');
        $queue = $this->config->get('queue.default');
        $databaseConfig = $this->connection('database.connections', $database);
        $cacheConfig = $this->connection('cache.stores', $cache);
        $queueConfig = $this->connection('queue.connections', $queue);
        $rows = [
            'Application',
            'Name: '.$this->value($this->config->get('app.name')),
            'Environment: '.$this->value($this->app->environment()),
            'Debug: '.($this->config->get('app.debug') ? 'Enabled' : 'Disabled'),
            'Timezone: '.$this->value($this->config->get('app.timezone')),
            '',
            'Runtime',
            'Laravel: '.$this->app->version(),
            'PHP: '.PHP_VERSION,
            'Memory limit: '.ini_get('memory_limit'),
            'Configuration cache: '.($this->app->configurationIsCached() ? 'Cached' : 'Not cached'),
            'Route cache: '.($this->app->routesAreCached() ? 'Cached' : 'Not cached'),
            '',
            'Database configuration',
            'Connection: '.$this->value($database),
            'Driver: '.$this->value($databaseConfig['driver'] ?? null),
            'Database: '.$this->value($databaseConfig['database'] ?? null),
            '',
            'Cache configuration',
            'Store: '.$this->value($cache),
            'Driver: '.$this->value($cacheConfig['driver'] ?? null),
            '',
            'Queue configuration',
            'Connection: '.$this->value($queue),
            'Driver: '.$this->value($queueConfig['driver'] ?? null),
        ];
        $queueName = $queueConfig['queue'] ?? null;

        if ($queueName !== null) {
            $rows[] = 'Queue: '.$this->value($queueName);
        }

        if (in_array($queueConfig['driver'] ?? null, ['sync', 'deferred', 'background', 'null'], true)) {
            $rows[] = match ($queueConfig['driver']) {
                'sync' => 'Jobs execute immediately in the current process',
                'deferred' => 'Jobs execute after the response in the current process',
                'background' => 'Jobs execute in a background process',
                default => 'Jobs are discarded',
            };
        }

        return implode("\n", array_map($this->plain(...), $rows));
    }

    /** @return array<string, mixed> */
    private function connection(string $key, mixed $name): array
    {
        $connections = $this->config->get($key, []);
        $connection = is_array($connections) && is_string($name) ? ($connections[$name] ?? []) : [];

        return is_array($connection) ? $connection : [];
    }

    private function value(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $this->plain($value) : 'Not configured';
    }

    private function plain(string $text): string
    {
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);

        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }
}
