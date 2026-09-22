<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The proof that the loop moves forward in a single process: no `messenger:consume`, no cluster,
 * only the in-process worker the TUI calls.
 */
final class ConversationLoopTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAMessageGetsAnAnswerThroughAReadTool(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Paris?');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working);
        self::assertSame('Paris: 22°C, sunny', $this->lastAssistant($transcript));
        self::assertSame('weather', $transcript->steps[0]->tool);
    }

    public function testAnExternalToolWaitsForApprovalThenRuns(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Send an email to the team');
        $worker->drain();

        $pending = $conversations->transcript($id)->pending;
        self::assertCount(1, $pending);
        self::assertSame('send_email', $pending[0]->tool);

        $conversations->decide($id, $pending[0]->callId, true);
        $worker->drain();

        self::assertSame('Email sent to team@example.test.', $this->lastAssistant($conversations->transcript($id)));
    }

    public function testAQuestionIsAnsweredByTheHuman(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Ask me a question');
        $worker->drain();

        $questions = $conversations->transcript($id)->questions;
        self::assertCount(1, $questions);

        $conversations->answer($id, $questions[0]->callId, ['The weather']);
        $worker->drain();

        self::assertSame('The weather', $this->lastAssistant($conversations->transcript($id)));
    }

    public function testAWatchSleepsUntilTheAlert(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Watch the delivery');
        $worker->drain();

        $watches = $conversations->transcript($id)->watches;
        self::assertCount(1, $watches);
        self::assertSame('order.shipped', $watches[0]->subject->value);

        $conversations->alert($id, $watches[0]->callId, 'the truck is at the dock');
        $worker->drain();

        self::assertStringContainsString('the truck is at the dock', (string) $this->lastAssistant($conversations->transcript($id)));
    }

    public function testADelegationGetsTheSubAgentAnswer(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Delegate the research');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working, 'The parent is still waiting for its sub-agent.');
        self::assertStringContainsString('The sub-agent', (string) $this->lastAssistant($transcript));
    }

    /**
     * A broken tool, once the retries are spent, becomes a result the model reads: the conversation
     * carries on.
     */
    public function testAToolThatKeepsFailingIsReportedToTheModel(): void
    {
        [$conversations, $worker] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'Take a note');
        $worker->drain();

        $transcript = $conversations->transcript($id);
        self::assertFalse($transcript->working);
        self::assertFalse($transcript->finished);
        self::assertSame('Tool "save_note" failed: The disk is full.', $this->lastAssistant($transcript));
    }

    /**
     * @return array{Conversations, InProcessWorker}
     */
    private function services(): array
    {
        $container = self::getContainer();

        return [$container->get(Conversations::class), $container->get(InProcessWorker::class)];
    }

    private function lastAssistant(Transcript $transcript): ?string
    {
        foreach (array_reverse($transcript->messages) as $message) {
            if ('assistant' === $message->role && null !== $message->content) {
                return $message->content;
            }
        }

        return null;
    }
}
