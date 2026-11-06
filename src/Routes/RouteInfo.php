<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Routes;

use RuntimeException;

readonly class RouteInfo
{
    /**
     * @param  list<string>  $methods
     * @param  list<string>  $details
     */
    public function __construct(
        public string $id,
        public array $methods,
        public string $uri,
        public string $name,
        public string $domain,
        public string $action,
        public array $details,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'uri', 'name', 'domain', 'action'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || strlen($data[$key]) > 8192) {
                throw new RuntimeException('Invalid route response');
            }
        }
        foreach (['methods', 'details'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key]) || ! array_is_list($data[$key]) || count($data[$key]) > 512) {
                throw new RuntimeException('Invalid route response');
            }
            foreach ($data[$key] as $value) {
                if (! is_string($value) || strlen($value) > 8192) {
                    throw new RuntimeException('Invalid route response');
                }
            }
        }

        if ($data['id'] === '' || $data['methods'] === []) {
            throw new RuntimeException('Invalid route identity');
        }

        return new self($data['id'], $data['methods'], $data['uri'], $data['name'], $data['domain'], $data['action'], $data['details']);
    }
}
