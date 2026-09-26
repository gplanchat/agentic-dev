<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Mikado;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The tools through which the agent keeps the Mikado graph of the task it conducts.
 *
 * Executed in the workflow, like `watch`: they change the run's {@see MikadoBoard} and nothing else,
 * so they are pure — the replay makes the same calls and finds the same graph. Classified `read`:
 * they write nowhere but the journal, and noting what a task requires is exactly what planning does.
 */
final class MikadoTool
{
    public const START = 'mikado_start';
    public const NOTE = 'mikado_note';
    public const DONE = 'mikado_done';
    public const SHOW = 'mikado_show';

    private function __construct()
    {
    }

    public static function handles(string $tool): bool
    {
        return \in_array($tool, [self::START, self::NOTE, self::DONE, self::SHOW], true);
    }

    /**
     * @return list<ToolDefinition>
     */
    public static function definitions(): array
    {
        $node = static fn (string $what): array => ['type' => 'string', 'pattern' => '^M[0-9]+$', 'description' => $what];

        return [
            new ToolDefinition(
                self::START,
                'Starts the Mikado graph of the task you conduct, from its goal (M1). The Mikado method: try the change naively; when run_checks goes red, note each prerequisite it showed with mikado_note, revert_worktree back to your last commit, then do a READY node, commit_worktree once green, mikado_done it — and so on up to the goal. The graph survives the conversation\'s resumptions. A prerequisite too big for this task becomes a work ticket instead (ticket_open_work).',
                ToolEffect::Read,
                ['type' => 'object', 'properties' => [
                    'goal' => ['type' => 'string', 'description' => 'The change the task must make.'],
                    'ticket' => ['type' => 'integer', 'minimum' => 1, 'description' => 'The work ticket this task conducts, if there is one.'],
                ], 'required' => ['goal']],
            ),
            new ToolDefinition(
                self::NOTE,
                'Notes that a node requires a prerequisite — a new one, or one already in the graph (one prerequisite often serves several nodes).',
                ToolEffect::Read,
                ['type' => 'object', 'properties' => [
                    'for' => $node('The node that requires it, e.g. "M1".'),
                    'prerequisite' => ['type' => 'string', 'description' => 'A new prerequisite: what must change first, and what broke that showed it.'],
                    'existing' => $node('Instead of a new one: a node already in the graph.'),
                ], 'required' => ['for']],
            ),
            new ToolDefinition(
                self::DONE,
                'Marks a node done — once its checks are green and it is committed, and only when all it requires is done.',
                ToolEffect::Read,
                ['type' => 'object', 'properties' => ['node' => $node('The node done, e.g. "M3".')], 'required' => ['node']],
            ),
            new ToolDefinition(
                self::SHOW,
                'Shows the Mikado graph of the task: every node, its state, and what is READY.',
                ToolEffect::Read,
                ['type' => 'object', 'properties' => new \stdClass()],
            ),
        ];
    }

    /**
     * Runs one of the tools on the board. A refusal is handed back to the model, the board untouched.
     *
     * @param array<string, mixed> $arguments what the model asked for, nothing guarantees the schema
     */
    public static function apply(MikadoBoard $board, string $tool, array $arguments): string
    {
        try {
            $graph = $board->graph;
            if (self::START === $tool) {
                if (null !== $graph && !$graph->isFinished()) {
                    return "A Mikado graph is under way: finish it before starting another.\n\n".$graph->render();
                }
                $ticket = isset($arguments['ticket']) ? filter_var($arguments['ticket'], \FILTER_VALIDATE_INT) : null;
                if (false === $ticket) {
                    return '"ticket" must be a ticket number.';
                }
                $board->graph = MikadoGraph::start((string) ($arguments['goal'] ?? ''), $ticket);

                return $board->graph->render();
            }
            if (null === $graph) {
                return 'No Mikado graph yet: start one with mikado_start.';
            }

            $board->graph = match ($tool) {
                self::NOTE => isset($arguments['existing'])
                    ? $graph->link(self::node($arguments, 'for'), self::node($arguments, 'existing'))
                    : $graph->note(self::node($arguments, 'for'), (string) ($arguments['prerequisite'] ?? '')),
                self::DONE => $graph->done(self::node($arguments, 'node')),
                default => $graph,
            };

            if (!$board->graph->isFinished()) {
                return $board->graph->render();
            }

            return null === $board->graph->ticket
                ? "The goal is done.\n\n".$board->graph->render()
                : \sprintf("The goal is done. Post this graph on #%d with ticket_comment, for the trace.\n\n%s", $board->graph->ticket, $board->graph->render());
        } catch (\DomainException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function node(array $arguments, string $name): int
    {
        if (1 !== preg_match('/^M?([0-9]+)$/i', trim((string) ($arguments[$name] ?? '')), $match)) {
            throw new \DomainException(\sprintf('"%s" is a node of the graph, e.g. "M1".', $name));
        }

        return (int) $match[1];
    }
}
