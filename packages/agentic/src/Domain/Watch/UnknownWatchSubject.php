<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Watch;

/**
 * The model asked for a watch on a subject the application does not publish.
 *
 * Thrown at the boundary and caught in the workflow code: the agent receives the list of known
 * subjects in place of a tool result, and takes over. An exception rather than a `null` because
 * there is nothing to do with a watch without a subject — letting it through means putting the
 * agent to sleep for nothing.
 */
final class UnknownWatchSubject extends \InvalidArgumentException
{
    public function __construct(public readonly string $subject)
    {
        parent::__construct(\sprintf('Unknown watch subject: "%s".', $subject));
    }
}
