<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Help\CommandCatalog;
use Gplanchat\Agentic\Application\Help\CommandSummary;
use Symfony\Component\Console\Application;

/**
 * Adapter of the {@see CommandCatalog} port: the commands registered in a Console application.
 */
final readonly class ConsoleCommandCatalog implements CommandCatalog
{
    public function __construct(private Application $application)
    {
    }

    public function all(): iterable
    {
        foreach ($this->application->all() as $name => $command) {
            // An alias is a second key for the same command: we list it only once.
            if ($name === $command->getName() && !$command->isHidden()) {
                yield new CommandSummary($name, $command->getDescription());
            }
        }
    }
}
