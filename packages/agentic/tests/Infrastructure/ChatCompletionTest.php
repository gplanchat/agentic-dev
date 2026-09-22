<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Infrastructure;

use Gplanchat\Agentic\Application\Chat\ToolCallRef;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\ChatCompletion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The "chat completions" shape lives nowhere but here — so this is where it must be held.
 *
 * {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ScriptedChatModelClient} writes it, {@see \Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript} and
 * {@see \Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow} read it back. The scripted model being exercised
 * only by the demo launched by hand, nothing else would notice that a key had moved: both sides
 * would drift together and the real provider, for its part, would not drift.
 *
 * Hence literals rather than a round trip alone: what is checked is the exact shape, not its
 * consistency with itself.
 */
#[CoversClass(ChatCompletion::class)]
final class ChatCompletionTest extends TestCase
{
    public function testPlainTextKeepsTheSimpleStringEveryProviderExpects(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop']]],
            ChatCompletion::ofText('Hello.')->toWire(),
        );
    }

    /**
     * Mistral's shape: `thinking`/`text` chunks, and not a `reasoning_content` on the side — which
     * Mistral refuses with a 422.
     */
    public function testReasoningIsWrittenAsThinkingChunks(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'I weigh it up.']]],
                ['type' => 'text', 'text' => 'Hello.'],
            ]], 'finish_reason' => 'stop']]],
            ChatCompletion::ofText('Hello.', 'I weigh it up.')->toWire(),
        );
    }

    public function testAToolCallCarriesItsArgumentsAsAJsonString(): void
    {
        self::assertSame(
            ['choices' => [['message' => ['content' => null, 'tool_calls' => [[
                'id' => 'call_3',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Paris"}'],
            ]]], 'finish_reason' => 'tool_calls']]],
            ChatCompletion::ofToolCall(new ToolCallRef('call_3', 'weather', ['city' => 'Paris']))->toWire(),
        );
    }

    public function testWhatIsWrittenIsWhatIsRead(): void
    {
        $readBack = ChatCompletion::fromWire(
            ChatCompletion::ofToolCall(new ToolCallRef('call_3', 'weather', ['city' => 'Paris']))->toWire(),
        );

        self::assertNotNull($readBack);
        self::assertNull($readBack->text);
        self::assertEquals([new ToolCallRef('call_3', 'weather', ['city' => 'Paris'])], $readBack->toolCalls);

        $reasoned = ChatCompletion::fromWire(ChatCompletion::ofText('Hello.', 'I weigh it up.')->toWire());
        self::assertSame('Hello.', $reasoned?->text);
        self::assertSame('I weigh it up.', $reasoned?->reasoning);
    }

    /**
     * A journal that does not carry a reply yet returns `null`, and that is not a shortcoming: it is
     * what tells a turn in progress from a finished one ({@see \Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript}).
     */
    public function testAnAbsentAnswerIsNull(): void
    {
        self::assertNull(ChatCompletion::fromWire(null));
        self::assertNull(ChatCompletion::fromWire('not an array'));
        self::assertNull(ChatCompletion::fromWire(['choices' => []]));
    }
}
