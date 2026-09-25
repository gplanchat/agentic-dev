<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Mikado;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Mikado\MikadoBoard;
use Gplanchat\Agentic\Domain\Mikado\MikadoTool;
use PHPUnit\Framework\TestCase;

final class MikadoToolTest extends TestCase
{
    public function testTheToolsAreHarmlessSoPlanningCanKeepTheGraph(): void
    {
        $names = [];
        foreach (MikadoTool::definitions() as $definition) {
            self::assertSame(ToolEffect::Read, $definition->effect, $definition->name);
            self::assertTrue(MikadoTool::handles($definition->name));
            $names[] = $definition->name;
        }

        self::assertSame(['mikado_start', 'mikado_note', 'mikado_done', 'mikado_show'], $names);
        self::assertFalse(MikadoTool::handles('watch'));
    }

    public function testTheSchemasTheModelFills(): void
    {
        $node = static fn (string $what): array => ['type' => 'string', 'pattern' => '^M[0-9]+$', 'description' => $what];
        [$start, $note, $done, $show] = MikadoTool::definitions();

        self::assertSame(['type' => 'object', 'properties' => [
            'goal' => ['type' => 'string', 'description' => 'The change the task must make.'],
            'ticket' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The work ticket this task conducts, if there is one.'],
        ], 'required' => ['goal']], $start->parameters);
        self::assertSame(['type' => 'object', 'properties' => [
            'for' => $node('The node that requires it, e.g. "M1".'),
            'prerequisite' => ['type' => 'string', 'description' => 'A new prerequisite: what must change first, and what broke that showed it.'],
            'existing' => $node('Instead of a new one: a node already in the graph.'),
        ], 'required' => ['for']], $note->parameters);
        self::assertSame(['type' => 'object', 'properties' => ['node' => $node('The node done, e.g. "M3".')], 'required' => ['node']], $done->parameters);
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass()], $show->parameters);
        self::assertStringContainsString('revert_worktree', $start->description);
    }

    public function testTheMethodThroughItsTools(): void
    {
        $board = new MikadoBoard();

        self::assertSame('No Mikado graph yet: start one with mikado_start.', MikadoTool::apply($board, MikadoTool::SHOW, []));
        self::assertSame("Mikado graph of the work ticket #45\nM1 Goal [READY]", MikadoTool::apply($board, MikadoTool::START, ['goal' => 'Goal', 'ticket' => '45']));
        self::assertStringEndsWith("  M2 Port [READY]", MikadoTool::apply($board, MikadoTool::NOTE, ['for' => ' M1 ', 'prerequisite' => 'Port']));
        self::assertStringEndsWith("  M3 Cache [READY]", MikadoTool::apply($board, MikadoTool::NOTE, ['for' => 'm1', 'prerequisite' => 'Cache']));
        self::assertStringContainsString("  M3 Cache [open]\n    M2 Port [READY] (see above)", MikadoTool::apply($board, MikadoTool::NOTE, ['for' => '3', 'existing' => 'M2']));
        self::assertSame("A Mikado graph is under way: finish it before starting another.\n\n".$board->graph?->render(), MikadoTool::apply($board, MikadoTool::START, ['goal' => 'Other']));
        self::assertSame('M3 still requires M2: do those first.', MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M3']));

        MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M2']);
        MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M3']);

        self::assertStringStartsWith("The goal is done. Post this graph on #45 with ticket_comment, for the trace.\n\nMikado graph of the work ticket #45\nM1 Goal [done]", MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M1']));
        self::assertSame("Mikado graph\nM1 Next [READY]", MikadoTool::apply($board, MikadoTool::START, ['goal' => 'Next']), 'A finished graph makes room for the next task.');
        self::assertSame("Mikado graph\nM1 Next [READY]", MikadoTool::apply($board, MikadoTool::SHOW, []));
        self::assertSame("The goal is done.\n\nMikado graph\nM1 Next [done]", MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M1']), 'No ticket, nothing to post it on.');
    }

    public function testARefusalLeavesTheBoardAsItWas(): void
    {
        $board = new MikadoBoard();
        MikadoTool::apply($board, MikadoTool::START, ['goal' => 'Goal']);
        $before = $board->graph;

        self::assertSame('"for" is a node of the graph, e.g. "M1".', MikadoTool::apply($board, MikadoTool::NOTE, ['prerequisite' => 'X']));
        self::assertSame('"node" is a node of the graph, e.g. "M1".', MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M1x']));
        self::assertSame('"node" is a node of the graph, e.g. "M1".', MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'xM1']));
        self::assertSame('There is no M7 in this graph.', MikadoTool::apply($board, MikadoTool::DONE, ['node' => 'M7']));
        self::assertSame($before, $board->graph);
        self::assertSame('"ticket" must be a ticket number.', MikadoTool::apply(new MikadoBoard(), MikadoTool::START, ['goal' => 'G', 'ticket' => 'x']));
        self::assertSame('A ticket number is positive, 0 is not.', MikadoTool::apply(new MikadoBoard(), MikadoTool::START, ['goal' => 'G', 'ticket' => 0]));
    }
}
