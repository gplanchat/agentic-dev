<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\ChatView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class ChatViewTest extends KernelTestCase
{
    private VirtualTerminal $terminal;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAMessageTypedInTheTerminalGetsAnAnswer(): void
    {
        $view = $this->open();

        $this->type($view, "Quel temps fait-il à Paris ?\r");

        self::assertStringContainsString('Paris : 22°C, ensoleillé', $this->terminal->getOutput());
    }

    public function testAnApprovalIsGivenFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Envoie un mail à l’équipe\r");
        self::assertStringContainsString('Approuver', $this->terminal->getOutput());

        // Le premier choix est « Approuver ».
        $this->type($view, "\r");
        self::assertStringContainsString('Courriel envoyé à equipe@example.test.', $this->terminal->getOutput());
    }

    public function testAQuestionIsAnsweredFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Pose-moi une question\r");
        self::assertStringContainsString('Sur quoi veux-tu que je tranche ?', $this->terminal->getOutput());

        $this->type($view, "\r");
        self::assertStringContainsString('La météo', $this->terminal->consumeOutput());
    }

    public function testASlashCommandIsNotSentToTheAgent(): void
    {
        $view = $this->open();

        $this->type($view, "/tools\r");

        $output = $this->terminal->getOutput();
        self::assertMatchesRegularExpression('/weather\s+read\s+passe/', $output);
        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages);
    }

    public function testTabCompletesTheCommandName(): void
    {
        $view = $this->open();

        $this->type($view, "/to\t");

        self::assertStringContainsString('› /tools ', $this->terminal->getOutput());
    }

    public function testClearSwitchesTheScreenToANewConversation(): void
    {
        $view = $this->open();
        $first = $view->conversation;

        $this->type($view, "/clear\r");

        self::assertNotSame($first, $view->conversation);
        self::assertStringContainsString(substr($view->conversation, 0, 8), $this->terminal->getOutput());
    }

    public function testShiftTabRotatesTheModeShownAtTheBottom(): void
    {
        $view = $this->open();
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertStringContainsString('● mode standard', $this->terminal->consumeOutput());

        foreach (['edition', 'auto', 'standard'] as $expected) {
            // Une séquence d'échappement arrive d'un bloc, pas caractère par caractère.
            $this->terminal->simulateInput("\e[Z");
            $view->refresh();
            $view->tui->tick();

            self::assertSame($expected, $conversations->transcript($view->conversation)->mode->value);
            self::assertStringContainsString('● mode '.$expected, $this->terminal->consumeOutput());
        }
    }

    public function testTheBananaDancesInTheHeader(): void
    {
        $view = $this->open();
        $before = $this->terminal->consumeOutput();
        self::assertStringContainsString('Agentic', AnsiUtils::stripAnsiCodes($before));

        $view->dance();
        $view->tui->tick();

        self::assertNotSame('', $this->terminal->consumeOutput(), 'Un temps de danse redessine l’en-tête.');
    }

    public function testCtrlCQuitsWithoutClosingTheConversation(): void
    {
        $view = $this->open();

        $this->type($view, "\x03");

        self::assertFalse($view->tui->isRunning());
        self::assertFalse(self::getContainer()->get(Conversations::class)->transcript($view->conversation)->finished);
    }

    private function open(): ChatView
    {
        $this->terminal = new VirtualTerminal(100, 30);
        $container = self::getContainer();
        $view = $container->get(ChatScreen::class)->open($container->get(Conversations::class)->start(), $this->terminal);
        $view->tui->start();
        $view->refresh();
        $view->tui->tick();

        return $view;
    }

    private function type(ChatView $view, string $keys): void
    {
        foreach (mb_str_split($keys) as $key) {
            $this->terminal->simulateInput($key);
        }
        $view->refresh();
        $view->tui->tick();
    }
}
