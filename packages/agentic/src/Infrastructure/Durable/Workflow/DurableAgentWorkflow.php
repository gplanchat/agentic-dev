<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\Durable\Workflow;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\ModelInvocationActivityInterface;
use Gplanchat\Agentic\Application\Chat\TranscriptMessage;
use Gplanchat\Agentic\Domain\Context\ContextBudget;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\DurableAgentFactory;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Guard\ToolApprovalGate;
use Gplanchat\Agentic\Domain\Guard\ToolGuardInterface;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\ChatCompletion;
use Gplanchat\Agentic\Domain\Question\HumanQuestionDesk;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Watch\WatchDesk;
use Gplanchat\Agentic\Domain\Watch\WatchSubjects;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\MultiPartResult;

/**
 * A durable agent: **one conversation = one execution**.
 *
 * Symfony AI's tool-calling loop (`Agent::call()` → `Runner::run()`) runs in workflow code; its two
 * non-deterministic legs go through the journal ({@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\DurableModelClient},
 * {@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\DurableToolExecutor}), so it replays and the `MessageBag` rebuilds itself.
 *
 * A single type for two uses, which differ only by the parameters:
 * - `prompt` + `maxTurns: 1` — one question, one answer, the execution ends;
 * - without `prompt` — a chat: every human message arrives through a `user_message` signal, and
 *   between two messages the workflow is *suspended*, not waiting inside a process. It can stay
 *   that way for days, across a redeployment.
 *
 * Every tool call goes through a guard ({@see ToolGuardInterface}): depending on the mode it goes
 * through, it is refused, or it suspends execution until a `tool_decision` signal.
 *
 * The agent additionally has two tools whose execution is a suspension: `ask_user` waits for a
 * `question_answered` signal — there the human authorises, here they inform — and `watch` waits for
 * an `alert` signal, raised from outside. Waking hands the agent back the observation **and the
 * intent it had written when registering**: it has nothing to remember, the journal tells it.
 *
 * `humanTimeoutSeconds` bounds every human wait for the agent instance: no answer counts as a
 * refusal for an approval, as "nothing chosen" for a question. The timer being journaled (DUR032),
 * the deadline survives a restart just as the wait itself does.
 *
 * `contextTokens` bounds the conversation: a durable conversation grows without end, and the day it
 * overflows the model window the agent does not miss a turn, it can no longer take a single one.
 * Compaction drops the oldest turns — by whole turns, so as not to leave an orphaned tool result —
 * and it is **pure**, hence replayed identically.
 *
 * Replay constraints, not to be relaxed: `symfony/ai` pinned (`Runner` is `@internal`, its loop is
 * the determinism contract), no streaming, tool schemas frozen in the payload, no external message
 * store — the journal is the only source of truth.
 *
 * Two bounds close the run, and neither loses the thread:
 * - `idleTimeoutSeconds` — a long enough silence counts as an ending. The run ends, the page offers
 *   to resume it, and the thread starts again in the payload of the new execution.
 * - `rolloverAfterTurns` — after N turns, `continueAsNew` opens a brand new run **inside the same
 *   execution**: same `workflowId`, same URL, blank journal, thread carried over.
 *
 * What is carried over is the *spoken* thread, not the journal: the tool calls and their returns
 * belong to the run that is ending. And `compactHistory` reduces it to a summary before the first
 * turn — which is what one wants from a cold resume, where replaying the conversation word for word
 * would make the first turn pay again everything the previous run had already cost.
 *
 * The relay does not have the same surface depending on the backend, and that is what makes it
 * opt-in: on Temporal the `workflowId` does not move, so neither does the chat URL; on the others,
 * {@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler} opens the next run under a brand new
 * `executionId`, which would have to be followed. Closing on inactivity, for its part, behaves the
 * same everywhere — it is the default path.
 */
#[AsWorkflow(self::TYPE)]
final class DurableAgentWorkflow
{
    /**
     * The journal and Temporal designate a workflow by its alias, never by its FQCN
     * ({@see \Gplanchat\Durable\WorkflowRegistry}): `continueAsNew` must give that one.
     */
    public const TYPE = 'Ai_DurableAgent';

    /** The default instruction. A constant because a caller needs to quote it. */
    public const SYSTEM_PROMPT = 'You are a concise assistant. Use the tools when they answer better than you do.';

