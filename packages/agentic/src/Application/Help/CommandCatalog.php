<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Help;

/**
 * Port : où trouver les commandes que l'application expose.
 */
interface CommandCatalog
{
    /** @return iterable<CommandSummary> */
    public function all(): iterable;
}
