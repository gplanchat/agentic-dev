<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\ModelInvocationActivityInterface;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Gplanchat\Agentic\Domain\Context\ContextBudget;
use Gplanchat\Agentic\Domain\Context\TokenLedger;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\ContextOverflow;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\JournaledHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * The low seam of Symfony AI: `Provider` has already normalised the conversation and the tool
 * schemas into arrays, all that is left is the HTTP call — which we replace with an activity.
 *
 * A consequence: `Runner::run()` becomes ordinary workflow code, replayed, whose only
 * non-deterministic leg comes out of the journal.
 */
final class DurableModelClient implements ModelClientInterface
{
    private readonly ActivityStub $stub;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
        private readonly ContextBudget $budget = new ContextBudget(),
        ?ActivityOptions $options = null,
        /** What the run has spent so far; fed here because this is where every model call passes. */
        private readonly ?TokenLedger $ledger = null,
    ) {
        $this->stub = $environment->activityStub(ModelInvocationActivityInterface::class, $options);
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('A textual payload does not cross the activity boundary of this prototype.');
        }

        if (true === ($options['stream'] ?? false)) {
            // An activity returns a value once: a stream of deltas does not replay.
            throw new \LogicException('Streaming is incompatible with replay: journal the assembled result, stream on a side channel.');
        }

        // Preventive: we never go out above the ceiling. Compaction is pure, so it returns the same
        // payload on every replay.
        $payload['messages'] = $this->budget->fit($payload['messages'] ?? []);

        $data = $this->environment->await($this->stub->invokeModel($model->getName(), $payload, $options));
        $this->ledger?->record($data);

        // Reactive: the provider counted differently from us. Replaying the same payload would give
        // the same verdict — it is the payload that has to change, not the call that has to be
        // retried.
        if (ContextOverflow::detected($data)) {
            $payload['messages'] = $this->budget->halved()->fit($payload['messages']);
            $data = $this->environment->await($this->stub->invokeModel($model->getName(), $payload, $options));
            // The retry after a window overflow is a second call, and it is paid for like the first.
            $this->ledger?->record($data);
        }

        return new JournaledHttpResult($data);
    }
}
