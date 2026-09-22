<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Chat;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\PendingApproval;
use Gplanchat\Agentic\Domain\Question\PendingQuestion;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Watch\Watch;
use Gplanchat\Durable\Duration;

/**
 * L'état de la conversation tel que le journal le raconte.
 *
 * `working` et `finished` sont dérivés, pas stockés : c'est une projection, elle n'a pas d'état
 * propre à tenir à jour.
 */
final readonly class Transcript implements \JsonSerializable
{
    /**
     * @param list<TranscriptMessage> $messages
     * @param list<ToolStep>          $steps
     * @param list<PendingApproval>   $pending   validations retenues par la garde
     * @param list<PendingQuestion>   $questions questions posées par l'agent
     * @param list<Watch>             $watches   veilles en cours
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
        /** Pourquoi l'exécution a échoué, si elle a échoué. */
        public ?string $failure = null,
        /** Le modèle du prochain tour. */
        public string $model = '',
        /** Les outils de l'application figés au démarrage — hors outils toujours offerts. */
        public Toolset $tools = new Toolset(),
    ) {
    }

    /**
     * Ce qu'une exécution neuve doit reprendre de celle-ci : le fil parlé, sans la mécanique
     * d'outils du run qui s'achève.
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
     * @return array{messages: list<TranscriptMessage>, steps: list<ToolStep>, pending: list<PendingApproval>, questions: list<PendingQuestion>, watches: list<Watch>, mode: string, humanTimeoutSeconds: float|null, working: bool, finished: bool, failure: string|null, model: string, tools: array<string, mixed>}
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
        ];
    }
}
