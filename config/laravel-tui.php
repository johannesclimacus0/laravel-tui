<?php

declare(strict_types=1);

use LaravelTui\LaravelTui\Modules\ArtisanModule;
use LaravelTui\LaravelTui\Modules\ExampleModule;
use LaravelTui\LaravelTui\Modules\OverviewModule;

return [
    'modules' => [
        OverviewModule::class,
        ExampleModule::class,
        ArtisanModule::class,
    ],

    'keybindings' => [
        'quit' => ['q', 'ctrl+c'],
        'cycle_art' => ['a'],
        'focus_next' => ['tab'],
        'focus_previous' => ['shift+tab'],
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
            'example' => 'popipoipo',
            'artisan' => 'miku.exe',
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
    ],
];
