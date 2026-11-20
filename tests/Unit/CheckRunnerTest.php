<?php

declare(strict_types=1);

use LaravelTui\LaravelTui\Checks\CheckRunner;

it('passes configured env vars to a check process', function () {
    $this->app['config']->set('laravel-tui.checks', [
        'working_directory' => base_path(),
        'timeout' => 30,
        'commands' => [
            'Environment' => [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'exit(getenv("APP_ENV") === "testing" ? 0 : 1);',
                ],
                'env' => [
                    'APP_ENV' => 'testing',
                ],
            ],
        ],
    ]);

    $runner = new CheckRunner($this->app['config'], $this->app);

    expect($runner->definitions()[0]->env)
        ->toBe(['APP_ENV' => 'testing']);

    $runner->start('Environment');

    $deadline = microtime(true) + 5;

    while ($runner->result('Environment')->status === 'running' && microtime(true) < $deadline) {
        usleep(10000);
        $runner->tick();
    }

    expect($runner->result('Environment')->status)->toBe('passed');
});

it('inherits env vars when no overrides are configured', function () {
    $name = 'LARAVEL_TUI_CHECK_ENV_TEST';

    $previous = getenv($name);
    $previousEnv = $_ENV[$name] ?? null;
    $previousServer = $_SERVER[$name] ?? null;

    putenv("{$name}=inherited");
    $_ENV[$name] = 'inherited';
    $_SERVER[$name] = 'inherited';

    try {
        $this->app['config']->set('laravel-tui.checks', [
            'working_directory' => base_path(),
            'timeout' => 30,
            'commands' => [
                'Environment' => [
                    PHP_BINARY,
                    '-r',
                    'exit(getenv("LARAVEL_TUI_CHECK_ENV_TEST") === "inherited" ? 0 : 1);',
                ],
            ],
        ]);

        $runner = new CheckRunner($this->app['config'], $this->app);

        expect($runner->definitions()[0]->env)->toBe([]);

        $runner->start('Environment');

        $deadline = microtime(true) + 5;

        while ($runner->result('Environment')->status === 'running' && microtime(true) < $deadline) {
            usleep(10000);
            $runner->tick();
        }

        expect($runner->result('Environment')->status)->toBe('passed');
    } finally {
        if ($previous === false) {
            putenv($name);
        } else {
            putenv("{$name}={$previous}");
        }

        if ($previousEnv === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $previousEnv;
        }

        if ($previousServer === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $previousServer;
        }
    }
});

it('rejects invalid env configuration', function () {
    $this->app['config']->set('laravel-tui.checks', [
        'commands' => [
            'Invalid environment' => [
                'command' => [PHP_BINARY, '-v'],
                'env' => [
                    'INVALID=NAME' => 'value',
                ],
            ],
        ],
    ]);

    expect(fn () => new CheckRunner(
        $this->app['config'],
        $this->app,
    ))->toThrow(
        InvalidArgumentException::class,
        'env must contain valid string names and values',
    );
});
