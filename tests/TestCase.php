<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Tests;

use LaravelTui\LaravelTui\LaravelTuiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelTuiServiceProvider::class,
        ];
    }
}
