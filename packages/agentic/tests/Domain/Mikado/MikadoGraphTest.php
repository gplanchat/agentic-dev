<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Mikado;

use Gplanchat\Agentic\Domain\Mikado\MikadoBoard;
use Gplanchat\Agentic\Domain\Mikado\MikadoGraph;
use Gplanchat\Agentic\Domain\Mikado\MikadoNode;
use PHPUnit\Framework\TestCase;

final class MikadoGraphTest extends TestCase
{
    public function testTheLeavesAreDoneFirstThenTheGoal(): void
    {
        // M1 ← M2 ← M4; M1 ← M3 ← M4: one prerequisite serving two nodes.
        $graph = MikadoGraph::start(' Move to PHP 8.4 ', 45)->note(1, 'Drop lib X')->note(1, 'Retype the cache')->note(2, 'Extract the port')->link(3, 4);

        self::assertSame(['M4'], self::names($graph->ready()));
        self::assertSame(implode("\n", [
            'Mikado graph of the work ticket #45',
            'M1 Move to PHP 8.4 [open]',
            '  M2 Drop lib X [open]',
            '    M4 Extract the port [READY]',
            '  M3 Retype the cache [open]',
            '    M4 Extract the port [READY] (see above)',
        ]), $graph->render());

        self::assertRefused('M1 still requires M2, M3: do those first.', static fn () => $graph->done(1));
        $graph = $graph->done(4);
        self::assertSame(['M2', 'M3'], self::names($graph->ready()));
        $graph = $graph->done(2)->done(3)->done(1);

        self::assertTrue($graph->isFinished());
        self::assertSame($graph, $graph->done(1), 'Done twice is done once.');
    }

    public function testALinkClosingACycleIsRefused(): void
    {
        $graph = MikadoGraph::start('G')->note(1, 'A')->note(2, 'B');

        self::assertRefused('M3 cannot require M1: M1 already requires M3, directly or not — neither could ever be done.', static fn () => $graph->link(3, 1));
        self::assertRefused('M2 cannot require M2: M2 already requires M2, directly or not — neither could ever be done.', static fn () => $graph->link(2, 2));
        self::assertSame($graph, $graph->link(2, 3), 'A link already there is kept, not doubled.');
    }

    public function testWhatTheGraphRefuses(): void
    {
        $graph = MikadoGraph::start('G')->note(1, 'A')->done(2);

        self::assertRefused('There is no M9 in this graph.', static fn () => $graph->note(9, 'X'));
        self::assertRefused('There is no M9 in this graph.', static fn () => $graph->done(9));
        self::assertRefused('There is no M9 in this graph.', static fn () => $graph->link(1, 9));
        self::assertRefused('M2 is done already: it requires nothing more.', static fn () => $graph->link(2, 1));
        self::assertRefused('M2 is done already: it requires nothing more.', static fn () => $graph->note(2, 'X'));
        self::assertRefused('The Mikado node M3 has no title.', static fn () => $graph->note(1, ' '));
        self::assertRefused('The Mikado node M1 has no title.', static fn () => MikadoGraph::start(''));
        self::assertRefused('A ticket number is positive, 0 is not.', static fn () => MikadoGraph::start('G', 0));
        self::assertSame(3, $graph->next());
    }

    public function testAGraphPastTheBoundIsRefused(): void
    {
        $graph = MikadoGraph::start('G');
        for ($i = 2; $i <= MikadoGraph::MAX_NODES; ++$i) {
            $graph = $graph->note(1, 'P'.$i);
        }

        self::assertRefused(\sprintf('This graph already has %d nodes: a task that needs more is several tasks — make work tickets of its branches.', MikadoGraph::MAX_NODES), static fn () => $graph->note(1, 'One more'));
    }

    public function testItSurvivesTheJournalBothWays(): void
    {
        $graph = MikadoGraph::start('G', 45)->note(1, 'A')->note(1, 'B')->note(2, 'C')->link(3, 4)->done(4);

        $wire = $graph->toWire();

        self::assertSame([
            'nodes' => [
                ['id' => 1, 'title' => 'G', 'done' => false],
                ['id' => 2, 'title' => 'A', 'done' => false],
                ['id' => 3, 'title' => 'B', 'done' => false],
                ['id' => 4, 'title' => 'C', 'done' => true],
            ],
            'prerequisites' => ['M1' => [2, 3], 'M2' => [4], 'M3' => [4]],
            'ticket' => 45,
        ], $wire);
        self::assertEquals($graph, MikadoGraph::fromWire(json_decode((string) json_encode($wire), true)));
        self::assertSame(1, MikadoGraph::fromWire(['nodes' => [['id' => 1, 'title' => 'G', 'done' => false]], 'ticket' => 1])->ticket, 'Ticket #1 is a ticket.');
        self::assertSame(1, MikadoGraph::start('G', 1)->ticket);
    }

