<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\Art;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use RuntimeException;

class ArtRepository
{
    /** @var array<string, non-empty-list<string>> */
    private array $originals = [];

    /** @var array{palette: array<string, string>, grids: array<string, list<string>>}|null */
    private ?array $colorMap = null;

    public function __construct(
        private string $bundledDirectory,
        private string $userDirectory,
        private Filesystem $files,
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        $names = [];
        foreach ([$this->bundledDirectory, $this->userDirectory] as $directory) {
            foreach (glob($directory.'/art/*.txt') ?: [] as $file) {
                $name = basename($file, '.txt');

                if (is_readable($file) && preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}._-]*\z/u', $name) === 1) {
                    $names[] = $name;
                }
            }
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /** @return non-empty-list<string> */
    public function lines(string $name): array
    {
        if (! in_array($name, $this->names(), true)) {
            throw new InvalidArgumentException('Unknown art: '.$name);
        }

        if (! isset($this->originals[$name])) {
            $path = $this->userDirectory.'/art/'.$name.'.txt';

            if (! is_file($path)) {
                $path = $this->bundledDirectory.'/art/'.$name.'.txt';
            }
            $text = $this->files->get($path);

            if (strlen($text) > 1048576 || preg_match('/\A[\x{2800}-\x{28ff} \r\n]+\z/u', $text) !== 1) {
                throw new RuntimeException('Art must contain UTF-8 braille characters: '.$name);
            }
            $lines = array_values(array_filter(
                explode("\n", str_replace("\r", '', $text)),
                static fn (string $line): bool => preg_match('/[^ ⠀]/u', $line) === 1,
            ));

            if ($lines === [] || count($lines) > 512 || max(array_map(mb_strlen(...), $lines)) > 512) {
                throw new RuntimeException('Art must contain 1–512 rows and at most 512 columns: '.$name);
            }
            $indent = min(array_map(static function (string $line): int {
                preg_match('/^[ ⠀]*/u', $line, $match);

                return mb_strlen($match[0] ?? '');
            }, $lines));
            $this->originals[$name] = array_map(
                static fn (string $line): string => preg_replace('/[ ⠀]+$/u', '', mb_substr($line, $indent)) ?? '',
                $lines,
            );
        }

        return $this->originals[$name];
    }

    public function hash(string $name): string
    {
        return hash('sha256', implode("\n", $this->lines($name)));
    }

    /** @return array{palette: array<string, string>, grids: array<string, list<string>>} */
    public function colorMap(): array
    {
        if ($this->colorMap !== null) {
            return $this->colorMap;
        }
        $bundled = $this->readJson($this->bundledDirectory.'/colors.json');
        $palette = $bundled['palette'];
        $grids = $bundled['grids'];
        $path = $this->userDirectory.'/colors.json';

        if (is_file($path)) {
            $custom = $this->validate($this->readJson($path));
            $palette = array_replace($palette, $custom['palette']);
            $grids = array_replace($grids, $custom['grids']);
        }

        foreach ($grids as $name => $grid) {
            $name = (string) $name;

            if (is_file($this->userDirectory.'/art/'.$name.'.txt') && ! isset($custom['grids'][$name]) && ($bundled['sources'][$name] ?? null) !== $this->hash($name)) {
                unset($grids[$name]);

                continue;
            }
            $lines = $this->lines($name);
            $width = max(array_map(mb_strlen(...), $lines));

            if (count($grid) !== count($lines) || array_filter($grid, static fn (string $row): bool => strlen($row) !== $width) !== []) {
                throw new RuntimeException('Color grid no longer matches art: '.$name);
            }
        }

        return $this->colorMap = ['palette' => $palette, 'grids' => $grids];
    }

