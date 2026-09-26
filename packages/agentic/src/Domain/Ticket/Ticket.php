<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Ticket;

/**
 * A ticket of the project's forge, as the agent sees it. Its number is the forge's own, the one
 * humans type (`#12`) — whatever internal id an adapter needs stays in the adapter.
 *
 * A head carries its family; a work ticket — the leaf one person finishes, the only place time is
 * logged (EWA-002 § 1) — carries none, and hangs under its head.
 */
final readonly class Ticket
{
    public function __construct(
        public int $number,
        public string $title,
        public TicketState $state,
        public string $body = '',
        /** `null`: not a head — a work ticket, or a ticket nobody classified. */
        public ?HeadKind $head = null,
        /** @var list<TicketMark> who it waits on, whether it is taken */
        public array $marks = [],
    ) {
        if ($number < 1) {
            throw new \InvalidArgumentException(\sprintf('A ticket number is positive, %d is not.', $number));
        }
    }

    public function isHead(): bool
    {
        return null !== $this->head;
    }

    public function has(TicketMark $mark): bool
    {
        return \in_array($mark, $this->marks, true);
    }

    /**
     * @return list<TicketMark>
     */
    public function waits(): array
    {
        return array_values(array_filter($this->marks, static fn (TicketMark $mark): bool => $mark->isWait()));
    }
}
