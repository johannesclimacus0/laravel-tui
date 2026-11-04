<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelTui\LaravelTui\Http\Controllers\ArtEditorController;
use LaravelTui\LaravelTui\Http\Middleware\EnsureEditorIsLocal;

Route::prefix(config('laravel-tui.editor.prefix', 'laravel-tui/art-editor'))
    ->middleware([EnsureEditorIsLocal::class, 'web'])
    ->name('laravel-tui.editor.')
    ->group(function (): void {
        Route::get('/', [ArtEditorController::class, 'index'])->name('index');
        Route::get('/assets/{file}', [ArtEditorController::class, 'asset'])->name('asset');
        Route::get('/data', [ArtEditorController::class, 'data'])->name('data');
        Route::post('/colors', [ArtEditorController::class, 'save'])->name('save');
    });
