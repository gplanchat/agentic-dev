<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\FoldedDiff;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class FoldedDiffTest extends TestCase
{
    /**
     * What git wrote of a command's changes: each file named once, its hunks with their line numbers.
     */
    public function testAGitDiffNamesEachFileOnce(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/A.php b/src/A.php
            index 1111111..2222222 100644
            --- a/src/A.php
            +++ b/src/A.php
            @@ -1,2 +1,2 @@
             a
            -b
            +++ counter
            diff --git a/src/New.php b/src/New.php
            new file mode 100644
            index 0000000..3333333
            --- /dev/null
            +++ b/src/New.php
            @@ -0,0 +1 @@
            +new
            DIFF;

        [$text, $foldable] = FoldedDiff::ofUnified($diff, true);

        self::assertTrue($foldable);
        self::assertSame(implode("\n", [
            '    diff --git a/src/A.php b/src/A.php',
            '    @@ -1,2 +1,2 @@',
            '     a',
            '    -b',
            '    +++ counter',
            '    diff --git a/src/New.php b/src/New.php',
            '    new file mode 100644',
            '    @@ -0,0 +1 @@',
            '    +new',
            '    ▴ fold',
        ]), AnsiUtils::stripAnsiCodes($text), 'A line added as "++ counter" is content, not a header.');
        self::assertStringContainsString("\e[1mdiff --git a/src/A.php", $text, 'The file in bold.');
        self::assertStringContainsString("\e[36m@@ -1,2 +1,2 @@", $text, 'The hunk in cyan.');
    }

    public function testAShortEditIsShownWholeAndCannotFold(): void
    {
        [$text, $foldable] = FoldedDiff::ofEdit(['path' => 'src/A.php', 'old_string' => "a\nb", 'new_string' => "a\nc"], false);

        self::assertFalse($foldable);
        self::assertSame("    \e[2m a\e[0m\n    \e[31m-b\e[0m\n    \e[32m+c\e[0m", $text, 'Context dimmed, removed in red, added in green.');
    }

    public function testAsManyLinesAsTheFoldShowsDoNotFold(): void
    {
        [$text, $foldable] = FoldedDiff::ofEdit(['old_string' => '', 'new_string' => implode("\n", range(1, FoldedDiff::FOLDED_LINES))], false);

        self::assertFalse($foldable);
        self::assertStringNotContainsString('more line', $text);
    }

    /**
     * A file created: every line is new. Folded, the first ones and how many more there are.
     */
    public function testALongEditIsFoldedToItsFirstLines(): void
    {
        $arguments = ['path' => 'src/B.php', 'old_string' => '', 'new_string' => implode("\n", range(1, 10))];

        [$folded, $foldable] = FoldedDiff::ofEdit($arguments, false);
        self::assertTrue($foldable);
        self::assertSame("    +1\n    +2\n    +3\n    +4\n    +5\n    +6\n    ▸ 4 more lines — click to unfold", AnsiUtils::stripAnsiCodes($folded));

        [$unfolded] = FoldedDiff::ofEdit($arguments, true);
        self::assertStringContainsString("    +10\n    ▴ fold", AnsiUtils::stripAnsiCodes($unfolded));
    }

    public function testOneLineMoreSaysLine(): void
    {
        [$folded] = FoldedDiff::ofEdit(['old_string' => '', 'new_string' => implode("\n", range(1, 7))], false);

        self::assertStringEndsWith('▸ 1 more line — click to unfold', AnsiUtils::stripAnsiCodes($folded));
    }

    /**
     * Written by the model, printed on the terminal: its escape sequences are not.
     */
    public function testTheModelCannotStyleTheTerminal(): void
    {
        [$text] = FoldedDiff::ofEdit(['old_string' => '', 'new_string' => "\e[2Jcleared"], false);

        self::assertStringNotContainsString("\e[2J", $text);
        self::assertStringContainsString('+[2Jcleared', $text);
    }
}
