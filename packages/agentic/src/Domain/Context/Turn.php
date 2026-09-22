<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Context;

/**
 * One conversation turn: the human's message, and everything the agent produced in reply — its
 * tool calls, their results, its final answer.
 *
 * This is **the indivisible unit of compaction**. An `assistant` message asking for tools and the
 * `tool` messages answering it form one block: cutting through the middle leaves an orphaned
 * result, which providers refuse. Making the turn a type rather than a convention makes the
 * mistake impossible to commit by inattention.
 *
 * The messages themselves stay arrays: this is wire, the exact shape belongs to the provider, and
 * retyping it would amount to rewriting its protocol.
 */
final readonly class Turn
{
    /**
     * @param non-empty-list<array<string, mixed>> $messages
     */
    private function __construct(
        public array $messages,
    ) {
    }

    /**
     * @param non-empty-list<array<string, mixed>> $messages
     */
    public static function of(array $messages): self
    {
        if ([] === $messages) {
            throw new \InvalidArgumentException('A turn carries at least one message.');
        }

        return new self($messages);
    }

    public function count(): int
    {
        return \count($this->messages);
    }
}
