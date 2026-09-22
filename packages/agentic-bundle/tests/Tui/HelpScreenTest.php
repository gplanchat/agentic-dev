<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tui;

use Gplanchat\Agentic\Application\Help\CommandSummary;
use Gplanchat\AgenticBundle\Tui\HelpScreen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class HelpScreenTest extends TestCase
{
    public static function quitKeys(): \Generator
    {
        yield 'q' => ['q'];
        yield 'Échap' => ["\e"];
        yield 'Ctrl+C' => ["\x03"];
    }

    #[DataProvider('quitKeys')]
    public function testListsCommandsAndQuits(string $key): void
    {
        $terminal = new VirtualTerminal(80, 20);
        $tui = (new HelpScreen())->build([new CommandSummary('help', 'Display help for a command')], $terminal);

        $tui->start();
        $tui->tick();

        self::assertStringContainsString('Display help for a command', $terminal->getOutput());
        self::assertTrue($tui->isRunning());

        $terminal->simulateInput($key);
        $tui->tick();

        self::assertFalse($tui->isRunning());
    }
}
