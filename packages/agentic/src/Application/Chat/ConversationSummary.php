<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

/**
 * Une conversation passée, telle que `/resume` la propose.
 */
final readonly class ConversationSummary
{
    public function __construct(
        public string $id,
        /** Le premier message de l'humain : ce qui permet de la reconnaître. */
        public string $title,
        public bool $finished,
        public ?\DateTimeImmutable $startedAt = null,
    ) {
    }
}
