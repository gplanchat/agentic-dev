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
 * Primary adapter: `help` with no argument opens the help screen in the TUI.
 *
 * `help <command>` and `<command> --help` keep the detailed help of Symfony Console, hence the
 * inheritance: Application::doRun() only injects the targeted command into a HelpCommand. `help`,
 * `--help` and `help help` all three open the help screen: the argument defaults to `help`.
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

        // ponytail: the catalogue is built here rather than injected, because it reads the
        // application that holds this command — injecting it would make a cycle in the container.
        $commands = (new ListCommands(new ConsoleCommandCatalog($this->getApplication())))();

        if (!$input->isInteractive() || !$output instanceof StreamOutput || !stream_isatty($output->getStream())) {
            // Outside a terminal (pipe, CI): the same list in plain text.
            foreach ($commands as $command) {
                $output->writeln(\sprintf('%-20s %s', $command->name, $command->description));
            }

            return self::SUCCESS;
        }

        $this->screen->build($commands)->run();

        return self::SUCCESS;
    }
}
