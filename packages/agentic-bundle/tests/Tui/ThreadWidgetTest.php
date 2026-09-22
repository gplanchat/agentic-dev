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
 * Ten rows: "top", eight rows of thread, "bottom".
 */
final class ThreadWidgetTest extends TestCase
{
    public function testTheScreenFillsTheTerminalWithTheFooterOnTheLastRow(): void
    {
        $lines = $this->render((new ThreadWidget())->setText('short'));

        self::assertCount(10, $lines);
        self::assertSame('bottom', $lines[9]);
    }

    public function testALongThreadKeepsItsEndAboveTheFooter(): void
    {
        $lines = $this->render($this->fifty());

        self::assertSame('line 50', $lines[8], 'The last line of the thread is right above the bottom.');
        self::assertSame('bottom', $lines[9]);
    }

    public function testScrollingUpShowsOlderLinesAndSaysHowManyAreBelow(): void
    {
        $thread = $this->fifty();
        $this->render($thread);

        $lines = $this->render($thread->scroll(5));

        self::assertSame('line 45', $lines[7], 'Five newer lines are hidden below the window.');
        self::assertStringStartsWith('▼ 5 newer lines below', $lines[8]);
        self::assertSame('bottom', $lines[9]);
    }

    public function testScrollingStopsAtTheFirstLineAndComesBackToTheBottom(): void
    {
        $thread = $this->fifty();
        $this->render($thread);

        $lines = $this->render($thread->scroll(1000));
        self::assertSame('line 1', $lines[1], 'One does not scroll past the start of the thread.');

        $lines = $this->render($thread->scrollToBottom());
        self::assertSame(0, $thread->offset());
        self::assertSame('line 50', $lines[8]);
    }

    public function testTheModelAnswerIsRenderedAsMarkdown(): void
    {
        $thread = (new ThreadWidget())->setEntries([
            ['› What weather?', false],
            ["It is **27°C**.\n\n- take a cap", true],
        ]);

        $lines = $this->render($thread);

        self::assertContains('It is 27°C.', $lines, 'The bold is formatted, not shown with its asterisks.');
        self::assertContains('• take a cap', $lines);
    }

    private function fifty(): ThreadWidget
    {
        return (new ThreadWidget())->setText(implode("\n", array_map(static fn (int $i): string => 'line '.$i, range(1, 50))));
    }

    /**
     * @return list<string>
     */
    private function render(ThreadWidget $thread): array
    {
        $terminal = new VirtualTerminal(60, 10);
        $tui = new Tui(terminal: $terminal);
        $tui->add(new TextWidget('top'))->add($thread)->add(new TextWidget('bottom'));
        $tui->start();
        $tui->tick();

        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", str_replace("\r", '', AnsiUtils::stripAnsiCodes($terminal->getOutput()))),
        );
        $tui->stop();
        // The thread belongs to a single screen at a time: we detach it for the next rendering.
        $tui->remove($thread);

        return $lines;
    }
}
