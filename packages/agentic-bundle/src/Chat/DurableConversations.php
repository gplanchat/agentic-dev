<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

use Gplanchat\Agentic\Application\Chat\ConversationSummary;
use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
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
 * Adaptateur du port {@see Conversations} : une conversation est une exécution de
 * {@see DurableAgentWorkflow}, chaque intention un signal envoyé par le bus.
 */
final readonly class DurableConversations implements Conversations
{
    /**
     * @param array<string, mixed> $options charge de démarrage du workflow, hors outils
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
        private array $options = [],
    ) {
    }

    public function start(): string
    {
        return $this->launch([], false);
    }

    public function restart(string $from, ?int $keepUserMessages = null, bool $compact = false): string
    {
        $transcript = $this->transcript($from);
        $history = null === $keepUserMessages ? $transcript->seed() : $transcript->seedBefore($keepUserMessages);

        if (!$transcript->finished) {
            $this->close($from);
        }

        // Un fil vide n'a rien à résumer : la compaction coûterait un appel modèle pour rien.
        return $this->launch($history, $compact && [] !== $history);
    }

    public function exists(string $conversation): bool
    {
        foreach ($this->events->readStream($conversation) as $_) {
            return true;
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

            $said = $this->transcript($run->runId)->userMessages();
            $recent[] = new ConversationSummary(
                $run->runId,
                $said[0] ?? '(rien de dit)',
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
    private function launch(array $history, bool $compact): string
    {
        $conversation = (string) Uuid::v4();
        $this->dispatcher->dispatchNewWorkflowRun($conversation, DurableAgentWorkflow::class, [
            ...$this->options,
            'systemPrompt' => $this->instructions->appendTo((string) ($this->options['systemPrompt'] ?? DurableAgentWorkflow::SYSTEM_PROMPT)),
            'tools' => $this->tools->toolset()->toWire(),
            'history' => $history,
            'compactHistory' => $compact,
        ]);

        return $conversation;
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
        $this->signal($conversation, 'alerte', ['callId' => $callId, 'observation' => $observation]);
    }

    public function setMode(string $conversation, AgentMode $mode): void
    {
        $this->signal($conversation, 'set_mode', ['mode' => $mode->value]);
    }

    public function setModel(string $conversation, string $model): void
    {
        // Le workflow ne valide pas : un nom inconnu ferait échouer l'appel modèle du tour suivant,
        // donc toute la conversation. On refuse ici, avant que le signal soit journalisé.
        if (!\in_array($model, $this->models(), true)) {
            throw new \InvalidArgumentException(\sprintf('Modèle inconnu : « %s ».', $model));
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
        return $this->transcripts->forExecution($conversation);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signal(string $conversation, string $name, array $payload): void
    {
        $this->bus->dispatch(new DeliverWorkflowSignalMessage($conversation, $name, $payload));
    }
}
