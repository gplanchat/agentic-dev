<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Identity;

/**
 * Someone asked for a conversation that is not theirs.
 *
 * Its own type so that an interface can tell it from a malformed request and say "not yours"
 * rather than crashing — and so that nothing catches it by accident along with an
 * `InvalidArgumentException`.
 */
final class ConversationNotOwned extends \RuntimeException
{
    public function __construct(public readonly string $conversation)
    {
        // The message says nothing of the owner: telling the asker who it belongs to would answer a
        // question they had no right to ask.
        parent::__construct(\sprintf('The conversation "%s" does not belong to you.', $conversation));
    }
}
