<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SlashCommandsTest extends KernelTestCase
{
    private Conversations $conversations;
    private InProcessWorker $worker;
    private SlashCommands $commands;
    private string $id;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->conversations = $container->get(Conversations::class);
        $this->worker = $container->get(InProcessWorker::class);
        $this->commands = new SlashCommands($this->conversations);
        $this->id = $this->conversations->start();
        $this->worker->drain();
    }

    public function testSuggestionsFollowWhatIsTyped(): void
    {
        self::assertSame(['/mode', '/model'], array_keys(SlashCommands::suggestions('/mo')));
        self::assertSame([], SlashCommands::suggestions('/mode auto'), 'Once the argument is started, no more suggestions.');
        self::assertSame([], SlashCommands::suggestions('hello'));
    }

    public function testToolsSayWhatTheGuardDoesInTheCurrentMode(): void
    {
        $notice = $this->commands->run($this->id, '/tools')->notice;

        self::assertMatchesRegularExpression('/^weather\s+read\s+passes$/m', $notice);
        self::assertMatchesRegularExpression('/^send_email\s+external\s+needs approval$/m', $notice);
        self::assertStringContainsString('delegate', $notice);
    }

    /**
     * The sandbox allowlist is a rule like the others: gone to the journal with the conversation,
     * shown by `/tools`, and it holds in `auto`.
     */
    /**
     * Layer names are what the model types: the configuration keeps `kernel-unit` as written, where
     * Symfony would turn the dash into an underscore.
     */
    /**
     * The tool calls a turn may make travel in the start payload: a coding turn needs more than
     * Symfony AI's own default, and the configuration says how many.
     */
    public function testATurnMayMakeAsManyToolCallsAsConfigured(): void
    {
        $metadata = self::getContainer()->get(WorkflowMetadataStore::class);
        self::assertInstanceOf(WorkflowMetadataStore::class, $metadata);
        $payload = $metadata->get($this->id)['payload'] ?? [];

        self::assertSame(40, $payload['maxToolCalls'] ?? null);
    }

    public function testCheckLayersReachTheModelAsWritten(): void
    {
        $runChecks = null;
        foreach ($this->conversations->transcript($this->id)->tools as $tool) {
            if ('run_checks' === $tool->name) {
                $runChecks = $tool;
            }
        }

        self::assertSame(['kernel-unit'], $runChecks?->parameters['properties']['layer']['enum'] ?? null);
    }

    public function testTheSandboxAllowlistHoldsInAuto(): void
    {
        $this->commands->run($this->id, '/mode auto');
        $notice = $this->commands->run($this->id, '/tools')->notice;

        self::assertMatchesRegularExpression('/^run_command\s+external\s+needs approval$/m', $notice);
        self::assertStringContainsString('ask   run_command in auto unless command=git status | git status *', $notice);
    }

    /**
     * A conversation knows its worktree from the start, and a restart — /rewind, /compact, /resume —
     * goes on in the same one. Nothing is created until a command needs it.
     */
    public function testAConversationKeepsItsWorktreeAcrossRestarts(): void
    {
        $workspace = $this->conversations->transcript($this->id)->workspace;

        self::assertStringEndsWith('/.worktrees/agentic-'.substr($this->id, 0, 8), (string) $workspace);
        self::assertStringContainsString('Workspace: '.$workspace.' (created by the first command)', $this->commands->run($this->id, '/tools')->notice);

        $restarted = $this->conversations->restart($this->id);
        $this->worker->drain();
        self::assertSame($workspace, $this->conversations->transcript($restarted)->workspace);
    }

    public function testModeChangesTheGuard(): void
    {
        $this->commands->run($this->id, '/mode auto');

        self::assertSame(AgentMode::Auto, $this->conversations->transcript($this->id)->mode);
        self::assertMatchesRegularExpression('/^send_email\s+external\s+passes$/m', $this->commands->run($this->id, '/tools')->notice);
        self::assertTrue($this->commands->run($this->id, '/mode turbo')->error);
    }

    /**
     * The previous turn keeps its model; the next one takes the new one.
     */
    public function testModelSwitchesFromTheNextMessage(): void
    {
        $this->conversations->send($this->id, 'Hello');
        $this->worker->drain();

        $outcome = $this->commands->run($this->id, '/model mistral-large-latest');
        self::assertFalse($outcome->error);
        self::assertSame('mistral-large-latest', $this->conversations->transcript($this->id)->model);

        $this->conversations->send($this->id, 'Again');
        $this->worker->drain();

        self::assertSame(['mistral-small-latest', 'mistral-large-latest'], $this->modelsCalled());
    }

    public function testAnUnknownModelIsRefusedBeforeReachingTheJournal(): void
    {
        $outcome = $this->commands->run($this->id, '/model gpt-imaginary');

        self::assertTrue($outcome->error);
        self::assertSame('mistral-small-latest', $this->conversations->transcript($this->id)->model);
        self::assertStringNotContainsString('mistral-embed', $this->commands->run($this->id, '/model')->notice, 'A model with no tool calling cannot lead an agent.');
    }

    public function testClearClosesAndOpensANewConversation(): void
    {
        $outcome = $this->commands->run($this->id, '/clear');
        $this->worker->drain();

        self::assertNotNull($outcome->conversation);
        self::assertNotSame($this->id, $outcome->conversation);
        self::assertTrue($this->conversations->transcript($this->id)->finished);
        self::assertFalse($this->conversations->transcript($outcome->conversation)->finished);
    }

    public function testRewindListsMessagesLatestFirstThenRestartsBeforeTheChosenOne(): void
    {
        $this->say('What is the weather in Paris?', 'And in Lyon?');

        $list = $this->commands->run($this->id, '/rewind');
        self::assertSame('/rewind', $list->choose);
        self::assertSame(['2', '1'], array_column($list->choices, 'value'), 'The most recent first.');

        $outcome = $this->commands->run($this->id, '/rewind 2');
        $this->worker->drain();

        self::assertNotNull($outcome->conversation);
        self::assertSame('And in Lyon?', $outcome->prefill, 'The undone message comes back into the input.');
        self::assertSame(['What is the weather in Paris?'], $this->conversations->transcript($outcome->conversation)->userMessages());
        self::assertTrue($this->conversations->transcript($this->id)->finished, 'The old one is closed; its journal, though, stays.');
        self::assertTrue($this->commands->run($outcome->conversation, '/rewind 9')->error);
    }

    public function testCompactRestartsFromASummary(): void
    {
        $this->say('What is the weather in Paris?');

        $outcome = $this->commands->run($this->id, '/compact');
        $this->worker->drain();

        $first = $this->conversations->transcript((string) $outcome->conversation)->messages[0] ?? null;
        self::assertStringStartsWith('Summary of our previous conversation', (string) $first?->content);
    }

    public function testResumeSwitchesToARunningConversationAndReopensAFinishedOne(): void
    {
        $this->say('What is the weather in Paris?');
        $other = $this->conversations->start();
        $this->worker->drain();

        $list = $this->commands->run($other, '/resume');
        self::assertContains($this->id, array_column($list->choices, 'value'));
        self::assertNotContains($other, array_column($list->choices, 'value'), 'The conversation on screen is not offered.');

        self::assertSame($this->id, $this->commands->run($other, '/resume '.substr($this->id, 0, 8))->conversation);

        $this->conversations->close($this->id);
        $this->worker->drain();
        $reopened = $this->commands->run($other, '/resume '.$this->id)->conversation;
        $this->worker->drain();

        self::assertNotSame($this->id, $reopened);
        self::assertSame(['What is the weather in Paris?'], $this->conversations->transcript((string) $reopened)->userMessages());
    }

    public function testAnUnknownCommandSaysSo(): void
    {
        self::assertTrue($this->commands->run($this->id, '/mcp')->error);
    }

    private function say(string ...$messages): void
    {
        foreach ($messages as $message) {
            $this->conversations->send($this->id, $message);
            $this->worker->drain();
        }
    }

    /**
     * @return list<string>
     */
    private function modelsCalled(): array
    {
        $models = [];
        foreach (self::getContainer()->get(EventStoreInterface::class)->readStream($this->id) as $event) {
            if ($event instanceof ActivityScheduled && 'ai_model_invoke' === $event->activityName()) {
                $payload = $event->payload();
                while (!\array_key_exists('model', $payload) && \is_array($payload['payload'] ?? null)) {
                    $payload = $payload['payload'];
                }
                $models[] = $payload['model'] ?? '?';
            }
        }

        return $models;
    }
}
