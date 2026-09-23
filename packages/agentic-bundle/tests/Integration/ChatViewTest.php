<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\ToolStep;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use Gplanchat\AgenticBundle\Tui\BananaWords;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\ChatView;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
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

    /**
     * The arrows walk the open suggestions instead of the history, the pick is visible, and Tab
     * takes the pick rather than the first match.
     */
    public function testArrowsPickASuggestionAndTabTakesIt(): void
    {
        $view = $this->open();
        $this->type($view, '/m');
        $listed = $this->terminal->consumeOutput();
        // Everything dim and nothing carets: before an arrow, no suggestion is picked.
        self::assertStringContainsString("\e[2m  /mode", $listed);
        self::assertStringContainsString("\e[2m  /mcp", $listed);
        self::assertStringNotContainsString("\e[36m›", $listed);

        // Only the line that changes is redrawn, so each step is read on its own output.
        $this->key($view, "\e[B");
        self::assertStringContainsString("\e[36m› /mode", $this->terminal->consumeOutput(), 'Down opens on the first listed.');

        $this->key($view, "\e[B");
        self::assertStringContainsString("\e[36m› /model", $this->terminal->consumeOutput(), 'Down again takes the next.');

        $this->type($view, "\t");
        self::assertStringContainsString('› /model ', $this->terminal->getOutput(), 'Tab took the pick, not the first match.');
    }

    /**
     * Wrapping, and the reason the input is left alone: it is what filters the list, so a pick that
     * rewrote it would collapse the very thing being browsed.
     */
    public function testUpFromNothingTakesTheLastAndTypingStartsTheChoiceAgain(): void
    {
        $view = $this->open();
        $this->type($view, '/m');

        $this->key($view, "\e[A");
        self::assertStringContainsString("\e[36m› /mcp", $this->terminal->consumeOutput(), 'Up from nothing opens on the last.');

        $this->type($view, 'o');
        $narrowed = $this->terminal->consumeOutput();
        self::assertStringContainsString('/mode', AnsiUtils::stripAnsiCodes($narrowed));
        self::assertStringNotContainsString("\e[36m›", $narrowed, 'One more letter starts the choice again.');
    }

    /**
     * Enter takes the pick as Tab does. Accepting a completion is not running it: arrowing onto
     * `/clear` and pressing Enter out of habit must not wipe the conversation.
     */
    public function testEnterAcceptsThePickWithoutRunningIt(): void
    {
        $view = $this->open();
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertInstanceOf(Conversations::class, $conversations);
        $before = $view->conversation;

        $this->type($view, '/cl');
        $this->key($view, "\e[B");
        $this->type($view, "\r");

        self::assertStringContainsString('› /clear ', $this->terminal->getOutput(), 'Enter wrote the pick into the line.');
        self::assertSame($before, $view->conversation, 'And did not run it: /clear would have opened another conversation.');
        self::assertSame([], $conversations->transcript($before)->messages, 'Nor sent it as a message.');
    }

    /**
     * A click on a listed command takes it: one gesture, not a pick and then a key.
     *
     * The row comes from the emulated screen — the whole stream replayed through a ScreenBuffer —
     * because the list is drawn near the bottom, where a widget's own height says nothing about
     * where the layout put it.
     */
    public function testClickingASuggestionTakesIt(): void
    {
        $view = $this->open();
        $this->type($view, '/mo');
        $screen = self::screen($this->terminal->getOutput());

        // The second listed, `/model`: taking the first would prove nothing about the row.
        $this->key($view, \sprintf("\e[<0;10;%dM", self::rowOf($screen, '/model') + 1));

        self::assertStringContainsString('› /model ', $this->terminal->getOutput());
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

        // The rotation is a full cycle over AgentMode, back to where it started: standard →
        // edition → auto → plan → standard.
        foreach (['edition', 'auto', 'plan', 'standard'] as $expected) {
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

    /**
     * An edit shows what it changed, folded; a click on the diff unfolds it, a second folds it back.
     * A refused edit changed nothing, and shows no diff.
     */
    public function testAClickUnfoldsTheDiffOfAnEdit(): void
    {
        $view = $this->openOn(new EditedConversations([
            new ToolStep('call-weather', 'weather', ['city' => 'Paris'], 'Paris: 22°C, sunny'),
            new ToolStep('call-created', 'edit_file', ['path' => 'src/New.php', 'old_string' => '', 'new_string' => implode("\n", array_map(static fn (int $i): string => "created $i", range(1, 10)))], "Created src/New.php (10 lines).\n"),
            new ToolStep('call-refused', 'edit_file', ['path' => 'src/Old.php', 'old_string' => 'absent', 'new_string' => 'never written'], 'old_string not found in src/Old.php.'),
        ]));
        $screen = self::screen($this->terminal->consumeOutput());
        self::assertStringContainsString('⚙ weather {"city":"Paris"} → Paris: 22°C, sunny', implode("\n", $screen), 'Another tool: its arguments, as before.');
        self::assertStringContainsString('⚙ edit_file "src/New.php" → Created src/New.php (10 lines).', implode("\n", $screen), 'The path, not the strings: they are the diff.');
        self::assertStringContainsString('+created 1', $screen[self::rowOf($screen, '⚙ edit_file') + 1], 'Right under its call.');
        self::assertStringNotContainsString('never written', implode("\n", $screen));
        self::assertStringNotContainsString('created 7', implode("\n", $screen));

        // Its first row: right under the "⚙" line, which is not part of it.
        $this->key($view, \sprintf("\e[<0;10;%dM", self::rowOf($screen, '+created 1') + 1));
        self::assertStringContainsString('+created 10', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));

        // Unfolded, the diff is taller, and the thread keeps its bottom: its last row, "▴ fold", is
        // where "click to unfold" was. Only the rows that changed are redrawn — not a whole frame.
        $this->key($view, \sprintf("\e[<0;10;%dM", self::rowOf($screen, 'click to unfold') + 1));
        self::assertStringContainsString('click to unfold', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    /**
     * A command that changed files: its output on the call line, the diff of the changes under it,
     * folded like an edit's.
     */
    public function testWhatACommandChangedIsShownLikeAnEdit(): void
    {
        $diff = "diff --git a/src/A.php b/src/A.php\nindex 1111111..2222222 100644\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1,8 +1,8 @@\n"
            .implode("\n", array_map(static fn (int $i): string => "-old $i\n+new $i", range(1, 8)));
        $view = $this->openOn(new EditedConversations([
            new ToolStep('call-fixer', 'run_command', ['command' => 'vendor/bin/php-cs-fixer fix'], "Exit code: 0\nFixed 1 file\n".RunCommandTool::CHANGES.$diff),
            new ToolStep('call-status', 'run_command', ['command' => 'git status'], "Exit code: 0\nclean\n"),
        ]));
        $screen = self::screen($this->terminal->consumeOutput());
        $shown = implode("\n", $screen);

        self::assertStringContainsString('⚙ run_command {"command":"vendor\/bin\/php-cs-fixer fix"} → Exit code: 0', $shown);
        self::assertStringNotContainsString('Files changed by the command', $shown, 'The marker is for the thread to split on, not to show.');
        self::assertStringContainsString('    diff --git a/src/A.php b/src/A.php', $screen[self::rowOf($screen, 'Fixed 1 file') + 1], 'Right under the output.');
        self::assertStringNotContainsString('+++ b/src/A.php', $shown);
        self::assertStringContainsString('⚙ run_command {"command":"git status"} → Exit code: 0', $shown, 'No change, no diff: as before.');

        $this->key($view, \sprintf("\e[<0;10;%dM", self::rowOf($screen, 'diff --git') + 1));
        self::assertStringContainsString('+new 8', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
    }

    public function testAClickElsewhereFoldsNothing(): void
    {
        $view = $this->openOn(new EditedConversations([
            new ToolStep('call-created', 'edit_file', ['path' => 'src/New.php', 'old_string' => '', 'new_string' => implode("\n", range(1, 10))], 'Created src/New.php (10 lines).'),
        ]));
        $screen = self::screen($this->terminal->consumeOutput());

        $this->key($view, \sprintf("\e[<0;10;%dM", self::rowOf($screen, '⚙ edit_file') + 1));

        self::assertStringNotContainsString('▴ fold', AnsiUtils::stripAnsiCodes($this->terminal->consumeOutput()));
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

    /**
     * The bug that killed the chat: refresh() runs in a Revolt callback, so an exception from
     * transcript() did not fail a frame, it took the loop and the whole screen with it. A
     * conversation that can no longer be read is a line in the header, not the end of the session.
     */
    public function testAnUnreadableConversationIsShownInsteadOfKillingTheChat(): void
    {
        $view = $this->open();
        $container = self::getContainer();
        $metadata = $container->get(WorkflowMetadataStore::class);
        self::assertInstanceOf(WorkflowMetadataStore::class, $metadata);

        // Exactly what Durable leaves behind when a run fails, on a conversation that called no
        // tool: nothing anywhere says who it belongs to.
        $metadata->delete($view->conversation);

        $view->refresh();
        $view->tui->tick();

        self::assertStringContainsString('unreadable', $this->terminal->consumeOutput());
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

    private function openOn(Conversations $conversations): ChatView
    {
        $this->terminal = new VirtualTerminal(100, 30);
        $worker = self::getContainer()->get(InProcessWorker::class);
        self::assertInstanceOf(InProcessWorker::class, $worker);
        $view = (new ChatScreen($conversations, $worker))->open($conversations->start(), $this->terminal);
        $view->tui->start();
        $view->refresh();
        $view->tui->tick();

        return $view;
    }

    /**
     * The rows of the first frame, drawn whole from the top of an empty screen, one per line.
     *
     * @return list<string>
     */
    private static function screen(string $output): array
    {
        // The component's own terminal emulator, not a hand-rolled one: a redraw moves the cursor
        // and overwrites, so stripping the codes and keeping the last rows shows what was drawn
        // last rather than what is on screen. Feed it the whole stream, from the first byte.
        $screen = new ScreenBuffer(100, 30);
        $screen->write($output);

        return array_values($screen->getLines());
    }

    /**
     * @param list<string> $screen
     */
    private static function rowOf(array $screen, string $text): int
    {
        foreach ($screen as $row => $line) {
            if (str_contains($line, $text)) {
                return $row;
            }
        }

        self::fail(\sprintf('"%s" is not on the screen.', $text));
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
