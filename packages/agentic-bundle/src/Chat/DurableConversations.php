<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

use Gplanchat\Agentic\Application\Chat\ConversationSummary;
use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Identity\ConversationNotOwned;
use Gplanchat\Agentic\Domain\Identity\ConversationOwnerUnknown;
use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Adapter of the {@see Conversations} port: a conversation is a run of
 * {@see DurableAgentWorkflow}, every intent a signal sent through the bus.
 */
final readonly class DurableConversations implements Conversations
{
    /**
     * @param array<string, mixed> $options the workflow start payload, tools aside
     */
    public function __construct(
        private WorkflowResumeDispatcher $dispatcher,
        private MessageBusInterface $bus,
        private ChatTranscript $transcripts,
        private AgentTools $tools,
        private ModelCatalogInterface $catalog,
        private ProjectInstructions $instructions,
        private EventStoreInterface $events,
        private WorkflowRunCatalogInterface $runs,
        private CurrentPrincipal $principal,
        private array $options,
        private Project $project,
        private ?Workspaces $workspaces = null,
    ) {
    }

    public function start(): string
    {
        return $this->launch([], false, null);
    }

    public function restart(string $from, ?int $keepUserMessages = null, bool $compact = false): string
    {
        // `transcript()` asserts ownership: one cannot fork a thread one may not read.
        $transcript = $this->transcript($from);
        $history = null === $keepUserMessages ? $transcript->seed() : $transcript->seedBefore($keepUserMessages);

        if (!$transcript->finished) {
            $this->close($from);
        }

        // An empty thread has nothing to summarise: compaction would cost a model call for nothing.
        // The worktree carries over: /rewind, /compact and /resume go on with the same work.
        return $this->launch($history, $compact && [] !== $history, $transcript->workspace);
    }

    /**
     * A conversation that is not ours does not exist — the distinction between "no such id" and
     * "not yours" is itself an answer to a question the asker had no right to put.
     */
    public function exists(string $conversation): bool
    {
        foreach ($this->events->readStream($conversation) as $_) {
            return $this->owns($conversation);
        }

        return false;
    }

    public function recent(int $limit = 20): array
    {
        $recent = [];
        foreach ($this->runs->listRuns(limit: $limit)->runs as $run) {
            if (DurableAgentWorkflow::class !== $run->workflowName && DurableAgentWorkflow::TYPE !== $run->workflowName) {
                continue;
            }

            // Read unchecked, then filter: asserting here would throw on the first conversation
            // belonging to someone else instead of simply not listing it.
            $transcript = $this->read($run->runId);
            if (!($transcript->owner?->is(($this->principal)()) ?? false) || !$this->isHere($transcript)) {
                continue;
            }

            $said = $transcript->userMessages();
            $recent[] = new ConversationSummary(
                $run->runId,
                $said[0] ?? '(nothing said)',
                WorkflowRunStatus::Running !== $run->status,
                $run->startedAt,
            );
        }

        usort($recent, static fn (ConversationSummary $a, ConversationSummary $b): int => ($b->startedAt?->getTimestamp() ?? 0) <=> ($a->startedAt?->getTimestamp() ?? 0));

        return $recent;
    }

    /**
     * @param list<array{role: string, content: string}> $history
     */
    private function launch(array $history, bool $compact, ?string $workspace): string
    {
        $conversation = (string) Uuid::v4();
        // Only a path: the worktree is created by the first command that needs it.
        $workspace ??= $this->workspaces?->worktrees()?->pathFor($conversation);
        $this->dispatcher->dispatchNewWorkflowRun($conversation, DurableAgentWorkflow::class, [
            ...$this->options,
            'systemPrompt' => $this->systemPrompt(),
            'toolRules' => $this->toolRules(),
            'tools' => $this->tools->toolset()->toWire(),
            'history' => $history,
            'compactHistory' => $compact,
            'workspace' => $workspace,
            // Frozen at start like the tools and the rules: who opened the conversation is a fact
            // of the journal, not of the session that reads it back.
            'owner' => ($this->principal)()->toWire(),
        ]);

        return $conversation;
    }

    public function belongsHere(string $conversation): bool
    {
        return $this->isHere($this->read($conversation));
    }

    /**
     * Its workspace is this project's root, or one of this project's worktrees. With no workspace —
     * no sandbox — or no project, there is nowhere else it could belong.
     */
    private function isHere(Transcript $transcript): bool
    {
        if (null === $transcript->workspace) {
            return true;
        }

        return $transcript->workspace === $this->project->root
            || ($this->workspaces?->worktrees()?->owns($transcript->workspace) ?? false);
    }

    /**
     * The prompt a conversation starts with: the installation's, the method of the project's check
     * layers, then the installation's instructions and the project's. Frozen into the start payload:
     * a project file changed tomorrow does not change a conversation begun today.
     */
    private function systemPrompt(): string
    {
        $prompt = (string) ($this->options['systemPrompt'] ?? DurableAgentWorkflow::SYSTEM_PROMPT);
        if (null !== $this->workspaces && [] !== $this->project->checks) {
            $prompt .= "\n\n".RunChecksTool::method($this->project->checks);
        }
        $prompt = $this->instructions->appendTo($prompt);

        return null === $this->project->instructionsFile ? $prompt : (new ProjectInstructions($this->project->instructionsFile))->appendTo($prompt);
    }

    /**
     * The installation's rules, the project's, and — with the sandbox — the auto-mode list as a rule
     * like the others: an ask beats an allow, so an `allow` elsewhere does not widen it.
     *
     * @return list<array<string, mixed>>
     */
    private function toolRules(): array
    {
        /** @var list<array<string, mixed>> $installation built by AgenticBundle from its configuration */
        $installation = $this->options['toolRules'] ?? [];
        $rules = [...$installation, ...$this->project->toolRules];
        if (null !== $this->workspaces) {
            $rules[] = [
                'tool' => RunCommandTool::TOOL,
                'decision' => 'ask',
                'modes' => ['auto'],
                'unless' => ['command' => $this->project->autoAllow],
                'reason' => 'Command outside the auto-mode list (sandbox.auto_allow, in the installation or the project configuration).',
            ];
        }

        return $rules;
    }

    public function send(string $conversation, string $text): void
    {
        $this->signal($conversation, 'user_message', ['text' => $text]);
    }

    public function decide(string $conversation, string $callId, bool $approved): void
    {
        $this->signal($conversation, 'tool_decision', ['callId' => $callId, 'approved' => $approved]);
    }

    public function answer(string $conversation, string $callId, array $answers): void
    {
        $this->signal($conversation, 'question_answered', ['callId' => $callId, 'answers' => array_values($answers)]);
    }

    public function alert(string $conversation, string $callId, string $observation): void
    {
        $this->signal($conversation, 'alert', ['callId' => $callId, 'observation' => $observation]);
    }

    public function setMode(string $conversation, AgentMode $mode): void
    {
        $this->signal($conversation, 'set_mode', ['mode' => $mode->value]);
    }

    public function setModel(string $conversation, string $model): void
    {
        // The workflow does not validate: an unknown name would make the next turn's model call
        // fail, hence the whole conversation. We refuse here, before the signal is journalled.
        if (!\in_array($model, $this->models(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown model: "%s".', $model));
        }

        $this->signal($conversation, 'set_model', ['model' => $model]);
    }

    public function models(): array
    {
        $models = [];
        foreach (array_keys($this->catalog->getModels()) as $name) {
            if ($this->catalog->getModel($name)->supports(Capability::TOOL_CALLING)) {
                $models[] = $name;
            }
        }

        return $models;
    }

    public function close(string $conversation): void
    {
        $this->signal($conversation, 'close', []);
    }

    public function transcript(string $conversation): Transcript
    {
        $transcript = $this->read($conversation);
        // Three states, not two. Telling someone their own conversation is not theirs — which is
        // what a missing owner used to do — is worse than refusing: it accuses them.
        if (null === $transcript->owner) {
            throw new ConversationOwnerUnknown($conversation);
        }
        if (!$transcript->owner->is(($this->principal)())) {
            throw new ConversationNotOwned($conversation);
        }

        return $transcript;
    }

    /**
     * The projection, with nothing asked of the reader. Everything public goes through
     * {@see transcript()} or {@see owns()}; this is for the two places that must look before they
     * are allowed to refuse.
     */
    private function read(string $conversation): Transcript
    {
        return $this->transcripts->forExecution($conversation);
    }

    /**
     * A conversation with no owner in its journal belongs to nobody, hence to no reader: those
     * opened before owners existed are readable by no one rather than by everyone.
     */
    private function owns(string $conversation): bool
    {
        return $this->read($conversation)->owner?->is(($this->principal)()) ?? false;
    }

    /**
     * Every intent that moves a conversation goes through here, which is why the check lives here
     * and not in each of the seven methods above: one gate on the way through cannot be forgotten
     * by the eighth.
     *
     * @param array<string, mixed> $payload
     */
    private function signal(string $conversation, string $name, array $payload): void
    {
        if (!$this->owns($conversation)) {
            throw new ConversationNotOwned($conversation);
        }

        $this->bus->dispatch(new DeliverWorkflowSignalMessage($conversation, $name, $payload));
    }
}
