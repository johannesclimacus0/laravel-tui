<?php

declare(strict_types=1);

namespace LaravelTui\LaravelTui\UI;

use LaravelTui\LaravelTui\Art\ArtRepository;

class Miku
{
    private const array DOTS = [[0, 3], [1, 4], [2, 5], [6, 7]];

    /** @var array<string, int> */
    private array $widths = [];

    public function __construct(private ArtRepository $arts) {}

    /** @return list<string> */
    public function names(): array
    {
        return $this->arts->names();
    }

    public function maxWidth(int $columns, int $rows): int
    {
        $key = $columns.'x'.$rows;

        if (! isset($this->widths[$key])) {
            $width = 0;
            foreach ($this->names() as $name) {
                foreach (explode("\n", $this->render($name, $columns, $rows)) as $line) {
                    $width = max($width, mb_strlen($line));
                }
            }
            $this->widths[$key] = $width;
        }

        return $this->widths[$key];
    }

    public function render(string $artName, int $columns, int $rows): string
    {
        if ($columns < 14 || $rows < 7) {
            return '';
        }
        $lines = $this->arts->lines($artName);
        $width = max(array_map(mb_strlen(...), $lines));
        $height = count($lines);
        $scale = min(1, $columns / $width, $rows / $height);

        if ($scale >= 1) {
            return implode("\n", $lines);
        }
        $outWidth = max(1, (int) floor($width * $scale));
        $outHeight = max(1, (int) floor($height * $scale));
        $cells = array_map(static fn (string $line): array => array_map(
            static fn (string $char): int => max(0, mb_ord($char) - 0x2800),
            mb_str_split($line),
        ), $lines);
        $output = [];
        for ($y = 0; $y < $outHeight; $y++) {
            $line = '';
            for ($x = 0; $x < $outWidth; $x++) {
                $mask = 0;
                foreach (self::DOTS as $dy => $bits) {
                    foreach ($bits as $dx => $bit) {
                        $sourceX = (int) floor(($x * 2 + $dx + 0.5) * $width / $outWidth);
                        $sourceY = (int) floor(($y * 4 + $dy + 0.5) * $height / $outHeight);
                        $sourceMask = $cells[intdiv($sourceY, 4)][intdiv($sourceX, 2)] ?? 0;

                        if ($sourceMask & (1 << self::DOTS[$sourceY % 4][$sourceX % 2])) {
                            $mask |= 1 << $bit;
                        }
                    }
                }
                $line .= mb_chr(0x2800 + $mask);
            }
            $output[] = $line;
        }

        return implode("\n", $output);
    }

    public function colored(string $artName, int $columns, int $rows): string
    {
        $picture = $this->render($artName, $columns, $rows);

        if ($picture === '') {
            return $picture;
        }
        $original = $this->arts->lines($artName);
        $sourceWidth = max(array_map(mb_strlen(...), $original));
        $sourceHeight = count($original);
        $colors = $this->arts->colors($artName);

        if ($colors === null) {
            return $picture;
        }
        $lines = explode("\n", $picture);
        $width = max(array_map(mb_strlen(...), $lines));
        $height = count($lines);
        $cells = array_map(static fn (string $line): array => array_map(
            static fn (string $char): int => max(0, mb_ord($char) - 0x2800),
            mb_str_split($line),
        ), $original);
        foreach ($lines as $y => &$line) {
            $output = '';
            $previous = null;
            foreach (mb_str_split($line) as $x => $char) {
                $votes = [];
                foreach (self::DOTS as $dy => $bits) {
                    foreach ($bits as $dx => $bit) {
                        $sx = (int) floor(($x * 2 + $dx + 0.5) * $sourceWidth / $width);
                        $sy = (int) floor(($y * 4 + $dy + 0.5) * $sourceHeight / $height);
                        $mask = $cells[intdiv($sy, 4)][intdiv($sx, 2)] ?? 0;

                        if ($mask & (1 << self::DOTS[$sy % 4][$sx % 2])) {
                            $color = $colors[intdiv($sy, 4)][intdiv($sx, 2)] ?? null;

                            if ($color !== null) {
                                $votes[$color] = ($votes[$color] ?? 0) + 1;
                            }
                        }
                    }
                }
                arsort($votes);
                $color = array_key_first($votes);

                if ($color !== $previous) {
                    $output .= $color === null ? "\033[39m" : "\033[38;2;{$color}m";
                    $previous = $color;
                }
                $output .= $char;
            }
            $line = $output."\033[39m";
        }
        unset($line);

        return implode("\n", $lines);
    }
}
