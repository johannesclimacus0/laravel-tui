<?php

declare(strict_types=1);

use LaravelTui\LaravelTui\Art\ArtRepository;

it('resolves the art repository with bundled artwork', function () {
    expect(app(ArtRepository::class)->names())->toContain('mliku');
});

it('returns the same instance from the container', function () {
    expect(app(ArtRepository::class))->toBe(app(ArtRepository::class));
});

it('merges the package config', function () {
    expect(config('laravel-tui.art.default'))->toBe('mliku');
});

it('registers the artisan command', function () {
    $this->artisan('help', ['command_name' => 'ui'])
        ->expectsOutputToContain('Open the Laravel TUI')
        ->assertSuccessful();
});
