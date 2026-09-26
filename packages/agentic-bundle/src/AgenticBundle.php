<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Application\Chat\CurrentPrincipal;
use Gplanchat\Agentic\Application\Help\ListCommands;
use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Infrastructure\Durable\Activity\AgentToolActivityInterface;
use Gplanchat\Agentic\Infrastructure\Durable\Activity\ModelInvocationActivityInterface;
use Gplanchat\Agentic\Infrastructure\Durable\ChatTranscript;
use Gplanchat\Agentic\Infrastructure\Durable\Workflow\DurableAgentWorkflow;
use Gplanchat\Agentic\Infrastructure\SymfonyAi\ModelInvocationActivityHandler;
use Gplanchat\AgenticBundle\Activity\AgentToolActivityHandler;
use Gplanchat\AgenticBundle\Ai\ModelClientFactory;
use Gplanchat\AgenticBundle\Chat\DurableConversations;
use Gplanchat\AgenticBundle\Chat\LocalPrincipal;
use Gplanchat\AgenticBundle\Chat\ProjectInstructions;
use Gplanchat\AgenticBundle\Mcp\McpCatalog;
use Gplanchat\AgenticBundle\Mcp\McpServer;
use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Gplanchat\AgenticBundle\Console\ChatCommand;
use Gplanchat\AgenticBundle\Console\ConsoleCommandCatalog;
use Gplanchat\AgenticBundle\Console\HelpCommand;
use Gplanchat\AgenticBundle\Controller\HelpController;
use Gplanchat\AgenticBundle\Project\Project;
use Gplanchat\AgenticBundle\Project\ProjectLoader;
use Gplanchat\AgenticBundle\Project\ProjectSchema;
use Gplanchat\AgenticBundle\Project\TrustStore;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Skill\Skills;
use Gplanchat\AgenticBundle\Skill\SkillTool;
use Gplanchat\AgenticBundle\Ticket\TicketOperation;
use Gplanchat\AgenticBundle\Ticket\TicketTool;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\AgenticBundle\Tool\CommitWorktreeTool;
use Gplanchat\AgenticBundle\Tool\EditFileTool;
use Gplanchat\AgenticBundle\Tool\ReadFileTool;
use Gplanchat\AgenticBundle\Tool\RevertWorktreeTool;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use Gplanchat\AgenticBundle\Tool\WorktreeDiffTool;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The bundle provides two primary adapters: the `agentic` TUI application (its own Console
 * application, separate from `bin/console`) and its web version. Behind them, a durable agent: a
 * conversation is a run of {@see DurableAgentWorkflow}.
 */
final class AgenticBundle extends AbstractBundle
{
    public const COMMAND_TAG = 'gplanchat_agentic.command';

