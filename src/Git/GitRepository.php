<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Git;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class GitRepository
{
    private const int OUTPUT_LIMIT = 2097152;

    private const int DETAIL_LIMIT = 262144;

    private const string TRUNCATION = "\n[Output truncated]\n";

    public ?GitSnapshot $snapshot = null;

    public string $detail = '';

    public ?string $error = null;

    public bool $truncated = false;

    private ?Process $process = null;

    private string $phase = '';

    private string $output = '';

    private string $stderr = '';

    private bool $overflow = false;

    private int $revision = 0;

    private string $directory;

    private ?string $git;

    private ?string $configurationError = null;

    private float $timeout = 8;

    private int $historyLimit = 30;

    private string $root = '';

    private ?GitSnapshot $pending = null;

    private ?GitFile $file = null;

    public function __construct(Repository $config, Application $app)
    {
        $this->directory = $app->basePath();
        $this->git = (new ExecutableFinder)->find('git');

        try {
            $settings = $config->get('laravel-tui.git', []);

            if (! is_array($settings)) {
                throw new RuntimeException('Git configuration must be an array.');
            }
            $path = $settings['path'] ?? $app->basePath();

            if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new RuntimeException('Git path must be an existing directory.');
            }
            $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
            $directory = realpath($absolute ? $path : $app->basePath().DIRECTORY_SEPARATOR.$path);

            if ($directory === false || ! is_dir($directory)) {
                throw new RuntimeException('Git path must be an existing directory.');
            }
            $this->directory = $directory;
            $timeout = $settings['timeout'] ?? 8;

            if ((! is_int($timeout) && ! is_float($timeout)) || ! is_finite((float) $timeout) || $timeout <= 0 || $timeout > 60) {
                throw new RuntimeException('Git timeout must be a positive finite number of at most 60 seconds.');
            }
            $limit = $settings['history_limit'] ?? 30;

            if (! is_int($limit) || $limit < 1 || $limit > 100) {
                throw new RuntimeException('Git history limit must be an integer between 1 and 100.');
            }
            $this->timeout = (float) $timeout;
            $this->historyLimit = $limit;
        } catch (Throwable $exception) {
            $this->configurationError = $exception->getMessage();
        }
    }

    public function refresh(): void
    {
        $this->begin();
        $this->phase = 'root';
        $this->start(['rev-parse', '--show-toplevel'], $this->directory);
    }

    public function openFile(string $id): void
    {
        $this->begin();
        foreach ($this->snapshot->files ?? [] as $file) {
            if ($file->id === $id) {
                $this->file = $file;
                $this->root = $this->snapshot->root;

                if ($file->untracked) {
                    $this->phase = 'preview';
                } else {
                    $this->phase = 'staged';
                    $this->appendDetail("Staged changes\n\n");
                    $this->start($this->diffArguments(true), $this->root);
                }

                return;
            }
        }
        $this->fail('This changed file is no longer available. Refresh Git.');
    }

    public function openCommit(string $hash): void
    {
        $this->begin();
        foreach ($this->snapshot->commits ?? [] as $commit) {
            if ($commit->hash === $hash && preg_match('/\A[0-9a-f]{40,64}\z/D', $hash) === 1) {
                $this->phase = 'commit';
                $this->start(['show', '--no-show-signature', '--format=fuller', '--stat', '--patch', '--no-color', '--no-ext-diff', '--no-textconv', $hash, '--'], $this->snapshot->root);

                return;
            }
        }
        $this->fail('This commit is no longer available. Refresh Git.');
    }

    public function tick(): void
    {
        if ($this->phase === '') {
            return;
        }

        try {
            if ($this->configurationError !== null) {
                throw new RuntimeException($this->configurationError);
            }

            if ($this->git === null) {
                throw new RuntimeException('Git executable was not found. Install Git and ensure it is on PATH.');
            }

            if ($this->phase === 'preview') {
                $this->preview();
                $this->finish();

                return;
            }
            $process = $this->process;

            if ($process === null) {
                return;
            }
            $process->checkTimeout();
            $running = $process->isRunning();

            if ($this->overflow && ! $this->isDetail()) {
                throw new RuntimeException('Git output exceeds the 2 MiB limit. Reduce the number of changed files or history size.');
            }

            if ($this->isDetail() && $this->truncated) {
                $this->cancel();

                return;
            }

            if ($running) {
                return;
            }
            $this->process = null;

            if ($process->getExitCode() !== 0) {
                throw new RuntimeException($this->stderr !== '' ? trim($this->stderr) : 'Git could not complete the request.');
            }
            $this->advance();
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
    }

    public function cancel(): void
    {
        $process = $this->process;
        $this->process = null;

        try {
            $process?->stop(0);
        } catch (Throwable) {
        }

        if ($this->phase !== '') {
            $this->phase = '';
            $this->revision++;
        }
        $this->pending = null;
        $this->file = null;
    }

    public function busy(): bool
    {
        return $this->phase !== '';
    }

    public function revision(): int
    {
        return $this->revision;
    }

    private function begin(): void
    {
        $this->cancel();
        $this->detail = '';
        $this->error = null;
        $this->truncated = false;
        $this->revision++;
    }

    /** @param list<string> $arguments */
    private function start(array $arguments, string $directory): void
    {
        $this->output = '';
        $this->stderr = '';
        $this->overflow = false;

        if ($this->configurationError !== null || $this->git === null) {
            return;
        }

        try {
            $process = $this->process = new Process([
                $this->git, '--no-optional-locks', '--no-pager',
                '-c', 'core.fsmonitor=false', '-c', 'core.untrackedCache=false',
                '-c', 'color.ui=false', '-c', 'core.pager=cat',
                ...$arguments,
            ], $directory, [
                'GIT_OPTIONAL_LOCKS' => '0', 'GIT_LITERAL_PATHSPECS' => '1',
                'GIT_PAGER' => 'cat', 'GIT_TERMINAL_PROMPT' => '0',
                'GIT_EXTERNAL_DIFF' => false, 'GIT_DIFF_OPTS' => false,
                'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false,
                'GIT_COMMON_DIR' => false, 'GIT_NAMESPACE' => false,
                'GIT_CONFIG_COUNT' => false, 'GIT_CONFIG_PARAMETERS' => false,
                'GIT_NO_LAZY_FETCH' => '1', 'LC_ALL' => 'C',
            ], timeout: $this->timeout);
            $process->disableOutput();
            $process->start(function (string $type, string $chunk): void {
                if ($type === Process::ERR) {
                    $this->stderr .= substr($chunk, 0, max(0, 16384 - strlen($this->stderr)));
                } elseif ($this->isDetail()) {
                    $this->appendDetail($chunk);
                } else {
                    $remaining = max(0, self::OUTPUT_LIMIT - strlen($this->output));
                    $this->output .= substr($chunk, 0, $remaining);
                    $this->overflow = $this->overflow || strlen($chunk) > $remaining;
                }
            });
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
    }

    private function advance(): void
    {
        if ($this->phase === 'root') {
            $path = str_ends_with($this->output, "\n") ? substr($this->output, 0, -1) : $this->output;
            $root = $path === '' ? false : realpath($path);

            if ($root === false || ! is_dir($root)) {
                throw new RuntimeException('Git requires a working tree; bare repositories are not supported.');
            }
            $this->root = $root;
            $this->phase = 'status';
            $this->start(['status', '--porcelain=v2', '--branch', '-z', '--untracked-files=all'], $this->root);
        } elseif ($this->phase === 'status') {
            $this->pending = $this->parseStatus($this->output);

            if ($this->pending->head === null) {
                $this->snapshot = $this->pending;
                $this->finish();
            } else {
                $this->phase = 'log';
                $this->start(['log', '-z', '--no-show-signature', '--format=%H%x00%h%x00%s%x00%an%x00%aI', '-n', (string) $this->historyLimit, $this->pending->head, '--'], $this->root);
            }
        } elseif ($this->phase === 'log') {
            $pending = $this->pending ?? throw new RuntimeException('Git snapshot was interrupted.');
            $this->snapshot = new GitSnapshot($pending->root, $pending->branch, $pending->head, $pending->upstream, $pending->ahead, $pending->behind, $pending->files, $this->parseLog($this->output));
            $this->finish();
        } elseif ($this->phase === 'staged') {
            $this->appendDetail("\nUnstaged changes\n\n");
            $this->phase = 'unstaged';
            $this->start($this->diffArguments(false), $this->root);
        } else {
            $this->finish();
        }
    }

    private function parseStatus(string $output): GitSnapshot
    {
        $branch = '';
        $head = null;
        $upstream = null;
        $ahead = $behind = 0;
        $files = [];
        $records = explode("\0", $output);
        for ($i = 0, $count = count($records); $i < $count; $i++) {
            $record = $records[$i];

            if ($record === '') {
                continue;
            }

            if (str_starts_with($record, '# ')) {
                if (str_starts_with($record, '# branch.head ')) {
                    $branch = substr($record, 14);
                } elseif (str_starts_with($record, '# branch.oid ')) {
                    $oid = substr($record, 13);

                    if ($oid !== '(initial)' && preg_match('/\A[0-9a-f]{40,64}\z/D', $oid) !== 1) {
                        throw new RuntimeException('Git returned an invalid HEAD.');
                    }
                    $head = $oid === '(initial)' ? null : $oid;
                } elseif (str_starts_with($record, '# branch.upstream ')) {
                    $upstream = substr($record, 18);
                } elseif (preg_match('/\A# branch\.ab \+(\d+) -(\d+)\z/D', $record, $matches) === 1) {
                    $ahead = (int) $matches[1];
                    $behind = (int) $matches[2];
                }

                continue;
            }
            $kind = $record[0];
            $original = null;

            if ($kind === '?') {
                $path = substr($record, 2);
                $xy = '??';
            } else {
                $fields = match ($kind) {
                    '1' => 9,
                    '2' => 10,
                    'u' => 11,
                    default => throw new RuntimeException('Git returned an unsupported status record.'),
                };
                $parts = explode(' ', $record, $fields);

                if (count($parts) !== $fields || strlen($parts[1]) !== 2) {
                    throw new RuntimeException('Git returned an invalid status record.');
                }
                $xy = $parts[1];
                $path = $parts[$fields - 1];

                if ($kind === '2') {
                    $original = $records[++$i] ?? '';
                    $this->validatePath($original);
                }
            }
            $this->validatePath($path);
            $files[] = new GitFile(bin2hex($path), $path, $original, $xy[0], $xy[1], $kind === '?', $kind === 'u');

            if (count($files) > 2000) {
                throw new RuntimeException('Git has more than 2000 changed files. Reduce the number of changes to view this repository.');
            }
        }

        if ($branch === '') {
            throw new RuntimeException('Git returned no branch information.');
        }

        return new GitSnapshot($this->root, $branch, $head, $upstream, $ahead, $behind, $files, []);
    }

    /** @return list<GitCommit> */
    private function parseLog(string $output): array
    {
        $fields = explode("\0", $output);

        if (end($fields) === '') {
            array_pop($fields);
        }

        if (count($fields) % 5 !== 0) {
            throw new RuntimeException('Git returned invalid history.');
        }
        $commits = [];
        for ($i = 0, $count = count($fields); $i < $count; $i += 5) {
            if (preg_match('/\A[0-9a-f]{40,64}\z/D', $fields[$i]) !== 1) {
                throw new RuntimeException('Git returned an invalid commit.');
            }
            $commits[] = new GitCommit($fields[$i], $fields[$i + 1], $fields[$i + 2], $fields[$i + 3], $fields[$i + 4]);
        }

        return $commits;
    }

    /** @return list<string> */
    private function diffArguments(bool $staged): array
    {
        $file = $this->file ?? throw new RuntimeException('Git file was interrupted.');
        $paths = $file->originalPath === null ? [$file->path] : [$file->originalPath, $file->path];

        return ['diff', ...($staged ? ['--cached'] : []), '--no-color', '--no-ext-diff', '--no-textconv', '--no-relative', '--', ...$paths];
    }

    private function preview(): void
    {
        $file = $this->file ?? throw new RuntimeException('Git file was interrupted.');
        $path = $this->root.DIRECTORY_SEPARATOR.$file->path;
        $parent = realpath(dirname($path));

        if ($parent === false || ! $this->insideRoot($parent)) {
            throw new RuntimeException('The untracked file is outside the repository or no longer exists.');
        }
        $this->appendDetail("Untracked file\n\n");

        if (is_link($path)) {
            $target = readlink($path);

            if ($target === false) {
                throw new RuntimeException('Unable to read symbolic link.');
            }
            $this->appendDetail('+Symbolic link: '.$target."\n");

            return;
        }
        $resolved = realpath($path);

        if ($resolved === false || ! $this->insideRoot($resolved) || ! is_file($resolved)) {
            throw new RuntimeException('The untracked file is unavailable or is not a regular file.');
        }
        $stream = @fopen($resolved, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read untracked file.');
        }

        try {
            $content = fread($stream, self::DETAIL_LIMIT + 1);

            if ($content === false) {
                throw new RuntimeException('Unable to read untracked file.');
            }
        } finally {
            fclose($stream);
        }

        if (str_contains($content, "\0")) {
            $this->appendDetail("Binary file (content preview unavailable).\n");

            return;
        }
        $this->appendDetail('+'.str_replace("\n", "\n+", $content));
    }

    private function insideRoot(string $path): bool
    {
        $root = $this->root;

        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function validatePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('Git returned an invalid file path.');
        }
    }

    private function isDetail(): bool
    {
        return in_array($this->phase, ['staged', 'unstaged', 'commit'], true);
    }

    private function appendDetail(string $chunk): void
    {
        if ($this->truncated || $chunk === '') {
            return;
        }
        $remaining = self::DETAIL_LIMIT - strlen($this->detail);
        $this->detail .= substr($chunk, 0, max(0, $remaining));

        if (strlen($chunk) > $remaining) {
            $this->detail = substr($this->detail, 0, self::DETAIL_LIMIT - strlen(self::TRUNCATION)).self::TRUNCATION;
            $this->truncated = true;
        }
        $this->revision++;
    }

    private function finish(): void
    {
        $this->phase = '';
        $this->pending = null;
        $this->file = null;
        $this->revision++;
    }

    private function fail(string $message): void
    {
        $this->cancel();
        $this->error = $message;
        $this->revision++;
    }
}