    /**
     * What is asked of the model when a cold conversation restarts.
     *
     * The summary replaces the thread: it must therefore carry what the next turn needs — the
     * request, what has been done, what is still open — and nothing of the mechanics.
     */
    private const COMPACTION_PROMPT = 'You are resuming an interrupted conversation. Summarise it in '
        . 'a few sentences: what the person asked for, what has been done for them, and what is '
        . 'still outstanding. Write the summary alone, with no preamble and no opening formula.';

    /** @var list<string> */
    private array $inbox = [];

    private bool $closed = false;

    private AgentMode $mode = AgentMode::Standard;

    /**
     * The model of the next turn. Workflow state like the mode: `set_model` is journaled, so the
     * replay finds the same model on the same turn.
     */
    private string $model = '';

    /**
     * What this agent cannot go past, whatever it asks for. `auto` for a first-rank agent — that is
     * to say no bound at all — and the parent's effective mode for a delegate.
     */
    private AgentMode $ceiling = AgentMode::Auto;

    private readonly ToolApprovalGate $gate;

    private readonly HumanQuestionDesk $desk;

    private readonly WatchDesk $watches;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {
        $this->gate = new ToolApprovalGate();
        $this->desk = new HumanQuestionDesk();
        $this->watches = new WatchDesk();
    }

    /**
     * A queue, not a field: a second message posted while the agent is working would overwrite the
     * first. The order of consumption is the order of the journal, hence stable on replay.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('user_message')]
    public function onUserMessage(array $payload): void
    {
        $text = trim((string) ($payload['text'] ?? ''));
        if ('' !== $text) {
            $this->inbox[] = $text;
        }
    }

    /**
     * The approval — or the refusal — of a tool call. The execution suspended on its condition
     * resumes here.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('tool_decision')]
    public function onToolDecision(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->gate->decide($callId, (bool) ($payload['approved'] ?? false));
        }
    }

    /**
     * The answer to a question asked by the agent. This is the other direction of the conversation:
     * here the human does not steer, they inform.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('question_answered')]
    public function onQuestionAnswered(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->desk->answer($callId, \is_array($payload['answers'] ?? null) ? $payload['answers'] : []);
        }
    }

    /**
     * The alert that lifts a watch. It comes from outside — a monitoring system, a webhook, another
     * agent — and it is the journal, not the model, that will remind the agent what it meant to do.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('alert')]
    public function onAlert(array $payload): void
    {
        $callId = (string) ($payload['callId'] ?? '');
        if ('' !== $callId) {
            $this->watches->raise($callId, (string) ($payload['observation'] ?? ''));
        }
    }

    /**
     * Changing mode in the middle of a conversation is journaled, hence replayed identically.
     *
     * @param array<string, mixed> $payload
     */
    /**
     * The mode changes, **without ever loosening the ceiling**.
     *
     * The ceiling holds on entry *and* along the way: a sub-agent that accepted `set_mode: auto`
     * would have no ceiling at all, and delegating would again become the escape hatch of the
     * guard. A first-rank agent has `auto` as its ceiling — the bound costs it nothing.
     */
    #[AsSignalMethod('set_mode')]
    public function onSetMode(array $payload): void
    {
        $requested = AgentMode::tryFrom((string) ($payload['mode'] ?? ''));
        if (null === $requested || $requested->loosens($this->ceiling)) {
            return;
        }

        $this->mode = $requested;
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * The model changes for the next turn; the turn in progress finishes with its own.
     *
     * No validation here: the catalogue of models lives outside the workflow, and reading it on
     * replay would make it depend on the configuration of the day. It is the adapter that refuses an
     * unknown name.
     *
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('set_model')]
    public function onSetModel(array $payload): void
    {
        $model = trim((string) ($payload['model'] ?? ''));
        if ('' !== $model) {
            $this->model = $model;
        }
    }

    #[AsSignalMethod('close')]
    public function onClose(array $payload): void
    {
        $this->closed = true;
    }

    /**
     * Reducing a cold conversation to what needs to be known of it.
     *
     * A model call, not an agent loop: there is nothing to tool here, and going through `Runner`
     * would only expose the compaction to the guards and to tool calls. The call goes out of the
     * journal like the others — hence replayed, hence paid for once.
     *
     * An empty summary is not a summary: the thread then starts again as is. It is the only choice
     * that keeps the display and the bag in agreement — the projection, too, falls back on the raw
     * thread when the journal carries no usable summary.
     *
     * @param list<TranscriptMessage> $thread
     *
     * @return list<TranscriptMessage>
     */
    private function compact(string $model, array $thread): array
    {
        $result = $this->environment->await(
            $this->environment
                ->activityStub(ModelInvocationActivityInterface::class)
                ->compactConversation($model, ['messages' => [
                    ['role' => 'system', 'content' => self::COMPACTION_PROMPT],
                    // Without the label: what goes out to the model is the conversation, not the way
                    // it was presented to it the time before.
                    ...TranscriptMessage::listToWire(
                        array_map(static fn (TranscriptMessage $m): TranscriptMessage => $m->stripped(), $thread),
                    ),
                ]], []),
        );

        $digest = trim((string) (ChatCompletion::fromWire($result)?->text ?? ''));

        return '' === $digest ? $thread : [TranscriptMessage::compaction($digest)];
    }

