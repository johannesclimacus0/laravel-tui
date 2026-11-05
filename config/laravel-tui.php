<?php

declare(strict_types=1);

use LaravelTui\LaravelTui\Modules\ArtisanModule;
use LaravelTui\LaravelTui\Modules\LogsModule;
use LaravelTui\LaravelTui\Modules\OverviewModule;

return [
    'modules' => [
        OverviewModule::class,
        ArtisanModule::class,
        LogsModule::class,
    ],

    'keybindings' => [
        'quit' => ['q', 'ctrl+c'],
        'cycle_art' => ['a'],
        'toggle_search' => ['/', '.'],
        'focus_next' => ['tab'],
        'focus_previous' => ['shift+tab'],
        'interrupt' => ['ctrl+c'],
        'output_up' => ['page_up'],
        'output_down' => ['page_down'],
        'output_line_up' => ['up'],
        'output_line_down' => ['down'],
        'output_back' => ['escape'],
        'history_previous' => ['up'],
        'history_next' => ['down'],
        'complete_command' => ['right'],
        'logs_start' => ['home'],
        'logs_follow' => ['end'],
    ],

    'artisan' => [
        'exclude' => [],
        'command' => [PHP_BINARY, base_path('artisan')],
        'working_directory' => base_path(),
    ],

    'key_aliases' => [
        'Q' => 'q',
        'й' => 'q',
        'Й' => 'q',
        'A' => 'a',
        'ф' => 'a',
        'Ф' => 'a',
    ],

    'art' => [
        'path' => resource_path('laravel-tui'),
        'default' => 'mliku',
        'modules' => [
            'overview' => 'mliku',
            'artisan' => 'miku.exe',
            'logs' => 'mikudayo',
        ],
    ],

    'editor' => [
        'enabled' => true,
        'prefix' => 'laravel-tui/art-editor',
    ],

    'icons' => [
        'project' => "\u{f07b}",
        'default_module' => "\u{f12e}",
        'modules' => [
            'overview' => "\u{f015}",
            'example' => "\u{f121}",
            'artisan' => "\u{f011b}",
            'logs' => "\u{f0219}",
        ],
    ],

    'captions' => [
        'default' => [
            'the servers are probably fine',
        ],
        'overview' => [
            'powered by leek and delusion.',
            'certified leek-powered computing.',
        ],
        'example' => [
            'oo ee oo~',
        ],
        'artisan' => [
            'maybe later',
        ],
        'logs' => [
            'the logs remember everything.',
        ],
    ],

    'logs' => [
        'path' => storage_path('logs'),
        'max_bytes' => 262144,
        'refresh_interval' => 0.5,
    ],
];
