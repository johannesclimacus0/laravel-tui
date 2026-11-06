<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use RuntimeException;

class MigrationRepository
{
    public function __construct(private Migrator $migrator, private Application $app) {}

    public function snapshot(): MigrationSnapshot
    {
        return $this->migrator->usingConnection(null, function (): MigrationSnapshot {
            $files = $this->migrator->getMigrationFiles([
                ...$this->migrator->paths(),
                $this->app->databasePath('migrations'),
            ]);
            $repository = $this->migrator->getRepository();
            $installed = $repository->repositoryExists();
            $batches = $installed ? $repository->getMigrationBatches() : [];
            $names = array_unique([...array_keys($files), ...array_keys($batches)]);
            sort($names, SORT_STRING);

            if (count($names) > 5000) {
                throw new RuntimeException('Migration list exceeds 5000 entries');
            }
            $records = [];
            foreach ($names as $name) {
                $records[] = [
                    'name' => (string) $name,
                    'batch' => array_key_exists($name, $batches) ? (int) $batches[$name] : null,
                    'file' => $files[$name] ?? null,
                ];
            }

            return new MigrationSnapshot(
                $this->migrator->resolveConnection($this->migrator->getConnection())->getName(),
                $installed,
                $records,
            );
        });
    }
}
