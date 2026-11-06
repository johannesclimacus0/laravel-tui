<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Console\Command;
use LaravelTui\LaravelTui\Routes\RouteRepository;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class RouteDataCommand extends Command
{
    protected $signature = 'ui:routes';

    protected $description = 'Read routes for the TUI';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            $response = ['version' => 1, 'ok' => true, 'data' => $this->laravel->make(RouteRepository::class)->routes()];
            $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

            if (strlen($json) > 1048576) {
                throw new RuntimeException('Route response exceeds 1 MiB; configure laravel-tui.routes.exclude');
            }
            $code = self::SUCCESS;
        } catch (Throwable $error) {
            $json = json_encode(['version' => 1, 'ok' => false, 'error' => substr($error->getMessage(), 0, 2000)], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $code = self::FAILURE;
        }
        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
