<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Application\Chat\AgentOutcome;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A turn that uses up its tool calls ends, it does not fail: one more model call, with the tools out
 * of reach, says where the agent stands — and the conversation goes on at the next message.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class ToolCallLimitWorkflowTest extends TestCase
{
    public function testATurnOutOfToolCallsEndsOnTheModelsOwnWords(): void
    {
        $toolCalls = 0;
        $choices = [];
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$choices): array {
                $choice = $payload['options']['tool_choice'] ?? 'auto';
                $choices[] = $choice;
                if ('none' === $choice) {
                    return ['choices' => [['message' => ['content' => 'Stopped after two checks: run the functional layer next.'], 'finish_reason' => 'stop']]];
                }

                // A model that would call tools forever.
                return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                    'id' => 'call_'.\count($choices),
                    'type' => 'function',
                    'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}'],
                ]]], 'finish_reason' => 'tool_calls']]];
            },
            'ai_tool_call' => static function () use (&$toolCalls): string {
                ++$toolCalls;

                return 'sunny';
            },
        ]);

        $result = $environment->run(
            static fn ($workflowEnvironment): array => (new DurableAgentWorkflow($workflowEnvironment))->run(
                ['weather' => ['description' => 'Weather', 'effect' => 'read']],
                mode: 'auto',
                prompt: 'Check the weather again and again',
                maxTurns: 1,
                maxToolCalls: 2,
            ),
            'tool-call-limit-1',
        );

        self::assertSame(2, $toolCalls, 'The call past the limit is never executed.');
        self::assertSame('Stopped after two checks: run the functional layer next.', AgentOutcome::fromWire($result)->answer);
        self::assertSame('none', end($choices), 'The closing call leaves the model no tool to call.');
    }
}
