<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Database;

use RuntimeException;

final readonly class DatabaseSnapshot
{
    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>  $indexes
     */
    public function __construct(
        public string $kind,
        public string $connection,
        public string $table,
        public int $page,
        public array $columns,
        public array $rows,
        public bool $hasNext,
        public array $indexes,
        public string $note,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['kind', 'connection', 'table', 'note'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || strlen($data[$key]) > 8192) {
                throw new RuntimeException('Invalid database snapshot');
            }
        }

        if (! in_array($data['kind'], ['tables', 'structure', 'rows'], true)
            || ! isset($data['page']) || ! is_int($data['page']) || $data['page'] < 0 || $data['page'] > 10000
            || ! isset($data['hasNext']) || ! is_bool($data['hasNext'])) {
            throw new RuntimeException('Invalid database snapshot');
        }
        foreach (['columns', 'rows', 'indexes'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key])) {
                throw new RuntimeException('Invalid database snapshot');
            }
        }

        if (count($data['columns']) > 256 || count($data['rows']) > 5000 || count($data['indexes']) > 256) {
            throw new RuntimeException('Database snapshot exceeds limits');
        }

        if (($data['kind'] === 'tables' && ($data['columns'] !== ['Table'] || $data['table'] !== '' || $data['page'] !== 0 || $data['hasNext']))
            || ($data['kind'] === 'structure' && ($data['columns'] !== ['Column', 'Type', 'Nullable', 'Default'] || $data['page'] !== 0 || $data['hasNext'] || count($data['rows']) > 256))
            || ($data['kind'] === 'rows' && count($data['rows']) > 100)
            || ($data['kind'] !== 'tables' && $data['table'] === '')) {
            throw new RuntimeException('Invalid database snapshot shape');
        }
        foreach ([$data['columns'], $data['indexes']] as $list) {
            foreach ($list as $value) {
                if (! is_string($value) || strlen($value) > 8192) {
                    throw new RuntimeException('Invalid database snapshot');
                }
            }
        }
        foreach ($data['rows'] as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($data['columns'])) {
                throw new RuntimeException('Invalid database row');
            }
            foreach ($row as $value) {
                if (! is_string($value) || strlen($value) > 8192) {
                    throw new RuntimeException('Invalid database cell');
                }
            }
        }

        return new self($data['kind'], $data['connection'], $data['table'], $data['page'], $data['columns'], $data['rows'], $data['hasNext'], $data['indexes'], $data['note']);
    }
}
