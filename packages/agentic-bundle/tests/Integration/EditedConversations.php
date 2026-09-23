<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\ToolStep;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;

/**
 * A conversation that has edited files, without running a model or a sandbox: the scripted client
 * never calls edit_file, and a real one would cut a worktree of this repository.
 */
final class EditedConversations implements Conversations
{
    /**
     * @param list<ToolStep> $steps
     */
    public function __construct(private readonly array $steps)
    {
    }

    public function start(): string
    {
        return 'edited-conversation';
    }

    public function send(string $conversation, string $text): void
    {
    }

    public function decide(string $conversation, string $callId, bool $approved): void
    {
    }

    public function answer(string $conversation, string $callId, array $answers): void
    {
    }

    public function alert(string $conversation, string $callId, string $observation): void
    {
    }

    public function setMode(string $conversation, AgentMode $mode): void
    {
    }

    public function setModel(string $conversation, string $model): void
    {
    }

    public function models(): array
    {
        return [];
    }

    public function close(string $conversation): void
    {
    }

    public function exists(string $conversation): bool
    {
        return true;
    }

    public function belongsHere(string $conversation): bool
    {
        return true;
    }

    public function recent(int $limit = 20): array
    {
        return [];
    }

    public function restart(string $from, ?int $keepUserMessages = null, bool $compact = false): string
    {
        return $from;
    }

    public function transcript(string $conversation): Transcript
    {
        return new Transcript([], $this->steps, [], [], [], AgentMode::Standard, null, false, false);
    }
}
