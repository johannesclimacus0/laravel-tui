<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use JsonException;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\OverviewPanel;
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
        return new OverviewPanel($this->snapshot(...));
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $database = $this->config->get('database.default');
        $cache = $this->config->get('cache.default');
        $queue = $this->config->get('queue.default');
        $databaseConfig = $this->connection('database.connections', $database);
        $cacheConfig = $this->connection('cache.stores', $cache);
        $queueConfig = $this->connection('queue.connections', $queue);

        $info = array_map($this->plain(...), [
            'environment' => $this->value($this->app->environment()),
            'debug' => $this->config->get('app.debug') ? 'Enabled' : 'Disabled',
            'timezone' => $this->value($this->config->get('app.timezone')),
            'locale' => $this->value($this->config->get('app.locale')),
            'session' => $this->value($this->config->get('session.driver')),
            'logging' => $this->value($this->config->get('logging.default')),
            'mail' => $this->value($this->config->get('mail.default')),
            'filesystem' => $this->value($this->config->get('filesystems.default')),
            'laravel' => $this->app->version(),
            'php' => PHP_VERSION,
            'config_cache' => $this->app->configurationIsCached() ? 'Cached' : 'Not cached',
            'route_cache' => $this->app->routesAreCached() ? 'Cached' : 'Not cached',
            'database' => $this->value($database),
            'database_driver' => $this->value($databaseConfig['driver'] ?? null),
            'database_name' => $this->value($databaseConfig['database'] ?? null),
            'cache' => $this->value($cache),
            'cache_driver' => $this->value($cacheConfig['driver'] ?? null),
            'queue' => $this->value($queue),
            'queue_driver' => $this->value($queueConfig['driver'] ?? null),
            'queue_name' => $this->value($queueConfig['queue'] ?? null),
        ]);
        $info['packages'] = $this->packages();

        return $info;
    }

    private function packages(): string
    {
        $root = InstalledVersions::getRootPackage()['install_path'];
        $required = $this->requirements($root.'/composer.json');

        if ($required === null) {
            return 'Composer manifest unavailable';
        }
        $frameworkPath = InstalledVersions::isInstalled('laravel/framework')
            ? InstalledVersions::getInstallPath('laravel/framework') : null;
        $framework = $frameworkPath === null ? [] : ($this->requirements($frameworkPath.'/composer.json') ?? []);
        $packages = [];
        foreach (array_keys($required) as $name) {
            if (! is_string($name) || ! str_contains($name, '/') || str_starts_with($name, 'laravel/') || str_starts_with($name, 'illuminate/') || array_key_exists($name, $framework)) {
                continue;
            }
            $version = InstalledVersions::isInstalled($name)
                ? (InstalledVersions::getPrettyVersion($name) ?? 'Unknown version')
                : 'Not installed';
            $packages[$name] = $this->plain($name).'  '.$this->plain($version);
        }
        ksort($packages);

        return $packages === [] ? 'No additional production packages' : implode("\n", $packages);
    }

    /** @return array<array-key, mixed>|null */
    private function requirements(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $manifest = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($manifest)) {
            return null;
        }
        $required = $manifest['require'] ?? [];

        return is_array($required) ? $required : null;
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
