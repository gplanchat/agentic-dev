<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * What an `edit_file` call changed, as a diff of its own arguments — `old_string` against
 * `new_string`: what the agent did, not a reread of the file, which may have moved on since.
 *
 * Folded, the first lines only; a click on it unfolds the rest.
 */
final class EditDiff
{
    /** The lines shown while folded. */
    public const FOLDED_LINES = 6;

    /**
     * @param array<string, mixed> $arguments the arguments of the call
     *
     * @return array{string, bool} the styled lines, and whether there is more than the folded view
     */
    public static function render(array $arguments, bool $unfolded): array
    {
        $diff = (new Differ(new UnifiedDiffOutputBuilder('')))->diff(
            self::text($arguments['old_string'] ?? ''),
            self::text($arguments['new_string'] ?? ''),
        );
        $lines = array_values(array_filter(
            explode("\n", rtrim($diff, "\n")),
            static fn (string $line): bool => !str_starts_with($line, '@@'),
        ));
        $foldable = \count($lines) > self::FOLDED_LINES;

        $shown = array_map(
            static fn (string $line): string => '    '.match ($line[0] ?? ' ') {
                '+' => "\e[32m".$line,
                '-' => "\e[31m".$line,
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
     * Written by the model: no escape sequence of its own reaches the terminal.
     */
    private static function text(mixed $value): string
    {
        return \is_string($value) ? StringUtils::stripControlBytes(StringUtils::sanitizeUtf8($value)) : '';
    }
}
