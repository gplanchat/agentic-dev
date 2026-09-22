<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Tool;

use Gplanchat\Agentic\Domain\Guard\ToolEffect;

/**
 * The tools an agent has at its disposal.
 *
 * Three things wanted them in three different shapes: the toolbox a list of schemas, the guard a
 * name → effect map, the journal a JSON object indexed by name. Hence two static factories on
 * {@see ToolDefinition} and a private `effects()` in the agent factory, each rebuilding the same
 * thing. The collection replaces them: the list is here, the projections are methods.
 *
 * A boundary factory both ways, like {@see \Gplanchat\Agentic\Domain\Context\Conversation}: the wire is an object
 * indexed by tool name — that is the shape providers expect — and it does not run through the
 * code.
 */
final readonly class Toolset implements \Countable, \IteratorAggregate
{
    /** @var list<ToolDefinition> */
    public array $definitions;

    public function __construct(ToolDefinition ...$definitions)
    {
        $this->definitions = array_values($definitions);
    }

    /**
     * The workflow payload arrives from the journal, hence as arrays.
     *
     * @param array<string, array{description?: string, effect?: string, parameters?: array<string, mixed>|null}> $wire
     */
    public static function fromWire(array $wire): self
    {
        $definitions = [];
        foreach ($wire as $name => $definition) {
            $definitions[] = ToolDefinition::fromWire((string) $name, $definition);
        }

        return new self(...$definitions);
    }

    /**
     * @return array<string, array{description: string, effect: string, parameters: array<string, mixed>|null}>
     */
    public function toWire(): array
    {
        $wire = [];
        foreach ($this->definitions as $definition) {
            $wire[$definition->name] = $definition->toWire();
        }

        return $wire;
    }

    public function with(ToolDefinition ...$more): self
    {
        return new self(...$this->definitions, ...$more);
    }

    /**
     * What a tool does — the only question the guard asks this catalogue.
     *
     * An unknown tool is external: the cautious default, the one that needs an approval in every
     * mode but `auto`.
     */
    public function effectOf(string $name): ToolEffect
    {
        // ponytail: linear scan. An agent carries a handful of tools; the day it carries a hundred,
        // a map built in the constructor.
        foreach ($this->definitions as $definition) {
            if ($definition->name === $name) {
                return $definition->effect;
            }
        }

        return ToolEffect::External;
    }

    public function count(): int
    {
        return \count($this->definitions);
    }

    /**
     * @return \Traversable<int, ToolDefinition>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->definitions);
    }
}
