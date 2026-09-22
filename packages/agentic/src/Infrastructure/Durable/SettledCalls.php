<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\Durable;

/**
 * The tool calls the journal has already answered.
 *
 * Four different events settle a call — the activity that runs it, the decision of the guard, the
 * answer to a question, the alert that lifts a watch — but the projection makes only one use of
 * them: deciding whether the last turn is still waiting for something. So it was four
 * `array<string, true>` sets walked by a single condition with four `isset()`, where adding a fifth
 * way of settling a call meant remembering to add it in both places.
 *
 * One set, one question: {@see has()}.
 */
final class SettledCalls
{
    /** @var array<string, true> */
    private array $ids = [];

    /**
     * The empty identifier goes in like any other, and that is deliberate: it is what the four
     * arrays used to do. A malformed signal then settles the call without an identifier — two
     * degenerate cases cancelling each other out. Refusing it here would be a fix, not a
     * restatement, and it would change the displayed status.
     */
    public function settle(string $callId): void
    {
        $this->ids[$callId] = true;
    }

    public function has(string $callId): bool
    {
        return isset($this->ids[$callId]);
    }
}
