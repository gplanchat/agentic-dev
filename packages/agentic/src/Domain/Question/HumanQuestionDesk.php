<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Question;

/**
 * The desk of pending questions. Workflow state, rebuilt by replay from the journaled signals —
 * never read from some storage on the side.
 *
 * A twin of {@see \Gplanchat\Agentic\Domain\Guard\ToolApprovalGate} in shape, not in role: the gate lets through or
 * not what the model decided, the desk brings back to it what it did not know.
 */
final class HumanQuestionDesk
{
    /** @var array<string, list<string>> call id → answers kept */
    private array $answers = [];

    /** @var array<string, PendingQuestion> */
    private array $pending = [];

    public function ask(PendingQuestion $question): void
    {
        $this->pending[$question->callId] = $question;
    }

    /**
     * @param list<string> $answers
     */
    public function answer(string $callId, array $answers): void
    {
        // The first answer wins: a second signal arriving after the deadline must not reopen an
        // already closed question, otherwise the order of the journal would give the opposite
        // verdict on replay.
        $this->answers[$callId] ??= array_values(array_filter(
            array_map(static fn (mixed $answer): string => trim((string) $answer), $answers),
            static fn (string $answer): bool => '' !== $answer,
        ));
        unset($this->pending[$callId]);
    }

    public function isAnswered(string $callId): bool
    {
        return \array_key_exists($callId, $this->answers);
    }

    /**
     * @return list<string>
     */
    public function answersOf(string $callId): array
    {
        return $this->answers[$callId] ?? [];
    }

    /**
     * @return list<PendingQuestion>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
