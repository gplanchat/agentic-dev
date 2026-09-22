<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

/**
 * La mascotte : la banane de « Peanut Butter Jelly Time », qui danse dans l'en-tête.
 *
 * Dessinée en pixels, deux par caractère : `▀` porte la couleur du haut au premier plan et celle du
 * bas au fond. Les grilles restent lisibles et modifiables ; la conversion en ANSI se fait ici.
 */
final class Banana
{
    /** @var array<string, array{int, int, int}> */
    private const PALETTE = [
        'Y' => [255, 214, 64],   // jaune
        'y' => [222, 170, 30],   // jaune ombré
        'B' => [110, 72, 30],    // brun : queue
        'K' => [25, 25, 25],     // noir : yeux, bouche
        'W' => [250, 250, 250],  // blanc : reflet des yeux
        'L' => [214, 128, 44],   // caramel : bras et jambes, visibles sur fond sombre
    ];

    /** Bras en l'air, puis en bas, jambes écartées : les deux temps de la danse. */
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
     * @return list<string> les lignes ANSI de l'image demandée ; l'animation boucle
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
