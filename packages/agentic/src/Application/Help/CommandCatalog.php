<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Help;

/**
 * Port: where to find the commands the application exposes.
 */
interface CommandCatalog
{
    /** @return iterable<CommandSummary> */
    public function all(): iterable;
}
