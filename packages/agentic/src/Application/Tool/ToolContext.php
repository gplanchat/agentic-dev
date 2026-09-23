<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Tool;

use Gplanchat\Agentic\Domain\Identity\Principal;

/**
 * What a tool is told about the call it is serving, beyond the arguments the model wrote.
 *
 * Everything here comes from the conversation, **never from the model**: the workspace and the owner
 * are frozen in the start payload, the call identifier is the one the journal knows. A tool that
 * read any of it from `$arguments` would be taking the model's word for where to act and for whom.
 *
 * A value object rather than three more parameters on the tool interface, and that is
 * `docs/decisions/ADR-001-value-objects-and-enums.md` applied to its own repository: a positional
 * list that grows is exactly the shape that made `DurableToolExecutor::delegate()` a landmine. One
 * type grows without moving anything already in place.
 */
final readonly class ToolContext
{
    public function __construct(
        /**
         * The journal's identifier for this call. A retried activity presents the **same** one, so
         * a tool whose effect must not happen twice — a payment, a mail — has the key it needs to
         * recognise a repeat. Nothing here does that yet; what this offers is the possibility,
         * which a tool with an irreversible effect has no other way of getting.
         */
        public string $callId,
        /** The conversation's working directory; `null` = the project itself. */
        public ?string $workspace = null,
        /** On whose behalf the conversation runs; `null` = nobody, which claims nothing. */
        public ?Principal $principal = null,
    ) {
    }
}
