<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Help\ListCommands;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as ConsoleHelpCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Primary adapter: `help` with no argument prints the list of commands, and returns.
 *
 * `help <command>` and `<command> --help` keep the detailed help of Symfony Console, hence the
 * inheritance: Application::doRun() only injects the targeted command into a HelpCommand. `help`,
 * `--help` and `help help` all three print the list: the argument defaults to `help`.
 */
final class HelpCommand extends ConsoleHelpCommand
{
    private bool $targeted = false;

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

        // ponytail: the catalogue is built here rather than injected, because it reads the
        // application that holds this command — injecting it would make a cycle in the container.
        $commands = (new ListCommands(new ConsoleCommandCatalog($this->getApplication())))();

        $width = max(array_map(static fn ($c): int => mb_strlen($c->name), $commands) ?: [0]);
        $output->writeln(['<options=bold>Agentic — available commands</>', '']);
        foreach ($commands as $command) {
            $output->writeln(\sprintf('  <info>%s</info>  %s', str_pad($command->name, $width), $command->description));
        }

        return self::SUCCESS;
    }
}
