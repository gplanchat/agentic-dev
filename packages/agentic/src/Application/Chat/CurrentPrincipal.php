<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

use Gplanchat\Agentic\Domain\Identity\Principal;

/**
 * Port: on whose behalf the process is acting right now.
 *
 * Ambient and not a parameter of {@see Conversations}, for the same reason Symfony's `Security`
 * service is ambient: every caller would otherwise have to carry a principal it has no business
 * knowing about — the TUI, a slash command, a controller. The adapter decides where it comes from:
 * the local account in the terminal, the token of the request on the web.
 *
 * It must answer the same thing for the whole of one interaction; a conversation's owner is frozen
 * in the journal at start, so nothing downstream asks this twice about the same run.
 */
interface CurrentPrincipal
{
    public function __invoke(): Principal;
}
