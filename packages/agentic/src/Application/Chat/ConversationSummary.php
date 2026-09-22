<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * A past conversation, as `/resume` offers it.
 */
final readonly class ConversationSummary
{
    public function __construct(
        public string $id,
        /** The first message from the human: what makes it recognisable. */
        public string $title,
        public bool $finished,
        public ?\DateTimeImmutable $startedAt = null,
    ) {
    }
}
