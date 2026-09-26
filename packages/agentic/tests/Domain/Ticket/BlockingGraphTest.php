<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Ticket;

use Gplanchat\Agentic\Domain\Ticket\BlockingGraph;
use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\Agentic\Domain\Ticket\Ticket;
use Gplanchat\Agentic\Domain\Ticket\TicketMark;
use Gplanchat\Agentic\Domain\Ticket\TicketState;
use PHPUnit\Framework\TestCase;

final class BlockingGraphTest extends TestCase
{
    public function testWhatCanBeTakenIsOpenWithEveryBlockerDone(): void
    {
        // #1 goal ← #2 ← #4 (done); #1 ← #3 ← #4. #2 and #3 are ready, #1 waits on them.
        $graph = self::graph([1 => TicketState::Open, 2 => TicketState::Open, 3 => TicketState::Open, 4 => TicketState::Done], [1 => [2, 3], 2 => [4], 3 => [4]]);

        self::assertSame([2, 3], self::numbers($graph->ready()));
    }

    public function testAnAbandonedBlockerDoesNotUnblock(): void
    {
        $graph = self::graph([1 => TicketState::Open, 2 => TicketState::Abandoned], [1 => [2]]);

        self::assertSame([], $graph->ready());
        self::assertSame([2], self::numbers($graph->abandoned()));
        self::assertStringContainsString('rethink the plan there: #2.', $graph->render());
    }

    public function testAClosedTicketIsNeverReady(): void
    {
        self::assertSame([], self::graph([1 => TicketState::Done], [])->ready());
    }

    public function testASharedBlockerIsShownOnceThenReferredTo(): void
    {
        $graph = self::graph([1 => TicketState::Open, 2 => TicketState::Open, 3 => TicketState::Open, 4 => TicketState::Open], [1 => [2, 3], 2 => [4], 3 => [4]]);

        self::assertSame(implode("\n", [
            '#1 T1 [open]',
            '  #2 T2 [open]',
            '    #4 T4 [open, READY]',
            '  #3 T3 [open]',
            '    #4 T4 [open, READY] (see above)',
        ]), $graph->render());
    }

    public function testTheRootBelongsToItsGraph(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new BlockingGraph(9, [], []);
    }

    public function testOnlyAClassifiedTicketIsAHead(): void
    {
        self::assertTrue((new Ticket(1, 'T', TicketState::Open, '', HeadKind::Debt))->isHead());
        self::assertFalse((new Ticket(1, 'T', TicketState::Open))->isHead());
    }

    public function testATicketSaysWhatItWaitsOnAndWhetherItIsTaken(): void
    {
        $ticket = new Ticket(1, 'T', TicketState::Open, '', null, [TicketMark::Taken, TicketMark::WaitsForAuthor, TicketMark::WaitsForThirdParty, TicketMark::WaitsForMeasure]);

        self::assertTrue($ticket->has(TicketMark::Taken));
        self::assertFalse((new Ticket(1, 'T', TicketState::Open))->has(TicketMark::Taken));
        self::assertSame([TicketMark::WaitsForAuthor, TicketMark::WaitsForThirdParty, TicketMark::WaitsForMeasure], $ticket->waits());
        self::assertSame(['waits:author', 'waits:third-party', 'waits:measure', 'taken'], array_column(TicketMark::cases(), 'value'), 'The labels on the forge.');
    }

    public function testATicketNumberIsPositive(): void
    {
        $this->expectExceptionMessage('A ticket number is positive, 0 is not.');

        new Ticket(0, 'T', TicketState::Open);
    }

    /**
     * @param array<int, TicketState> $states
     * @param array<int, list<int>>   $blockers
     */
    private static function graph(array $states, array $blockers): BlockingGraph
    {
        $tickets = [];
        foreach ($states as $number => $state) {
            $tickets[$number] = new Ticket($number, 'T'.$number, $state);
        }

        return new BlockingGraph(1, $tickets, $blockers);
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
