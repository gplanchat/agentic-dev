<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

/**
 * The mode decides what goes through without asking. It is **workflow state**, changed by signal:
 * switching to `auto` in the middle of a conversation is journaled, hence replayed identically.
 */
enum AgentMode: string
{
    /** Everything goes through. Nobody is watching. */
    case Auto = 'auto';

    /** Writes go through, external effects ask. */
    case Edition = 'edition';

    /** Only reads go through. */
    case Standard = 'standard';

    /**
     * Reads go through; everything else is **refused**, not held for approval.
     *
     * The difference with `standard` is the whole point: there, an approval card interrupts the
     * human and the agent waits. Here nobody is asked, because the answer would always be no — the
     * agent is meant to look and then say what it would do. A refusal it reads straight away is
     * also what lets it carry on planning instead of suspending on a decision nobody will take.
     */
    case Plan = 'plan';

    /**
     * The rule of the mode, stated once — this is where it belongs, not in the comparisons of some
     * guard.
     *
     * | | read | write | external |
     * |---|---|---|---|
     * | `auto` | goes | goes | goes |
     * | `edition` | goes | goes | asks |
     * | `standard` | goes | asks | asks |
     * | `plan` | goes | refuses | refuses |
     *
     * A {@see ToolVerdict} and not a boolean: "does this need approval" is a two-state answer to a
     * three-state question, and `plan` is the state it could not express
     * (`docs/decisions/ADR-001-value-objects-and-enums.md`).
     */
    public function verdictFor(ToolEffect $effect): ToolVerdict
    {
        return match ($this) {
            self::Auto => ToolVerdict::Allow,
            self::Edition => $effect->isIrreversible() ? ToolVerdict::Ask : ToolVerdict::Allow,
            self::Standard => $effect->isHarmless() ? ToolVerdict::Allow : ToolVerdict::Ask,
            self::Plan => $effect->isHarmless() ? ToolVerdict::Allow : ToolVerdict::Deny,
        };
    }

    /**
     * How much this mode lets through. The three cases are a total order, and it lives here for the
     * same reason as {@see requiresApprovalFor()}: it is a property of the mode, not a comparison
     * every caller redoes its own way.
     */
    private function permissiveness(): int
    {
        return match ($this) {
            // `plan` is the floor: it lets through strictly less than `standard`, which still holds
            // a write for approval. Anything added below it shifts what `strictest()` and
            // `loosens()` mean, so the order is the contract, not the numbers.
            self::Plan => 0,
            self::Standard => 1,
            self::Edition => 2,
            self::Auto => 3,
        };
    }

    /**
     * The strictest of several modes — **authority does not grow by delegation**.
     *
     * A delegated agent takes the strictest of its chain. Without that, delegating would be the
     * escape hatch of the guard: an agent in `standard` cannot send an email, but it would hand the
     * task to a sub-agent in `auto` that would send it. The guard would not be worked around
     * through a flaw, it would have become decorative.
     */
    public static function strictest(self ...$modes): self
    {
        $strict = self::Auto;
        foreach ($modes as $mode) {
            if ($mode->permissiveness() < $strict->permissiveness()) {
                $strict = $mode;
            }
        }

        return $strict;
    }

    /**
     * Does this mode loosen the ceiling? That is the question a `set_mode` received along the way
     * raises — the ceiling holds on entry **and** afterwards, otherwise a sub-agent would lift it
     * with a single signal.
     */
    public function loosens(self $ceiling): bool
    {
        return $this->permissiveness() > $ceiling->permissiveness();
    }
}
