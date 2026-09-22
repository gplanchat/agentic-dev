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
        $conversation = $input->getArgument('conversation');
        if (null !== $conversation && !$this->conversations->exists($conversation)) {
            // Un écran vide qui ne répondrait jamais serait pire qu'un refus.
            $output->writeln(\sprintf('<error>Conversation inconnue : %s.</error> `chat` sans argument en commence une ; `/resume` liste les précédentes.', $conversation));

            return self::FAILURE;
        }

        if (!$input->isInteractive() || !$output instanceof StreamOutput || !stream_isatty($output->getStream())) {
            $output->writeln('<error>Le chat demande un terminal interactif.</error>');

            return self::FAILURE;
        }

        if (null === $conversation) {
            $conversation = $this->conversations->start();
        } elseif ($this->conversations->transcript($conversation)->finished) {
            // Une exécution terminée ne se rouvre pas : une neuve repart de son fil.
            $from = $conversation;
            $conversation = $this->conversations->restart($from);
            $output->writeln(\sprintf('La conversation %s était terminée : elle reprend dans %s.', $from, $conversation));
        }

        $this->screen->open($conversation)->run();

        $output->writeln(\sprintf('Conversation <info>%s</info>.', $conversation));

        return self::SUCCESS;
    }
}
