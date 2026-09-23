<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Context;

/**
 * What a run has spent, and what it may.
 *
 * The other half of {@see ContextBudget}, and not the same half at all: that one bounds **one
 * call** — how much conversation fits in the model's window — while this one bounds **the whole
 * run**. A conversation can sit comfortably under the window on every single turn and still cost a
 * fortune over four hundred of them. Nothing here was counting that.
 *
 * **Deterministic under replay, though it is mutable.** It is fed from the results of
 * `ai_model_invoke` activities, which come back from the journal in journal order: replaying a run
 * adds the same numbers in the same sequence and lands on the same total. That is the whole reason
 * it can live in workflow state next to the approval gate and the watch desk, rather than being
 * recomputed from the outside.
 *
 * `maxTokens` of 0 means no ceiling — the ledger still counts, because counting is the part nobody
 * can do afterwards.
 */
final class TokenLedger
{
    private int $spent = 0;

    public function __construct(private readonly int $maxTokens = 0)
    {
        if ($maxTokens < 0) {
            throw new \InvalidArgumentException('A token budget cannot be negative; 0 means no ceiling.');
        }
    }

    /**
     * Adds what a model call cost, as the provider reported it.
     *
     * Providers do not agree on the names: `total_tokens` when it is given, otherwise the two parts
     * added up, under either spelling. A reply that reports nothing adds nothing — the alternative
     * would be to invent a number, and a budget built on invented numbers is worse than none.
     *
     * @param array<string, mixed> $result the provider's reply, straight out of the journal
     */
    public function record(array $result): void
    {
        $usage = $result['usage'] ?? null;
        if (!\is_array($usage)) {
            return;
        }

        $total = (int) ($usage['total_tokens'] ?? 0);
        if (0 === $total) {
            $total = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0)
                + (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        }

        $this->spent += max(0, $total);
    }

    /**
     * Adds a total someone else counted — a delegate reporting what its whole subtree cost.
     *
     * This is what makes the accounting recursive without any recursion here: a child already
     * added its own children's totals to its ledger before reporting, so one addition per level
     * carries the entire tree below it.
     */
    public function add(int $tokens): void
    {
        $this->spent += max(0, $tokens);
    }

    public function spent(): int
    {
        return $this->spent;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    /**
     * Has the run spent what it was given? Always false without a ceiling.
     */
    public function isSpent(): bool
    {
        return 0 !== $this->maxTokens && $this->spent >= $this->maxTokens;
    }
}
