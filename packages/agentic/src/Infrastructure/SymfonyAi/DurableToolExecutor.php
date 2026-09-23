<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\AgentToolActivityInterface;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ApprovalOutcome;
use Gplanchat\Agentic\Domain\Guard\ToolApprovalGate;
use Gplanchat\Agentic\Domain\Guard\ToolGuardInterface;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Question\HumanQuestionDesk;
use Gplanchat\Agentic\Domain\Question\PendingQuestion;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Watch\UnknownWatchSubject;
use Gplanchat\Agentic\Domain\Watch\Watch;
use Gplanchat\Agentic\Domain\Watch\WatchDesk;
use Gplanchat\Agentic\Domain\Watch\WatchSubjects;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * `Runner` delegates here through `yield from`; the `Fiber::suspend()` of
 * {@see WorkflowEnvironment::await()} goes through the generator delegation without breaking
 * anything.
 *
 * ponytail: sequential execution. `$environment->all(...)` would parallelise the calls of a single
 * turn — to be done when a turn really has several slow tools.
 */
final class DurableToolExecutor implements ToolExecutorInterface
{
    private readonly ActivityStub $stub;

    /**
     * @param \Closure(): AgentMode $mode         the mode is workflow state, it can change between
     *                                            two turns — so it is read at the moment of the decision
     * @param Duration|null         $humanTimeout deadline of every human wait — approval of a tool as
     *                                            well as answer to a question — global to the agent
     *                                            instance; `null` = wait indefinitely
     * @param string|null           $workspace    the conversation's working directory, handed to every
     *                                            tool activity; `null` = the project
     */
    public function __construct(
        private readonly WorkflowEnvironment $environment,
        private readonly ToolGuardInterface $guard,
        private readonly ToolApprovalGate $gate,
        private readonly HumanQuestionDesk $desk,
        private readonly WatchDesk $watches,
        private readonly \Closure $mode,
        private readonly ?Duration $humanTimeout = null,
        private readonly string $model = 'gpt-4o-mini',
        private readonly WatchSubjects $subjects = new WatchSubjects(),
        private readonly AgentProfiles $profiles = new AgentProfiles(),
        /** @var array<string, array{description: string, effect: string, parameters: array<string, mixed>|null}> */
        private readonly array $toolsWire = [],
        /** @var list<array<string, mixed>> */
        private readonly array $rulesWire = [],
        ?ActivityOptions $options = null,
        private readonly ?string $workspace = null,
        /** On whose behalf this agent runs; what a delegate inherits, narrowed, never widened. */
        private readonly ?Principal $principal = null,
    ) {
        $this->stub = $environment->activityStub(AgentToolActivityInterface::class, $options);
    }

    public function execute(array $toolCalls): \Generator
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $decision = $this->guard->decide(self::invocation($toolCall), ($this->mode)());

            if ($decision->isDenied()) {
                yield new Progress('tool_denied', (string) $decision->reason, $toolCall);
                $results[] = new ToolResult($toolCall, \sprintf('Refused: %s', $decision->reason));

                continue;
            }

            if ($decision->needsApproval()) {
                $this->gate->ask(self::invocation($toolCall), (string) $decision->reason);
                yield new Progress('tool_approval', (string) $decision->reason, $toolCall);

                // A suspension, not a wait: the process can die here, the approval can arrive
                // tomorrow, the workflow will resume on this very line. The deadline is a journal
                // timer (DUR032), so it survives a restart too.
                try {
                    $this->environment->await(
                        fn(): bool => $this->gate->isSettled($toolCall->getId()),
                        $this->humanTimeout,
                    );
                } catch (DeadlineExceededException) {
                    // No answer counts as a refusal — but the outcome stays distinct from a human
                    // refusal: nobody decided anything. It is written into the gate so that the
                    // replay reads it back instead of scheduling a timer that has already fired.
                    $this->gate->timeout($toolCall->getId());
                    yield new Progress('tool_expired', \sprintf('Approval of "%s" expired.', $toolCall->getName()), $toolCall);
                }

                $outcome = $this->gate->outcome($toolCall->getId()) ?? ApprovalOutcome::Refused;
                if (!$outcome->isApproved()) {
                    $results[] = new ToolResult($toolCall, $outcome->message());

                    continue;
                }
            }

            // The only tool whose execution is a suspension: its result is not computed, it is
            // awaited.
            if (AskUserQuestion::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->askHuman($toolCall));

