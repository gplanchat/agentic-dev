<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Application\Ticket;

use Gplanchat\Agentic\Application\Ticket\Backlog;
use Gplanchat\Agentic\Application\Ticket\Tickets;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Application\Ticket\HeadProgress;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketMark;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use PHPUnit\Framework\TestCase;

final class BacklogTest extends TestCase
{
    public function testAWorkTicketHangsUnderItsHead(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $head = $backlog->openHead(HeadKind::Capability, 'Tickets in the chat', 'Why.', null);

        $work = $backlog->openWork($head->number, '3.2 Forgejo adapter', 'How.', null);

        self::assertSame(HeadKind::Capability, $head->head);
        self::assertNull($work->head);
        self::assertSame([$work->number], self::numbers($forge->children($head->number)));
    }

    public function testAWorkTicketNeedsAnOpenHead(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $head = $backlog->openHead(HeadKind::Defect, 'H', '', null);
        $work = $backlog->openWork($head->number, 'W', '', null);

        self::assertRefused('#2 is not a head ticket: a work ticket hangs under a head (EWA-002 § 3).', static fn () => $backlog->openWork($work->number, 'W2', '', null));
        $forge->close($head->number);
        self::assertRefused('The head #1 is closed: open a new head, or reopen it, before adding work to it.', static fn () => $backlog->openWork($head->number, 'W2', '', null));
    }

    public function testAnInvestigationSaysItsBoundAndItsDecision(): void
    {
        $backlog = new Backlog(new InMemoryTickets());

        $ticket = $backlog->openHead(HeadKind::Investigation, 'Sub-issues on Forgejo?', "Context.\n", null, ' 2 days ', ' body reference or dependencies ');

        self::assertSame("Context.\n\nTime box: 2 days\nDecision it must enable: body reference or dependencies", $ticket->body);
        $refusal = 'An investigation says how long it may take and which decision it must enable — without both it never ends (EWA-002 § 5).';
        self::assertRefused($refusal, static fn () => $backlog->openHead(HeadKind::Investigation, 'T', '', null, '2 days'));
        self::assertRefused($refusal, static fn () => $backlog->openHead(HeadKind::Investigation, 'T', '', null, ' ', 'which'));
        self::assertRefused($refusal, static fn () => $backlog->openHead(HeadKind::Investigation, 'T', '', null, '2 days', ' '));
        self::assertSame('B', $backlog->openHead(HeadKind::Debt, 'T', 'B', null)->body, 'Only an investigation carries a bound.');
    }

    public function testARetriedOpeningFindsTheTicketItAlreadyOpened(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);

        $first = $backlog->openHead(HeadKind::Debt, 'Extract the port', "Why.\n", 'call-7');
        $retried = $backlog->openHead(HeadKind::Debt, 'Extract the port', "Why.\n", 'call-7');
        $work = $backlog->openWork($first->number, 'W', 'How.', 'call-8');

