<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;
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
        self::assertSame([], SlashCommands::suggestions('/mode auto'), 'Une fois l’argument commencé, plus de suggestion.');
        self::assertSame([], SlashCommands::suggestions('bonjour'));
    }

    public function testToolsSayWhatTheGuardDoesInTheCurrentMode(): void
    {
        $notice = $this->commands->run($this->id, '/tools')->notice;

        self::assertMatchesRegularExpression('/^weather\s+read\s+passe$/m', $notice);
        self::assertMatchesRegularExpression('/^send_email\s+external\s+demande une validation$/m', $notice);
        self::assertStringContainsString('deleguer', $notice);
    }

    public function testModeChangesTheGuard(): void
    {
        $this->commands->run($this->id, '/mode auto');

        self::assertSame(AgentMode::Auto, $this->conversations->transcript($this->id)->mode);
        self::assertMatchesRegularExpression('/^send_email\s+external\s+passe$/m', $this->commands->run($this->id, '/tools')->notice);
        self::assertTrue($this->commands->run($this->id, '/mode turbo')->error);
    }

    /**
     * Le tour d'avant garde son modèle ; celui d'après prend le nouveau.
     */
    public function testModelSwitchesFromTheNextMessage(): void
    {
        $this->conversations->send($this->id, 'Bonjour');
        $this->worker->drain();

        $outcome = $this->commands->run($this->id, '/model mistral-large-latest');
        self::assertFalse($outcome->error);
        self::assertSame('mistral-large-latest', $this->conversations->transcript($this->id)->model);

        $this->conversations->send($this->id, 'Encore');
        $this->worker->drain();

        self::assertSame(['mistral-small-latest', 'mistral-large-latest'], $this->modelsCalled());
    }

    public function testAnUnknownModelIsRefusedBeforeReachingTheJournal(): void
    {
        $outcome = $this->commands->run($this->id, '/model gpt-imaginaire');

        self::assertTrue($outcome->error);
        self::assertSame('mistral-small-latest', $this->conversations->transcript($this->id)->model);
        self::assertStringNotContainsString('mistral-embed', $this->commands->run($this->id, '/model')->notice, 'Un modèle sans appel d’outils ne peut pas mener un agent.');
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
        $this->say('Quel temps fait-il à Paris ?', 'Et à Lyon ?');

        $list = $this->commands->run($this->id, '/rewind');
        self::assertSame('/rewind', $list->choose);
        self::assertSame(['2', '1'], array_column($list->choices, 'value'), 'Le plus récent d’abord.');

        $outcome = $this->commands->run($this->id, '/rewind 2');
        $this->worker->drain();

        self::assertNotNull($outcome->conversation);
        self::assertSame('Et à Lyon ?', $outcome->prefill, 'Le message défait revient dans la saisie.');
        self::assertSame(['Quel temps fait-il à Paris ?'], $this->conversations->transcript($outcome->conversation)->userMessages());
        self::assertTrue($this->conversations->transcript($this->id)->finished, 'L’ancienne est close ; son journal, lui, reste.');
        self::assertTrue($this->commands->run($outcome->conversation, '/rewind 9')->error);
    }

    public function testCompactRestartsFromASummary(): void
    {
        $this->say('Quel temps fait-il à Paris ?');

        $outcome = $this->commands->run($this->id, '/compact');
        $this->worker->drain();

        $first = $this->conversations->transcript((string) $outcome->conversation)->messages[0] ?? null;
        self::assertStringStartsWith('Résumé de notre conversation précédente', (string) $first?->content);
    }

    public function testResumeSwitchesToARunningConversationAndReopensAFinishedOne(): void
    {
        $this->say('Quel temps fait-il à Paris ?');
        $other = $this->conversations->start();
        $this->worker->drain();

        $list = $this->commands->run($other, '/resume');
        self::assertContains($this->id, array_column($list->choices, 'value'));
        self::assertNotContains($other, array_column($list->choices, 'value'), 'La conversation affichée n’est pas proposée.');

        self::assertSame($this->id, $this->commands->run($other, '/resume '.substr($this->id, 0, 8))->conversation);

        $this->conversations->close($this->id);
        $this->worker->drain();
        $reopened = $this->commands->run($other, '/resume '.$this->id)->conversation;
        $this->worker->drain();

        self::assertNotSame($this->id, $reopened);
        self::assertSame(['Quel temps fait-il à Paris ?'], $this->conversations->transcript((string) $reopened)->userMessages());
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
