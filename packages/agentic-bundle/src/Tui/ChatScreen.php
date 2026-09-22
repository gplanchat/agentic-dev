<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tui;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Component\Tui\Terminal\TerminalInterface;

/**
 * Ouvre l'écran de chat d'une conversation.
 */
final readonly class ChatScreen
{
    public function __construct(
        private Conversations $conversations,
        private InProcessWorker $worker,
    ) {
    }

    public function open(string $conversation, ?TerminalInterface $terminal = null): ChatView
    {
        return new ChatView($this->conversations, $this->worker, new SlashCommands($this->conversations), $conversation, $terminal);
    }
}
