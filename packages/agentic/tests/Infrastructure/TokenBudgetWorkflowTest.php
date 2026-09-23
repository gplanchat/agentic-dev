<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Testing\WorkflowTestEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A run stops taking turns once it has spent what it was given.
 *
 * The check is before a turn, not during it — so the run can overshoot by one turn's worth, which
 * is what `maxToolCalls` bounds. What must not happen is a run that never stops.
 */
#[CoversClass(DurableAgentWorkflow::class)]
final class TokenBudgetWorkflowTest extends TestCase
{
    /** Each reply costs 100 tokens, so the arithmetic in the assertions is the point, not a fixture. */
    private const COST = 100;

    public function testTheRunStopsOnceTheBudgetIsSpent(): void
    {
        $calls = $this->runWith(budget: 250, messages: 6);

        // Turn 1 spends 100, turn 2 spends 200, turn 3 spends 300. The check runs before a turn:
        // it lets turns 1, 2 and 3 start and refuses the fourth, the first to find 250 reached.
        self::assertSame(3, $calls, 'The run did not stop when the budget was spent.');
    }

    public function testNoCeilingMeansNoStop(): void
    {
        self::assertSame(6, $this->runWith(budget: 0, messages: 6), '0 must mean no ceiling, not a ceiling of zero.');
    }

    public function testABudgetLargerThanTheRunChangesNothing(): void
    {
        self::assertSame(6, $this->runWith(budget: 1_000_000, messages: 6));
    }

    /**
     * Runs a conversation of `$messages` turns, every reply priced at COST, and says how many model
     * calls actually happened.
     */
    private function runWith(int $budget, int $messages): int
    {
        $calls = 0;
        $environment = WorkflowTestEnvironment::inMemory([
            'ai_model_invoke' => static function () use (&$calls): array {
                ++$calls;

                return [
                    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['total_tokens' => self::COST],
                ];
            },
        ]);

        $environment->runWorkflowClass(DurableAgentWorkflow::class, [
            'tools' => [],
            'mode' => 'auto',
            // The first message starts the run; the rest are queued before it begins, so every turn
            // has something waiting and only the budget can stop the loop.
            'prompt' => 'turn 1',
            'pending' => array_map(static fn (int $n): string => 'turn '.$n, range(2, $messages)),
            'maxTurns' => $messages,
            'tokenBudget' => $budget,
        ], 'budget-'.$budget.'-'.$messages);

        return $calls;
    }
}
