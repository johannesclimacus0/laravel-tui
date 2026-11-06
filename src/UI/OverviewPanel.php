<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use Closure;
use Symfony\Component\Tui\Style\Color;

class OverviewPanel extends ScrollableText
{
    /** @param Closure(): array<string, string> $snapshot */
    public function __construct(private Closure $snapshot)
    {
        parent::__construct(highlightCurrentLine: false);
        $this->refresh();
        $this->onInput(function (string $data): bool {
            $keys = $this->getKeybindings();
            $printable = $keys->getParser()->decodePrintable($data) ?? $data;

            if (! $keys->matches(strtr($printable, config('laravel-tui.key_aliases', [])), 'overview_refresh')) {
                return false;
            }
            $this->refresh();

            return true;
        });
    }

    private function refresh(): void
    {
        $info = ($this->snapshot)();
        $rows = [
            $this->field('Laravel', $info['laravel'], 'border'),
            $this->field('PHP', $info['php']),
            '',
            $this->field('Environment', $info['environment'], $info['environment'] === 'production' ? 'warning' : 'workspace_border'),
            $this->field('Debug', $info['debug'], $info['debug'] === 'Enabled' ? 'warning' : 'text'),
            $this->field('Timezone', $info['timezone']),
            $this->field('Locale', $info['locale']),
            '',
            $this->field('Database', $this->driver($info['database'], $info['database_driver'])),
            $this->field('DB name', $info['database_name']),
            $this->field('Cache', $this->driver($info['cache'], $info['cache_driver'])),
            $this->field('Queue', $this->driver($info['queue'], $info['queue_driver'])),
        ];

        if ($info['queue_name'] !== 'Not configured') {
            $rows[] = $this->field('Queue name', $info['queue_name']);
        }
        $rows = [...$rows, '',
            $this->field('Config cache', $info['config_cache'], $info['config_cache'] === 'Cached' ? 'border' : 'muted'),
            $this->field('Route cache', $info['route_cache'], $info['route_cache'] === 'Cached' ? 'border' : 'muted'),
            $this->field('Session', $info['session']),
            $this->field('Logs', $info['logging']),
            $this->field('Filesystem', $info['filesystem']),
            $this->field('Mailer', $info['mail']),
            '',
            $this->color('Project packages', 'workspace_border'),
        ];
        foreach (explode("\n", $info['packages']) as $package) {
            $parts = explode('  ', $package, 2);
            $rows[] = count($parts) === 2
                ? $this->color($parts[0], 'text').'  '.$this->color($parts[1], 'border')
                : $this->color($package, 'muted');
        }
        $this->setText(implode("\n", $rows));
    }

    private function driver(string $name, string $driver): string
    {
        return $name === $driver ? $name : $name.' ('.$driver.')';
    }

    private function field(string $label, string $value, string $tone = 'text'): string
    {
        return $this->color(str_pad($label, 15), 'muted').$this->color($value, $tone);
    }

    private function color(string $text, string $tone): string
    {
        $fallback = match ($tone) {
            'border' => '#39C5BB',
            'workspace_border' => '#E879A9',
            'muted' => '#65727A',
            'warning' => '#E5C07B',
            default => 'default',
        };

        return Color::from(config('laravel-tui.theme.'.$tone, $fallback))->toForegroundCode().$text."\x1b[0m";
    }
}
