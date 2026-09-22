<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Tui\BananaWords;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\ChatView;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
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

        $this->type($view, "What is the weather in Paris?\r");

        self::assertStringContainsString('Paris: 22°C, sunny', $this->terminal->getOutput());
    }

    public function testAnApprovalIsGivenFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Send an email to the team\r");
        self::assertStringContainsString('Approve', $this->terminal->getOutput());

        // The first choice is "Approve".
        $this->type($view, "\r");
        // The Markdown rendering turns the address into a link: the text stays, what wraps it does not.
        self::assertStringContainsString('Email sent to team@example.test', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
    }

    public function testAQuestionIsAnsweredFromTheList(): void
    {
        $view = $this->open();

        $this->type($view, "Ask me a question\r");
        self::assertStringContainsString('What do you want me to decide on?', $this->terminal->getOutput());

        $this->type($view, "\r");
        self::assertStringContainsString('The weather', $this->terminal->consumeOutput());
    }

    public function testASlashCommandIsNotSentToTheAgent(): void
    {
        $view = $this->open();

        $this->type($view, "/tools\r");

        $output = $this->terminal->getOutput();
        self::assertMatchesRegularExpression('/weather\s+read\s+passes/', $output);
        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages);
    }

    /**
     * Said inside the screen, not before: the alternate screen would erase whatever came first.
     */
    public function testAnUnavailableSandboxIsAnnouncedOnOpening(): void
    {
        $this->terminal = new VirtualTerminal(100, 30);
        $container = self::getContainer();
        $screen = new ChatScreen($container->get(Conversations::class), $container->get(InProcessWorker::class), new Bubblewrap(__DIR__, binary: 'bwrap-not-found'));
        $view = $screen->open($container->get(Conversations::class)->start(), $this->terminal);
        $view->tui->start();
        $view->refresh();
        $view->tui->tick();

        self::assertStringContainsString('apt install bubblewrap', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
    }

    /**
     * An armed watch shows what it waits for and what the agent will do on waking.
     */
    public function testAnArmedWatchShowsItsIntent(): void
    {
        $view = $this->open();

        $this->type($view, "Watch the delivery\r");

        self::assertStringContainsString('What the agent will do:', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
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
            // An escape sequence arrives in one block, not character by character.
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

        self::assertNotSame('', $this->terminal->consumeOutput(), 'One beat of the dance redraws the header.');
    }

    public function testTheWheelScrollsTheThreadNotTheTerminal(): void
    {
        $view = $this->open();
        foreach (['Paris', 'Lyon', 'Marseille', 'Paris', 'Lyon', 'Marseille'] as $city) {
            $this->type($view, "What is the weather in $city?\r");
        }
        $this->terminal->consumeOutput();

        $this->key($view, "\e[<64;10;10M");
        self::assertStringContainsString('newer lines below', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));

        $this->key($view, "\e[<65;10;10M");
        self::assertStringNotContainsString('newer line', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    public function testAClickNeverReachesTheInput(): void
    {
        $view = $this->open();

        $this->key($view, "\e[<0;12;3M");
        $this->type($view, "\r");

        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages);
    }

    public function testPageUpScrollsToo(): void
    {
        $view = $this->open();
        foreach (['Paris', 'Lyon', 'Marseille', 'Paris', 'Lyon', 'Marseille'] as $city) {
            $this->type($view, "What is the weather in $city?\r");
        }
        $this->terminal->consumeOutput();

        $this->key($view, "\e[5~");

        self::assertStringContainsString('newer lines below', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    /**
     * Like a shell: ↑ goes up, ↓ comes back down, and you find again what you were typing.
     */
    public function testArrowsRecallPreviousMessagesAndKeepTheDraft(): void
    {
        $view = $this->open();
        $this->type($view, "What is the weather in Paris?\r");
        $this->type($view, "/tools\r");
        $this->type($view, 'draft');

        $this->key($view, "\e[A");
        self::assertStringContainsString('› /tools', $this->terminal->consumeOutput());

        $this->key($view, "\e[A");
        self::assertStringContainsString('› What is the weather in Paris?', $this->terminal->consumeOutput());

        $this->key($view, "\e[B");
        $this->key($view, "\e[B");
        self::assertStringContainsString('› draft', $this->terminal->consumeOutput());

        // Recalling sends nothing: only Enter sends.
        self::assertCount(1, array_filter(
            self::getContainer()->get(Conversations::class)->transcript($view->conversation)->messages,
            static fn ($message): bool => $message->isUser(),
        ));
    }

    /**
     * The message shows before the worker has handled it: the human knows it has left, and the
     * banana says it is getting to it.
     */
    public function testASentMessageShowsAtOnceBeforeTheAgentAnswers(): void
    {
        $view = $this->open();

        foreach (mb_str_split("What is the weather in Paris?\r") as $key) {
            $this->terminal->simulateInput($key);
        }
        $view->tui->tick();

        $shown = AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput());
        self::assertStringContainsString('› What is the weather in Paris?  ✓ sent', $shown);
        self::assertMatchesRegularExpression('/('.implode('|', array_map('preg_quote', BananaWords::PHRASES)).')… \(\d+ s\)/u', $shown);
        self::assertStringNotContainsString('22°C', $shown, 'The worker has not run yet.');

        $view->refresh();
        $view->tui->tick();

        $shown = AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput());
        self::assertStringContainsString('Paris: 22°C, sunny', $shown);
        self::assertStringNotContainsString('✓ sent', $shown, 'Once in the journal, the message is no longer "on the way".');
    }

    public function testAnApprovalIsConfirmedOnScreen(): void
    {
        $view = $this->open();
        $this->type($view, "Send an email to the team\r");

        $this->type($view, "\r");

        self::assertStringContainsString('✓ Approved — send_email', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
    }

    public function testRewindOffersAListAndPutsTheMessageBackInTheInput(): void
    {
        $view = $this->open();
        $first = $view->conversation;
        $this->type($view, "What is the weather in Paris?\r");

        $this->type($view, "/rewind\r");
        self::assertMatchesRegularExpression('/#1\s+What is the weather in Paris\?/u', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));

        $this->type($view, "\r");

        self::assertNotSame($first, $view->conversation);
        self::assertStringContainsString('› What is the weather in Paris?', AnsiUtils::stripAnsiCodes($this->terminal->getOutput()));
        self::assertSame([], self::getContainer()->get(Conversations::class)->transcript($view->conversation)->userMessages(), 'Nothing is sent again until Enter is pressed.');
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

    /**
     * An escape sequence arrives in one block, not character by character.
     */
    private function key(ChatView $view, string $sequence): void
    {
        $this->terminal->simulateInput($sequence);
        $view->refresh();
        $view->tui->tick();
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