    /**
     * The payload arrives from the journal, hence as arrays: `$tools` is converted to a
     * {@see Toolset} on entry, and nothing below it handles an associative array any more.
     *
     * @param array<string, array{description?: string, parameters?: array<string, mixed>|null, effect?: string}> $tools
     * @param float|null                                                                                         $idleTimeoutSeconds silence after which the execution ends; `null` = never
     * @param int|null                                                                                           $rolloverAfterTurns turns after which the run hands over to a brand new run; `null` = never
     * @param bool                                                                                               $compactHistory     replace the resumed thread with a summary before the first turn
     * @param list<array{role?: string, content?: string|null}>                                                   $history            the thread resumed from a previous execution
     * @param list<string>                                                                                        $pending            messages received but not processed yet, handed over by the previous run
     * @param array<string, string>                                                                               $watchSubjects      the application's watch vocabulary, subject → description
     * @param list<array<string, mixed>>                                                                          $toolRules          the project's decision hooks ({@see \Gplanchat\Agentic\Domain\Guard\ToolRule})
     * @param array<string, array<string, mixed>>                                                                 $agents             the sub-agents the application declares ({@see \Gplanchat\Agentic\Domain\Team\AgentProfile})
     * @param string|null                                                                                         $workspace          the conversation's working directory, handed to the tools; `null` = the project
     * @param array<string, mixed>                                                                                $owner              on whose behalf this runs ({@see Principal}); `[]` = nobody, which claims nothing
     *
     * @return string the agent's last reply
     */
    #[AsWorkflowMethod]
    public function run(
        array $tools = [],
        string $model = 'mistral-small-latest',
        string $mode = 'standard',
        string $modeCeiling = 'auto',
        string $systemPrompt = self::SYSTEM_PROMPT,
        ?string $prompt = null,
        int $maxTurns = 20,
        int $maxToolCalls = 10,
        ?float $humanTimeoutSeconds = null,
        int $contextTokens = 24_000,
        ?float $idleTimeoutSeconds = null,
        ?int $rolloverAfterTurns = null,
        bool $compactHistory = false,
        array $history = [],
        array $pending = [],
        ?ToolGuardInterface $guard = null,
        array $watchSubjects = [],
        array $toolRules = [],
        array $agents = [],
        ?string $workspace = null,
        // ⚠ Last, and it must stay last: `delegate()` builds the child's call **positionally**
        // ({@see \Gplanchat\Agentic\Infrastructure\SymfonyAi\DurableToolExecutor::delegate()}), so a
        // parameter slipped into the middle silently shifts every one after it.
        array $owner = [],
    ): string {
        // The ceiling first: the requested mode bends to it, it does not go around it.
        $this->ceiling = AgentMode::tryFrom($modeCeiling) ?? AgentMode::Auto;
        $this->mode = AgentMode::strictest($this->ceiling, AgentMode::tryFrom($mode) ?? AgentMode::Standard);

        // What the previous run did not have the time to process goes first: these messages arrived
        // before the ones the new run will receive.
        foreach ($pending as $carried) {
            $this->inbox[] = (string) $carried;
        }

        // A question asked at start-up is the first message of the queue: nothing to tell apart
        // afterwards between it and the ones that will arrive by signal.
        if (null !== $prompt && '' !== trim($prompt)) {
            $this->inbox[] = trim($prompt);
        }

        $this->model = $model;
        $build = fn (): Agent => DurableAgentFactory::create(
            $this->environment,
            $this->model,
            Toolset::fromWire($tools),
            $maxToolCalls,
            gate: $this->gate,
            desk: $this->desk,
            watches: $this->watches,
            mode: fn(): AgentMode => $this->mode,
            guard: $guard,
            humanTimeout: Duration::fromWireValue($humanTimeoutSeconds),
            budget: new ContextBudget($contextTokens),
            subjects: WatchSubjects::fromWire($watchSubjects),
            rules: RuleBasedToolGuard::rulesFromWire($toolRules),
            profiles: AgentProfiles::fromWire($agents),
            toolsWire: $tools,
            rulesWire: $toolRules,
            workspace: $workspace,
            principal: Principal::fromWire($owner),
        );
        $agent = $build();
        $agentModel = $this->model;

        // The bag is rebuilt on every replay; `$thread` is its carryable trace — same turns, the
        // shape of the thread, without the tool calls.
        $thread = TranscriptMessage::listFromWire($history);
        if ($compactHistory && [] !== $thread) {
            $thread = $this->compact($model, $thread);
        }

        $messages = new MessageBag(Message::forSystem($systemPrompt));
        foreach ($thread as $carried) {
            $messages->add($carried->isUser()
                ? Message::ofUser((string) $carried->content)
                : Message::ofAssistant((string) $carried->content));
        }

        $answer = '';
        $turns = 0;

        while (!$this->closed && $turns < $maxTurns) {
            try {
                $this->environment->await(
                    fn(): bool => [] !== $this->inbox || $this->closed,
                    $idleTimeoutSeconds,
                );
            } catch (DeadlineExceededException) {
                // A long enough silence counts as an ending. Nothing is lost: the thread is in the
                // journal, and the page offers to resume it in a brand new execution.
                break;
            }

            if ($this->closed) {
                break;
            }

            $text = (string) array_shift($this->inbox);
            $messages->add(Message::ofUser($text));
            $thread[] = TranscriptMessage::user($text);

            // `Runner` adds the messages of the tool loop to the bag itself, but not the final
            // reply: that one leaves the loop without going through it.
            // A `set_model` received since the last turn: the agent is rebuilt with the new model.
            // The message bag, for its part, does not move — the conversation goes on.
            if ($agentModel !== $this->model) {
                $agent = $build();
                $agentModel = $this->model;
            }

            $result = $agent->call($messages)->getResult();
            // The bag receives the whole result — `Message::toContent()` unfolds a `MultiPartResult`,
            // and the reasoning block thus goes back out on the next turn. The thread, for its part,
            // only wants the text: `getContent()` of a multi-part returns an array, not a string.
            $messages->add(Message::ofAssistant($result));
            $answer = $result instanceof MultiPartResult ? $result->asText() : (string) $result->getContent();
            $thread[] = TranscriptMessage::assistant($answer);

            ++$turns;

            // The relay is taken here, between two turns: nothing is in flight, no guard is waiting
            // for a decision, and what the queue received during the turn goes along with it.
            //
            // ponytail: the threshold is a number of turns, not the real quantity. What costs is
            // that every turn sends the whole bag back to the model — the size of the payload of the
            // last `ai_model_invoke` is the right trigger, the turn count is only its proxy.
            if (null !== $rolloverAfterTurns && $turns >= $rolloverAfterTurns && !$this->closed) {
                // ponytail: a signal that arrives during the task issuing the command can be lost —
                // an irreducible window, and the reason why the relay is opt-in where closing on
                // inactivity is the default path.
                $this->environment->continueAsNew(self::TYPE, [
                    'tools' => $tools,
                    'model' => $this->model,
                    'mode' => $this->mode->value,
                    // The ceiling follows the relay: otherwise a delegate would find `auto` again on
                    // the next run.
                    'modeCeiling' => $this->ceiling->value,
                    'systemPrompt' => $systemPrompt,
                    'maxTurns' => $maxTurns,
                    'maxToolCalls' => $maxToolCalls,
                    'humanTimeoutSeconds' => $humanTimeoutSeconds,
                    'contextTokens' => $contextTokens,
                    'idleTimeoutSeconds' => $idleTimeoutSeconds,
                    'rolloverAfterTurns' => $rolloverAfterTurns,
                    // The relay hands the thread over as is: it happens in the middle of a living
                    // conversation, where losing the detail would be paid for straight away.
                    // Compaction is for cold resumes.
                    'compactHistory' => false,
                    'history' => TranscriptMessage::listToWire($thread),
                    'pending' => $this->inbox,
                    'watchSubjects' => $watchSubjects,
                    'toolRules' => $toolRules,
                    'agents' => $agents,
                    'workspace' => $workspace,
                    // The owner follows the relay, like the ceiling: otherwise a long conversation
                    // would come back from its rollover belonging to nobody.
                    'owner' => $owner,
                ]);
            }
        }

        return $answer;
    }
}
