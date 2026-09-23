<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a tool is told about the call it serves, and where each piece comes from.
 *
 * All three travel through the activity, so they are in the journal and a replay hands the tool the
 * same ones. None of them comes from the model: a tool that read the workspace or the owner out of
 * `$arguments` would be taking the model's word for where to act and for whom.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class ToolContextWorkflowTest extends TestCase
{
    private const TOOLS = ['weather' => ['description' => 'Weather', 'effect' => 'read']];

    public function testTheCallCarriesItsIdentifierItsWorkspaceAndItsOwner(): void
    {
        $seen = [];
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$round): array {
                return 0 === $round++
                    ? ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_ctx_1',
                        'type' => 'function',
                        'function' => ['name' => 'weather', 'arguments' => '{"city":"Lyon"}'],
                    ]]], 'finish_reason' => 'tool_calls']]]
                    : ['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$seen): string {
                // The activity payload has a different depth depending on the backend; descend to
                // the layer that carries the keys rather than hard-coding one.
                while (!\array_key_exists('callId', $payload) && \is_array($payload['payload'] ?? null)) {
                    $payload = $payload['payload'];
                }
                $seen = $payload;

                return 'Lyon: 25°C';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'mode' => 'auto',
            'prompt' => 'Weather in Lyon',
            'maxTurns' => 1,
            'workspace' => '/tmp/worktree-of-the-conversation',
            'owner' => ['id' => 'alice', 'roles' => ['support']],
        ], 'tool-context-1');

        self::assertSame('call_ctx_1', $seen['callId'] ?? null, 'The journal\'s identifier: what a tool needs to recognise a retry.');
        self::assertSame('/tmp/worktree-of-the-conversation', $seen['workspace'] ?? null);
        self::assertSame(['id' => 'alice', 'roles' => ['support']], $seen['owner'] ?? null);
    }

    /**
     * A conversation with no owner hands the tool none, rather than an empty principal that would
     * match nobody's id — the same posture the guard takes.
     */
    public function testAConversationWithNoOwnerHandsTheToolNone(): void
    {
        $seen = null;
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$round): array {
                return 0 === $round++
                    ? ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_ctx_2',
                        'type' => 'function',
                        'function' => ['name' => 'weather', 'arguments' => '{}'],
                    ]]], 'finish_reason' => 'tool_calls']]]
                    : ['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$seen): string {
                while (!\array_key_exists('callId', $payload) && \is_array($payload['payload'] ?? null)) {
                    $payload = $payload['payload'];
                }
                $seen = $payload['owner'] ?? 'absent';

                return 'ok';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'mode' => 'auto',
            'prompt' => 'Weather',
            'maxTurns' => 1,
        ], 'tool-context-2');

        self::assertSame([], $seen, 'No owner travels as nothing, not as a principal claiming nothing.');
    }
}
