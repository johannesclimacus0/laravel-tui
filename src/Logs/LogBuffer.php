<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Logs;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;

class LogBuffer
{
    public const array LEVELS = [
        'ALL' => 0,
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600,
    ];

    private string $level = 'ALL';

    /** @var list<LogRecord> */
    private array $entries = [];

    public function ingest(string $raw): void
    {
        $raw = $this->plain(str_replace("\r", '', $raw));
        $chunks = [];
        $current = null;

        foreach (explode("\n", rtrim($raw, "\n")) as $line) {
            $json = str_starts_with(ltrim($line), '{') ? json_decode($line, true) : null;
            $header = preg_match('/^\[\d{4}-\d{2}-\d{2}[^\]]*\]\s+\S+\.[a-z]+:/i', $line);

            if ($header || (is_array($json) && is_string($json['message'] ?? null))) {
                if ($current !== null) {
                    $chunks[] = $current;
                    $current = null;
                }

                if ($header) {
                    $current = $line;
                } else {
                    $chunks[] = $line;
                }
            } elseif ($current !== null) {
                $current .= "\n".$line;
            } elseif (trim($line) !== '') {
                $chunks[] = $line;
            }
        }

        if ($current !== null) {
            $chunks[] = $current;
        }

        $this->entries = [];
        $occurrences = [];

        foreach ($chunks as $chunk) {
            $firstLine = explode("\n", $chunk, 2)[0];
            $fingerprint = hash('sha256', $firstLine);
            $occurrence = $occurrences[$fingerprint] ?? 0;
            $occurrences[$fingerprint] = $occurrence + 1;
            $this->entries[] = $this->parse($chunk, $fingerprint.':'.$occurrence);
        }
    }

    /** @return list<LogRecord> */
    public function records(): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (LogRecord $record): bool => $this->level === 'ALL'
                || (self::LEVELS[$record->level] ?? -1) >= self::LEVELS[$this->level],
        ));
    }

    public function find(string $id): ?LogRecord
    {
        foreach ($this->entries as $record) {
            if ($record->id === $id) {
                return $record;
            }
        }

        return null;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function cycleLevel(): void
    {
        $levels = array_keys(self::LEVELS);
        $index = array_search($this->level, $levels, true);
        $this->level = $levels[((int) $index + 1) % count($levels)];
    }

    private function parse(string $raw, string $id): LogRecord
    {
        $json = str_starts_with(ltrim($raw), '{') ? json_decode($raw, true) : null;

        if (is_array($json) && is_string($json['message'] ?? null)) {
            $level = $this->normalizeLevel($json['level_name'] ?? $json['level'] ?? null);
            $message = $this->plain($json['message']);
            $details = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $details = $this->plain($details === false ? $raw : $details);
        } elseif (preg_match('/^\[[^\r\n\]]+\]\s+\S+\.([a-z]+):\s?([^\r\n]*)/i', $raw, $match)) {
            $level = $this->normalizeLevel($match[1]);
            $message = $match[2];
            $details = $raw;
        } else {
            preg_match('/\b(DEBUG|INFO|NOTICE|WARNING|WARN|ERROR|CRITICAL|CRIT|ALERT|EMERGENCY)\b/i', $raw, $match);
            $level = $this->normalizeLevel($match[1] ?? null);
            $message = $raw;
            $details = $raw;
        }

        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? '';

        return new LogRecord($id, $level, $message === '' ? '(empty message)' : $message, $details, $raw);
    }

    private function normalizeLevel(mixed $value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $level = array_search((int) $value, self::LEVELS, true);

            return $level !== false && $level !== 'ALL' ? $level : 'RAW';
        }

        if (! is_string($value)) {
            return 'RAW';
        }

        $level = match (strtoupper($value)) {
            'WARN' => 'WARNING',
            'CRIT' => 'CRITICAL',
            default => strtoupper($value),
        };

        return isset(self::LEVELS[$level]) && $level !== 'ALL' ? $level : 'RAW';
    }

    private function plain(string $text): string
    {
        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }
}
