<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Console\Command;
use LaravelTui\LaravelTui\Migrations\MigrationRepository;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class MigrationDataCommand extends Command
{
    protected $signature = 'ui:migrations';

    protected $description = 'Read migration status for the TUI';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            $response = ['version' => 1, 'ok' => true, 'data' => $this->laravel->make(MigrationRepository::class)->snapshot()];
            $code = self::SUCCESS;
        } catch (Throwable $error) {
            $response = ['version' => 1, 'ok' => false, 'error' => substr($error->getMessage(), 0, 2000)];
            $code = self::FAILURE;
        }
        $this->output->writeln(json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
