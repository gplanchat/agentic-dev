<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Application\Chat\ToolCallRef;
use Gplanchat\Agentic\Application\Chat\TranscriptMessage;

/**
 * The reply of a "chat completions" provider, read once and for all.
 *
 * `$data['choices'][0]['message'][…]` was written by hand everywhere it was needed — the workflow
 * for the compaction, the projection three times, the scripted model to build it. That many
 * chances to get the level wrong, and a change of provider to make that many times. Here the shape
 * is described once, both ways.
 *
 * A boundary factory like {@see \Gplanchat\Agentic\Domain\Context\Conversation}: `fromWire()` for what comes out
 * of the journal, `toWire()` for what the scripted model writes into it. The wire stays the wire,
 * it no longer runs through the code.
 *
 * What does **not** go through it: the error envelope. {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\ContextOverflow} reads an
 * `error.code`, not a `choice` — a window overflow is not a completion, and passing it through this
 * type would amount to manufacturing an empty one.
 */
final readonly class ChatCompletion
{
    /**
     * @param list<ToolCallRef> $toolCalls
     */
    private function __construct(
        public ?string $text,
        public ?string $reasoning,
        public array $toolCalls,
    ) {
    }

    /**
     * `null` when the journal carries no reply — and that is information, not a shortcoming: it is
     * what tells a turn in progress from a finished one.
     */
    public static function fromWire(mixed $result): ?self
    {
        $message = \is_array($result) ? $result['choices'][0]['message'] ?? null : null;
        if (!\is_array($message)) {
            return null;
        }

        $sidecar = $message['reasoning_content'] ?? null;
        [$text, $reasoning] = TranscriptMessage::splitContent(
            $message['content'] ?? null,
            \is_string($sidecar) ? $sidecar : null,
        );

        $calls = $message['tool_calls'] ?? null;

        return new self($text, $reasoning, array_map(
            ToolCallRef::fromWire(...),
            array_values(\is_array($calls) ? $calls : []),
        ));
    }

    public static function ofText(string $text, ?string $reasoning = null): self
    {
        return new self($text, $reasoning, []);
    }

    public static function ofToolCall(ToolCallRef $call): self
    {
        return new self(null, null, [$call]);
    }

    /**
     * The exact shape the bridge's converter expects — this is wire, it goes out as is.
     *
     * The reasoning is written as `thinking`/`text` chunks and not in a `reasoning_content` on the
     * side: that is Mistral's shape, and `splitContent()` reads both ({@see TranscriptMessage}).
     * Without reasoning we keep the plain string every other model expects.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $message = ['content' => null === $this->reasoning ? $this->text : [
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $this->reasoning]]],
            ['type' => 'text', 'text' => (string) $this->text],
        ]];

        if ([] !== $this->toolCalls) {
            $message['tool_calls'] = array_map(static fn (ToolCallRef $call): array => [
                'id' => $call->callId,
                'type' => 'function',
                'function' => [
                    'name' => $call->tool,
                    'arguments' => json_encode($call->arguments, \JSON_UNESCAPED_UNICODE),
                ],
            ], $this->toolCalls);
        }

        return ['choices' => [[
            'message' => $message,
            'finish_reason' => [] === $this->toolCalls ? 'stop' : 'tool_calls',
        ]]];
    }
}
