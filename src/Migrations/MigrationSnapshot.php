<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Migrations;

use RuntimeException;

readonly class MigrationSnapshot
{
    /** @param list<array{name: string, batch: int|null, file: string|null}> $records */
    public function __construct(public string $connection, public bool $installed, public array $records) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! isset($data['connection'], $data['installed'], $data['records'])
            || ! is_string($data['connection']) || ! is_bool($data['installed']) || ! is_array($data['records'])
            || count($data['records']) > 5000) {
            throw new RuntimeException('Invalid migration response');
        }
        $records = [];
        $seen = [];
        foreach ($data['records'] as $record) {
            if (! is_array($record) || ! isset($record['name']) || ! is_string($record['name']) || $record['name'] === ''
                || ! array_key_exists('batch', $record) || ! array_key_exists('file', $record)
                || ($record['batch'] !== null && (! is_int($record['batch']) || $record['batch'] < 0))
                || ($record['file'] !== null && ! is_string($record['file'])) || isset($seen[$record['name']])) {
                throw new RuntimeException('Invalid migration record');
            }
            $seen[$record['name']] = true;
            $records[] = ['name' => $record['name'], 'batch' => $record['batch'], 'file' => $record['file']];
        }

        return new self($data['connection'], $data['installed'], $records);
    }
}
