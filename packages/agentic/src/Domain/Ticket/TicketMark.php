<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Ticket;

/**
 * What a ticket's labels say about who may move it now: it waits on someone, or a conversation has
 * taken it. The values are the forge labels themselves — Épopée's `⛔ attend:` vocabulary, and its
 * `PRISES` as `pris`.
 */
enum TicketMark: string
{
    /** A decision only the author can take. */
    case WaitsForAuthor = 'attend:auteur';

    /** Someone else must deliver first: a design, another team, a supplier. */
    case WaitsForThirdParty = 'attend:tiers';

    /** A fact nobody has measured yet. */
    case WaitsForMeasure = 'attend:mesure';

    /** A conversation is working on it: nobody else takes it. */
    case Taken = 'pris';

    /**
     * A wait means the work does not exist yet: nothing to take until it is lifted.
     */
    public function isWait(): bool
    {
        return match ($this) {
            self::WaitsForAuthor, self::WaitsForThirdParty, self::WaitsForMeasure => true,
            self::Taken => false,
        };
    }
}