                continue;
            }

            // The third one: it waits for another agent. The delegate is a child workflow — its own
            // execution, its own journal, its own model — and the parent suspends on its reply like
            // on any other `await`.
            if (DelegateTool::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->delegate($toolCall));

                continue;
            }

            // The other suspension: this one does not wait for a human in front of a card, but for
            // an event from outside.
            if (WatchTool::TOOL === $toolCall->getName()) {
                $results[] = new ToolResult($toolCall, yield from $this->standBy($toolCall));

                continue;
            }

            yield new Progress('tool_call', \sprintf('Running tool "%s".', $toolCall->getName()), $toolCall);

            try {
                $result = $this->environment->await($this->stub->callTool($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments(), $this->workspace));
            } catch (DurableActivityFailedException $failure) {
                // A broken tool, retries exhausted, is not a breakdown of the conversation: the
                // model reads it as a result and adapts. The failure is in the journal
                // (`ActivityFailed`), so the replay takes the same branch again.
                yield new Progress('tool_failed', $failure->envelope()->message, $toolCall);
                $result = \sprintf('Tool "%s" failed: %s', $toolCall->getName(), $failure->envelope()->message);
            }

            $results[] = new ToolResult($toolCall, $result);
        }

        return $results;
    }

    /**
     * The boundary: the domain does not speak the provider's type.
     */
    private static function invocation(ToolCall $toolCall): ToolInvocation
    {
        return new ToolInvocation($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments());
    }

    /**
     * Delegation: a sub-agent does the work, the parent waits for its reply.
     *
     * **The ceiling is the subject of this method.** The delegate receives the parent's effective
     * mode as a ceiling, and its own mode can no longer loosen it — neither on entry, nor through a
     * `set_mode` afterwards. Without that, an agent in `standard` would hand to a sub-agent in
     * `auto` what its guard refuses it: the guard would not be worked around, it would be
     * decorative.
     *
     * The delegate receives **only** its mission: neither the parent's thread, nor its suspension
     * tools. A sub-agent nobody is watching has nothing to ask a human.
     *
     * @return \Generator<int, Progress, mixed, string>
     */
    private function delegate(ToolCall $toolCall): \Generator
    {
        $arguments = $toolCall->getArguments();
        $mission = trim((string) ($arguments['mission'] ?? ''));
        if ('' === $mission) {
            return 'A delegation without a mission has nothing to delegate. Say what the sub-agent must do.';
        }

        $named = trim((string) ($arguments['agent'] ?? ''));
        $profile = '' === $named ? null : $this->profiles->find($named);
        if ('' !== $named && null === $profile) {
            // Refused in the open: a name nobody declares would otherwise become an anonymous
            // sub-agent with none of the tools nor the instructions the model was counting on.
            yield new Progress('delegation_refused', $named, $toolCall);

            return \sprintf(
                'No sub-agent is named "%s". Declared: %s. Pick one of them, or delegate without a name.',
                $named,
                [] === $this->profiles->names() ? 'none' : implode(', ', $this->profiles->names()),
            );
        }

        // The ceiling is the subject here: the delegate takes the strictest of its profile and of
        // its parent's effective mode. Naming a sub-agent grants nothing.
        $ceiling = AgentMode::strictest(($this->mode)(), $profile?->ceiling ?? AgentMode::Standard);

        // The same property on the identity axis: the delegate keeps its caller's identity, holding
        // only what the profile still allows of it — an intersection, so a profile naming a role
        // its caller lacks grants nothing. Unnamed, it claims nothing at all.
        $principal = $this->principal?->restrictedTo(null === $profile ? [] : $profile->roles);
        $model = $profile?->model ?? (trim((string) ($arguments['model'] ?? '')) ?: $this->model);

        // Only the tools the profile allows travel to the child, and their schemas travel with them:
        // the child freezes them in its own payload, as any conversation does.
        $tools = [];
        foreach ($this->toolsWire as $tool => $definition) {
            if (null !== $profile && $profile->allows((string) $tool)) {
                $tools[$tool] = $definition;
            }
        }

        yield new Progress('delegated', \sprintf('Mission handed to %s (%s).', $profile?->name ?? 'a sub-agent', $model), $toolCall);

        // ⚠ Positional, and not by names. `ChildWorkflowStub::argumentsToInput()` matches the
        // arguments **by position** (`$arguments[$i]`): PHP passes named arguments to `__call` in an
        // array with string keys, no index answers there, and *every* parameter falls back to its
        // default value. With no exception, with no trace — the sub-agent starts with an empty
        // prompt and waits for a message that will never come. It is a flaw of the core, not of
        // here; in the meantime, the order of the signature is what counts.
        $reply = (string) $this->environment->await(
            $this->environment->childWorkflowStub(DurableAgentWorkflow::class)->run(
                $tools,                               // tools
                $model,                               // model
                $ceiling->value,                      // mode
                $ceiling->value,                      // modeCeiling
                '' !== ($profile?->prompt ?? '') ? $profile->prompt : DurableAgentWorkflow::SYSTEM_PROMPT,
                $mission,                             // prompt
                $profile?->maxTurns ?? 1,             // maxTurns
                10,                                   // maxToolCalls
                null,                                 // humanTimeoutSeconds
                24_000,                               // contextTokens
                null,                                 // idleTimeoutSeconds
                null,                                 // rolloverAfterTurns
                false,                                // compactHistory
                [],                                   // history
                [],                                   // pending
                null,                                 // guard
                [],                                   // watchSubjects
                $this->rulesWire,                     // toolRules: the project's rules bind the child too
                [],                                   // agents: a delegate does not re-delegate by name
                // The conversation's workspace, or the child would act in the project itself — which
                // is precisely what the per-conversation worktree exists to prevent.
                $this->workspace,                     // workspace
                // The narrowed identity. Last, matching the signature of `run()` — and what proves
                // the slot did not shift is `DelegateNarrowsIdentityTest`, not the counting.
                $principal?->toWire() ?? [],          // owner
            ),
        );

        return \sprintf('The sub-agent %s (%s) replies: %s', $profile?->name ?? '(unnamed)', $model, $reply);
    }

    /**
     * The watch: the agent sleeps until the alert, and wakes up finding its intent again.
     *
     * @return \Generator<int, Progress, mixed, string> what the model will read in place of a tool result
     */
    private function standBy(ToolCall $toolCall): \Generator
    {
        try {
            $watch = Watch::fromArguments($toolCall->getId(), $toolCall->getArguments(), $this->subjects);
        } catch (UnknownWatchSubject $refusal) {
            // Handed back to the model as a tool result, not thrown: it is an instruction badly
            // followed, not a breakdown, and the agent can correct itself on the next turn. A watch
            // armed on an unknown subject, on the other hand, would sleep until its deadline with
            // nothing saying so.
            yield new Progress('watch_refused', $refusal->subject, $toolCall);

            return \sprintf(
                '%s Known subjects: %s. Pick one of them, or do otherwise.',
                $refusal->getMessage(),
                implode(', ', $this->subjects->values()),
            );
        }

        $this->watches->watch($watch);
        yield new Progress('watch_started', $watch->observation, $toolCall);

        // The deadline of the watch is the one the model asked for; failing that, the agent's human
        // budget — a watch with no bound at all would end up not being a watch any more.
        $deadline = Duration::fromWireValue($toolCall->getArguments()['deadlineSeconds'] ?? null) ?? $this->humanTimeout;

        try {
            $this->environment->await(fn(): bool => $this->watches->isSettled($toolCall->getId()), $deadline);
        } catch (DeadlineExceededException) {
            $this->watches->raise($toolCall->getId(), '');
            yield new Progress('watch_expired', $watch->observation, $toolCall);

            return \sprintf(
                'The watch "%s" expired without an alert. You intended: %s. Take over and say what you are doing.',
                $watch->observation,
                $watch->intent,
            );
        }

        // Waking hands back the observation **and** the intent: that is what spares the agent from
        // remembering what it was doing three days ago.
        return \sprintf(
            'Alert on "%s": %s. You intended: %s.',
            $watch->observation,
            '' === $this->watches->observationOf($toolCall->getId()) ? 'nothing more was reported' : $this->watches->observationOf($toolCall->getId()),
            $watch->intent,
        );
    }

    /**
     * @return \Generator<int, Progress, mixed, string> what the model will read in place of a tool result
     */
    private function askHuman(ToolCall $toolCall): \Generator
    {
        $question = PendingQuestion::fromArguments($toolCall->getId(), $toolCall->getArguments());
        $this->desk->ask($question);
        yield new Progress('question_asked', $question->question, $toolCall);

        try {
            $this->environment->await(
                fn(): bool => $this->desk->isAnswered($toolCall->getId()),
                $this->humanTimeout,
            );
        } catch (DeadlineExceededException) {
            // As for an approval: the absence of an answer is written down, otherwise the replay
            // would schedule a timer that has already fired.
            $this->desk->answer($toolCall->getId(), []);
            yield new Progress('question_expired', \sprintf('Question "%s" left unanswered.', $question->header), $toolCall);
        }

        $answers = $this->desk->answersOf($toolCall->getId());

        return [] === $answers
            ? 'No answer: the user chose nothing before the deadline. Carry on without it, or say what you are missing.'
            : implode('; ', $answers);
    }
}
