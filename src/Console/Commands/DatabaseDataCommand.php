<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Console\Commands;

use Illuminate\Console\Command;
use LaravelTui\LaravelTui\Database\DatabaseRepository;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Throwable;

class DatabaseDataCommand extends Command
{
    protected $signature = 'ui:database {connection} {--view=tables} {--table=} {--page=0}';

    protected $description = 'Read database data for the TUI';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            $connection = $this->input->getArgument('connection');
            $view = $this->input->getOption('view');
            $table = $this->input->getOption('table') ?? '';
            $page = $this->input->getOption('page');

            if (! is_string($connection) || ! is_string($view) || ! is_string($table)
                || ! is_string($page) || ! preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/', $page) || (int) $page > 10000) {
                throw new RuntimeException('Invalid database request');
            }
            $repository = $this->laravel->make(DatabaseRepository::class);
            $response = ['version' => 1, 'ok' => true, 'data' => $repository->snapshot($connection, $view, $table, (int) $page)];
            $code = self::SUCCESS;
        } catch (Throwable $error) {
            $message = StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes(substr($error->getMessage(), 0, 2000))));
            $response = ['version' => 1, 'ok' => false, 'error' => $message ?: 'Database operation failed'];
            $code = self::FAILURE;
        }
        $this->output->writeln(json_encode($response, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);

        return $code;
    }
}
