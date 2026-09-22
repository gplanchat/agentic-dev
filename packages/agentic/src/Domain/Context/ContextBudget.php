<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Context;

/**
 * The context budget of an agent instance, and the way to hold it.
 *
 * A durable conversation grows without end — that is the price of the journal. Left unbounded, it
 * eventually overflows the model window, and on that day the agent does not miss a turn: it can no
 * longer take a single one. The budget is therefore a guard, just like the one on tools, but set
 * on the other leg: the model call.
 *
 * **Pure by construction.** Compaction runs in workflow code, so it is replayed: two executions
 * over the same conversation must produce exactly the same payload. No clock, no randomness, no
 * model call to summarise — a summary produced by a model would be a second source of
 * non-determinism right where we are trying to remove one.
 */
final readonly class ContextBudget
{
    /**
     * Four characters per token: the usual order of magnitude on Latin text.
     *
     * ponytail: a heuristic, not a measurement. It underestimates code and non-Latin languages.
     * The real count needs the model's tokeniser — to be wired in the day the margin no longer
     * suffices, and that is exactly what the reserve is for.
     */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        public int $maxTokens = 24_000,
        /**
         * What we keep for the reply and for the imprecision of the estimate. A budget held to the
         * byte would be blown by the first miscounted word.
         */
        public float $reserve = 0.25,
    ) {
        if ($maxTokens < 1) {
            throw new \InvalidArgumentException('A context budget must be positive.');
        }
    }

    public function halved(): self
    {
        return new self(max(1, intdiv($this->maxTokens, 2)), $this->reserve);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function estimate(array $messages): int
    {
        return intdiv(mb_strlen(json_encode($messages, \JSON_UNESCAPED_UNICODE) ?: ''), self::CHARS_PER_TOKEN);
    }

    public function ceiling(): int
    {
        return (int) ($this->maxTokens * (1 - $this->reserve));
    }

    /**
     * Brings the conversation back under the ceiling by dropping the oldest turns.
     *
     * Splitting into turns lives in {@see Conversation}: here we only remove them from the front
     * as long as it overflows. It is {@see Turn} that guarantees a tool result never ends up
     * without the call that produced it.
     *
     * ponytail: an accepted ceiling — a turn that is on its own bigger than the window cannot be
     * compacted, since dropping the very message that must be answered would make no sense. It
     * goes out as is, the provider refuses it, and the reactive path takes over. A model-made
     * summary would be the way out — but it is not deterministic, so it cannot live here: its
     * place is in a `continueAsNew`, where it becomes a starting payload.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array<string, mixed>>
     */
    public function fit(array $messages): array
    {
        if ($this->estimate($messages) <= $this->ceiling()) {
            return $messages;
        }

        $conversation = Conversation::fromWire($messages);
        $before = $conversation->messageCount();

        while (!$conversation->hasSingleTurn() && $this->estimate($conversation->toWire()) > $this->ceiling()) {
            $conversation = $conversation->withoutOldestTurn();
        }

        $dropped = $before - $conversation->messageCount();
        if (0 === $dropped) {
            return $messages;
        }

        return $conversation->withNotice(\sprintf(
            '[%d older messages were dropped from the context to fit the model '
            .'window. If something is missing, ask for it rather than inventing it.]',
            $dropped,
        ))->toWire();
    }
}
