<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * A change to files, as the thread shows it under the call that made it: folded to its first
 * lines, a click unfolds the rest.
 *
 * Two sources: an `edit_file` call — the diff of its own arguments, `old_string` against
 * `new_string`, what the agent did whatever the file became since —, or the unified diff git wrote
 * of what a command changed.
 */
final class FoldedDiff
{
    /** The lines shown while folded. */
    public const FOLDED_LINES = 6;

    /**
     * @param array<string, mixed> $arguments the arguments of the `edit_file` call
     *
     * @return array{string, bool} the styled lines, and whether there is more than the folded view
     */
    public static function ofEdit(array $arguments, bool $unfolded): array
    {
        $diff = (new Differ(new UnifiedDiffOutputBuilder('')))->diff(
            self::text($arguments['old_string'] ?? ''),
            self::text($arguments['new_string'] ?? ''),
        );

        return self::fold(array_values(array_filter(
            explode("\n", rtrim($diff, "\n")),
            static fn (string $line): bool => !str_starts_with($line, '@@'),
        )), $unfolded);
    }

    /**
     * @param string $diff a unified diff, as `git diff` writes it
     *
     * @return array{string, bool} the styled lines, and whether there is more than the folded view
     */
    public static function ofUnified(string $diff, bool $unfolded): array
    {
        // The file names are on the `diff --git` line: in the header of a file, `index`, `---` and
        // `+++` say them again. Past its first hunk, a `+++` is an added line starting with `++`.
        $lines = [];
        $header = false;
        foreach (explode("\n", rtrim(self::text($diff), "\n")) as $line) {
            $header = str_starts_with($line, 'diff ') || ($header && !str_starts_with($line, '@@'));
            if (!$header || !preg_match('/^(index |--- |\+\+\+ )/', $line)) {
                $lines[] = $line;
            }
        }

        return self::fold($lines, $unfolded);
    }

    /**
     * @param list<string> $lines
     *
     * @return array{string, bool}
     */
    private static function fold(array $lines, bool $unfolded): array
    {
        $foldable = \count($lines) > self::FOLDED_LINES;

        $shown = array_map(
            static fn (string $line): string => '    '.match (true) {
                str_starts_with($line, 'diff ') => "\e[1m".$line,
                str_starts_with($line, '@@') => "\e[36m".$line,
                str_starts_with($line, '+') => "\e[32m".$line,
                str_starts_with($line, '-') => "\e[31m".$line,
                default => "\e[2m".$line,
            }."\e[0m",
            $foldable && !$unfolded ? \array_slice($lines, 0, self::FOLDED_LINES) : $lines,
        );
        if ($foldable) {
            $shown[] = $unfolded
                ? "    \e[2;4m▴ fold\e[0m"
                : \sprintf("    \e[2;4m▸ %d more line%s — click to unfold\e[0m", \count($lines) - self::FOLDED_LINES, \count($lines) - self::FOLDED_LINES > 1 ? 's' : '');
        }

        return [implode("\n", $shown), $foldable];
    }

    /**
     * Written by the model, or by the files of the workspace: no escape sequence of its own reaches
     * the terminal.
     */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($value)) : '';
    }
}