    /**
     * No ticket, no prerequisite: the payload says only what is — and a run started before the
     * graph existed carries no `mikado` at all.
     */
    public function testAGraphWithNoTicketKeepsTheShapeItWasWrittenWith(): void
    {
        self::assertSame(['nodes' => [['id' => 1, 'title' => 'G', 'done' => false]], 'prerequisites' => []], MikadoGraph::start('G')->toWire());
        self::assertNull(MikadoBoard::fromWire([])->graph);
        self::assertSame([], (new MikadoBoard())->toWire());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function brokenGraphs(): iterable
    {
        $goal = ['id' => 1, 'title' => 'G', 'done' => false];
        $a = ['id' => 2, 'title' => 'A', 'done' => false];

        yield 'no goal' => [['nodes' => [$a]], 'A Mikado graph starts from its goal, M1.'];
        yield 'no nodes' => [['prerequisites' => []], 'A Mikado graph starts from its goal, M1.'];
        yield 'a node twice' => [['nodes' => [$goal, $goal]], 'A Mikado graph node is {id, title, done}, each id once.'];
        yield 'a node without its state' => [['nodes' => [['id' => 1, 'title' => 'G']]], 'A Mikado graph node is {id, title, done}, each id once.'];
        yield 'a node that is no node' => [['nodes' => ['G']], 'A Mikado graph node is {id, title, done}, each id once.'];
        yield 'a dangling link' => [['nodes' => [$goal], 'prerequisites' => ['M1' => [7]]], 'The prerequisites of "M1" name nodes the graph does not have.'];
        yield 'links of no node' => [['nodes' => [$goal, $a], 'prerequisites' => ['M9' => [2]]], 'The prerequisites of "M9" name nodes the graph does not have.'];
        yield 'links under a bad key' => [['nodes' => [$goal, $a], 'prerequisites' => ['1' => [2]]], 'The prerequisites of "1" name nodes the graph does not have.'];
        yield 'links under a key with more before' => [['nodes' => [$goal, $a], 'prerequisites' => ['xM1' => [2]]], 'The prerequisites of "xM1" name nodes the graph does not have.'];
        yield 'links under a key with more after' => [['nodes' => [$goal, $a], 'prerequisites' => ['M1x' => [2]]], 'The prerequisites of "M1x" name nodes the graph does not have.'];
        yield 'a link that is a string' => [['nodes' => [$goal, $a], 'prerequisites' => ['M1' => ['2']]], 'The prerequisites of "M1" name nodes the graph does not have.'];
        yield 'a node with a string id' => [['nodes' => [['id' => '1', 'title' => 'G', 'done' => false]]], 'A Mikado graph node is {id, title, done}, each id once.'];
        yield 'a node with a number for a title' => [['nodes' => [['id' => 1, 'title' => 3, 'done' => false]]], 'A Mikado graph node is {id, title, done}, each id once.'];
        yield 'links that are no list' => [['nodes' => [$goal, $a], 'prerequisites' => ['M1' => ['x' => 2]]], 'The prerequisites of "M1" name nodes the graph does not have.'];
        yield 'a cycle' => [['nodes' => [$goal, $a], 'prerequisites' => ['M1' => [2], 'M2' => [1]]], 'M1 requires itself, directly or not.'];
        yield 'a bad ticket' => [['nodes' => [$goal], 'ticket' => '45'], 'A Mikado graph\'s ticket is a positive number.'];
        yield 'a ticket zero' => [['nodes' => [$goal], 'ticket' => 0], 'A Mikado graph\'s ticket is a positive number.'];
    }

    /**
     * @param array<string, mixed> $wire
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('brokenGraphs')]
    public function testABrokenGraphIsRefusedNotReadIn(array $wire, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        MikadoGraph::fromWire($wire);
    }

    public function testANodeHasATitle(): void
    {
        $this->expectExceptionMessage('The Mikado node M1 has no title.');

        new MikadoNode(1, ' ');
    }

    public function testANodeIdIsPositive(): void
    {
        $this->expectExceptionMessage('A Mikado node id is positive, 0 is not.');

        new MikadoNode(0, 'T');
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
     * @param list<MikadoNode> $nodes
     *
     * @return list<string>
     */
    private static function names(array $nodes): array
    {
        return array_map(static fn (MikadoNode $node): string => $node->name(), $nodes);
    }
}
