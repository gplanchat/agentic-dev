<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\EditDiff;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class EditDiffTest extends TestCase
{
    public function testAShortEditIsShownWholeAndCannotFold(): void
    {
        [$text, $foldable] = EditDiff::render(['path' => 'src/A.php', 'old_string' => "a\nb", 'new_string' => "a\nc"], false);

        self::assertFalse($foldable);
        self::assertSame("    \e[2m a\e[0m\n    \e[31m-b\e[0m\n    \e[32m+c\e[0m", $text, 'Context dimmed, removed in red, added in green.');
    }

    public function testAsManyLinesAsTheFoldShowsDoNotFold(): void
    {
        [$text, $foldable] = EditDiff::render(['old_string' => '', 'new_string' => implode("\n", range(1, EditDiff::FOLDED_LINES))], false);

        self::assertFalse($foldable);
        self::assertStringNotContainsString('more line', $text);
    }

    /**
     * A file created: every line is new. Folded, the first ones and how many more there are.
     */
    public function testALongEditIsFoldedToItsFirstLines(): void
    {
        $arguments = ['path' => 'src/B.php', 'old_string' => '', 'new_string' => implode("\n", range(1, 10))];

        [$folded, $foldable] = EditDiff::render($arguments, false);
        self::assertTrue($foldable);
        self::assertSame("    +1\n    +2\n    +3\n    +4\n    +5\n    +6\n    ▸ 4 more lines — click to unfold", AnsiUtils::stripAnsiCodes($folded));

        [$unfolded] = EditDiff::render($arguments, true);
        self::assertStringContainsString("    +10\n    ▴ fold", AnsiUtils::stripAnsiCodes($unfolded));
    }

    public function testOneLineMoreSaysLine(): void
    {
        [$folded] = EditDiff::render(['old_string' => '', 'new_string' => implode("\n", range(1, 7))], false);

        self::assertStringEndsWith('▸ 1 more line — click to unfold', AnsiUtils::stripAnsiCodes($folded));
    }

    /**
     * Written by the model, printed on the terminal: its escape sequences are not.
     */
    public function testTheModelCannotStyleTheTerminal(): void
    {
        [$text] = EditDiff::render(['old_string' => '', 'new_string' => "\e[2Jcleared"], false);

        self::assertStringNotContainsString("\e[2J", $text);
        self::assertStringContainsString('+[2Jcleared', $text);
    }
}
