<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Routes;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Widget\Util\StringUtils;
use Throwable;

class RouteRepository
{
    public function __construct(private Router $router, private Application $app) {}

    /** @return list<RouteInfo> */
    public function routes(): array
    {
        $excluded = config('laravel-tui.routes.exclude', []);

        if (! is_array($excluded)) {
            throw new RuntimeException('Route exclusions must be a list');
        }
        $records = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $name = $route->getName() ?? '';
            $uri = '/'.ltrim($route->uri(), '/');
            foreach ($excluded as $pattern) {
                if (is_string($pattern) && (Str::is($pattern, $uri) || Str::is($pattern, ltrim($uri, '/')) || Str::is($pattern, $name))) {
                    continue 2;
                }
            }
            $methods = array_values($route->methods());
            $domain = $route->getDomain() ?? '';
            $action = $route->getActionName();
            $details = [
                implode('|', $methods).' '.$uri,
                '',
                'Name: '.($name === '' ? 'Unnamed' : $name),
                'Domain: '.($domain === '' ? 'Any' : $domain),
                'Action: '.$action,
                'Source: '.$this->source($route),
                '',
                'Parameters',
            ];
            $parameters = $route->parameterNames();

            if ($parameters === []) {
                $details[] = 'None';
            }
            foreach ($parameters as $parameter) {
                $optional = str_contains($route->uri().' '.$domain, '{'.$parameter.'?}');
                $details[] = $parameter.' ['.($optional ? 'optional' : 'required').']';

                if (isset($route->wheres[$parameter])) {
                    $details[] = '  Constraint: '.$route->wheres[$parameter];
                }

                if (array_key_exists($parameter, $route->defaults)) {
                    $details[] = '  Default: '.$this->value($route->defaults[$parameter]);
                }
            }
            $details[] = '';
            $details[] = 'Middleware';

            try {
                $middleware = $this->router->gatherRouteMiddleware($route);
                foreach ($middleware as $entry) {
                    $details[] = is_string($entry) ? $entry : 'Closure';
                }

                if ($middleware === []) {
                    $details[] = 'None';
                }
            } catch (Throwable $error) {
                $details[] = 'Unavailable: '.$error->getMessage();
            }
            $assigned = $route->middleware();

            if ($assigned !== []) {
                $details[] = '';
                $details[] = 'Assigned aliases / groups: '.implode(', ', array_map($this->value(...), $assigned));
            }
            $records[] = new RouteInfo(
                hash('sha256', $domain."\0".implode('|', $methods)."\0".$route->uri()),
                array_map($this->plain(...), $methods),
                $this->plain($uri), $this->plain($name), $this->plain($domain), $this->plain($action),
                array_map($this->plain(...), $details),
            );

            if (count($records) > 5000) {
                throw new RuntimeException('Route list exceeds 5000 entries; configure laravel-tui.routes.exclude');
            }
        }
        usort($records, static fn (RouteInfo $a, RouteInfo $b): int => [$a->uri, $a->domain, $a->methods] <=> [$b->uri, $b->domain, $b->methods]);

        return $records;
    }

    private function source(Route $route): string
    {
        try {
            $uses = $route->getAction('uses');

            if ($uses instanceof Closure) {
                $reflection = new ReflectionFunction($uses);
            } elseif (is_string($uses) && str_contains($uses, '@')) {
                [$class, $method] = explode('@', $uses, 2);
                $reflection = new ReflectionMethod($class, $method);
            } else {
                return 'Unavailable';
            }
            $file = $reflection->getFileName();
            $line = $reflection->getStartLine();

            if ($file === false || $line === false) {
                return 'Unavailable';
            }
            $base = rtrim($this->app->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            return (str_starts_with($file, $base) ? substr($file, strlen($base)) : $file).':'.$line;
        } catch (Throwable) {
            return 'Unavailable';
        }
    }

    private function value(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '['.get_debug_type($value).']';
    }

    private function plain(string $text): string
    {
        $text = mb_strcut(str_replace(["\r", "\n", "\t"], ' ', $text), 0, 8000, 'UTF-8');

        return StringUtils::stripControlBytes(StringUtils::sanitizeUtf8(AnsiUtils::stripAnsiCodes($text)));
    }
}
