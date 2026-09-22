<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Help\ListCommands;
use Gplanchat\AgenticBundle\Tui\HelpScreen;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as ConsoleHelpCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Adaptateur primaire : `help` sans argument ouvre l'écran d'aide en TUI.
 *
 * `help <commande>` et `<commande> --help` gardent l'aide détaillée de Symfony Console, d'où
 * l'héritage : Application::doRun() n'injecte la commande visée que dans un HelpCommand. `help`,
 * `--help` et `help help` ouvrent tous trois l'écran d'aide : l'argument vaut `help` par défaut.
 */
final class HelpCommand extends ConsoleHelpCommand
{
    private bool $targeted = false;

    public function __construct(private readonly HelpScreen $screen)
    {
        parent::__construct();
    }

    public function setCommand(Command $command): void
    {
        $this->targeted = true;
        parent::setCommand($command);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targeted = $this->targeted || 'help' !== $input->getArgument('command_name');
        $this->targeted = false;

        if ($targeted) {
            return parent::execute($input, $output);
        }

        // ponytail: le catalogue est construit ici et non injecté, parce qu'il lit l'application
        // qui contient cette commande — l'injecter ferait un cycle dans le conteneur.
        $commands = (new ListCommands(new ConsoleCommandCatalog($this->getApplication())))();

        if (!$input->isInteractive() || !$output instanceof StreamOutput || !stream_isatty($output->getStream())) {
            // Hors terminal (pipe, CI) : la même liste en texte brut.
            foreach ($commands as $command) {
                $output->writeln(\sprintf('%-20s %s', $command->name, $command->description));
            }

            return self::SUCCESS;
        }

        $this->screen->build($commands)->run();

        return self::SUCCESS;
    }
}
