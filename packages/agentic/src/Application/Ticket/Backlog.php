<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Ticket;

use Gplanchat\Agentic\Domain\Ticket\BlockingGraph;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
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
