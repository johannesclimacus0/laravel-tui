<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Contracts\Foundation\Application;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\Git\GitRepository;
use LaravelTui\LaravelTui\UI\GitPanel;
use LaravelTui\LaravelTui\UI\StatusLine;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Throwable;

class GitModule implements Module
{
    public function __construct(private Application $app) {}

    public function id(): string
    {
        return 'git';
    }

    public function label(): string
    {
        return 'Git';
    }

    public function widget(): AbstractWidget
    {
        try {
            return new GitPanel($this->app->make(GitRepository::class));
        } catch (Throwable $error) {
            $status = new StatusLine;
            $status->setStatus('Error', $error->getMessage(), 'error');

            return $status;
        }
    }
}
