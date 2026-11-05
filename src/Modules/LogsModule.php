<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Modules;

use Illuminate\Filesystem\Filesystem;
use LaravelTui\LaravelTui\Contracts\Module;
use LaravelTui\LaravelTui\UI\SearchableList;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\TextWidget;

class LogsModule implements Module
{
    public function __construct(private Filesystem $files) {}

    public function id(): string
    {
        return 'logs';
    }

    public function label(): string
    {
        return 'Logs';
    }

    public function widget(): AbstractWidget
    {
        $path = config('laravel-tui.logs.path');

        if(!$this->files->isDirectory($path) || !is_readable($path)) {
            return new TextWidget('Logs might not be configured');
        }

        $items = [];

        foreach ($this->files->files($path) as $file) {
            if(strtolower($file->getExtension()) !== 'log' || !$file->isReadable()) {
                continue;
            }

            $items[] = [
                'value' => $file->getFilename(),
                'label' => $file->getFilename(),
            ];
        }

        usort($items, static fn (array $left, array $right): int =>
            strnatcasecmp($left['label'], $right['label'])
        );

        if ($items === []) {
            return new TextWidget('No log files found.');
        }

        return new SearchableList($items);
    }
}
