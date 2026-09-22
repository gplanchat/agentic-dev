<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Chat;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
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
        private array $options = [],
    ) {
    }

    public function start(): string
    {
        $conversation = (string) Uuid::v4();
        $this->dispatcher->dispatchNewWorkflowRun($conversation, DurableAgentWorkflow::class, [
            ...$this->options,
            'systemPrompt' => $this->instructions->appendTo((string) ($this->options['systemPrompt'] ?? DurableAgentWorkflow::SYSTEM_PROMPT)),
            'tools' => $this->tools->toolset()->toWire(),
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
