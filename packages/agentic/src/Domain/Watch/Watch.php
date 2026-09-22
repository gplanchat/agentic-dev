<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * A watch set by the agent: what it is watching for, and **what it meant to do about it**.
 *
 * The intent is written at the moment of registration, not rebuilt on waking. That is the whole
 * point: on waking — in an hour, in three days, after a redeployment — the agent does not have to
 * remember, the journal tells it.
 */
final readonly class Watch implements \JsonSerializable
{
    public function __construct(
        public string $callId,
        public WatchSubject $subject,
        public string $observation,
        public string $intent,
        public ?float $expiresAt = null,
    ) {
    }

    /**
     * A boundary factory: the arguments come from the model, nothing guarantees the schema.
     *
     * @param array<string, mixed> $arguments
     */
    public static function fromArguments(string $callId, array $arguments, WatchSubjects $subjects, ?float $expiresAt = null): self
    {
        $subject = $subjects->find(trim((string) ($arguments['subject'] ?? '')));
        if (null === $subject) {
            // A visible refusal rather than a dead watch: with no known subject, no event will ever
            // be able to lift this watch, and the agent would sleep until its deadline with nothing
            // to signal it.
            throw new UnknownWatchSubject(trim((string) ($arguments['subject'] ?? '')));
        }

        return new self(
            $callId,
            $subject,
            trim((string) ($arguments['observation'] ?? '')),
            trim((string) ($arguments['intent'] ?? '')),
            $expiresAt,
        );
    }

    /**
     * @return array{callId: string, subject: string, observation: string, intent: string, expiresAt: float|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'callId' => $this->callId,
            'subject' => $this->subject->value,
            'observation' => $this->observation,
            'intent' => $this->intent,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
