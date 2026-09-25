<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Ticket;

/**
 * Where a ticket stands. Three cases and not a boolean: a forge that says *why* a ticket was closed
 * tells "done" from "given up", and the Mikado graph must not take one for the other.
 */
enum TicketState: string
{
    case Open = 'open';

    /** Closed because the work is done. */
    case Done = 'done';

    /**
     * Closed without the work being done: not planned, a duplicate. It does **not** unblock what it
     * was a prerequisite of — a prerequisite given up means the graph needs rethinking, not that the
     * way is clear.
     */
    case Abandoned = 'abandoned';

    public function unblocks(): bool
    {
        return match ($this) {
            self::Done => true,
            self::Open, self::Abandoned => false,
        };
    }
}
