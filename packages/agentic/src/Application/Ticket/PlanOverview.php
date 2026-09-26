<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Ticket;

use Gplanchat\Agentic\Domain\Ticket\Ticket;

/**
 * The plan at a glance, as `status` reads it: the heads and how far along they are, what can be
 * taken now, what is taken, what waits on someone, what waits on another ticket, and the work that
 * hangs under no open head — an orphan EWA-002 § 3 forbids.
 */
final readonly class PlanOverview
{
    /**
     * @param list<HeadProgress>    $heads
     * @param list<Ticket>          $ready    open, free, nothing it waits on: can be taken now
     * @param list<Ticket>          $taken
     * @param list<Ticket>          $waiting  on the author, a third party, a measure
     * @param array<int, list<int>> $blocked  ticket number → the open tickets it waits on
     * @param list<Ticket>          $orphans  work under no open head
     * @param list<Ticket>          $toSplit  capabilities with no work ticket yet: to frame
     */
    public function __construct(
        public array $heads,
        public array $ready,
        public array $taken,
        public array $waiting,
        public array $blocked,
        public array $orphans,
        public array $toSplit,
    ) {
    }
}
