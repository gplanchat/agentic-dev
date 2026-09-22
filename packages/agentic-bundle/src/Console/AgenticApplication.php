<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * L'application TUI `agentic`. Ses commandes sont les services marqués
 * {@see \Gplanchat\AgenticBundle\AgenticBundle::COMMAND_TAG}.
 */
final class AgenticApplication extends Application
{
    /**
     * @param iterable<Command> $commands
     */
    public function __construct(iterable $commands, string $version = 'dev')
    {
        parent::__construct('agentic', $version);

        foreach ($commands as $command) {
            $this->addCommand($command);
        }

        $this->setDefaultCommand('help');
    }
}
