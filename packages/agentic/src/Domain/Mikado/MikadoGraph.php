<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Mikado;

/**
 * The Mikado graph of one task: the goal (M1), what a naive attempt showed it requires, what those
 * require, and so on — until the leaves can be done on green.
 *
 * Not tickets: these are the conduct of one work ticket, discovered and done within it. A
 * prerequisite too big for the task becomes a work ticket of its own; the rest never reaches the
 * forge (EWA-002 wants leaves with time and proof, not thoughts).
 *
 * A DAG: one prerequisite often serves several nodes. Immutable — every change is a new graph, which
 * is what lets the workflow journal each state and replay it.
 */
final readonly class MikadoGraph
{
    /** ponytail: a bound on one task's graph. A task that needs more is several tasks. */
    public const MAX_NODES = 100;

    /**
     * @param non-empty-array<int, MikadoNode> $nodes id → node; M1 is the goal, always there
     * @param array<int, list<int>>  $prerequisites id → the ids it requires
     * @param int|null               $ticket        the work ticket this task conducts, if any
     */
    private function __construct(
        private array $nodes,
        private array $prerequisites,
        public ?int $ticket,
    ) {
    }

    public static function start(string $goal, ?int $ticket = null): self
    {
        if (null !== $ticket && $ticket < 1) {
            throw new \DomainException(\sprintf('A ticket number is positive, %d is not.', $ticket));
        }

        return new self([1 => self::node(1, $goal)], [], $ticket);
    }

    public function isFinished(): bool
    {
        return $this->nodes[1]->done;
    }

    /**
     * The id the next noted prerequisite takes.
     */
    public function next(): int
    {
        return max(array_keys($this->nodes)) + 1;
    }

    /**
     * `$for` requires a new prerequisite, `M{@see next()}`.
     */
    public function note(int $for, string $prerequisite): self
    {
        $this->open($for);
        if (\count($this->nodes) >= self::MAX_NODES) {
            throw new \DomainException(\sprintf('This graph already has %d nodes: a task that needs more is several tasks — make work tickets of its branches.', self::MAX_NODES));
        }
        $id = $this->next();
        // Copies, not spreads: `[...$a, $k => $v]` renumbers integer keys, and ids are the keys.
        $nodes = $this->nodes;
        $nodes[$id] = self::node($id, $prerequisite);
        $prerequisites = $this->prerequisites;
        $prerequisites[$for][] = $id;

        return new self($nodes, $prerequisites, $this->ticket);
    }

    /**
     * `$for` also requires `$existing`, already in the graph: one prerequisite serving several nodes.
     */
    public function link(int $for, int $existing): self
    {
        $this->open($for);
        $this->known($existing);
        if ($for === $existing || $this->reaches($existing, $for)) {
            throw new \DomainException(\sprintf('M%d cannot require M%d: M%d already requires M%d, directly or not — neither could ever be done.', $for, $existing, $existing, $for));
        }
        if (\in_array($existing, $this->prerequisites[$for] ?? [], true)) {
            return $this;
        }

        $prerequisites = $this->prerequisites;
        $prerequisites[$for][] = $existing;

        return new self($this->nodes, $prerequisites, $this->ticket);
    }

    /**
     * A node is done once its checks are green and it is committed — and only once every
     * prerequisite is: that is the order the method works in.
     */
    public function done(int $id): self
    {
        $this->known($id);
        if ($this->nodes[$id]->done) {
            return $this;
        }
        $waiting = array_filter($this->prerequisites[$id] ?? [], fn (int $prerequisite): bool => !$this->nodes[$prerequisite]->done);
        if ([] !== $waiting) {
            throw new \DomainException(\sprintf('M%d still requires %s: do those first.', $id, implode(', ', array_map(static fn (int $node): string => 'M'.$node, $waiting))));
        }

        $nodes = $this->nodes;
        $nodes[$id] = $nodes[$id]->finished();

        return new self($nodes, $this->prerequisites, $this->ticket);
    }

    /**
     * What can be done now: open, every prerequisite done.
     *
     * @return list<MikadoNode>
     */
    public function ready(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn (MikadoNode $node): bool => !$node->done && [] === array_filter($this->prerequisites[$node->id] ?? [], fn (int $prerequisite): bool => !$this->nodes[$prerequisite]->done),
        ));
    }

    /**
     * The graph as the model reads it: an indented tree from the goal, each node with its state,
     * the ready ones marked, a node already shown referred to instead of repeated.
     */
    public function render(): string
    {
        $ready = array_map(static fn (MikadoNode $node): int => $node->id, $this->ready());
        $lines = [null === $this->ticket ? 'Mikado graph' : \sprintf('Mikado graph of the work ticket #%d', $this->ticket)];
        $seen = [];
        $walk = function (int $id, int $depth) use (&$walk, &$lines, &$seen, $ready): void {
            $node = $this->nodes[$id];
            $line = \sprintf('%s%s %s [%s]', str_repeat('  ', $depth), $node->name(), $node->title, $node->done ? 'done' : (\in_array($id, $ready, true) ? 'READY' : 'open'));
            if (\in_array($id, $seen, true)) {
                $lines[] = $line.' (see above)';

                return;
            }
            $seen[] = $id;
            $lines[] = $line;
            foreach ($this->prerequisites[$id] ?? [] as $prerequisite) {
                $walk($prerequisite, $depth + 1);
            }
        };
        $walk(1, 0);

        return implode("\n", $lines);
    }

    /**
     * @return array{nodes: list<array{id: int, title: string, done: bool}>, prerequisites: array<string, list<int>>, ticket?: int}
     */
    public function toWire(): array
    {
        $prerequisites = [];
        foreach ($this->prerequisites as $id => $required) {
            // String keys: a list of lists would lose which node each belongs to on its way through JSON.
            $prerequisites['M'.$id] = $required;
        }

        return [
            'nodes' => array_values(array_map(static fn (MikadoNode $node): array => ['id' => $node->id, 'title' => $node->title, 'done' => $node->done], $this->nodes)),
            'prerequisites' => $prerequisites,
            ...(null === $this->ticket ? [] : ['ticket' => $this->ticket]),
        ];
    }

    /**
     * Read back from the journal, where it was written weeks ago: every rule the operations keep is
     * checked again, and a graph that breaks one is refused — a cycle or a dangling link read in
     * silently would leave a task that can never be finished.
     *
     * @param array<mixed> $wire
     *
     * @throws \InvalidArgumentException
     */
    public static function fromWire(array $wire): self
    {
        $nodes = [];
        foreach (\is_array($wire['nodes'] ?? null) ? $wire['nodes'] : [] as $node) {
            if (!\is_array($node) || !\is_int($node['id'] ?? null) || !\is_string($node['title'] ?? null) || !\is_bool($node['done'] ?? null) || isset($nodes[$node['id']])) {
                throw new \InvalidArgumentException('A Mikado graph node is {id, title, done}, each id once.');
            }
            $nodes[$node['id']] = new MikadoNode($node['id'], $node['title'], $node['done']);
        }
        if (!isset($nodes[1])) {
            throw new \InvalidArgumentException('A Mikado graph starts from its goal, M1.');
        }

        $byName = [];
        foreach ($nodes as $id => $node) {
            $byName[$node->name()] = $id;
        }
        $prerequisites = [];
        foreach (\is_array($wire['prerequisites'] ?? null) ? $wire['prerequisites'] : [] as $key => $required) {
            $id = $byName[$key] ?? null;
            if (null === $id || !\is_array($required) || !array_is_list($required) || [] !== array_filter($required, static fn (mixed $prerequisite): bool => !\is_int($prerequisite) || !isset($nodes[$prerequisite]))) {
                throw new \InvalidArgumentException(\sprintf('The prerequisites of "%s" name nodes the graph does not have.', (string) $key));
            }
            $prerequisites[$id] = $required;
        }

        $ticket = $wire['ticket'] ?? null;
        if (null !== $ticket && (!\is_int($ticket) || $ticket < 1)) {
            throw new \InvalidArgumentException('A Mikado graph\'s ticket is a positive number.');
        }

        $graph = new self($nodes, $prerequisites, $ticket);
        foreach (array_keys($prerequisites) as $id) {
            if ($graph->reaches($id, $id)) {
                throw new \InvalidArgumentException(\sprintf('M%d requires itself, directly or not.', $id));
            }
        }

        return $graph;
    }

    /**
     * Does `$from` require `$to`, directly or not?
     */
    private function reaches(int $from, int $to): bool
    {
        $seen = [];
        $queue = $this->prerequisites[$from] ?? [];
        while (null !== $id = array_shift($queue)) {
            if ($to === $id) {
                return true;
            }
            if (!\in_array($id, $seen, true)) {
                $seen[] = $id;
                array_push($queue, ...$this->prerequisites[$id] ?? []);
            }
        }

        return false;
    }

    private function known(int $id): void
    {
        if (!isset($this->nodes[$id])) {
            throw new \DomainException(\sprintf('There is no M%d in this graph.', $id));
        }
    }

    private function open(int $id): void
    {
        $this->known($id);
        if ($this->nodes[$id]->done) {
            throw new \DomainException(\sprintf('M%d is done already: it requires nothing more.', $id));
        }
    }

    private static function node(int $id, string $title): MikadoNode
    {
        try {
            return new MikadoNode($id, trim($title));
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage(), previous: $e);
        }
    }
}
