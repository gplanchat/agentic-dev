<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\Durable;

use Gplanchat\Agentic\Application\Chat\ToolStep;
use Gplanchat\Agentic\Application\Chat\Transcript;
use Gplanchat\Agentic\Application\Chat\TranscriptMessage;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\PendingApproval;
use Gplanchat\Agentic\Domain\Context\TokenLedger;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\ChatCompletion;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Question\PendingQuestion;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Watch\UnknownWatchSubject;
use Gplanchat\Agentic\Domain\Watch\Watch;
use Gplanchat\Agentic\Domain\Watch\WatchSubjects;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;

/**
 * The thread of the conversation is a **projection of the journal**, not a state stored on the
 * side.
 *
 * Every `ai_model_invoke` carries as input the complete conversation of the turn; the last
 * `ActivityScheduled` under that name is therefore the richest snapshot, and its completion carries
 * the reply. No workflow query is necessary: DUR037/DUR043, a projection over the events.
 */
final class ChatTranscript
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowMetadataStore $metadataStore,
    ) {
    }

    /**
     * The payload of an activity does not have the same depth depending on the backend: in memory
     * the event carries the arguments of the activity, on Temporal it carries the `ActivityMessage`
     * envelope that contains them. We descend to the layer that carries the expected key rather
     * than hard-coding a depth — it is the only asymmetry this projection has met between the two.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function descendTo(array $payload, string $key): array
    {
        while (!\array_key_exists($key, $payload) && \is_array($payload['payload'] ?? null)) {
            $payload = $payload['payload'];
        }

        return $payload;
    }

    /**
     * When the deadline will decide in place of the human.
     *
     * `TimerScheduled::scheduledAt()` does not say the same thing depending on the backend: the core
     * puts the firing instant in it, the Temporal bridge the starting instant — its converter builds
     * `new TimerScheduled($id, $timerId, $ts)` with the timestamp of the event and drops
     * `startToFireTimeout`. As long as the wait lasts, the firing is necessarily still to come: that
     * is what makes it possible to decide between the two readings without guessing the backend.
     *
     * @param array<string, float> $deadlines timers still in flight
     */
    private static function expiryOf(array $deadlines, ?Duration $timeout): ?float
    {
        if ([] === $deadlines) {
            return null;
        }

        $scheduled = (float) end($deadlines);

        return $scheduled >= microtime(true) || null === $timeout
            ? $scheduled
            : $scheduled + $timeout->toSeconds();
    }

    public function forExecution(string $executionId): Transcript
    {
        $messages = [];
        $steps = [];
        $results = [];
        $lastModelCallId = null;
        $compactionCallId = null;
        $finished = false;
        $failure = null;
        $settled = new SettledCalls();
        $signalledMode = null;
        $signalledModel = null;
        $messagesSignalled = 0;
        $deadlines = [];
        // The spend is recomputed from the journal rather than read from the run's ledger: both
        // count the same events, so there is nothing to keep in agreement.
        $ledger = new TokenLedger();
        // The start payload is not in the same place depending on the backend: on native Temporal it
        // opens the journal (ExecutionStarted), on DBAL a dispatched run only writes its execution
        // events and the payload stays in the metadata store. We read both.
        //
        // The event goes first, and that is not a matter of style: after a continue-as-new, the
        // metadata store still carries the payload of the **first** run — the one from before the
        // relay, whose thread is empty. Only the event of the current run says what this run
        // resumed.
        $startedFromStore = $this->metadataStore->get($executionId)['payload'] ?? [];
        $started = [];

        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $payload = $event->payload();

                if ('ai_model_invoke' === $event->activityName()) {
                    $messages = self::descendTo($payload, 'messages')['messages'] ?? $messages;
                    $lastModelCallId = $event->activityId();

                    continue;
                }

                // Compaction is a model call like any other, under a name of its own: its payload is
                // the conversation being replaced, not the one of the turn in progress.
                if ('ai_model_compact' === $event->activityName()) {
                    $compactionCallId = $event->activityId();

                    continue;
                }

                if ('ai_tool_call' === $event->activityName()) {
                    $call = self::descendTo($payload, 'arguments');
                    $settled->settle((string) ($call['callId'] ?? ''));
                    $steps[$event->activityId()] = new ToolStep(
                        (string) ($call['callId'] ?? ''),
                        (string) ($call['name'] ?? '?'),
                        (array) ($call['arguments'] ?? []),
                        null,
                    );
                }

                continue;
            }

            if ($event instanceof TimerScheduled) {
                // No filter on the summary: it does not survive the Temporal round trip, where the
                // event comes back without it. An agent only schedules a timer here, so the last
                // timer still in flight is the deadline of the wait in progress.
                $deadlines[$event->timerId()] = $event->scheduledAt();

                continue;
            }

            if ($event instanceof TimerCompleted || $event instanceof TimerCancelled) {
                unset($deadlines[$event->timerId()]);

                continue;
            }

            if ($event instanceof ActivityCompleted) {
                $results[$event->activityId()] = $event->result();
                if (\is_array($event->result())) {
                    $ledger->record($event->result());
                }

                continue;
            }

            if ($event instanceof WorkflowSignalReceived) {
                $signal = $event->signalPayload();
                if ('user_message' === $event->signalName()) {
                    ++$messagesSignalled;
                } elseif (\in_array($event->signalName(), ['tool_decision', 'question_answered', 'alert'], true)) {
                    $settled->settle((string) ($signal['callId'] ?? ''));
                } elseif ('set_model' === $event->signalName() && '' !== trim((string) ($signal['model'] ?? ''))) {
                    $signalledModel = trim((string) $signal['model']);
                } elseif ('set_mode' === $event->signalName()) {
                    $signalledMode = AgentMode::tryFrom((string) ($signal['mode'] ?? '')) ?? $signalledMode;
                }

                continue;
            }

            if ($event instanceof ExecutionStarted) {
                $started = $event->payload();

                continue;
            }

            if ($event instanceof ExecutionCompleted) {
                $finished = true;
            }

            // A failure is an ending: without this, the dead execution would look like it is
            // thinking forever.
            if ($event instanceof WorkflowExecutionFailed) {
                $finished = true;
                $failure = $event->failureMessage();
            }
        }

        $started = [] !== $started ? $started : $startedFromStore;

        // A resumed run — resumed after closing or continue-as-new — carries its original thread in
        // its start payload. As long as no model call has re-emitted it, that is the only trace the
        // journal has of it; without this the conversation looks like it has emptied itself.
        //
        // Unless it compacted it: then it is the summary that counts, from before the first turn.
        // Displaying it earlier would not only look wrong — the complete thread would count
        // messages the model will never see, and would skew the status of the agent.
        $digest = null !== $compactionCallId
            ? ChatCompletion::fromWire($results[$compactionCallId] ?? null)?->text
            : null;
        $carried = null !== $digest && '' !== trim($digest)
            ? [TranscriptMessage::compaction(trim($digest))->toWire()]
            : $started['history'] ?? [];
        if ([] === $messages) {
            $messages = $carried;
        }

        // The resumed thread enters the bag as soon as the first model call happens. Without
        // counting it on the received-messages side, `messagesSignalled` (this run alone) and
        // `messagesSeenByModel` (the whole bag) stop talking about the same thing, and the agent
        // looks idle while it is working.
        $messagesSignalled += \count(array_filter(
            $carried,
            static fn (array $message): bool => 'user' === ($message['role'] ?? null),
        ));

        // The last `set_mode` wins over the start-up mode: it comes after it.
        $mode = $signalledMode ?? AgentMode::tryFrom((string) ($started['mode'] ?? '')) ?? AgentMode::Standard;
        $humanTimeout = Duration::fromWireValue($started['humanTimeoutSeconds'] ?? null);

        foreach ($steps as $activityId => $step) {
            $result = $results[$activityId] ?? null;
            $steps[$activityId] = $step->withResult(\is_string($result) ? $result : null);
        }

        // Two human waits, read in the same place: the model asked for a tool, and neither the
        // activity nor the answer is in the journal. The guard holds one back, the desk the other.
        $expiresAt = self::expiryOf($deadlines, $humanTimeout);
        $answer = ChatCompletion::fromWire($results[$lastModelCallId] ?? null);
        $pending = [];
        $questions = [];
        $watches = [];
        foreach ($answer?->toolCalls ?? [] as $ref) {
            if ($settled->has($ref->callId)) {
                continue;
            }

            if (AskUserQuestion::TOOL === $ref->tool) {
                $questions[] = PendingQuestion::fromArguments($ref->callId, $ref->arguments, $expiresAt);

                continue;
            }

            if (WatchTool::TOOL === $ref->tool) {
                try {
                    $watches[] = Watch::fromArguments($ref->callId, $ref->arguments, WatchSubjects::fromWire($started['watchSubjects'] ?? []), $expiresAt);
                } catch (UnknownWatchSubject) {
                    // Refused by the executor, handed back to the model: this is not a watch in
                    // progress.
                }

                continue;
            }

            $pending[] = new PendingApproval(
                $ref->callId,
                $ref->tool,
                $ref->arguments,
                'Waiting for your decision.',
                $expiresAt,
            );
        }

        // A signalled message the last model call does not contain yet: the turn has started but the
        // journal does not carry its trace yet. Without this the agent looks inactive between the
        // submission and the scheduling of the activity.
        $messagesSeenByModel = \count(array_filter(
            $messages,
            static fn (array $message): bool => 'user' === ($message['role'] ?? null),
        ));

        $thread = array_values(array_filter(
            array_map(TranscriptMessage::fromWire(...), $messages),
            static fn (TranscriptMessage $message): bool => !$message->isSystem(),
        ));

        // The reasoning of the last turn is nowhere else: the previous turns have theirs in the
        // payload of the next turn (the normaliser puts it back in), but the last one has no next
        // turn. It is read in the result, mixed with the reply — and it is {@see ChatCompletion}
        // that does the splitting, the same one as for the thread.
        if (null !== $answer?->text && '' !== $answer->text) {
            $thread[] = TranscriptMessage::assistant($answer->text, $answer->reasoning);
        }

        return new Transcript(
            $thread,
            array_values($steps),
            $pending,
            $questions,
            $watches,
            $mode,
            $humanTimeout,
            // Four ways of having a turn in progress: a compaction in flight, a received message the
            // model has not seen yet, a model call scheduled without a result, or a reply that still
            // asks for tools. No model call at all is *not* a turn in progress: that is the starting
            // state, where the workflow is suspended on its first signal.
            //
            // And three ways of NOT working while not being finished either: the ball is in the
            // human's court — an approval, a question, an armed watch. That is not machine waiting,
            // and displaying it as such would make the status lie.
            !$finished && [] === $pending && [] === $questions && [] === $watches && (
                (null !== $compactionCallId && !\array_key_exists($compactionCallId, $results))
                || $messagesSignalled > $messagesSeenByModel
                || (null !== $lastModelCallId && !\array_key_exists($lastModelCallId, $results))
                || (null !== $lastModelCallId && null === $answer?->text)
            ),
            $finished,
            $failure,
            $signalledModel ?? (string) ($started['model'] ?? ''),
            Toolset::fromWire(\is_array($started['tools'] ?? null) ? $started['tools'] : []),
            RuleBasedToolGuard::rulesFromWire(\is_array($started['toolRules'] ?? null) ? $started['toolRules'] : []),
            // Named from here on: this list grows, and a positional argument slipping one slot is
            // exactly how the workspace once became the profiles.
            profiles: AgentProfiles::fromWire(\is_array($started['agents'] ?? null) ? $started['agents'] : []),
            workspace: \is_string($started['workspace'] ?? null) ? $started['workspace'] : null,
            owner: Principal::fromWire($started['owner'] ?? null),
            tokensSpent: $ledger->spent(),
            tokenBudget: (int) ($started['tokenBudget'] ?? 0),
        );
    }
}
