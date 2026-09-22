<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\ThreadWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

final class ThreadWidgetTest extends TestCase
{
    public function testTheScreenFillsTheTerminalWithTheFooterOnTheLastRow(): void
    {
        $lines = $this->render("court", rows: 10);

        self::assertCount(10, $lines);
        self::assertSame('bas', $lines[9]);
    }

    public function testALongThreadKeepsItsEndAboveTheFooter(): void
    {
        $lines = $this->render(implode("\n", array_map(static fn (int $i): string => 'ligne '.$i, range(1, 50))), rows: 10);

        self::assertCount(10, $lines);
        self::assertSame('ligne 50', $lines[8], 'La dernière ligne du fil est juste au-dessus du bas.');
        self::assertSame('bas', $lines[9]);
    }

    /**
     * @return list<string>
     */
    private function render(string $thread, int $rows): array
    {
        $terminal = new VirtualTerminal(40, $rows);
        $tui = new Tui(terminal: $terminal);
        $tui->add(new TextWidget('haut'))->add((new ThreadWidget())->setText($thread))->add(new TextWidget('bas'));
        $tui->start();
        $tui->tick();

        return array_map(
            static fn (string $line): string => rtrim(AnsiUtils::stripAnsiCodes($line)),
            explode("\n", str_replace("\r", '', AnsiUtils::stripAnsiCodes($terminal->getOutput()))),
        );
    }
}
