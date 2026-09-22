<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Context;

/**
 * The conversation as compaction sees it: a system preamble, then turns.
 *
 * The flat array that goes out to the provider does not state its invariants — that the system
 * message opens it, that a tool result always follows its call. Here they are carried by the
 * structure, and {@see ContextBudget} only has to remove turns from the front.
 *
 * A boundary factory both ways: `fromWire()` on the way in, `toWire()` on the way out. The wire
 * stays the wire, but it no longer runs through the code.
 */
final readonly class Conversation
{
    /**
     * @param list<array<string, mixed>> $system
     * @param list<Turn>                 $turns
     */
    private function __construct(
        public array $system,
        public array $turns,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public static function fromWire(array $messages): self
    {
        $system = [];
        $turns = [];
        $current = [];

        foreach ($messages as $message) {
            // Only the *leading* system messages are the preamble: a compaction marker set further
            // down belongs to the turn it precedes.
            if ('system' === ($message['role'] ?? null) && [] === $turns && [] === $current) {
                $system[] = $message;

                continue;
            }

            if ('user' === ($message['role'] ?? null) && [] !== $current) {
                $turns[] = Turn::of($current);
                $current = [];
            }

            $current[] = $message;
        }

        if ([] !== $current) {
            $turns[] = Turn::of($current);
        }

        return new self($system, $turns);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toWire(): array
    {
        $messages = $this->system;
        foreach ($this->turns as $turn) {
            foreach ($turn->messages as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The last turn never goes: without it there would be nothing left to answer.
     */
    public function withoutOldestTurn(): self
    {
        if (\count($this->turns) <= 1) {
            return $this;
        }

        $turns = $this->turns;
        array_shift($turns);

        return new self($this->system, array_values($turns));
    }

    public function hasSingleTurn(): bool
    {
        return \count($this->turns) <= 1;
    }

    public function messageCount(): int
    {
        return \count($this->system) + array_sum(array_map(static fn (Turn $t): int => $t->count(), $this->turns));
    }

    public function withNotice(string $notice): self
    {
        return new self([...$this->system, ['role' => 'system', 'content' => $notice]], $this->turns);
    }
}
