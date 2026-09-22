<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * The mascot: the banana of "Peanut Butter Jelly Time", dancing in the header.
 *
 * Drawn in pixels, two per character: `▀` carries the top colour in the foreground and the bottom
 * one in the background. The grids stay readable and editable; the conversion to ANSI happens here.
 */
final class Banana
{
    /** @var array<string, array{int, int, int}> */
    private const PALETTE = [
        'Y' => [255, 214, 64],   // yellow
        'y' => [222, 170, 30],   // shaded yellow
        'B' => [110, 72, 30],    // brown: stalk
        'K' => [25, 25, 25],     // black: eyes, mouth
        'W' => [250, 250, 250],  // white: glint of the eyes
        'L' => [214, 128, 44],   // caramel: arms and legs, visible on a dark background
    ];

    /** Arms up, then down, legs apart: the two beats of the dance. */
    private const FRAMES = [
        <<<'GRID'
            .........BB...
            ........YY....
            .......YYy....
            ......YYYy....
            ..L..YYYYy.L..
            ...L.YWKWKL...
            ....LYKKYy....
            .....YYYYy....
            .....YYYy.....
            ......YYy.....
            ......L.L.....
            .....LL.LL....
            GRID,
        <<<'GRID'
            .........BB...
            ........YY....
            .......YYy....
            ......YYYy....
            .....YYYYy....
            .....YWKWKy...
            .....YKKYyL...
            ....LYYYYy.L..
            ...L.YYYy.....
            ......YYy.....
            .....L..L.....
            ....LL..LL....
            GRID,
    ];

    /** @var list<list<string>>|null */
    private static ?array $rendered = null;

    /**
     * @return list<string> the ANSI lines of the requested frame; the animation loops
     */
    public static function frame(int $index): array
    {
        self::$rendered ??= array_map(self::render(...), self::FRAMES);

        return self::$rendered[$index % \count(self::$rendered)];
    }

    public static function width(): int
    {
        return \strlen(explode("\n", self::FRAMES[0])[0]);
    }

    public static function height(): int
    {
        return intdiv(substr_count(self::FRAMES[0], "\n") + 2, 2);
    }

    /**
     * @return list<string>
     */
    private static function render(string $grid): array
    {
        $rows = explode("\n", $grid);
        if (1 === \count($rows) % 2) {
            $rows[] = str_repeat('.', \strlen($rows[0]));
        }

        $lines = [];
        for ($y = 0; $y < \count($rows); $y += 2) {
            $line = '';
            for ($x = 0; $x < \strlen($rows[$y]); ++$x) {
                $line .= self::cell(self::PALETTE[$rows[$y][$x]] ?? null, self::PALETTE[$rows[$y + 1][$x]] ?? null);
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param array{int, int, int}|null $top
     * @param array{int, int, int}|null $bottom
     */
    private static function cell(?array $top, ?array $bottom): string
    {
        return match (true) {
            null === $top && null === $bottom => ' ',
            null === $bottom => \sprintf("\e[38;2;%d;%d;%dm▀\e[0m", ...$top),
            null === $top => \sprintf("\e[38;2;%d;%d;%dm▄\e[0m", ...$bottom),
            default => \sprintf("\e[38;2;%d;%d;%d;48;2;%d;%d;%dm▀\e[0m", ...$top, ...$bottom),
        };
    }
}
