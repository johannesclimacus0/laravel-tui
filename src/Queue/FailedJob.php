<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Queue;

use RuntimeException;

final readonly class FailedJob
{
    public function __construct(
        public string $id,
        public string $connection,
        public string $queue,
        public string $name,
        public string $failedAt,
        public string $payload = '',
        public string $exception = '',
        public string $fingerprint = '',
        public bool $truncated = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['id', 'connection', 'queue', 'name', 'failedAt', 'payload', 'exception', 'fingerprint'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new RuntimeException('Invalid failed-job response');
            }
        }

        if (! isset($data['truncated']) || ! is_bool($data['truncated'])) {
            throw new RuntimeException('Invalid failed-job response');
        }

        return new self(
            $data['id'], $data['connection'], $data['queue'], $data['name'], $data['failedAt'],
            $data['payload'], $data['exception'], $data['fingerprint'], $data['truncated'],
        );
    }
}
