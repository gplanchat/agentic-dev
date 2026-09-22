<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

/**
 * What an approval request has become. Three outcomes, not a boolean: "refused" and "never
 * answered" look alike in their effects — the tool does not go out — but not at all in what they
 * say. One is a decision, the other is the absence of a decision, and a journal that conflates
 * them no longer lets anyone know whether someone actually looked.
 */
enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case Refused = 'refused';
    case Expired = 'expired';

    /**
     * The only outcome that lets the tool go out.
     */
    public function isApproved(): bool
    {
        return self::Approved === $this;
    }

    /**
     * Nobody decided: the deadline did it in their place. Distinct from a refusal, which is a
     * decision.
     */
    public function isExpired(): bool
    {
        return self::Expired === $this;
    }

    /**
     * What the model reads in place of the tool result.
     */
    public function message(): string
    {
        return match ($this) {
            self::Approved => '',
            self::Refused => 'Refused by the user.',
            self::Expired => 'Refused: no approval arrived before the deadline.',
        };
    }
}
