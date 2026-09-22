<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Context;

use Gplanchat\Agentic\Domain\Context\ContextBudget;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The guard set on the model call, where the one on tools is set on the effects.
 *
 * It runs in workflow code, so it is replayed: its only non-negotiable constraint is to be pure.
 * That is what the last test checks.
 */
#[CoversClass(ContextBudget::class)]
final class ContextBudgetTest extends TestCase
{
    public function testAConversationUnderTheCeilingIsLeftAlone(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        self::assertSame($messages, (new ContextBudget(10_000))->fit($messages));
    }

    public function testTheOldestTurnsGoFirstAndTheSystemMessageStays(): void
    {
        $fitted = (new ContextBudget(120))->fit($this->longConversation(20));

        self::assertSame('system', $fitted[0]['role']);
        self::assertSame('Be concise.', $fitted[0]['content'], 'The system message was carried away.');
        self::assertStringContainsString('dropped from the context', $fitted[1]['content']);
        self::assertSame('Question 20', end($fitted)['content'], 'The last turn must always stay.');
        self::assertLessThan(\count($this->longConversation(20)), \count($fitted));
    }

    /**
     * The trap of compaction: an `assistant` asking for tools and the `tool` messages answering it
     * form one block. Cutting through the middle leaves an orphaned result, which providers refuse.
     */
    public function testAToolResultIsNeverLeftWithoutItsCall(): void
    {
        $messages = [['role' => 'system', 'content' => 'S']];
        for ($turn = 0; $turn < 12; ++$turn) {
            $messages[] = ['role' => 'user', 'content' => \sprintf('Request %d %s', $turn, str_repeat('x', 40))];
            $messages[] = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c'.$turn]]];
            $messages[] = ['role' => 'tool', 'content' => 'result', 'tool_call_id' => 'c'.$turn];
        }

        $fitted = (new ContextBudget(200))->fit($messages);

        $open = [];
        foreach ($fitted as $message) {
            foreach ($message['tool_calls'] ?? [] as $call) {
                $open[$call['id']] = true;
            }
            if ('tool' === $message['role']) {
                self::assertArrayHasKey($message['tool_call_id'], $open, 'A tool result lost its call.');
            }
        }
    }

    public function testHalvingTightensTheCeiling(): void
    {
        $budget = new ContextBudget(1_000);

        self::assertSame(500, $budget->halved()->maxTokens);
        self::assertLessThan($budget->ceiling(), $budget->halved()->ceiling());
    }

    /**
     * Replayed, compaction must return exactly the same payload — otherwise the journaled model call
     * and the one of the replay diverge, and the divergence guard (DUR042) fires.
     */
    public function testCompactionIsPure(): void
    {
        $budget = new ContextBudget(120);
        $messages = $this->longConversation(20);

        self::assertSame($budget->fit($messages), $budget->fit($messages));
    }

    /**
     * The ceiling of compaction, and it is accepted: **a turn that is on its own bigger than the
     * window cannot be compacted**. Dropping the message the model has to answer would make no
     * sense; we let it go out, the provider refuses it, and it is the reactive path, on the
     * provider adapter side, that takes over.
     */
    public function testASingleTurnBiggerThanTheWindowIsLeftAlone(): void
    {
        $huge = [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => str_repeat('z', 4_000)],
        ];

        $budget = new ContextBudget(200);

        self::assertSame($huge, $budget->fit($huge));
        self::assertGreaterThan($budget->ceiling(), $budget->estimate($huge));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function longConversation(int $turns): array
    {
        $messages = [['role' => 'system', 'content' => 'Be concise.']];
        for ($turn = 0; $turn < $turns; ++$turn) {
            $messages[] = ['role' => 'user', 'content' => 'Question '.$turn];
            $messages[] = ['role' => 'assistant', 'content' => 'Answer '.$turn.' '.str_repeat('y', 30)];
        }

        // The last message is a `user`: it is the turn the model has to answer.
        $messages[] = ['role' => 'user', 'content' => 'Question '.$turns];

        return $messages;
    }
}
