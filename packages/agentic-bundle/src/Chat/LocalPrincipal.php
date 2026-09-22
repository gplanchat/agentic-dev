<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Domain\Identity\Principal;

/**
 * Who is speaking in a terminal: the account that started the process.
 *
 * The honest adapter for a tool that has no authentication — and the seam where a real application
 * puts its own. In a Symfony app with a firewall, `CurrentPrincipal` is implemented over
 * `Security::getUser()` and this one is replaced; nothing else in the chat moves, because nothing
 * else knows where the principal comes from.
 *
 * ponytail: no roles. Nothing reads them yet, and a local terminal has none to tell apart — the
 * field exists on {@see Principal} so the journal's shape does not change the day a firewall fills
 * it in.
 */
final readonly class LocalPrincipal implements CurrentPrincipal
{
    public function __construct(private string $fallback = 'local')
    {
    }

    public function __invoke(): Principal
    {
        // The effective uid, and not `get_current_user()`: that one reads the owner of the *script
        // file*, which answers a different question — a `bin/agentic` owned by root would give
        // "root" to everyone who runs it, and under `php -r` it gives nothing at all. Nor the USER
        // environment variable, which the caller writes.
        $user = \function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';

        return new Principal('' === trim((string) $user) ? $this->fallback : trim((string) $user));
    }
}
