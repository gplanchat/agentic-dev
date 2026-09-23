<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Console;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Project\InvalidProjectConfiguration;
use Gplanchat\AgenticBundle\Project\ProjectFile;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Primary adapter: talking to a durable agent in the terminal.
 */
final class ChatCommand extends Command
{
    public function __construct(
        private readonly Conversations $conversations,
        private readonly ChatScreen $screen,
        private readonly ProjectLoader $projects,
        private readonly TrustStore $trust,
        private readonly string $projectRoot,
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

        if (null !== $conversation && !$this->conversations->belongsHere($conversation)) {
            $output->writeln(\sprintf('<error>The conversation %s belongs to another project (%s).</error> Launch the agent from that directory to resume it.', $conversation, OutputFormatter::escape((string) $this->conversations->transcript($conversation)->workspace)));

            return self::FAILURE;
        }

        if (!$this->approveProjectFile($input, $output)) {
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

    /**
     * The project's `.agentic/config.*`, new or changed since it was approved: shown, and used only on
     * a yes. It can add commands the agent runs and rules for what it may do — the human who launched
     * the agent decides, not the agent, and not whoever last wrote the file.
     *
     * @return bool false when the file cannot be used at all: the chat stops, and says why
     */
    private function approveProjectFile(InputInterface $input, OutputInterface $output): bool
    {
        try {
            $pending = $this->projects->pending($this->projectRoot);
            if (null !== $pending) {
                $output->writeln([
                    '',
                    \sprintf('<comment>%s</comment> %s:', $pending->path, $this->trust->knows($pending) ? 'has changed since it was approved' : 'is not approved yet'),
                    '',
                    ...$this->shown($pending),
                    '',
                    'It can add commands the agent runs without asking, and rules for what it may do.',
                ]);
                $approve = $input->isInteractive()
                    && (new SymfonyStyle($input, $output))->confirm('Use it?', false);
                if ($approve) {
                    $this->trust->trust($pending);
                } else {
                    $output->writeln('<comment>Starting without it: the installation\'s configuration only.</comment>');
                }
            }

            // Read once now: a file that approves but does not hold together stops here, not mid-turn.
            $this->projects->load($this->projectRoot);
        } catch (InvalidProjectConfiguration $invalid) {
            $output->writeln(\sprintf('<error>%s</error> Fix it, or remove it.', OutputFormatter::escape($invalid->getMessage())));

            return false;
        }

        return true;
    }

    /**
     * The file set apart from the rest of the screen: changed since an approval, only what changed,
     * against what was approved; otherwise all of it. Escaped — a file that wrote console tags could
     * otherwise colour a line into the background.
     *
     * @return list<string>
     */
    private function shown(ProjectFile $pending): array
    {
        $approved = $this->trust->approvedContents($pending);
        if (null === $approved) {
            return array_map(static fn (string $line): string => '  │ '.OutputFormatter::escape($line), explode("\n", rtrim($pending->contents)));
        }

        $diff = (new Differ(new StrictUnifiedDiffOutputBuilder(['fromFile' => 'approved', 'toFile' => 'now'])))->diff($approved, $pending->contents);

        return array_map(static fn (string $line): string => '  │ '.match ($line[0] ?? '') {
            '+' => '<fg=green>'.OutputFormatter::escape($line).'</>',
            '-' => '<fg=red>'.OutputFormatter::escape($line).'</>',
            default => OutputFormatter::escape($line),
        }, explode("\n", rtrim($diff)));
    }
}
