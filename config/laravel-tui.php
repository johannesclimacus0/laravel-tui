<?php

declare(strict_types=1);

use LaravelTui\LaravelTui\Modules\ArtisanModule;
use LaravelTui\LaravelTui\Modules\LogsModule;
use LaravelTui\LaravelTui\Modules\OverviewModule;
use LaravelTui\LaravelTui\Modules\QueueModule;
use LaravelTui\LaravelTui\Modules\SchedulerModule;

return [
    'modules' => [
        OverviewModule::class,
        ArtisanModule::class,
        LogsModule::class,
        SchedulerModule::class,
        QueueModule::class,
    ],

    'theme' => [
        'text' => 'default',
        'muted' => '#65727A',
        'border' => '#39C5BB',
        'focus' => '#6DE2D5',
        'workspace_border' => '#E879A9',
        'workspace_focus' => '#F4A6C8',
        'selection' => '#39C5BB',
        'selection_inactive' => '#39C5BB',
        'warning' => '#E5C07B',
        'error' => '#E879A9',
    ],

    'keybindings' => [
        'quit' => ['q', 'ctrl+c'],
        'cycle_art' => ['a'],
        'toggle_search' => ['/'],
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
        'logs_start' => ['ctrl+up'],
        'logs_follow' => ['ctrl+down'],
        'logs_level' => ['f'],
        'logs_pause' => ['p'],
        'scheduler_refresh' => ['r'],
        'queue_refresh' => ['r'],
        'queue_retry' => ['t'],
        'queue_forget' => ['d'],
        'queue_confirm' => ['y'],
        'queue_cancel' => ['n', 'escape'],
    ],

    'key_aliases' => [
        'R' => 'r',
        'к' => 'r',
        'К' => 'r',
        'Q' => 'q',
        'й' => 'q',
        'Й' => 'q',
        'A' => 'a',
        'ф' => 'a',
        'Ф' => 'a',
        'F' => 'f',
        'а' => 'f',
        'А' => 'f',
        'P' => 'p',
        'з' => 'p',
        'З' => 'p',
        '.' => '/',
        'T' => 't',
        'е' => 't',
        'Е' => 't',
        'D' => 'd',
        'в' => 'd',
        'В' => 'd',
        'Y' => 'y',
        'н' => 'y',
        'Н' => 'y',
        'N' => 'n',
        'т' => 'n',
        'Т' => 'n',
    ],

    'artisan' => [
        'exclude' => [],
        'command' => [PHP_BINARY, base_path('artisan')],
        'working_directory' => base_path(),
    ],

    'art' => [
        'path' => resource_path('laravel-tui'),
        'default' => 'mliku',
        'modules' => [
            'overview' => 'mliku',
            'artisan' => 'miku.exe',
            'logs' => 'mikudayo',
            'scheduler' => 'mikumiku',
            'queue' => 'mikumiku',
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
            'scheduler' => "\u{f0954}",
            'queue' => "\u{f0ae}",
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
            'maybe later',
        ],
        'scheduler' => [
            'maybe later',
        ],
        'queue' => [
            'maybe later',
        ],
    ],

    'logs' => [
        'path' => storage_path('logs'),
        'max_bytes' => 262144,
        'refresh_interval' => 0.5,
    ],

    'queue' => [
        'connections' => [],
        'queues' => [],
        'refresh_interval' => 2,
        'timeout' => 8,
        'action_timeout' => 30,
        'failed_limit' => 50,
        'max_detail_bytes' => 65536,
        'allow_actions' => true,
    ],
];
