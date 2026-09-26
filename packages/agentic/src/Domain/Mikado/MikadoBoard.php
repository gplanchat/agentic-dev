<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Mikado;

/**
 * Where a run keeps the Mikado graph of the task it conducts. Owned by the run, like the watch desk:
 * rebuilt on replay by the same tool calls, carried to the next run in its start payload.
 */
final class MikadoBoard
{
    public function __construct(public ?MikadoGraph $graph = null)
    {
    }

    /**
     * @param array<mixed> $wire the start payload's `mikado`; `[]` = no graph
     *
     * @throws \InvalidArgumentException
     */
    public static function fromWire(array $wire): self
    {
        return new self([] === $wire ? null : MikadoGraph::fromWire($wire));
    }

    /**
     * @return array<string, mixed> `[]` when there is no graph — and then the payload leaves the key out
     */
    public function toWire(): array
    {
        return $this->graph?->toWire() ?? [];
    }
}
