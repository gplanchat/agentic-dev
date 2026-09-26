<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Ticket;

use Gplanchat\Agentic\Domain\Ticket\BlockingGraph;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketMark;
use Gplanchat\Agentic\Domain\Ticket\TicketState;

/**
 * The project's plan on its forge, under the decidable rules of EWA-002: a work ticket hangs under
 * an open head (§ 3), an investigation says its bound and the decision it must enable (§ 5), a work
 * ticket closes with its code (§ 6), a head does not close over an open child (§ 7.5).
 *
 * Every refusal is a `\DomainException`: the model's request, not the forge, was wrong.
 */
final readonly class Backlog
{
    /** ponytail: a bound on the walk, not a limit of the plan. Raise it when a real plan hits it. */
    public const MAX_NODES = 200;

    public function __construct(private Tickets $tickets)
    {
    }

    /**
     * @param string|null $key      the journal's call id: opening survives a retry ({@see once()})
     * @param string|null $bound    an investigation's time box
     * @param string|null $decision the decision an investigation must enable
     */
    public function openHead(HeadKind $kind, string $title, string $body, ?string $key, ?string $bound = null, ?string $decision = null): Ticket
    {
        if (HeadKind::Investigation === $kind) {
            if ('' === trim((string) $bound) || '' === trim((string) $decision)) {
                throw new \DomainException('An investigation says how long it may take and which decision it must enable — without both it never ends (EWA-002 § 5).');
            }
            $body = rtrim($body)."\n\nTime box: ".trim((string) $bound)."\nDecision it must enable: ".trim((string) $decision);
        }

        return $this->once($body, $key, fn (string $body): Ticket => $this->tickets->openHead($kind, $title, $body));
    }

    public function openWork(int $head, string $title, string $body, ?string $key): Ticket
    {
        $parent = $this->tickets->get($head);
        if (!$parent->isHead()) {
            throw new \DomainException(\sprintf('#%d is not a head ticket: a work ticket hangs under a head (EWA-002 § 3).', $head));
        }
        if (TicketState::Open !== $parent->state) {
            throw new \DomainException(\sprintf('The head #%d is closed: open a new head, or reopen it, before adding work to it.', $head));
        }

        $work = $this->once($body, $key, fn (string $body): Ticket => $this->tickets->openWork($head, $title, $body));
        // Also after a retry that found the ticket: the failure may have come between the two.
        $this->tickets->adopt($head, $work->number);

        return $work;
    }

    /**
     * @throws \DomainException past {@see MAX_NODES}
     */
    public function graph(int $root): BlockingGraph
    {
        $tickets = [$root => $this->tickets->get($root)];
        $blockers = [];
        $queue = [$root];
        while (null !== $number = array_shift($queue)) {
            foreach ($this->tickets->blockers($number) as $blocker) {
                $blockers[$number][] = $blocker->number;
                if (!isset($tickets[$blocker->number])) {
                    if (\count($tickets) >= self::MAX_NODES) {
                        throw new \DomainException(\sprintf('What #%d waits on spans more than %d tickets.', $root, self::MAX_NODES));
                    }
                    $tickets[$blocker->number] = $blocker;
                    $queue[] = $blocker->number;
                }
            }
        }

        return new BlockingGraph($root, $tickets, $blockers);
    }

    /**
     * `$ticket` waits on `$blocker`. Nothing to do when the link is there already — a retried call
     * finds its own work done.
     *
     * @throws \DomainException when the link would close a cycle: every ticket of it would wait on
     *                          another, for ever
     */
    public function block(int $ticket, int $blocker): void
    {
        if ($ticket === $blocker || $this->graph($blocker)->contains($ticket)) {
            throw new \DomainException(\sprintf('#%d cannot wait on #%d: #%d already waits on #%d, directly or not — neither could ever start.', $ticket, $blocker, $blocker, $ticket));
        }

        foreach ($this->tickets->blockers($ticket) as $existing) {
            if ($existing->number === $blocker) {
                return;
            }
        }
        $this->tickets->block($ticket, $blocker);
    }

    public function closeHead(int $head): void
    {
        if (!$this->tickets->get($head)->isHead()) {
            throw new \DomainException(\sprintf('#%d is a work ticket: it closes with its code — "Closes #%d" in the commit that finishes it (EWA-002 § 6).', $head, $head));
        }
        $open = array_filter($this->tickets->children($head), static fn (Ticket $child): bool => TicketState::Open === $child->state);
        if ([] !== $open) {
            throw new \DomainException(\sprintf('The head #%d still has open work: %s (EWA-002 § 7.5).', $head, implode(', ', array_map(static fn (Ticket $child): string => '#'.$child->number, $open))));
        }

        $this->tickets->close($head);
    }

    /**
     * Posts a comment once per key: like opening, a retried post would say it twice.
     */
    public function comment(int $ticket, string $body, ?string $key): void
    {
        if ('' === trim($body)) {
            throw new \DomainException('A comment needs a body.');
        }
        if (null === $key) {
            $this->tickets->comment($ticket, $body);

            return;
        }

        if (!$this->posted($ticket, $key)) {
            $this->tickets->comment($ticket, rtrim($body)."\n\n".\sprintf('<!-- agentic:%s -->', $key));
        }
    }

    /**
     * A conversation takes a ticket — Épopée's PRISES, on the forge: a `pris` label, and a comment
     * saying by whom. Only what can really be worked on now: open, a leaf (or a head simple enough to
     * be its own leaf, EWA-002 § 4), waiting on nobody, not taken, every blocker done.
     *
     * @param string      $by  who takes it, as the comment says: the conversation's worktree
     * @param string|null $key the journal's call id: a retry finds its own comment and goes on
     */
    public function take(int $number, string $by, ?string $key): void
    {
        $ticket = $this->tickets->get($number);
        if (TicketState::Open !== $ticket->state) {
            throw new \DomainException(\sprintf('#%d is closed: nothing to take.', $number));
        }
        if (HeadKind::Capability === $ticket->head || ($ticket->isHead() && [] !== $this->tickets->children($number))) {
            throw new \DomainException(\sprintf('#%d is a head: take one of its work tickets — a capability without any is to be framed first.', $number));
        }
        if ([] !== $waits = $ticket->waits()) {
            throw new \DomainException(\sprintf('#%d waits (%s): that must be lifted before anyone takes it.', $number, implode(', ', array_map(static fn (TicketMark $mark): string => $mark->value, $waits))));
        }
        if ($ticket->has(TicketMark::Taken) && (null === $key || !$this->posted($number, $key))) {
            throw new \DomainException(\sprintf('#%d is taken already: its comments say by whom.', $number));
        }
        $blockers = array_filter($this->tickets->blockers($number), static fn (Ticket $blocker): bool => !$blocker->state->unblocks());
        if ([] !== $blockers) {
            throw new \DomainException(\sprintf('#%d waits on %s: take that first.', $number, implode(', ', array_map(static fn (Ticket $blocker): string => '#'.$blocker->number, $blockers))));
        }

        $this->comment($number, \sprintf('Taken by %s.', $by), $key);
        $this->tickets->mark($number, TicketMark::Taken);
    }

    /**
     * The ticket waits on someone: marked, the reason commented once, and given back if it was taken
     * — until the wait is lifted, nobody takes it again only to meet the same question.
     *
     * @throws \DomainException when the mark is not a wait
     */
    public function wait(int $number, TicketMark $on, string $reason, ?string $key): void
    {
        if (!$on->isWait()) {
            throw new \DomainException(\sprintf('"%s" is not a wait.', $on->value));
        }
        if ('' === trim($reason)) {
            throw new \DomainException('Say what it waits for: whoever lifts the wait has not your context.');
        }
        $this->comment($number, \sprintf('Waits (%s): %s', $on->value, trim($reason)), $key);
        $this->tickets->mark($number, $on);
        $this->release($number);
    }

    public function release(int $number): void
    {
        if ($this->tickets->get($number)->has(TicketMark::Taken)) {
            $this->tickets->unmark($number, TicketMark::Taken);
        }
    }

    /**
     * The plan at a glance. Bounded by what the forge lists in one page of open tickets.
     */
    public function overview(): PlanOverview
    {
        $open = $this->tickets->listOpen();
        $heads = [];
        $toSplit = [];
        $underAHead = [];
        $candidates = [];
        foreach ($open as $ticket) {
            if (null === $ticket->head) {
                $candidates[] = $ticket;

                continue;
            }
            $children = $this->tickets->children($ticket->number);
            foreach ($children as $child) {
                $underAHead[] = $child->number;
            }
            $heads[] = new HeadProgress($ticket, $ticket->head, \count(array_filter($children, static fn (Ticket $child): bool => TicketState::Open !== $child->state)), \count($children));
            if ([] === $children) {
                // A capability is split before it is worked; a simpler head is its own leaf.
                if (HeadKind::Capability === $ticket->head) {
                    $toSplit[] = $ticket;
                } else {
                    $candidates[] = $ticket;
                }
            }
        }

        $ready = $taken = $waiting = $orphans = [];
        $blocked = [];
        foreach ($candidates as $ticket) {
            if (!$ticket->isHead() && !\in_array($ticket->number, $underAHead, true)) {
                $orphans[] = $ticket;
            }
            if ($ticket->has(TicketMark::Taken)) {
                $taken[] = $ticket;
            } elseif ([] !== $ticket->waits()) {
                $waiting[] = $ticket;
            } elseif ([] !== $open = array_filter($this->tickets->blockers($ticket->number), static fn (Ticket $blocker): bool => !$blocker->state->unblocks())) {
                $blocked[$ticket->number] = array_values(array_map(static fn (Ticket $blocker): int => $blocker->number, $open));
            } else {
                $ready[] = $ticket;
            }
        }

        return new PlanOverview($heads, $ready, $taken, $waiting, $blocked, $orphans, $toSplit);
    }

    private function posted(int $number, string $key): bool
    {
        foreach ($this->tickets->comments($number) as $comment) {
            if (str_contains($comment, \sprintf('<!-- agentic:%s -->', $key))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Opens a ticket once per key, however often the call is retried: opening cannot be undone, and
     * an activity is retried after a failure that may have come *after* the forge created it. The key
     * is written in the body, and looked for among the latest tickets before opening.
     *
     * @param \Closure(string): Ticket $open
     */
    private function once(string $body, ?string $key, \Closure $open): Ticket
    {
        if (null === $key) {
            return $open($body);
        }

        $marker = \sprintf('<!-- agentic:%s -->', $key);
        foreach ($this->tickets->recent() as $ticket) {
            if (str_contains($ticket->body, $marker)) {
                return $ticket;
            }
        }

        return $open(rtrim($body)."\n\n".$marker);
    }
}
