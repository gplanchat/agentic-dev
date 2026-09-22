<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

/**
 * Three outcomes: go through, ask the human, refuse. A refusal is not an exception — it goes back
 * as a tool result handed to the model, which can then adapt instead of crashing.
 */
final readonly class ToolDecision
{
    private function __construct(
        private ToolVerdict $verdict,
        public ?string $reason,
    ) {
    }

    /**
     * The call goes out without anyone having to decide.
     */
    public function isAllowed(): bool
    {
        return ToolVerdict::Allow === $this->verdict;
    }

    /**
     * The call suspends execution until a human decision — or until the deadline.
     */
    public function needsApproval(): bool
    {
        return ToolVerdict::Ask === $this->verdict;
    }

    /**
     * The call will not go out, whatever the mode: the policy forbids it.
     */
    public function isDenied(): bool
    {
        return ToolVerdict::Deny === $this->verdict;
    }

    public static function allow(): self
    {
        return new self(ToolVerdict::Allow, null);
    }

    public static function ask(string $reason): self
    {
        return new self(ToolVerdict::Ask, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(ToolVerdict::Deny, $reason);
    }
}
