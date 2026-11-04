<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEditorIsLocal
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('local') && config('laravel-tui.editor.enabled'), 404);

        return $next($request);
    }
}
