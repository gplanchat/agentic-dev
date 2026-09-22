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
 * Primary adapter: talking to a durable agent in the terminal.
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
            ->setDescription('Talk to a durable agent')
            ->addArgument('conversation', InputArgument::OPTIONAL, 'The conversation to resume; a new one otherwise');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conversation = $input->getArgument('conversation');
        if (null !== $conversation && !$this->conversations->exists($conversation)) {
            // A blank screen that would never answer would be worse than a refusal.
            $output->writeln(\sprintf('<error>Unknown conversation: %s.</error> `chat` with no argument starts one; `/resume` lists the previous ones.', $conversation));

            return self::FAILURE;
        }

        if (!$input->isInteractive() || !$output instanceof StreamOutput || !stream_isatty($output->getStream())) {
            $output->writeln('<error>The chat needs an interactive terminal.</error>');

            return self::FAILURE;
        }

        if (null === $conversation) {
            $conversation = $this->conversations->start();
        } elseif ($this->conversations->transcript($conversation)->finished) {
            // A finished run is not reopened: a fresh one starts again from its thread.
            $from = $conversation;
            $conversation = $this->conversations->restart($from);
            $output->writeln(\sprintf('The conversation %s was finished: it resumes in %s.', $from, $conversation));
        }

        $this->screen->open($conversation)->run();

        $output->writeln(\sprintf('Conversation <info>%s</info>.', $conversation));

        return self::SUCCESS;
    }
}
