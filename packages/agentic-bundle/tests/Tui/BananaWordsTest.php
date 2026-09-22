<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\BananaWords;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class BananaWordsTest extends TestCase
{
    public function testThePhraseTurnsAndTheClockRuns(): void
    {
        $words = new BananaWords(seed: 0);

        self::assertSame('⠋ '.BananaWords::PHRASES[0].'… (0 s)', AnsiUtils::stripAnsiCodes($words->line(0, 0.4)));
        self::assertSame('⠙ '.BananaWords::PHRASES[0].'… (1 s)', AnsiUtils::stripAnsiCodes($words->line(1, 1.2)));
        self::assertStringContainsString(BananaWords::PHRASES[1], $words->line(8, 3.2), 'Au bout de huit temps, phrase suivante.');
    }
}
