<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Git;

final readonly class GitSnapshot
{
    /**
     * @param  list<GitFile>  $files
     * @param  list<GitCommit>  $commits
     */
    public function __construct(
        public string $root,
        public string $branch,
        public ?string $head,
        public ?string $upstream,
        public int $ahead,
        public int $behind,
        public array $files,
        public array $commits,
    ) {}
}
