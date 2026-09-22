<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Adaptateur primaire : discuter avec un agent durable dans le terminal.
 */
final class ChatCommand extends Command
{
    public function __construct(
        private readonly Conversations $conversations,
        private readonly ChatScreen $screen,
    ) {
        parent::__construct('chat');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Discuter avec un agent durable')
            ->addArgument('conversation', InputArgument::OPTIONAL, 'La conversation à reprendre ; une nouvelle sinon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive() || !$output instanceof StreamOutput || !stream_isatty($output->getStream())) {
            $output->writeln('<error>Le chat demande un terminal interactif.</error>');

            return self::FAILURE;
        }

        $conversation = $input->getArgument('conversation') ?? $this->conversations->start();
        $this->screen->open($conversation)->run();

        $output->writeln(\sprintf('Conversation <info>%s</info>.', $conversation));

        return self::SUCCESS;
    }
}
