<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * What a run hands back when it ends: what it said, and what it cost.
 *
 * It used to be the reply alone, a bare string. The cost had to join it for one reason: a delegate
 * is a **child workflow with its own journal**, so nothing its caller can read tells it what the
 * delegation spent. The return value is the only channel from a child back to its parent — a
 * signal would be machinery for the same result, and reading the child's journal is I/O a workflow
 * may not do.
 *
 * The same number then reaches the projection for free: it lands in the parent's journal as the
 * result of `ChildWorkflowCompleted`, so what is displayed and what the ledger enforced are
 * literally the same value rather than two counts hoping to agree.
 *
 * A wire array at the boundary and a type above it, as
 * `docs/decisions/ADR-001-value-objects-and-enums.md` has it: this crosses the journal, so
 * `fromWire()` tolerates what an older run wrote — a plain string, from before the cost travelled.
 */
final readonly class AgentOutcome
{
    public function __construct(
        public string $answer,
        public int $tokensSpent = 0,
    ) {
    }

    /**
     * A run from before this existed returned the reply on its own. Reading it back as an answer
     * that cost nothing is the only choice that does not lose the thread — and the alternative,
     * failing on an old journal, would make the change unreplayable.
     */
    public static function fromWire(mixed $wire): self
    {
        if (\is_array($wire)) {
            return new self((string) ($wire['answer'] ?? ''), max(0, (int) ($wire['tokensSpent'] ?? 0)));
        }

        return new self(\is_scalar($wire) ? (string) $wire : '');
    }

    /**
     * @return array{answer: string, tokensSpent: int}
     */
    public function toWire(): array
    {
        return ['answer' => $this->answer, 'tokensSpent' => $this->tokensSpent];
    }
}
