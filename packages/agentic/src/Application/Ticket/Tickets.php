<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;

/**
 * Port: the tickets of the project's forge — GitHub, Forgejo, whatever an adapter speaks. The forge
 * is the source of truth of the plan: heads, their work tickets, and what waits on what.
 *
 * Forge-shaped on purpose: it opens, reads, links and closes; the rules of EWA-002 are laid on top
 * by {@see Backlog}. Pull requests are not tickets: an adapter whose forge lists both hands back only
 * the tickets. A ticket of another repository is refused, never read as the local one of the same
 * number.
 *
 * Every method may throw on a transport or forge failure: the activity running the tool retries it.
 * A `\DomainException` is the forge refusing what was asked — a missing label, a foreign ticket —
 * and goes back to the model.
 */
interface Tickets
{
    public function get(int $number): Ticket;

    /**
     * The tickets this one is blocked by.
     *
     * @return list<Ticket>
     */
    public function blockers(int $number): array;

    /**
     * The work tickets of a head.
     *
     * @return list<Ticket>
     */
    public function children(int $head): array;

    /**
     * The latest tickets, newest first, bodies included — one page, enough to find one just opened.
     *
     * @return list<Ticket>
     */
    public function recent(): array;

    /**
     * @throws \DomainException when the forge has no label for this family
     */
    public function openHead(HeadKind $kind, string $title, string $body): Ticket;

    /**
     * Opens a work ticket for `$head` — linked to it by {@see adopt()}, which the caller calls next.
     */
    public function openWork(int $head, string $title, string $body): Ticket;

    /**
     * `$work` hangs under `$head`. Nothing to do when it does already: a retried opening finds its
     * ticket, and must still find it linked.
     *
     * @throws \DomainException when `$work` hangs under another head: a leaf has one head
     */
    public function adopt(int $head, int $work): void;

    /** Closed as done. */
    public function close(int $number): void;

    /** `$number` is blocked by `$by`. */
    public function block(int $number, int $by): void;

    public function unblock(int $number, int $by): void;

    /**
     * The bodies of a ticket's comments, oldest first — enough to find one just posted.
     *
     * @return list<string>
     */
    public function comments(int $number): array;

    public function comment(int $number, string $body): void;
}
