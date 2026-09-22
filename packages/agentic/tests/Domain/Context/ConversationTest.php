<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Context;

use Gplanchat\Agentic\Domain\Context\Conversation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The flat array that goes out to the provider does not state its invariants. This type carries
 * them.
 */
#[CoversClass(Conversation::class)]
final class ConversationTest extends TestCase
{
    public function testTheWireSurvivesTheRoundTrip(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1']]],
            ['role' => 'tool', 'content' => 'ok', 'tool_call_id' => 'c1'],
            ['role' => 'assistant', 'content' => 'There you go'],
            ['role' => 'user', 'content' => 'Thanks'],
        ];

        self::assertSame($messages, Conversation::fromWire($messages)->toWire());
    }

    public function testATurnGathersEverythingTheAgentProducedInAnswer(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
            ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c1']]],
            ['role' => 'tool', 'content' => 'r1', 'tool_call_id' => 'c1'],
            ['role' => 'assistant', 'content' => 'a1'],
            ['role' => 'user', 'content' => 'u2'],
        ]);

        self::assertCount(2, $conversation->turns);
        self::assertSame(4, $conversation->turns[0]->count(), 'The turn must hold the tool call and its result.');
        self::assertCount(1, $conversation->system);
    }

    public function testTheLastTurnIsNeverDropped(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
        ]);

        self::assertSame($conversation, $conversation->withoutOldestTurn());
    }

    /**
     * A compaction marker set along the turns is not preamble: attaching it to the system would
     * make it climb back to the front on every pass, and it would end up with several of them.
     */
    public function testOnlyLeadingSystemMessagesArePreamble(): void
    {
        $conversation = Conversation::fromWire([
            ['role' => 'system', 'content' => 'S'],
            ['role' => 'user', 'content' => 'u1'],
            ['role' => 'system', 'content' => '[messages dropped]'],
            ['role' => 'user', 'content' => 'u2'],
        ]);

        self::assertCount(1, $conversation->system);
        self::assertCount(2, $conversation->turns);
    }
}