    public const TOOL_TAG = 'gplanchat_agentic.tool';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('model')->defaultValue('mistral-small-latest')->end()
                ->scalarNode('mistral_api_key')->defaultValue('')->info('Empty: a scripted client answers, with no network.')->end()
                ->scalarNode('tickets_token')->defaultValue('')->info('The token of the forge a project names in its tickets setting. The installation\'s, never a project file\'s.')->end()
                ->scalarNode('system_prompt')->defaultValue(DurableAgentWorkflow::SYSTEM_PROMPT)->end()
                ->floatNode('human_timeout_seconds')->defaultValue(900.0)->info('Deadline of every wait on a human: approval as well as question.')->end()
                ->floatNode('idle_timeout_seconds')->defaultValue(3600.0)->info('Silence after which the conversation ends.')->end()
                ->integerNode('rollover_after_turns')->defaultValue(40)->end()
                ->integerNode('context_tokens')->defaultValue(24_000)->end()
                ->integerNode('max_tool_calls')
                    ->info('Tool-calling rounds a turn may make. Past them, the turn ends on the model saying where it stands, with no tool it may call; the next message goes on. A TDD cycle with its review takes a dozen.')
                    ->defaultValue(40)
                    ->min(1)
                ->end()
                ->integerNode('max_delegation_depth')
                    ->defaultValue(2)
                    ->info('How deep a chain of sub-agents may go. At that depth `delegate` is not offered at all, so the chain stops. 0 forbids delegating; without a bound an anonymous delegation could delegate for ever, each level costing a model call.')
                ->end()
                ->integerNode('token_budget')
                    ->defaultValue(0)
                    ->info('What one run may spend in model tokens before it stops taking turns. 0: no ceiling — the spend is counted either way, and counting is the part that cannot be done afterwards.')
                ->end()
                ->scalarNode('instructions_file')
                    ->defaultNull()
                    ->info('Instructions of the installation, appended to the system prompt of every conversation, whatever the project. The project\'s own come from its .agentic/config.* (AGENTS.md by default). Missing: ignored; null: none.')
                ->end()
                ->append(ProjectSchema::toolRules())
                ->arrayNode('sandbox')
                    ->info('The run_command, read_file and edit_file tools, run inside a bubblewrap sandbox: the workspace writable, no network and nothing else from the disk.')
                    ->canBeEnabled()
                    ->children()
                        ->arrayNode('hidden')
                            ->info('Project paths masked inside the sandbox (glob patterns): secrets, the agent journal.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['.env.local', '.env.*.local', 'var'])
                        ->end()
                        ->floatNode('timeout_seconds')->defaultValue(120.0)->end()
                        ->scalarNode('binary')->defaultValue('bwrap')->end()
                        ->booleanNode('worktrees')
                            ->info('One git worktree per conversation (<workspace>/.worktrees/agentic-<id>, branch agentic/agentic-<id>, cut from HEAD): the agent writes there, not in the project. Off: it writes in the project.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('shared')
                            ->info('Ignored directories a worktree borrows read-only from the project (glob patterns): the installed dependencies.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['vendor'])
                        ->end()
                        ->arrayNode('auto_allow')
                            ->info('The commands that pass without approval in auto mode (fnmatch patterns on the whole line); the others ask. Outside auto, the mode and tool_rules decide.')
                            ->scalarPrototype()->end()
                            ->defaultValue(['git status', 'git status *', 'git diff', 'git diff *', 'git log', 'git log *', 'vendor/bin/phpunit', 'vendor/bin/phpunit *'])
                        ->end()
                        ->append(ProjectSchema::checks())
                    ->end()
                ->end()
                ->arrayNode('agents')
                    ->info('The sub-agents `delegate` may hand a mission to. A profile narrows what its sub-agent may do; it never grants more than the parent already has.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('description')->defaultValue('')->info('What it is for — the model reads this to choose.')->end()
                            ->scalarNode('prompt')->defaultValue('')->info('Its instructions; empty takes the default system prompt.')->end()
                            ->scalarNode('model')->defaultNull()->info('Its model; null takes the caller\'s.')->end()
                            ->enumNode('ceiling')->values(['plan', 'standard', 'edition', 'auto'])->defaultValue('standard')->info('The most it may ever do; the strictest of this and the parent wins.')->end()
                            ->arrayNode('tools')->info('Patterns (fnmatch) of the tools it may use; empty = none.')->scalarPrototype()->end()->end()
                            ->integerNode('max_turns')->defaultValue(1)->end()
                            ->arrayNode('roles')
                                ->info('What it may still claim of its caller\'s identity. An intersection, never a grant: a role the caller does not hold stays out. Empty = it claims nothing.')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('mcp')
                    ->info('MCP servers whose tools are offered to the agent, discovered when a conversation starts and frozen in its payload.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('servers')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('command')->defaultNull()->info('Command of a server spawned over stdio; exclusive with url.')->end()
                                    ->arrayNode('args')->scalarPrototype()->end()->end()
                                    ->scalarNode('cwd')->defaultNull()->end()
                                    ->arrayNode('env')->useAttributeAsKey('name')->scalarPrototype()->end()->end()
                                    ->scalarNode('url')->defaultNull()->info('Endpoint of a remote server; exclusive with command.')->end()
                                    ->arrayNode('headers')->useAttributeAsKey('name')->scalarPrototype()->end()->end()
                                    ->arrayNode('effects')
                                        ->info('Tool name pattern (fnmatch) → read, write or external. Authoritative over what the server says about itself.')
                                        ->useAttributeAsKey('tool')
                                        ->enumPrototype()->values(['read', 'write', 'external'])->end()
                                    ->end()
                                    ->booleanNode('trust_annotations')
                                        ->defaultFalse()
                                        ->info('Believe the server\'s own hints (readOnlyHint…). Off by default: a tool that destroys can call itself read-only, and the guard is what protects from that.')
                                    ->end()
                                    ->integerNode('timeout_seconds')->defaultValue(15)->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('watch_subjects')
                    ->info('The vocabulary of the watches: subject → what the model reads of it.')
                    ->useAttributeAsKey('subject')
                    ->scalarPrototype()->end()
                ->end()
            ->end();
    }

    /**
     * The sub-agents the skills rely on, where the separation of powers must be enforced rather than
     * asked for: the `verifier` judges work it did not make, in a fresh context, at the `plan`
     * ceiling — it can read the ticket and the diff, and change nothing.
     *
     * @return array<string, array{description: string, prompt: string, model: null, ceiling: string, tools: list<string>, max_turns: int, roles: list<string>}>
     */
    private static function seats(): array
    {
        return [
            'verifier' => [
                'description' => 'Judges finished work against its ticket, in a clean context — never the one who made it',
                'prompt' => trim((string) file_get_contents(\dirname(__DIR__).'/seats/verifier.md')),
                'model' => null,
                'ceiling' => 'plan',
                'tools' => ['ticket_read', 'worktree_diff', 'read_file'],
                'max_turns' => 1,
                'roles' => [],
            ],
        ];
    }

    /**
     * @param array{model: string, mistral_api_key: string, tickets_token: string, system_prompt: string, human_timeout_seconds: float, idle_timeout_seconds: float, rollover_after_turns: int, context_tokens: int, max_tool_calls: int, token_budget: int, max_delegation_depth: int, instructions_file: string|null, tool_rules: list<array<string, mixed>>, agents: array<string, array{description: string, prompt: string, model: string|null, ceiling: string, tools: list<string>, max_turns: int, roles: list<string>}>, mcp: array{servers: array<string, array{command: string|null, args: list<string>, cwd: string|null, env: array<string, string>, url: string|null, headers: array<string, string>, effects: array<string, string>, trust_annotations: bool, timeout_seconds: int}>}, sandbox: array{enabled: bool, hidden: list<string>, timeout_seconds: float, binary: string, worktrees: bool, shared: list<string>, auto_allow: list<string>, checks: array<string, array{command: list<string>, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests: string, review: list<string>}>}, watch_subjects: array<string, string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(AgentTool::class)->addTag(self::TOOL_TAG);

        $services = $container->services();

        // The project the agent is launched in: the launch directory, or the installation when no one
        // says (tests, the web version). Read at runtime — the container is compiled once and reused
        // from every directory.
        $container->parameters()->set('agentic.project_root', '%env(default:kernel.project_dir:AGENTIC_WORKSPACE)%');

        // Approvals belong to the installation: one written where the agent works could be written
        // by the agent.
        $services->set(TrustStore::class)
            ->args(['%kernel.project_dir%/var/agentic-trust.json']);
        $services->set(ProjectLoader::class)
            ->args([service(TrustStore::class), [
                'checks' => $config['sandbox']['checks'],
                'hidden' => $config['sandbox']['hidden'],
                'shared' => $config['sandbox']['shared'],
                'auto_allow' => $config['sandbox']['auto_allow'],
                'worktrees' => $config['sandbox']['worktrees'],
            ]])
            ->public();
        // Lazy: read on first use, that is after the chat asked for the approval of a new project
        // file — not when the container builds the command that asks.
        $services->set(Project::class)
            ->factory([service(ProjectLoader::class), 'load'])
            ->args(['%agentic.project_root%'])
            ->lazy();

        if ($config['sandbox']['enabled']) {
            $services->set(Bubblewrap::class)
                ->factory([Bubblewrap::class, 'forProject'])
                ->args([service(Project::class), $config['sandbox']['timeout_seconds'], $config['sandbox']['binary']])
                ->lazy();
            $services->set(Workspaces::class)
                ->factory([Workspaces::class, 'forProject'])
                ->args([service(Bubblewrap::class), service(Project::class)])
                ->lazy();
            $services->set(ReadFileTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(EditFileTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(RunCommandTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            // Offered only when the project has layers: AgentTools leaves it out otherwise.
            $services->set(RunChecksTool::class)
                ->factory([RunChecksTool::class, 'forProject'])
                ->args([service(Workspaces::class), service(Project::class)])
                ->tag(self::TOOL_TAG);
            $services->set(RevertWorktreeTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(CommitWorktreeTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(WorktreeDiffTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
        }

        // Offered only when the project names a ticket tracker: AgentTools leaves them out otherwise.
        foreach (TicketOperation::cases() as $operation) {
            $services->set(null, TicketTool::class)
                ->args([$operation, service(Project::class), $config['tickets_token'], service('http_client')->nullOnInvalid()])
                ->tag(self::TOOL_TAG);
        }
        // Also offered only with a ticket tracker: the skills work on its tickets.
        $services->set(Skills::class)
            ->factory([Skills::class, 'bundled']);
        $services->set(SkillTool::class)
            ->args([service(Skills::class), service(Project::class)])
            ->tag(self::TOOL_TAG);

        // --- The durable agent
        $services->set(DurableAgentWorkflow::class)
            ->abstract()
            ->tag('durable.workflow');

        $servers = [];
        foreach ($config['mcp']['servers'] as $name => $server) {
            $servers[] = new Definition(McpServer::class, [
                $name, $server['command'], $server['args'], $server['cwd'], $server['env'],
                $server['url'], $server['headers'], $server['effects'], $server['trust_annotations'], $server['timeout_seconds'],
            ]);
        }

        $services->set(McpCatalog::class)
            ->args([$servers, service('logger')->nullOnInvalid()])
            ->public();

        $services->set(AgentTools::class)
            ->args([tagged_iterator(self::TOOL_TAG), service(McpCatalog::class)])
            ->public();

        $services->set(AgentToolActivityHandler::class)
            ->args([service(AgentTools::class)])
            ->tag('durable.activity_handler', ['contract' => AgentToolActivityInterface::class]);

        $services->set('gplanchat_agentic.model_client', ModelClientInterface::class)
            ->factory([ModelClientFactory::class, 'create'])
            ->args([$config['mistral_api_key']]);

        $services->set(ModelInvocationActivityHandler::class)
            ->args([service('gplanchat_agentic.model_client')])
            ->tag('durable.activity_handler', ['contract' => ModelInvocationActivityInterface::class]);

        $services->set('gplanchat_agentic.model_catalog', MistralModelCatalog::class);

        $services->set(ChatTranscript::class)
            ->args([service(EventStoreInterface::class), service(WorkflowMetadataStore::class)]);

        $services->set(ProjectInstructions::class)
            ->args([$config['instructions_file']]);

        // Who the conversations belong to. An application with a firewall replaces this alias with
        // its own adapter over `Security`; nothing else in the chat knows where the principal
        // comes from.
        $services->set(LocalPrincipal::class);
        $services->alias(CurrentPrincipal::class, LocalPrincipal::class);

        $services->set(DurableConversations::class)
            ->args([
                service(WorkflowResumeDispatcher::class),
                service(MessageBusInterface::class),
                service(ChatTranscript::class),
                service(AgentTools::class),
                service('gplanchat_agentic.model_catalog'),
                service(ProjectInstructions::class),
                service(EventStoreInterface::class),
                service(WorkflowRunCatalogInterface::class),
                service(CurrentPrincipal::class),
                [
                    'model' => $config['model'],
                    'systemPrompt' => $config['system_prompt'],
                    'humanTimeoutSeconds' => $config['human_timeout_seconds'],
                    'idleTimeoutSeconds' => $config['idle_timeout_seconds'],
                    'rolloverAfterTurns' => $config['rollover_after_turns'],
                    'contextTokens' => $config['context_tokens'],
                    'maxToolCalls' => $config['max_tool_calls'],
                    'tokenBudget' => $config['token_budget'],
                    'maxDepth' => $config['max_delegation_depth'],
                    'watchSubjects' => $config['watch_subjects'],
                    'toolRules' => $config['tool_rules'],
                    // The installation's own come last: a profile it names `verifier` replaces this one.
                    'agents' => [...self::seats(), ...$config['agents']],
                ],
                service(Project::class),
                service(Workspaces::class)->nullOnInvalid(),
            ]);
        $services->alias(Conversations::class, DurableConversations::class)->public();

        $services->set(InProcessWorker::class)
            ->args([service('messenger.receiver_locator'), service(MessageBusInterface::class)])
            ->public();

        // --- The TUI application
        $services->set(ChatScreen::class)
            ->args([service(Conversations::class), service(InProcessWorker::class), service(Bubblewrap::class)->nullOnInvalid(), service(McpCatalog::class)])
            ->public();

        // No `console.command` tag: these commands belong to the `agentic` application, and a
        // `help` registered in `bin/console` would replace Symfony's own there.
        $services->set(HelpCommand::class)
            ->tag(self::COMMAND_TAG);
        $services->set(ChatCommand::class)
            ->args([service(Conversations::class), service(ChatScreen::class), service(ProjectLoader::class), service(TrustStore::class), '%agentic.project_root%'])
            ->tag(self::COMMAND_TAG);

        $services->set(AgenticApplication::class)
            ->args([tagged_iterator(self::COMMAND_TAG)])
            ->public();

        // --- The web version
        $services->set(ConsoleCommandCatalog::class)
            ->args([service(AgenticApplication::class)]);

        $services->set(ListCommands::class)
            ->args([service(ConsoleCommandCatalog::class)]);

        $services->set(HelpController::class)
            ->args([service(ListCommands::class)])
            ->tag('controller.service_arguments');
    }
}
