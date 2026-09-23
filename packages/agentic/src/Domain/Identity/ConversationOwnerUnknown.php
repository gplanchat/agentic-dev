<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Identity;

/**
 * The journal no longer says who a conversation belongs to.
 *
 * Not the same thing as {@see ConversationNotOwned}, and conflating the two is how someone gets told
 * their own conversation is not theirs. It happens when the start payload is gone — Durable drops an
 * execution's metadata row when it fails, and on a backend where a dispatched run writes no
 * `ExecutionStarted`, the owner goes with it. The projection recovers it from any tool call the run
 * made; a run that failed before calling a single tool leaves nothing to recover.
 *
 * Still a refusal, and deliberately so: unknown must not mean everybody's. But it says what actually
 * happened, so an interface can show it instead of accusing its own user.
 */
final class ConversationOwnerUnknown extends \RuntimeException
{
    public function __construct(public readonly string $conversation)
    {
        parent::__construct(\sprintf(
            'The journal no longer says who the conversation "%s" belongs to: its start payload is '
            .'gone, which happens when a run fails. Nobody can be let in on a claim nothing backs.',
            $conversation,
        ));
    }
}
