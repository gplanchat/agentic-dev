<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Identity\ConversationNotOwned;
use Gplanchat\Agentic\Domain\Identity\ConversationOwnerUnknown;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A conversation whose start payload is gone.
 *
 * It is not a hypothetical: Durable deletes an execution's metadata row when a run fails
 * ({@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler}), and on a backend where a dispatched run
 * writes no `ExecutionStarted`, the start payload lives nowhere else. Owner included — which made
 * the ownership check refuse a reader their own conversation, and the refusal killed the chat.
 */
final class UnreadableConversationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * The journal survives the metadata row, and every tool call carries the owner: a conversation
     * that got as far as calling a tool is still its owner's.
     */
    public function testTheOwnerIsRecoveredFromTheJournalWhenTheStartPayloadIsGone(): void
    {
        [$conversations, $worker, , $metadata] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Paris?');
        $worker->drain();
        self::assertNotEmpty($conversations->transcript($id)->steps, 'The conversation must have called a tool.');

        // Exactly what a failed run leaves behind.
        $metadata->delete($id);

        $transcript = $conversations->transcript($id);
        self::assertSame('alice', $transcript->owner?->id, 'The owner was not recovered from the journal.');
        self::assertNotEmpty($transcript->messages, 'And the thread is readable again.');
    }

    /**
     * Recovered from the journal, not granted: someone else is still refused.
     */
    public function testARecoveredOwnerStillRefusesEveryoneElse(): void
    {
        [$conversations, $worker, $principal, $metadata] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $conversations->send($id, 'What is the weather in Paris?');
        $worker->drain();
        $metadata->delete($id);

        $principal->becomes('bob');

        $this->expectException(ConversationNotOwned::class);
        $conversations->transcript($id);
    }

    /**
     * A run that failed before calling a single tool leaves nothing to recover. Still a refusal —
     * unknown must not mean everybody's — but it says what happened instead of accusing its reader.
     */
    public function testWithNothingToRecoverTheRefusalSaysSoRatherThanAccusing(): void
    {
        [$conversations, $worker, , $metadata] = $this->services();

        $id = $conversations->start();
        $worker->drain();
        $metadata->delete($id);

        try {
            $conversations->transcript($id);
            self::fail('An unowned conversation went through.');
        } catch (ConversationOwnerUnknown $refusal) {
            self::assertSame($id, $refusal->conversation);
            self::assertStringNotContainsString('belong to you', $refusal->getMessage());
        }
    }

    /**
     * @return array{Conversations, InProcessWorker, SwitchablePrincipal, WorkflowMetadataStore}
     */
    private function services(): array
    {
        $container = self::bootKernel()->getContainer();
        $conversations = $container->get(Conversations::class);
        $worker = $container->get(InProcessWorker::class);
        $principal = $container->get(SwitchablePrincipal::class);
        $metadata = $container->get(WorkflowMetadataStore::class);

        self::assertInstanceOf(Conversations::class, $conversations);
        self::assertInstanceOf(InProcessWorker::class, $worker);
        self::assertInstanceOf(SwitchablePrincipal::class, $principal);
        self::assertInstanceOf(WorkflowMetadataStore::class, $metadata);

        return [$conversations, $worker, $principal, $metadata];
    }
}
