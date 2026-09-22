<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Worker;

use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * The worker, inside the interface process: one pass over the Durable transports, never waiting.
 * This is what moves the workflows and the activities forward when nobody is running
 * `messenger:consume` alongside — the TUI calls it on every refresh.
 *
 * An activity call — the model call included — runs during the pass: the screen freezes for as long
 * as a provider takes to answer.
 */
final class InProcessWorker
{
    /** The last breakdown of a message: the interface shows it instead of dying with it. */
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
     * @return bool true if at least one message was handled
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
                    // The journal has already recorded the failure (`WorkflowExecutionFailed`):
                    // rethrowing here would kill the interface, not the run.
                    $receiver->reject($envelope);
                    $this->lastFailure = $exception;
                }
                $worked = true;
            }
        }

        return $worked;
    }

    /**
     * Pass after pass until nothing moves any more — an agent turn chains workflow, activity,
     * workflow.
     */
    public function drain(int $maxPasses = 100): void
    {
        for ($pass = 0; $pass < $maxPasses && $this->pump(); ++$pass) {
        }
    }
}
