<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Help;

/**
 * Cas d'usage : lister les commandes disponibles, triées par nom.
 */
final readonly class ListCommands
{
    public function __construct(private CommandCatalog $catalog)
    {
    }

    /** @return list<CommandSummary> */
    public function __invoke(): array
    {
        $commands = [...$this->catalog->all()];
        usort($commands, static fn (CommandSummary $a, CommandSummary $b): int => strcmp($a->name, $b->name));

        return $commands;
    }
}
