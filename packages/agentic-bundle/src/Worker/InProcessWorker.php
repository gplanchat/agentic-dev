<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Worker;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Le worker, dans le processus de l'interface : une passe sur les transports de Durable, sans
 * jamais attendre. C'est ce qui fait avancer les workflows et les activités quand personne ne lance
 * `messenger:consume` à côté — la TUI l'appelle à chaque rafraîchissement.
 *
 * Un appel d'activité — l'appel au modèle compris — s'exécute pendant la passe : l'écran se fige le
 * temps d'une réponse de fournisseur.
 */
final class InProcessWorker
{
    /** La dernière panne d'un message : l'interface la montre au lieu de mourir avec. */
    public private(set) ?\Throwable $lastFailure = null;

    /**
     * @param list<string> $transports
     */
    public function __construct(
        private readonly ContainerInterface $receivers,
        private readonly MessageBusInterface $bus,
        private readonly array $transports = ['durable_workflows', 'durable_activities'],
    ) {
    }

    /**
     * @return bool vrai si au moins un message a été traité
     */
    public function pump(): bool
    {
        $worked = false;
        foreach ($this->transports as $name) {
            if (!$this->receivers->has($name)) {
                continue;
            }

            /** @var ReceiverInterface $receiver */
            $receiver = $this->receivers->get($name);
            foreach ($receiver->get() as $envelope) {
                try {
                    $this->bus->dispatch($envelope->with(new ReceivedStamp($name)));
                    $receiver->ack($envelope);
                } catch (\Throwable $exception) {
                    // Le journal a déjà enregistré l'échec (`WorkflowExecutionFailed`) : relever ici
                    // tuerait l'interface, pas l'exécution.
                    $receiver->reject($envelope);
                    $this->lastFailure = $exception;
                }
                $worked = true;
            }
        }

        return $worked;
    }

    /**
     * Passe après passe jusqu'à ce que plus rien ne bouge — un tour d'agent enchaîne workflow,
     * activité, workflow.
     */
    public function drain(int $maxPasses = 100): void
    {
        for ($pass = 0; $pass < $maxPasses && $this->pump(); ++$pass) {
        }
    }
}
