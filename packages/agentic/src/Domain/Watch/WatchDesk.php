<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * The watches in progress and the alerts received. Workflow state, rebuilt by replay.
 *
 * Third object of this shape after {@see \Gplanchat\Agentic\Domain\Guard\ToolApprovalGate} and
 * {@see \Gplanchat\Agentic\Domain\Question\HumanQuestionDesk}: a queue of waits by call identifier, settled by
 * signal, the first outcome winning. They are not merged because what they carry differs — a
 * three-case outcome, answers, an observation — and a generic object would replace three clear
 * types with a `mixed`. The day a fourth one arrives with the same payload as one of the three,
 * that will be the moment.
 */
final class WatchDesk
{
    /** @var array<string, string> call id → observation reported by the alert */
    private array $alerts = [];

    /** @var array<string, Watch> */
    private array $pending = [];

    public function watch(Watch $watch): void
    {
        $this->pending[$watch->callId] = $watch;
    }

    /**
     * An alert raised from outside: a webhook, a monitoring system, a human, another agent.
     */
    public function raise(string $callId, string $observation): void
    {
        // The first alert wins: a second one arriving after the deadline must not reopen an already
        // closed watch, otherwise the order of the journal would give the opposite verdict on
        // replay.
        $this->alerts[$callId] ??= trim($observation);
        unset($this->pending[$callId]);
    }

    public function isSettled(string $callId): bool
    {
        return \array_key_exists($callId, $this->alerts);
    }

    public function observationOf(string $callId): string
    {
        return $this->alerts[$callId] ?? '';
    }

    /**
     * @return list<Watch>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
