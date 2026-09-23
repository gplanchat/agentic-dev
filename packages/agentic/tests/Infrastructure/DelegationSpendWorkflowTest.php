<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Application\Chat\AgentOutcome;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a delegation costs, and how far one may go.
 *
 * A delegate is a child workflow with its own journal, so its spend is invisible to its caller
 * unless it is handed back. It comes back in the return value — the only channel there is — and
 * because each level adds what its own children reported before reporting in turn, one addition per
 * level carries the whole tree.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class DelegationSpendWorkflowTest extends TestCase
{
    private const COST = 100;

    /**
     * Two levels: the conversation delegates once, the delegate answers. Three model calls in all —
     * the parent's first turn, the child's turn, the parent's turn after the delegation — so the
     * total is three times the price of a reply, and none of it is lost on the way up.
     */
    public function testTheCallersTotalIncludesWhatItsDelegateSpent(): void
    {
        [$outcome, $calls] = $this->delegateChain(maxDepth: 1);

        self::assertSame(3, $calls);
        self::assertSame(3 * self::COST, $outcome->tokensSpent, 'The delegation was spent but not counted.');
    }

    /**
     * The recursive case, and the reason the accounting had to be recursive too: a delegate keeps
     * the `delegate` tool, so it can delegate in turn. Nothing bounded that before.
     */
    public function testTheTotalAddsUpThroughAWholeChainOfSubAgents(): void
    {
        [$outcome, $calls] = $this->delegateChain(maxDepth: 3);

        // Depth 0 delegates, 1 delegates, 2 delegates, 3 answers; then each level answers on its
        // way back up. Whatever that count is, the total must be exactly the price of every call.
        self::assertSame($calls * self::COST, $outcome->tokensSpent, 'A level of the chain went uncounted.');
        self::assertGreaterThan(3 * self::COST, $outcome->tokensSpent, 'The chain did not actually nest.');
    }

    /**
     * `delegate` is not offered at the deepest level allowed, so the chain stops there — whatever
     * the model keeps asking for.
     */
    public function testTheChainStopsAtTheConfiguredDepth(): void
    {
        [, $shallow] = $this->delegateChain(maxDepth: 1);
        [, $deeper] = $this->delegateChain(maxDepth: 2);

        self::assertLessThan($deeper, $shallow, 'A deeper ceiling must allow a longer chain.');
    }

    public function testADepthOfZeroForbidsDelegatingAtAll(): void
    {
        [$outcome, $calls] = $this->delegateChain(maxDepth: 0);

        // One call, one answer: the tool was never on offer, so the model could not ask for it.
        self::assertSame(1, $calls);
        self::assertSame(self::COST, $outcome->tokensSpent);
    }

    /**
     * Runs a conversation whose model delegates as long as it is allowed to and the chain is not
     * exhausted, then answers.
     *
     * @return array{AgentOutcome, int}
     */
    private function delegateChain(int $maxDepth): array
    {
        $calls = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function (array $payload) use (&$calls): array {
                ++$calls;

                $messages = $payload['payload']['messages'] ?? $payload['messages'] ?? [];
                $offered = array_map(
                    static fn (array $tool): string => (string) $tool['function']['name'],
                    $payload['options']['tools'] ?? [],
                );

                // One delegation per level, and one only: an agent that has already seen a tool
                // result has delegated, so it answers. Delegating twice in a turn would nest
                // nothing — it would make siblings, and the depth would never grow.
                $alreadyDelegated = [] !== array_filter(
                    $messages,
                    static fn (array $m): bool => 'tool' === ($m['role'] ?? null),
                );
                $mayDelegate = \in_array('delegate', $offered, true) && !$alreadyDelegated;

                $reply = $mayDelegate
                    ? ['content' => null, 'tool_calls' => [[
                        'id' => 'call_'.$calls,
                        'type' => 'function',
                        'function' => ['name' => 'delegate', 'arguments' => '{"mission":"go deeper"}'],
                    ]]]
                    : ['content' => 'done'];

                return [
                    'choices' => [['message' => $reply, 'finish_reason' => $mayDelegate ? 'tool_calls' : 'stop']],
                    'usage' => ['total_tokens' => self::COST],
                ];
            },
        ]);

        $result = $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => [],
            'mode' => 'auto',
            'prompt' => 'Delegate as deep as you can',
            'maxTurns' => 1,
            'maxDepth' => $maxDepth,
        ], 'spend-'.$maxDepth);

        return [AgentOutcome::fromWire($result), $calls];
    }
}
