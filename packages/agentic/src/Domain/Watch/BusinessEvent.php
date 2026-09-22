<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * A fact of the application, stated in the vocabulary the watches know.
 *
 * `details` is free-form: it plays no part in the matching — it is the `subject` that matches — but
 * it makes the text the agent will read on waking, in place of a tool result. An object rather
 * than an array, because this is what crosses the boundary between the business and the agent.
 */
final readonly class BusinessEvent
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public WatchSubject $subject,
        public array $details = [],
    ) {
    }

    /**
     * What the agent reads on waking. No JSON: a model is going to read it.
     */
    public function describe(): string
    {
        if ([] === $this->details) {
            return $this->subject->describe();
        }

        return \sprintf(
            '%s (%s)',
            $this->subject->describe(),
            implode(', ', array_map(
                static fn (string $key, mixed $value): string => \sprintf('%s: %s', $key, var_export($value, true)),
                array_keys($this->details),
                $this->details,
            )),
        );
    }
}
