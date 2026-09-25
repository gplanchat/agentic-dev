<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Ticket;

/**
 * What a ticket waits on: the tickets it is blocked by, theirs, and so on — the `⛔ attend:` of a
 * plan, between tickets of the project.
 *
 * Not the head/work tree: a ticket has one head, while one blocker often holds up several tickets.
 * The graph is therefore a DAG, and is walked as one — a node reached twice is listed once.
 */
final readonly class BlockingGraph
{
    /**
     * @param array<int, Ticket>    $tickets  number → ticket, every node of the graph
     * @param array<int, list<int>> $blockers number → the numbers of its blockers
     */
    public function __construct(
        public int $root,
        private array $tickets,
        private array $blockers,
    ) {
        if (!isset($tickets[$root])) {
            throw new \InvalidArgumentException(\sprintf('#%d is not in its own graph.', $root));
        }
    }

    /**
     * What can be taken now: open, and every blocker done. An abandoned blocker keeps what it
     * blocks blocked — see {@see TicketState::Abandoned}.
     *
     * @return list<Ticket>
     */
    public function ready(): array
    {
        return array_values(array_filter(
            $this->tickets,
            // No array_all(): this package stays installable on PHP 8.2.
            fn (Ticket $ticket): bool => TicketState::Open === $ticket->state && [] === array_filter(
                $this->blockers[$ticket->number] ?? [],
                fn (int $blocker): bool => !$this->tickets[$blocker]->state->unblocks(),
            ),
        ));
    }

    /**
     * @return list<Ticket>
     */
    public function abandoned(): array
    {
        return array_values(array_filter($this->tickets, static fn (Ticket $ticket): bool => TicketState::Abandoned === $ticket->state));
    }

    public function contains(int $number): bool
    {
        return isset($this->tickets[$number]);
    }

    /**
     * The graph as the model reads it: an indented tree, each node with its state, the ready ones
     * marked, a node already shown referred to instead of repeated.
     */
    public function render(): string
    {
        $ready = array_map(static fn (Ticket $ticket): int => $ticket->number, $this->ready());
        $lines = [];
        $seen = [];
        $walk = function (int $number, int $depth) use (&$walk, &$lines, &$seen, $ready): void {
            $ticket = $this->tickets[$number];
            $line = \sprintf('%s#%d %s [%s%s]', str_repeat('  ', $depth), $number, $ticket->title, $ticket->state->value, \in_array($number, $ready, true) ? ', READY' : '');
            if (\in_array($number, $seen, true)) {
                $lines[] = $line.' (see above)';

                return;
            }
            $seen[] = $number;
            $lines[] = $line;
            foreach ($this->blockers[$number] ?? [] as $blocker) {
                $walk($blocker, $depth + 1);
            }
        };
        $walk($this->root, 0);

        $abandoned = $this->abandoned();
        if ([] !== $abandoned) {
            $lines[] = '';
            $lines[] = 'Abandoned blockers keep what they block blocked — rethink the plan there: '
                .implode(', ', array_map(static fn (Ticket $ticket): string => '#'.$ticket->number, $abandoned)).'.';
        }

        return implode("\n", $lines);
    }
}
