<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Mikado;

/**
 * A node of a Mikado graph: the goal, or a prerequisite a naive attempt showed. Named `M<id>` so it
 * is never taken for a ticket `#<n>`.
 */
final readonly class MikadoNode
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $done = false,
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException(\sprintf('A Mikado node id is positive, %d is not.', $id));
        }
        if ('' === trim($title)) {
            throw new \InvalidArgumentException(\sprintf('The Mikado node M%d has no title.', $id));
        }
    }

    public function name(): string
    {
        return 'M'.$this->id;
    }

    public function finished(): self
    {
        return new self($this->id, $this->title, true);
    }
}
