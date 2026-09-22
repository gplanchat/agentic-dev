<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Delegating to a named sub-agent: it takes the profile's model, its instructions and only the tools
 * the profile allows — and never more authority than its parent.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class DelegateProfileWorkflowTest extends TestCase
{
    private const AGENTS = [
        'sorter' => [
            'description' => 'Sorts',
            'prompt' => 'You sort, briefly.',
            'model' => 'ministral-3b-latest',
            'ceiling' => 'standard',
            'tools' => ['weather'],
            'max_turns' => 1,
        ],
    ];

    private const TOOLS = [
        'weather' => ['description' => 'Weather', 'effect' => 'read'],
        'send_email' => ['description' => 'Mail', 'effect' => 'external'],
    ];

    public function testTheSubAgentTakesTheProfilesModelPromptAndTools(): void
    {
        /** @var list<array{model: string, system: string, tools: list<string>}> $calls */
        $calls = [];
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$calls, &$round): array {
                $messages = $payload['payload']['messages'] ?? [];
                $calls[] = [
                    'model' => (string) $payload['model'],
                    'system' => (string) ($messages[0]['content'] ?? ''),
                    'tools' => array_map(
                        static fn (array $tool): string => (string) $tool['function']['name'],
                        $payload['options']['tools'] ?? [],
                    ),
                    'messages' => $messages,
                ];

                // The parent delegates on its first turn; everyone else answers plainly.
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'delegate', 'arguments' => '{"mission":"Sort these cities","agent":"sorter"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                return ['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]];
            },
        ]);

        $answer = $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'agents' => self::AGENTS,
            'model' => 'mistral-small-latest',
            'mode' => 'auto',
            'prompt' => 'Delegate the sorting',
            'maxTurns' => 1,
        ], 'delegate-profile-1');

        self::assertSame('done', (string) $answer);

        // What the delegation gave back is in the parent's thread, not in its final answer.
        $toolResults = array_column(array_filter(end($calls)['messages'], static fn (array $m): bool => 'tool' === ($m['role'] ?? null)), 'content');
        self::assertStringContainsString('The sub-agent sorter (ministral-3b-latest) replies', implode("\n", $toolResults));

        $child = $calls[1] ?? null;
        self::assertNotNull($child, 'The sub-agent never called the model.');
        self::assertSame('ministral-3b-latest', $child['model'], 'The profile carries its own model.');
        self::assertSame('You sort, briefly.', $child['system'], 'And its own instructions.');
        self::assertSame(['weather', 'ask_user', 'watch', 'delegate'], $child['tools'], 'Only the tools the profile allows, plus the always-offered ones.');
    }

    /**
     * What the sub-agent's tools act in. A child started without a workspace would act in the
     * project itself, not in the conversation's worktree — the very thing the worktree prevents.
     */
    public function testTheSubAgentActsInItsCallersWorkspace(): void
    {
        $workspaces = [];
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$round): array {
                // The parent delegates; the sub-agent calls its one tool; both then answer.
                $call = match ($round++) {
                    0 => ['name' => 'delegate', 'arguments' => '{"mission":"Check the weather","agent":"sorter"}'],
                    1 => ['name' => 'weather', 'arguments' => '{"city":"Lyon"}'],
                    default => null,
                };

                if (null === $call) {
                    return ['choices' => [['message' => ['content' => 'done'], 'finish_reason' => 'stop']]];
                }

                return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                    'id' => 'call_'.$round,
                    'type' => 'function',
                    'function' => $call,
                ]]], 'finish_reason' => 'tool_calls']]];
            },
            'ai_tool_call' => static function (array $payload) use (&$workspaces): string {
                $arguments = $payload;
                while (!\array_key_exists('workspace', $arguments) && \is_array($arguments['payload'] ?? null)) {
                    $arguments = $arguments['payload'];
                }
                $workspaces[] = $arguments['workspace'] ?? null;

                return 'Lyon: 25°C';
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'agents' => self::AGENTS,
            'mode' => 'auto',
            'prompt' => 'Delegate the weather',
            'maxTurns' => 1,
            'workspace' => '/tmp/worktree-of-the-conversation',
        ], 'delegate-profile-3');

        self::assertSame(['/tmp/worktree-of-the-conversation'], $workspaces, 'The sub-agent acted somewhere else.');
    }

    /**
     * A name nobody declares is refused in the open rather than turned into an anonymous sub-agent
     * with none of the tools the model was counting on.
     */
    public function testAnUndeclaredAgentIsRefusedAndTheModelIsTold(): void
    {
        $round = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$round): array {
                if (0 === $round++) {
                    return ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'delegate', 'arguments' => '{"mission":"Do it","agent":"ghost"}'],
                    ]]], 'finish_reason' => 'tool_calls']]];
                }

                $last = end($payload['payload']['messages']);

                return ['choices' => [['message' => ['content' => (string) $last['content']], 'finish_reason' => 'stop']]];
            },
        ]);

        $answer = (string) $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => self::TOOLS,
            'agents' => self::AGENTS,
            'mode' => 'auto',
            'prompt' => 'Delegate to ghost',
            'maxTurns' => 1,
        ], 'delegate-profile-2');

        self::assertStringContainsString('No sub-agent is named "ghost"', $answer);
        self::assertStringContainsString('sorter', $answer, 'The refusal says what is declared.');
    }
}
