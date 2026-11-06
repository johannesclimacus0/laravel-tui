<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Git;

final readonly class GitCommit
{
    public function __construct(
        public string $hash,
        public string $shortHash,
        public string $subject,
        public string $author,
        public string $date,
    ) {}
}
