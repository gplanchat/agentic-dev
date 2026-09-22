<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The watch: the agent sleeps, and wakes up finding again what it meant to do.
 *
 * That is what a process watcher cannot do. Here the intent is written into the journal at the
 * moment of registration — the agent does not have to remember it three days later, it is handed
 * back to it.
 */
#[CoversClass(WatchTool::class)]
final class WatchWorkflowTest extends TestCase
{
    private const CALL = 'w1';

    public function testTheAlertHandsBackBothTheObservationAndTheIntent(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());
        $this->alertUpFront($environment, 'watch-1', 'the truck is at the dock');

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-1');

        self::assertStringContainsString('the truck is at the dock', $answer);
        self::assertStringContainsString('Record the goods receipt', $answer, 'Waking forgot to hand back the intent.');
    }

    /**
     * With no alert, the watch falls — and it still hands back the intent, so that the agent knows
     * what it is talking about when it takes over.
     */
    public function testAWatchNobodyRaisesExpiresAndStillRecallsTheIntent(): void
    {
        $environment = WorkflowTestEnvironment::inMemory($this->scriptedModel());

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-2');

        self::assertStringContainsString('expired without an alert', $answer);
        self::assertStringContainsString('Record the goods receipt', $answer);
    }

    public function testAWatchNeverReachesAnActivity(): void
    {
        $toolCalls = 0;
        $handlers = $this->scriptedModel();
        $handlers['ai_tool_call'] = static function () use (&$toolCalls): string {
            ++$toolCalls;

            return 'ran';
        };

        $environment = WorkflowTestEnvironment::inMemory($handlers);
        $this->alertUpFront($environment, 'watch-3', 'the truck is at the dock');

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-3');

        self::assertSame(0, $toolCalls);
    }

    public function testTheWatchToolIsAlwaysOffered(): void
    {
        $offered = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$offered): array {
                foreach ($payload['options']['tools'] ?? [] as $tool) {
                    $offered[] = $tool['function']['name'] ?? '?';
                }

                return ['choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop']]];
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-4');

        self::assertContains(WatchTool::TOOL, $offered);
    }

    private function alertUpFront(WorkflowTestEnvironment $environment, string $executionId, string $observation): void
    {
        $environment->getEventStore()->append(new ExecutionStarted($executionId, []));
        $environment->getEventStore()->append(new WorkflowSignalReceived(
            $executionId,
            'alert',
            ['callId' => self::CALL, 'observation' => $observation],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return ['prompt' => 'Watch the delivery', 'maxTurns' => 1, 'mode' => 'auto', 'watchSubjects' => ['order.shipped' => 'an order has left the warehouse']];
    }

    /**
     * @return array<string, callable>
     */
    private function scriptedModel(): array
    {
        $round = 0;

        return [
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => self::CALL,
                        'type' => 'function',
                        'function' => ['name' => WatchTool::TOOL, 'arguments' => json_encode([
                            'subject' => 'order.shipped',
                            'observation' => 'The delivery arrives at the warehouse',
                            'intent' => 'Record the goods receipt and warn the team',
                            'deadlineSeconds' => 5,
                        ], \JSON_UNESCAPED_UNICODE)],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => $last['content']], 'finish_reason' => 'stop']]];
            },
        ];
    }

    /**
     * A subject outside the vocabulary must not arm a watch: nothing could ever lift it, and the
     * agent would sleep until its deadline without anyone knowing.
     */
    public function testAnUnknownSubjectIsRefusedInsteadOfArmingADeadWatch(): void
    {
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => self::CALL,
                        'type' => 'function',
                        'function' => ['name' => WatchTool::TOOL, 'arguments' => json_encode([
                            'subject' => 'delivery.arrived',
                            'observation' => 'The truck',
                            'intent' => 'Warn',
                        ], \JSON_UNESCAPED_UNICODE)],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => $last['content']], 'finish_reason' => 'stop']]];
            },
        ]);

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, $this->input(), 'watch-refused');

        self::assertStringContainsString('Unknown watch subject', $answer);
        self::assertStringContainsString('order.shipped', $answer, 'The refusal must say what is acceptable.');
    }
}
