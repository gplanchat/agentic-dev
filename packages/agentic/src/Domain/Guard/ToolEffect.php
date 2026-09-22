<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

/**
 * What a tool does, declared along with its schema. It is this classification — and not the name of
 * the tool — that the mode consults.
 */
enum ToolEffect: string
{
    /** Writes nothing: can be replayed without consequence. */
    case Read = 'read';

    /** Writes inside the perimeter of the application. */
    case Write = 'write';

    /** Leaves the perimeter: mail, payment, third-party call. What a click does not undo. */
    case External = 'external';

    /**
     * Nothing to undo: a read tool can go out without anyone having to decide.
     */
    public function isHarmless(): bool
    {
        return self::Read === $this;
    }

    /**
     * Leaves the perimeter of the application: no compensation catches it back.
     */
    public function isIrreversible(): bool
    {
        return self::External === $this;
    }
}
