<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use LaravelTui\LaravelTui\Console\ArtisanRunner;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\Queue\QueueRepository;
use LaravelTui\LaravelTui\UI\QueuePanel;
use LaravelTui\LaravelTui\UI\StatusLine;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Throwable;

class QueueModule implements Module
{
    public function __construct(
        private QueueRepository $queues,
        private ArtisanRunner $runner,
    ) {}

    public function id(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return 'Queue';
    }

    public function widget(): AbstractWidget
    {
        try {
            return new QueuePanel($this->queues->targets(), $this->runner);
        } catch (Throwable $error) {
            $status = new StatusLine;
            $status->setStatus('Error', $error->getMessage(), 'error');

            return $status;
        }
    }
}
