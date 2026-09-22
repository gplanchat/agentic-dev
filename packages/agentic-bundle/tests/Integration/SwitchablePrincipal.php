<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Domain\Identity\Principal;

/**
 * The principal of the kernel under test, switchable in the middle of a test: that is how one
 * writes "and now someone else asks".
 *
 * It also takes the tests off the account running them — {@see \Gplanchat\AgenticBundle\Chat\LocalPrincipal}
 * reads the OS user, which is not something a suite should depend on.
 */
final class SwitchablePrincipal implements CurrentPrincipal
{
    public function __construct(private string $id = 'alice')
    {
    }

    public function becomes(string $id): void
    {
        $this->id = $id;
    }

    public function __invoke(): Principal
    {
        return new Principal($this->id);
    }
}
