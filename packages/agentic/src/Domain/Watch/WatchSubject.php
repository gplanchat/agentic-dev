<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * A subject a watch can look out for: an event the application knows how to publish.
 *
 * **This is the act of design, as {@see \Gplanchat\Agentic\Domain\Guard\ToolEffect} is for the
 * guard.** A watch described in free text is a watch nothing will ever be able to lift: the agent
 * would write "when the delivery arrives", the business event would say `order.shipped`, and nobody
 * would make the connection — with no error, with no trace, the agent would sleep until its
 * deadline.
 *
 * The vocabulary stays closed, but it is the application that closes it ({@see WatchSubjects}): the
 * facts of a shop are not those of a catalogue import, and a component that enumerated them would
 * force everyone to fork it to add a case.
 */
final readonly class WatchSubject
{
    public function __construct(
        public string $value,
        /** What the model reads in the schema: without it, it would pick at random among opaque strings. */
        public string $description,
    ) {
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('A watch subject must carry a name.');
        }
    }

    public function describe(): string
    {
        return $this->description;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
