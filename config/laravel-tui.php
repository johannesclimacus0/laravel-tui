<?php

declare(strict_types=1);
use LaravelTui\LaravelTui\Modules\ExampleModule;
use LaravelTui\LaravelTui\Modules\OverviewModule;

return [
    'art' => [
        'path' => resource_path('laravel-tui'),
        'default' => 'mliku',
        'modules' => [
            'overview' => 'mliku',
            'example' => 'popipoipo',
        ],
    ],

    'editor' => [
        'enabled' => true,
        'prefix' => 'laravel-tui/art-editor',
    ],

    'modules' => [
        OverviewModule::class,
        ExampleModule::class,
    ],

    'keybindings' => [
        'quit' => ['q', 'ctrl+c'],
        'cycle_art' => ['a'],
    ],

    'key_aliases' => [
        'Q' => 'q',
        'й' => 'q',
        'Й' => 'q',
        'A' => 'a',
        'ф' => 'a',
        'Ф' => 'a',
    ],

    'icons' => [
        'project' => "\u{f07b}",
        'default_module' => "\u{f12e}",
        'modules' => [
            'overview' => "\u{f015}",
            'example' => "\u{f121}",
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
    ],
];
