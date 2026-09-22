<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * The state of waiting for a human approval. This is **workflow state**: it is rebuilt by replay
 * from the journaled signals and timers, never read from some storage on the side.
 *
 * That is what sets this approval apart from Symfony AI's `ToolCallRequested` event: `deny()` is a
 * synchronous hook in the current process, whereas here the agent can stay suspended for three
 * days, across a redeployment, until someone decides — or until the deadline decides in their
 * place.
 */
final class ToolApprovalGate
{
    /** @var array<string, ApprovalOutcome> */
    private array $outcomes = [];

    /** @var array<string, PendingApproval> */
    private array $pending = [];

    public function ask(ToolInvocation $toolCall, string $reason): void
    {
        $this->pending[$toolCall->callId] = PendingApproval::of($toolCall, $reason);
    }

    /**
     * A human decision, arrived by signal.
     */
    public function decide(string $callId, bool $approved): void
    {
        $this->settle($callId, $approved ? ApprovalOutcome::Approved : ApprovalOutcome::Refused);
    }

    /**
     * The deadline decided for lack of an answer. Distinct from a refusal: nobody decided anything.
     */
    public function timeout(string $callId): void
    {
        $this->settle($callId, ApprovalOutcome::Expired);
    }

    public function isSettled(string $callId): bool
    {
        return \array_key_exists($callId, $this->outcomes);
    }

    public function outcome(string $callId): ?ApprovalOutcome
    {
        return $this->outcomes[$callId] ?? null;
    }

    /**
     * @return list<PendingApproval>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }

    private function settle(string $callId, ApprovalOutcome $outcome): void
    {
        // The first outcome wins: a signal arriving after the deadline fired must not resurrect a
        // call the workflow has already settled — on replay, the order of the journal would replay
        // the opposite.
        $this->outcomes[$callId] ??= $outcome;
        unset($this->pending[$callId]);
    }
}
