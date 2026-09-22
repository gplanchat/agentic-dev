<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\PendingApproval;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Question\PendingQuestion;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Watch\Watch;
use Gplanchat\Durable\Duration;

/**
 * The state of the conversation as the journal tells it.
 *
 * `working` and `finished` are derived, not stored: this is a projection, it has no state of its
 * own to keep up to date.
 */
final readonly class Transcript implements \JsonSerializable
{
    /**
     * @param list<TranscriptMessage> $messages
     * @param list<ToolStep>          $steps
     * @param list<PendingApproval>   $pending   approvals held back by the guard
     * @param list<PendingQuestion>   $questions questions asked by the agent
     * @param list<Watch>             $watches   watches in progress
     */
    public function __construct(
        public array $messages,
        public array $steps,
        public array $pending,
        public array $questions,
        public array $watches,
        public AgentMode $mode,
        public ?Duration $humanTimeout,
        public bool $working,
        public bool $finished,
        /** Why the execution failed, if it failed. */
        public ?string $failure = null,
        /** The model of the next turn. */
        public string $model = '',
        /** The application's tools frozen at start-up — excluding the always-offered tools. */
        public Toolset $tools = new Toolset(),
        /** @var list<ToolRule> the decision hooks frozen at start-up */
        public array $rules = [],
        /** The sub-agents this conversation may delegate to, frozen at start. */
        public AgentProfiles $profiles = new AgentProfiles(),
        /** The working directory the tools act in, frozen at start-up; `null` = the project. */
        public ?string $workspace = null,
        /** Who the conversation belongs to, frozen at start-up; `null` = opened before owners existed. */
        public ?Principal $owner = null,
    ) {
    }

    /**
     * What a brand new execution must carry over from this one: the spoken thread, without the tool
     * mechanics of the run that is ending.
     *
     * @return list<array{role: string, content: string}>
     */
    public function seed(): array
    {
        return TranscriptMessage::listToWire(array_filter(
            $this->messages,
            static fn (TranscriptMessage $message): bool => $message->carriesText(),
        ));
    }

    /**
     * The spoken thread, cut just before a message from the human — that is what `/rewind` resumes.
     *
     * @param int $userMessage the rank of the human message to cut at, starting from 0; past the last one, the whole thread
     *
     * @return list<array{role: string, content: string}>
     */
    public function seedBefore(int $userMessage): array
    {
        $seed = [];
        $seen = 0;
        foreach ($this->seed() as $message) {
            if ('user' === $message['role'] && $seen++ === $userMessage) {
                break;
            }
            $seed[] = $message;
        }

        return $seed;
    }

    /**
     * What the human said, in order.
     *
     * @return list<string>
     */
    public function userMessages(): array
    {
        return array_values(array_map(
            static fn (TranscriptMessage $message): string => (string) $message->content,
            array_filter($this->messages, static fn (TranscriptMessage $message): bool => $message->isUser() && $message->carriesText()),
        ));
    }

    /**
     * @return array{messages: list<TranscriptMessage>, steps: list<ToolStep>, pending: list<PendingApproval>, questions: list<PendingQuestion>, watches: list<Watch>, mode: string, humanTimeoutSeconds: float|null, working: bool, finished: bool, failure: string|null, model: string, tools: array<string, mixed>, rules: list<array<string, mixed>>, agents: array<string, array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'messages' => $this->messages,
            'steps' => $this->steps,
            'pending' => $this->pending,
            'questions' => $this->questions,
            'watches' => $this->watches,
            'mode' => $this->mode->value,
            'humanTimeoutSeconds' => $this->humanTimeout?->toSeconds(),
            'working' => $this->working,
            'finished' => $this->finished,
            'failure' => $this->failure,
            'model' => $this->model,
            'tools' => $this->tools->toWire(),
            'rules' => array_map(static fn (ToolRule $rule): array => $rule->toWire(), $this->rules),
            'agents' => $this->profiles->toWire(),
        ];
    }
}
