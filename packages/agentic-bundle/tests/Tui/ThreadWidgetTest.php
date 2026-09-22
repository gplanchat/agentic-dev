<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\AgenticBundle\Tui\ThreadWidget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Dix rangées : « haut », huit rangées de fil, « bas ».
 */
final class ThreadWidgetTest extends TestCase
{
    public function testTheScreenFillsTheTerminalWithTheFooterOnTheLastRow(): void
    {
        $lines = $this->render((new ThreadWidget())->setText('court'));

        self::assertCount(10, $lines);
        self::assertSame('bas', $lines[9]);
    }

    public function testALongThreadKeepsItsEndAboveTheFooter(): void
    {
        $lines = $this->render($this->fifty());

        self::assertSame('ligne 50', $lines[8], 'La dernière ligne du fil est juste au-dessus du bas.');
        self::assertSame('bas', $lines[9]);
    }

    public function testScrollingUpShowsOlderLinesAndSaysHowManyAreBelow(): void
    {
        $thread = $this->fifty();
        $this->render($thread);

        $lines = $this->render($thread->scroll(5));

        self::assertSame('ligne 45', $lines[7], 'Cinq lignes plus récentes sont cachées sous la fenêtre.');
        self::assertStringStartsWith('▼ 5 lignes plus récentes', $lines[8]);
        self::assertSame('bas', $lines[9]);
    }

    public function testScrollingStopsAtTheFirstLineAndComesBackToTheBottom(): void
    {
        $thread = $this->fifty();
        $this->render($thread);

        $lines = $this->render($thread->scroll(1000));
        self::assertSame('ligne 1', $lines[1], 'On ne remonte pas au-delà du début du fil.');

        $lines = $this->render($thread->scrollToBottom());
        self::assertSame(0, $thread->offset());
        self::assertSame('ligne 50', $lines[8]);
    }

    private function fifty(): ThreadWidget
    {
        return (new ThreadWidget())->setText(implode("\n", array_map(static fn (int $i): string => 'ligne '.$i, range(1, 50))));
    }

    /**
     * @return list<string>
     */
    private function render(ThreadWidget $thread): array
    {
        $terminal = new VirtualTerminal(60, 10);
        $tui = new Tui(terminal: $terminal);
        $tui->add(new TextWidget('haut'))->add($thread)->add(new TextWidget('bas'));
        $tui->start();
        $tui->tick();

        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", str_replace("\r", '', AnsiUtils::stripAnsiCodes($terminal->getOutput()))),
        );
        $tui->stop();
        // Le fil n'appartient qu'à un écran à la fois : on le détache pour le rendu suivant.
        $tui->remove($thread);

        return $lines;
    }
}
