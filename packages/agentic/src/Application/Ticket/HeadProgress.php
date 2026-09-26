<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;

/**
 * A head and how much of its work is closed — the sum of its leaves, never a total typed by hand
 * (EWA-002 § 8).
 */
final readonly class HeadProgress
{
    public function __construct(
        public Ticket $head,
        public HeadKind $kind,
        public int $closed,
        public int $total,
    ) {
    }
}