        self::assertSame($first->number, $retried->number);
        self::assertSame("Why.\n\n<!-- agentic:call-7 -->", $first->body);
        $forge->orphan($work->number); // the first try failed between opening and linking
        self::assertSame($work->number, $backlog->openWork($first->number, 'W', 'How.', 'call-8')->number);
        self::assertSame([$work->number], self::numbers($forge->children($first->number)), 'Linked by the retry.');
        self::assertCount(2, $forge->recent());
    }

    public function testWithoutAKeyEveryCallOpens(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);

        $backlog->openHead(HeadKind::Debt, 'A', '', null);
        $backlog->openHead(HeadKind::Debt, 'A', '', null);

        self::assertCount(2, $forge->recent());
    }

    public function testTheGraphFollowsTheBlockers(): void
    {
        $forge = new InMemoryTickets();
        $forge->add(1, 2, 3);
        $forge->link(1, 2);
        $forge->link(2, 3);

        $graph = (new Backlog($forge))->graph(1);

        self::assertTrue($graph->contains(3));
        self::assertSame([3], self::numbers($graph->ready()));
    }

    public function testBlockingLinksOnce(): void
    {
        $forge = new InMemoryTickets();
        $forge->add(1, 2);
        $backlog = new Backlog($forge);

        $backlog->block(1, 2);
        $backlog->block(1, 2);

        self::assertSame([2], self::numbers($forge->blockers(1)));
    }

    public function testALinkClosingACycleIsRefused(): void
    {
        $forge = new InMemoryTickets();
        $forge->add(1, 2, 3);
        $forge->link(1, 2);
        $forge->link(2, 3);
        $backlog = new Backlog($forge);

        self::assertRefused('#3 cannot wait on #1: #1 already waits on #3, directly or not — neither could ever start.', static fn () => $backlog->block(3, 1));
        self::assertSame([], $forge->blockers(3));
        self::assertRefused('#1 cannot wait on #1: #1 already waits on #1, directly or not — neither could ever start.', static fn () => $backlog->block(1, 1));
    }

    public function testAGraphPastTheBoundIsRefused(): void
    {
        $forge = new InMemoryTickets();
        $forge->add(...range(1, Backlog::MAX_NODES + 1));
        foreach (range(2, Backlog::MAX_NODES + 1) as $number) {
            $forge->link(1, $number);
        }

        $this->expectException(\DomainException::class);

        (new Backlog($forge))->graph(1);
    }

    public function testAHeadClosesOnceItsWorkIsDone(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $head = $backlog->openHead(HeadKind::Capability, 'H', '', null);
        $done = $backlog->openWork($head->number, 'W1', '', null);
        $open = $backlog->openWork($head->number, 'W2', '', null);
        $forge->close($done->number);

        self::assertRefused('The head #1 still has open work: #3 (EWA-002 § 7.5).', static fn () => $backlog->closeHead($head->number));
        self::assertRefused('#3 is a work ticket: it closes with its code — "Closes #3" in the commit that finishes it (EWA-002 § 6).', static fn () => $backlog->closeHead($open->number));

        $forge->close($open->number);
        $backlog->closeHead($head->number);

        self::assertSame(TicketState::Done, $forge->get($head->number)->state);
    }

    public function testACommentIsPostedOncePerKey(): void
    {
        $forge = new InMemoryTickets();
        $forge->add(1);
        $backlog = new Backlog($forge);

        $backlog->comment(1, "The graph.\n", 'call-3');
        $backlog->comment(1, 'The graph.', 'call-3');
        $backlog->comment(1, 'Plain.', null);

        self::assertSame(["The graph.\n\n<!-- agentic:call-3 -->", 'Plain.'], $forge->comments(1));
        self::assertRefused('A comment needs a body.', static fn () => $backlog->comment(1, ' ', 'call-4'));
    }

    public function testATicketIsTakenOnceAndSaysByWhom(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $head = $backlog->openHead(HeadKind::Capability, 'H', '', null);
        $work = $backlog->openWork($head->number, 'W', '', null);

        $backlog->take($work->number, 'agentic-3f2a9c1e', 'call-1');
        $backlog->take($work->number, 'agentic-3f2a9c1e', 'call-1');

        self::assertTrue($forge->get($work->number)->has(TicketMark::Taken));
        self::assertSame(["Taken by agentic-3f2a9c1e.\n\n<!-- agentic:call-1 -->"], $forge->comments($work->number), 'The retry found its own comment.');
        self::assertRefused('#2 is taken already: its comments say by whom.', static fn () => $backlog->take($work->number, 'agentic-other', 'call-2'));
        self::assertRefused('#2 is taken already: its comments say by whom.', static fn () => $backlog->take($work->number, 'agentic-other', null));

        $backlog->release($work->number);
        $backlog->release($work->number);
        self::assertSame([], $forge->get($work->number)->marks);
    }

    public function testATicketWaitsSaysWhyAndIsGivenBack(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $head = $backlog->openHead(HeadKind::Capability, 'H', '', null);
        $work = $backlog->openWork($head->number, 'W', '', null);
        $backlog->take($work->number, 'me', null);

        $backlog->wait($work->number, TicketMark::WaitsForAuthor, ' Which cache: PSR-6 or PSR-16? ', 'call-5');
        $backlog->wait($work->number, TicketMark::WaitsForAuthor, 'Which cache: PSR-6 or PSR-16?', 'call-5');

        self::assertSame([TicketMark::WaitsForAuthor], $forge->get($work->number)->marks, 'Given back, and waiting.');
        self::assertSame(["Taken by me.", "Waits (attend:auteur): Which cache: PSR-6 or PSR-16?\n\n<!-- agentic:call-5 -->"], $forge->comments($work->number));
        self::assertRefused('"pris" is not a wait.', static fn () => $backlog->wait($work->number, TicketMark::Taken, 'x', null));
        self::assertRefused('Say what it waits for: whoever lifts the wait has not your context.', static fn () => $backlog->wait($work->number, TicketMark::WaitsForMeasure, ' ', null));
        self::assertSame([TicketMark::WaitsForAuthor], $forge->get($work->number)->marks);
    }

    public function testOnlyWhatCanBeWorkedOnNowIsTaken(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $capability = $backlog->openHead(HeadKind::Capability, 'Cap', '', null);
        $debt = $backlog->openHead(HeadKind::Debt, 'Debt', '', null);
        $work = $backlog->openWork($capability->number, 'W', '', null);
        $blocker = $backlog->openWork($capability->number, 'Blocker', '', null);
        $forge->link($work->number, $blocker->number);

        self::assertRefused('#1 is a head: take one of its work tickets — a capability without any is to be scoped first.', static fn () => $backlog->take($capability->number, 'me', null));
        self::assertRefused('#3 waits on #4: take that first.', static fn () => $backlog->take($work->number, 'me', null));
        $forge->mark($blocker->number, TicketMark::WaitsForAuthor);
        $forge->mark($blocker->number, TicketMark::WaitsForMeasure);
        self::assertRefused('#4 waits (attend:auteur, attend:mesure): that must be lifted before anyone takes it.', static fn () => $backlog->take($blocker->number, 'me', null));
        $forge->close($blocker->number);
        self::assertRefused('#4 is closed: nothing to take.', static fn () => $backlog->take($blocker->number, 'me', null));

        $backlog->take($work->number, 'me', null);
        $backlog->take($debt->number, 'me', null);
        self::assertTrue($forge->get($debt->number)->has(TicketMark::Taken), 'A debt with no work ticket is its own leaf (EWA-002 § 4).');

        $backlog->openWork($debt->number, 'Split after all', '', null);
        $forge->unmark($debt->number, TicketMark::Taken);
        self::assertRefused('#2 is a head: take one of its work tickets — a capability without any is to be scoped first.', static fn () => $backlog->take($debt->number, 'me', null));
    }

    public function testThePlanAtAGlance(): void
    {
        $forge = new InMemoryTickets();
        $backlog = new Backlog($forge);
        $capability = $backlog->openHead(HeadKind::Capability, 'Cap', '', null);     // #1
        $empty = $backlog->openHead(HeadKind::Capability, 'Unscoped', '', null);     // #2
        $defect = $backlog->openHead(HeadKind::Defect, 'Bug', '', null);             // #3
        $done = $backlog->openWork($capability->number, 'Done', '', null);           // #4
        $ready = $backlog->openWork($capability->number, 'Ready', '', null);         // #5
        $taken = $backlog->openWork($capability->number, 'Taken', '', null);         // #6
        $waiting = $backlog->openWork($capability->number, 'Waiting', '', null);     // #7
        $blocked = $backlog->openWork($capability->number, 'Blocked', '', null);     // #8
        $forge->add(9);                                                              // an orphan
        $forge->close($done->number);
        $forge->mark($taken->number, TicketMark::Taken);
        $forge->mark($waiting->number, TicketMark::WaitsForThirdParty);
        $forge->link($blocked->number, $done->number);
        $forge->link($blocked->number, $ready->number);

        $plan = $backlog->overview();

        self::assertSame([[1, HeadKind::Capability, 1, 5], [2, HeadKind::Capability, 0, 0], [3, HeadKind::Defect, 0, 0]], array_map(static fn (HeadProgress $progress): array => [$progress->head->number, $progress->kind, $progress->closed, $progress->total], array_reverse($plan->heads)));
        self::assertSame([9, 5, 3], self::numbers($plan->ready), 'The defect with no work is its own leaf; the unscoped capability is not.');
        self::assertSame([6], self::numbers($plan->taken));
        self::assertSame([7], self::numbers($plan->waiting));
        self::assertSame([8 => [5]], $plan->blocked, 'Only the blockers still open.');
        self::assertSame([9], self::numbers($plan->orphans));
        self::assertSame([2], self::numbers($plan->toSplit));
    }

    private static function assertRefused(string $message, \Closure $call): void
    {
        try {
            $call();
            self::fail('Accepted: '.$message);
        } catch (\DomainException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @param list<Ticket> $tickets
     *
     * @return list<int>
     */
    private static function numbers(array $tickets): array
    {
        return array_map(static fn (Ticket $ticket): int => $ticket->number, $tickets);
    }
}

/**
 * A forge in memory: tickets numbered from 1, newest last.
 */
final class InMemoryTickets implements Tickets
{
    /** @var array<int, Ticket> */
    private array $tickets = [];

    /** @var array<int, list<int>> */
    private array $blockers = [];

    /** @var array<int, int> work → head */
    private array $heads = [];

    public function add(int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $this->tickets[$number] = new Ticket($number, 'T'.$number, TicketState::Open);
        }
    }

    public function orphan(int $work): void
    {
        unset($this->heads[$work]);
    }

    public function link(int $number, int $by): void
    {
        $this->blockers[$number][] = $by;
    }

    public function get(int $number): Ticket
    {
        return $this->tickets[$number];
    }

    public function blockers(int $number): array
    {
        return array_map(fn (int $by): Ticket => $this->tickets[$by], $this->blockers[$number] ?? []);
    }

    public function children(int $head): array
    {
        return array_map(fn (int $work): Ticket => $this->tickets[$work], array_keys($this->heads, $head, true));
    }

    public function recent(): array
    {
        return array_reverse(array_values($this->tickets));
    }

    public function openHead(HeadKind $kind, string $title, string $body): Ticket
    {
        return $this->tickets[$number = $this->next()] = new Ticket($number, $title, TicketState::Open, $body, $kind);
    }

    public function openWork(int $head, string $title, string $body): Ticket
    {
        return $this->tickets[$number = $this->next()] = new Ticket($number, $title, TicketState::Open, $body);
    }

    public function adopt(int $head, int $work): void
    {
        $this->heads[$work] = $head;
    }

    public function close(int $number): void
    {
        $ticket = $this->tickets[$number];
        $this->tickets[$number] = new Ticket($number, $ticket->title, TicketState::Done, $ticket->body, $ticket->head, $ticket->marks);
    }

    public function listOpen(): array
    {
        return array_values(array_filter($this->recent(), static fn (Ticket $ticket): bool => TicketState::Open === $ticket->state));
    }

    public function mark(int $number, TicketMark $mark): void
    {
        $ticket = $this->tickets[$number];
        $marks = array_values(array_unique([...$ticket->marks, $mark], \SORT_REGULAR));
        $this->tickets[$number] = new Ticket($number, $ticket->title, $ticket->state, $ticket->body, $ticket->head, $marks);
    }

    public function unmark(int $number, TicketMark $mark): void
    {
        $ticket = $this->tickets[$number];
        $marks = array_values(array_filter($ticket->marks, static fn (TicketMark $kept): bool => $mark !== $kept));
        $this->tickets[$number] = new Ticket($number, $ticket->title, $ticket->state, $ticket->body, $ticket->head, $marks);
    }

    public function block(int $number, int $by): void
    {
        $this->link($number, $by);
    }

    public function unblock(int $number, int $by): void
    {
        $this->blockers[$number] = array_values(array_diff($this->blockers[$number] ?? [], [$by]));
    }

    /** @var array<int, list<string>> */
    private array $comments = [];

    public function comments(int $number): array
    {
        return $this->comments[$number] ?? [];
    }

    public function comment(int $number, string $body): void
    {
        $this->comments[$number][] = $body;
    }

    private function next(): int
    {
        return [] === $this->tickets ? 1 : max(array_keys($this->tickets)) + 1;
    }
}
