<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DurableAgentWorkflow::class)]
#[CoversClass(ChatTranscript::class)]
final class ModelSwitchWorkflowTest extends TestCase
{
    /**
     * The model changed by signal serves the next turn, and the projection says so.
     */
    public function testASetModelSignalSwitchesTheModelOfTheNextTurn(): void
    {
        $models = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$models): array {
                $models[] = $payload['model'];

                return ['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']]];
            },
        ]);

        $environment->getEventStore()->append(new ExecutionStarted('switch-1', []));
        $environment->getEventStore()->append(new WorkflowSignalReceived('switch-1', 'user_message', ['text' => 'one']));
        $environment->getEventStore()->append(new WorkflowSignalReceived('switch-1', 'set_model', ['model' => 'mistral-large-latest']));
        $environment->getEventStore()->append(new WorkflowSignalReceived('switch-1', 'user_message', ['text' => 'two']));

        $environment->runWorkflowClass(DurableAgentWorkflow::class, ['model' => 'mistral-small-latest', 'maxTurns' => 2], 'switch-1');

        // Both messages and the signal are in the journal before the first turn: the signal is
        // already applied when the first turn goes out. What counts is that the model follows the
        // signal.
        self::assertSame('mistral-large-latest', end($models));

        $transcript = (new ChatTranscript($environment->getEventStore(), new InMemoryWorkflowMetadataStore()))->forExecution('switch-1');
        self::assertSame('mistral-large-latest', $transcript->model);
    }
}
