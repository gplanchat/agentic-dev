<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Domain\Mikado\MikadoGraph;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * /resume, /compact and /rewind open a new conversation from the thread of another: the Mikado
 * graph of the task under way goes along — its latest state, even on /rewind, since it describes the
 * code in the worktree, which is not rewound either.
 */
final class MikadoCarryOverTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testARestartedConversationKeepsItsGraph(): void
    {
        $graph = MikadoGraph::start('Move to PHP 8.4', 45)->note(1, 'Drop lib X');
        $from = $this->begin(['mikado' => $graph->toWire()]);

        $restarted = $this->conversations()->restart($from, 0);
        $this->worker()->drain();

        self::assertSame($graph->toWire(), $this->payload($restarted)['mikado'] ?? null);
        self::assertEquals($graph, $this->conversations()->transcript($restarted)->mikado);
    }

    public function testWithoutAGraphTheRestartedPayloadHasNoKeyForIt(): void
    {
        $restarted = $this->conversations()->restart($this->begin([]));
        $this->worker()->drain();

        self::assertArrayNotHasKey('mikado', $this->payload($restarted));
        self::assertNull($this->conversations()->transcript($restarted)->mikado);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function begin(array $payload): string
    {
        $container = self::getContainer();
        $dispatcher = $container->get(WorkflowResumeDispatcher::class);
        $principal = $container->get(CurrentPrincipal::class);
        self::assertInstanceOf(WorkflowResumeDispatcher::class, $dispatcher);
        self::assertInstanceOf(CurrentPrincipal::class, $principal);

        $id = (string) Uuid::v4();
        $dispatcher->dispatchNewWorkflowRun($id, DurableAgentWorkflow::class, ['owner' => $principal()->toWire(), 'idleTimeoutSeconds' => 3600.0, ...$payload]);
        $this->worker()->drain();
        $this->conversations()->send($id, 'What is the weather in Paris?');
        $this->worker()->drain();

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $conversation): array
    {
        $metadata = self::getContainer()->get(WorkflowMetadataStore::class);
        self::assertInstanceOf(WorkflowMetadataStore::class, $metadata);

        return $metadata->get($conversation)['payload'] ?? [];
    }

    private function conversations(): Conversations
    {
        $conversations = self::getContainer()->get(Conversations::class);
        self::assertInstanceOf(Conversations::class, $conversations);

        return $conversations;
    }

    private function worker(): InProcessWorker
    {
        $worker = self::getContainer()->get(InProcessWorker::class);
        self::assertInstanceOf(InProcessWorker::class, $worker);

        return $worker;
    }
}
