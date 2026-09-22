<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

use Gplanchat\Agentic\Domain\Guard\AgentMode;

/**
 * Port: what an interface — terminal or web — can do with a conversation with an agent.
 *
 * A conversation is a workflow execution; every intent below becomes a signal, hence is journaled
 * and replayed. Nothing is awaited here: the interface reads {@see transcript()} back.
 */
interface Conversations
{
    /**
     * @return string the identifier of the conversation
     */
    public function start(): string;

    public function send(string $conversation, string $text): void;

    public function decide(string $conversation, string $callId, bool $approved): void;

    /**
     * @param list<string> $answers
     */
    public function answer(string $conversation, string $callId, array $answers): void;

    public function alert(string $conversation, string $callId, string $observation): void;

    public function setMode(string $conversation, AgentMode $mode): void;

    /**
     * @throws \InvalidArgumentException if the model is not known
     */
    public function setModel(string $conversation, string $model): void;

    /**
     * The models a conversation can take: the ones that know how to call tools.
     *
     * @return list<string>
     */
    public function models(): array;

    public function close(string $conversation): void;

    /**
     * Does the journal know this conversation? An unknown identifier must be refused, not opened on
     * an empty screen that will never answer.
     */
    public function exists(string $conversation): bool;

    /**
     * The most recent conversations first.
     *
     * @return list<ConversationSummary>
     */
    public function recent(int $limit = 20): array;

    /**
     * A brand new conversation starting again from the thread of another one — that is how a
     * finished conversation is resumed, how one goes back, or how one compacts. The journal of the
     * old one is never rewritten; it is closed if it was still running.
     *
     * @param int|null $keepUserMessages keep from the thread only what precedes this human message (0 = the first one); `null` = everything
     * @param bool     $compact          summarise the resumed thread before the first turn
     *
     * @return string the new conversation
     */
    public function restart(string $from, ?int $keepUserMessages = null, bool $compact = false): string;

    public function transcript(string $conversation): Transcript;
}
