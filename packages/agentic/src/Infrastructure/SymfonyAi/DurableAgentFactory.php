<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Infrastructure\SymfonyAi;

use Gplanchat\Agentic\Domain\Context\ContextBudget;
use Gplanchat\Agentic\Domain\Context\TokenLedger;
use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Guard\ToolApprovalGate;
use Gplanchat\Agentic\Domain\Guard\ToolGuardInterface;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Question\AskUserQuestion;
use Gplanchat\Agentic\Domain\Question\HumanQuestionDesk;
use Gplanchat\Agentic\Domain\Team\AgentProfiles;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Watch\WatchDesk;
use Gplanchat\Agentic\Domain\Watch\WatchSubjects;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\WorkflowEnvironment;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\Mistral\Contract\AssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\Mistral\Contract\ToolNormalizer;
use Symfony\AI\Platform\Bridge\Mistral\Llm\ResultConverter;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;

/**
 * Assembles a Symfony AI `Agent` whose two non-deterministic legs go through the journal.
 *
 * The assembly is workflow code: it is re-executed on every replay, so it must stay pure — no
 * container lookup, no clock, no randomness.
 */
final class DurableAgentFactory
{
    /**
     * @param \Closure(): AgentMode|null $mode
     * @param Duration|null              $humanTimeout deadline of every human wait — approval as
     *                                                 well as answer to a question — global to this
     *                                                 agent instance
     * @param list<ToolRule>             $rules        the decision hooks, before the mode
     * @param string|null                $workspace    the conversation's working directory
     */
    public static function create(
        WorkflowEnvironment $environment,
        string $model,
        Toolset $tools,
        int $maxToolCalls = 10,
        ?ToolApprovalGate $gate = null,
        ?HumanQuestionDesk $desk = null,
        ?WatchDesk $watches = null,
        ?\Closure $mode = null,
        ?ToolGuardInterface $guard = null,
        ?Duration $humanTimeout = null,
        ?ContextBudget $budget = null,
        WatchSubjects $subjects = new WatchSubjects(),
        array $rules = [],
        ?string $workspace = null,
        AgentProfiles $profiles = new AgentProfiles(),
        array $toolsWire = [],
        array $rulesWire = [],
        ?Principal $principal = null,
        ?TokenLedger $ledger = null,
        int $depth = 0,
        int $maxDepth = 2,
    ): Agent {
        // Always offered: an agent that cannot ask makes things up, and an agent that cannot wait
        // botches the job.
        // At the deepest level allowed, `delegate` is not offered at all rather than offered and
        // refused: a tool the model cannot see is a tool it does not spend a turn reaching for.
        // Without a bound here, an anonymous delegation could delegate again for ever — the child
        // loses its caller's named profiles but never lost the tool itself.
        $tools = $depth < $maxDepth
            ? $tools->with(AskUserQuestion::definition(), WatchTool::definition($subjects), DelegateTool::definition($profiles))
            : $tools->with(AskUserQuestion::definition(), WatchTool::definition($subjects));

        // The Mistral bridge provides everything that is **pure** — the normalisation of the
        // conversation, the catalogue, the conversion of the JSON into a result — and that is what
        // runs in workflow code, hence replayed. Only its HTTP client is replaced: it alone leaves
        // the process, and that is precisely what must become an activity.
        $platform = new Platform([
            new Provider(
                'durable-mistral',
                [new DurableModelClient($environment, $budget ?? new ContextBudget(), ledger: $ledger)],
                [new ResultConverter()],
                new ModelCatalog(),
                Contract::create([new AssistantMessageNormalizer(), new ToolNormalizer()]),
            ),
        ]);

        return new Agent(
            $platform,
            $model,
            toolbox: new SchemaOnlyToolbox($tools),
            toolExecutor: new DurableToolExecutor(
                $environment,
                // The decision hooks first; with no rule applying, the mode.
                $guard ?? new RuleBasedToolGuard($rules, new ModeToolGuard($tools), $principal),
                $gate ?? new ToolApprovalGate(),
                $desk ?? new HumanQuestionDesk(),
                $watches ?? new WatchDesk(),
                $mode ?? static fn(): AgentMode => AgentMode::Auto,
                $humanTimeout,
                $model,
                $subjects,
                profiles: $profiles,
                toolsWire: $toolsWire,
                rulesWire: $rulesWire,
                workspace: $workspace,
                principal: $principal,
                ledger: $ledger,
                depth: $depth,
                maxDepth: $maxDepth,
            ),
            maxToolCalls: $maxToolCalls,
        );
    }
}