    /** @return list<list<string|null>>|null */
    public function colors(string $name): ?array
    {
        $map = $this->colorMap();
        $grid = $map['grids'][$name] ?? null;

        if ($grid === null) {
            return null;
        }
        $colors = [];
        foreach ($grid as $row) {
            $line = [];
            foreach (str_split($row) as $x => $key) {
                $hex = $map['palette'][$key] ?? null;
                $line[] = $hex === null ? null : implode(';', [
                    hexdec(substr($hex, 1, 2)),
                    hexdec(substr($hex, 3, 2)),
                    hexdec(substr($hex, 5, 2)),
                ]);
            }
            $colors[] = $line;
        }

        return $colors;
    }

    /** @return array<string, mixed> */
    public function editorData(): array
    {
        $map = $this->colorMap();
        $arts = [];
        $errors = [];
        foreach ($this->names() as $name) {
            try {
                $lines = $this->lines($name);
            } catch (RuntimeException $error) {
                $errors[] = $error->getMessage();

                continue;
            }
            $arts[] = [
                'name' => $name,
                'width' => max(array_map(mb_strlen(...), $lines)),
                'height' => count($lines),
                'lines' => $lines,
                'hash' => $this->hash($name),
                'grid' => $map['grids'][$name] ?? null,
            ];
        }

        return ['palette' => (object) $map['palette'], 'arts' => $arts, 'errors' => $errors, 'workspace' => hash('sha256', $this->userDirectory)];
    }

    /** @param array<string, mixed> $document */
    public function save(array $document): void
    {
        $document = $this->validate($document);
        $document['palette'] = (object) $document['palette'];
        $document['grids'] = (object) $document['grids'];
        $document['sources'] = (object) $document['sources'];
        $this->files->ensureDirectoryExists($this->userDirectory);
        $this->files->replace(
            $this->userDirectory.'/colors.json',
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
        );
        $this->colorMap = null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{version: int, palette: array<string, string>, grids: array<string, list<string>>, sources: array<string, string>}
     */
    private function validate(array $document): array
    {
        if (($document['version'] ?? null) !== 1 || ! is_array($document['palette'] ?? null) || ! is_array($document['grids'] ?? null) || ! is_array($document['sources'] ?? null)) {
            throw new InvalidArgumentException('Expected version 1, palette, grids and source hashes.');
        }

        if (count($document['palette']) < 1 || count($document['palette']) > 32) {
            throw new InvalidArgumentException('Palette must contain 1 to 32 colors.');
        }
        $palette = [];
        foreach ($document['palette'] as $key => $hex) {
            if (preg_match('/\A[a-zA-Z0-9]\z/', (string) $key) !== 1 || ! is_string($hex) || preg_match('/\A#[a-fA-F0-9]{6}\z/', $hex) !== 1) {
                throw new InvalidArgumentException('Colors require a letter or digit and a #RRGGBB value.');
            }
            $palette[$key] = strtoupper($hex);
        }
        $grids = [];
        $sources = [];
        foreach ($document['grids'] as $name => $grid) {
            $name = (string) $name;
            $lines = $this->lines($name);

            if (($document['sources'][$name] ?? null) !== $this->hash($name)) {
                throw new InvalidArgumentException('Source changed; reload the editor before coloring: '.$name);
            }
            $width = max(array_map(mb_strlen(...), $lines));

            if (! is_array($grid) || ! array_is_list($grid) || count($grid) !== count($lines)) {
                throw new InvalidArgumentException('Wrong color grid height: '.$name);
            }
            foreach ($grid as $row) {
                if (! is_string($row) || strlen($row) !== $width) {
                    throw new InvalidArgumentException('Wrong color grid width: '.$name);
                }
                foreach (str_split($row) as $key) {
                    if ($key !== '.' && ! isset($palette[$key])) {
                        throw new InvalidArgumentException('Unknown color in grid: '.$name);
                    }
                }
            }
            $grids[$name] = $grid;
            $sources[$name] = $this->hash($name);
        }

        return ['version' => 1, 'palette' => $palette, 'grids' => $grids, 'sources' => $sources];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $document = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($document)) {
            throw new InvalidArgumentException('Expected a JSON object: '.$path);
        }

        return $document;
    }
}
