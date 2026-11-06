<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Database;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;

class DatabaseRepository
{
    public function __construct(private DatabaseManager $database, private Repository $config) {}

    public function snapshot(string $connection, string $view = 'tables', string $table = '', int $page = 0): DatabaseSnapshot
    {
        $configured = $this->config->get('database.connections', []);
        $allowed = $this->config->get('laravel-tui.database.connections', []);

        if (! is_array($configured) || ! isset($configured[$connection]) || ! is_array($configured[$connection])
            || ! is_array($allowed) || ($allowed !== [] && ! in_array($connection, $allowed, true))) {
            throw new RuntimeException('Database connection is not available');
        }

        if (! in_array($view, ['tables', 'structure', 'rows'], true) || $page < 0 || $page > 10000) {
            throw new RuntimeException('Invalid database view or page');
        }
        $db = $this->database->connection($connection);
        $prefix = $db->getTablePrefix();
        $db->setTablePrefix('');

        try {
            $snapshot = $this->read($db, $connection, $view, $table, $page);

            if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)) > 1048576) {
                throw new RuntimeException('Database result exceeds the 1 MiB limit; reduce laravel-tui.database.page_size');
            }

            return $snapshot;
        } finally {
            $db->setTablePrefix($prefix);
        }
    }

    private function read(Connection $db, string $connection, string $view, string $table, int $page): DatabaseSnapshot
    {
        $schema = $db->getSchemaBuilder();
        $tables = $schema->getTables();

        if (count($tables) > 5000) {
            throw new RuntimeException('Database exceeds the 5000 table limit');
        }
        $names = array_column($tables, 'schema_qualified_name');
        sort($names, SORT_STRING);

        if ($view === 'tables') {
            return new DatabaseSnapshot('tables', $connection, '', 0, ['Table'], array_map(fn (string $name): array => [$name], $names), false, [], '');
        }

        if (! in_array($table, $names, true)) {
            throw new RuntimeException('Table is not available');
        }
        foreach ($tables as $metadata) {
            if ($metadata['schema_qualified_name'] === $table
                && (str_contains($metadata['name'], '.') || ! $this->safeIdentifier($table))) {
                throw new RuntimeException('This table identifier is not supported by the query builder');
            }
        }
        $columns = $schema->getColumns($table);
        $indexes = $schema->getIndexes($table);

        if (count($columns) > 256 || count($indexes) > 256) {
            throw new RuntimeException('Table exceeds the 256 column or index limit');
        }
        $truncated = false;
        $indexLabels = [];
        foreach ($indexes as $index) {
            $indexLabels[] = $this->cell($index['name'].' ('.implode(', ', $index['columns']).')'.($index['primary'] ? ' PRIMARY' : ($index['unique'] ? ' UNIQUE' : '')), $truncated);
        }

        if ($view === 'structure') {
            $rows = [];
            foreach ($columns as $column) {
                $rows[] = [$this->cell($column['name'], $truncated), $this->cell($column['type'], $truncated), $column['nullable'] ? 'Yes' : 'No', $this->cell($column['default'], $truncated)];
            }

            return new DatabaseSnapshot('structure', $connection, $table, 0, ['Column', 'Type', 'Nullable', 'Default'], $rows, false, $indexLabels, $truncated ? 'Cells truncated to 1024 bytes.' : '');
        }
        $columnNames = array_column($columns, 'name');
        $nullable = array_column($columns, 'nullable', 'name');
        $types = array_column($columns, 'type_name', 'name');
        $order = [];
        usort($indexes, fn (array $a, array $b): int => (int) $b['primary'] <=> (int) $a['primary']);
        foreach ($indexes as $index) {
            if ((! $index['primary'] && ! $index['unique']) || $index['columns'] === []) {
                continue;
            }

            if (! $index['primary'] && ! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                continue;
            }
            foreach ($index['columns'] as $column) {
                $integerPrimary = $index['primary'] && count($index['columns']) === 1 && $db->getDriverName() === 'sqlite'
                    && strtolower($types[$column] ?? '') === 'integer';

                if (! in_array($column, $columnNames, true) || (($nullable[$column] ?? true) && ! $integerPrimary) || ! $this->safeIdentifier($column) || str_contains($column, '.')) {
                    continue 2;
                }
            }
            $order = $index['columns'];

            break;
        }
        $size = $this->config->get('laravel-tui.database.page_size', 25);
        $size = is_int($size) ? max(1, min(100, $size)) : 25;
        $query = $db->table($table);
        foreach ($order as $column) {
            $query->orderBy($column);
        }
        $records = $query->offset($page * $size)->limit($size + 1)->get();
        $hasNext = $records->count() > $size;
        $rows = [];
        $binary = [];
        foreach ($columns as $column) {
            $binary[$column['name']] = preg_match('/blob|binary|bytea|image/i', $column['type_name']) === 1;
        }
        foreach ($records->take($size) as $record) {
            $values = (array) $record;
            $row = [];
            foreach ($columnNames as $column) {
                $row[] = $this->cell($values[$column] ?? null, $truncated, $binary[$column]);
            }
            $rows[] = $row;
        }
        $displayColumns = [];
        foreach ($columnNames as $column) {
            $displayColumns[] = $this->cell($column, $truncated);
        }
        $note = $order === [] ? 'No safe unique index; row order may change between pages.' : '';

        if ($truncated) {
            $note = trim($note.' Cells truncated to 1024 bytes.');
        }

        return new DatabaseSnapshot('rows', $connection, $table, $page, $displayColumns, $rows, $hasNext, $indexLabels, $note);
    }

    private function safeIdentifier(string $value): bool
    {
        return strlen($value) <= 1024 && ! preg_match('/[\x00-\x1f\x7f]|\s+as\s+|->|\*/i', $value);
    }

    private function cell(mixed $value, bool &$truncated, bool $binary = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value, 1025);
            $binary = true;
        }
        $value = is_scalar($value) ? (string) $value : '[unsupported value]';

        if ($binary) {
            if (strlen($value) > 511) {
                $truncated = true;
            }
            $value = '0x'.bin2hex(substr($value, 0, 511));
        }

        $value = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $value);

        if (strlen($value) > 1024) {
            $truncated = true;
            $value = substr($value, 0, 1024);
        }

        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($value)));
    }
}
