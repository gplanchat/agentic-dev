<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * A message of the thread. The `role` stays the provider's string: it is the vocabulary of the
 * protocol, not of the domain, and an unknown role must not make reading the journal fail.
 */
final readonly class TranscriptMessage implements \JsonSerializable
{
    /** What announces a summary in the thread — for display, never to the model. */
    private const COMPACTION_PREFIX = 'Summary of our previous conversation: ';

    /**
     * @param list<ToolCallRef> $toolCalls
     */
    private function __construct(
        public string $role,
        public ?string $content,
        public array $toolCalls,
        public ?string $reasoning = null,
    ) {
    }

    /**
     * @param array<string, mixed> $wire
     */
    public static function fromWire(array $wire): self
    {
        [$content, $reasoning] = self::splitContent($wire['content'] ?? null, $wire['reasoning_content'] ?? null);

        return new self(
            (string) ($wire['role'] ?? 'assistant'),
            $content,
            array_map(ToolCallRef::fromWire(...), $wire['tool_calls'] ?? []),
            $reasoning,
        );
    }

    /**
     * Separates what was said from what was thought, in the shape the Mistral bridge gives a
     * reasoned turn: a list of `thinking` and `text` chunks in place of the usual string.
     *
     * The generic contract, for its part, puts the reasoning in a `reasoning_content` on the side —
     * which Mistral refuses with a 422. Both shapes are therefore read here, because the thread is a
     * projection of the journal and a journal carries what the provider of the day wrote in it:
     * changing bridge must not make the conversations of before unreadable.
     *
     * @param mixed $content the raw value of `message.content`
     *
     * @return array{0: string|null, 1: string|null} the text, then the reasoning
     */
    public static function splitContent(mixed $content, ?string $sidecar = null): array
    {
        $reasoning = trim((string) ($sidecar ?? ''));

        if (!\is_array($content)) {
            $text = null !== $content ? (string) $content : null;

            return [$text, '' === $reasoning ? null : $reasoning];
        }

        $text = '';
        foreach ($content as $chunk) {
            if (!\is_array($chunk)) {
                continue;
            }

            if ('text' === ($chunk['type'] ?? null) && \is_string($chunk['text'] ?? null)) {
                $text .= $chunk['text'];
            } elseif ('thinking' === ($chunk['type'] ?? null)) {
                foreach ($chunk['thinking'] ?? [] as $part) {
                    if (\is_array($part) && \is_string($part['text'] ?? null)) {
                        $reasoning .= $part['text'];
                    }
                }
            }
        }

        return ['' === $text ? null : $text, '' === trim($reasoning) ? null : trim($reasoning)];
    }

    public static function assistant(string $content, ?string $reasoning = null): self
    {
        return new self('assistant', $content, [], $reasoning);
    }

    public static function user(string $content): self
    {
        return new self('user', $content, []);
    }

    /**
     * The summary that replaces a resumed conversation.
     *
     * One factory and not two `sprintf`: the workflow builds it for the model, the projection
     * rebuilds it for display, and the two must land on the same message — otherwise the visible
     * thread changes its text on the first turn.
     */
    public static function compaction(string $digest): self
    {
        return new self('assistant', self::COMPACTION_PREFIX . $digest, []);
    }

    /**
     * The text without its label.
     *
     * The prefix is there for the human. Handing it back to the model at the next compaction would
     * make it summarise a labelled summary — and a resume gets resumed in turn: on the second
     * round, the label would find itself nested inside its own text.
     */
    public function stripped(): self
    {
        $content = (string) $this->content;

        return str_starts_with($content, self::COMPACTION_PREFIX)
            ? new self($this->role, substr($content, \strlen(self::COMPACTION_PREFIX)), $this->toolCalls, $this->reasoning)
            : $this;
    }

    public function isSystem(): bool
    {
        return 'system' === $this->role;
    }

    public function isUser(): bool
    {
        return 'user' === $this->role;
    }

    /**
     * What makes sense to carry over into a new execution: the spoken turns.
     *
     * A `role: tool` and an assistant message that carries nothing but `tool_calls` tell the
     * mechanics of a run that is ending; replaying them in the next one would fill the thread with
     * empty bubbles and the bag with references to calls that no longer exist.
     */
    public function carriesText(): bool
    {
        return null !== $this->content && '' !== $this->content
            && ('user' === $this->role || 'assistant' === $this->role);
    }

    /**
     * The shape of the thread, the one the payload of a model call already carries — not one more
     * format.
     *
     * **The reasoning does not go in it.** This method feeds two things that go out to the model:
     * the compaction payload, and the thread the relay hands to the next run. Adding a key to it
     * would change an outgoing payload — and an outgoing payload that changes is a replay that
     * diverges. Reasoning is display; it goes out through {@see jsonSerialize()}.
     *
     * An accepted consequence: after a relay, the resumed thread no longer has its reasoning blocks.
     * What gets resumed is the spoken turns.
     *
     * @return array{role: string, content: string}
     */
    public function toWire(): array
    {
        return ['role' => $this->role, 'content' => (string) $this->content];
    }

    /**
     * @param list<array<string, mixed>> $wire
     *
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(self::fromWire(...), array_values($wire));
    }

    /**
     * @param list<self> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    public static function listToWire(array $messages): array
    {
        return array_map(static fn (self $message): array => $message->toWire(), array_values($messages));
    }

    /**
     * @return array{role: string, content: string|null, toolCalls: list<ToolCallRef>, reasoning: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'toolCalls' => $this->toolCalls,
            'reasoning' => $this->reasoning,
        ];
    }
}
