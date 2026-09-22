<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Help\CommandCatalog;
use Gplanchat\Agentic\Application\Help\CommandSummary;
use Symfony\Component\Console\Application;

/**
 * Adaptateur du port {@see CommandCatalog} : les commandes enregistrées dans une application Console.
 */
final readonly class ConsoleCommandCatalog implements CommandCatalog
{
    public function __construct(private Application $application)
    {
    }

    public function all(): iterable
    {
        foreach ($this->application->all() as $name => $command) {
            // Un alias est une seconde clé pour la même commande : on ne la liste qu'une fois.
            if ($name === $command->getName() && !$command->isHidden()) {
                yield new CommandSummary($name, $command->getDescription());
            }
        }
    }
}
