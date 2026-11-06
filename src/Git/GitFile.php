<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Git;

final readonly class GitFile
{
    public function __construct(
        public string $id,
        public string $path,
        public ?string $originalPath,
        public string $indexStatus,
        public string $worktreeStatus,
        public bool $untracked,
        public bool $conflicted,
    ) {}
}
