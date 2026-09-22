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
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Sandbox\Worktrees;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\AgenticBundle\Tool\EditFileTool;
use Gplanchat\AgenticBundle\Tool\ReadFileTool;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use Gplanchat\AgenticBundle\Tool\RunCommandTool;
use Gplanchat\AgenticBundle\Tui\ChatScreen;
use Gplanchat\AgenticBundle\Tui\HelpScreen;
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
                ->scalarNode('system_prompt')->defaultValue(DurableAgentWorkflow::SYSTEM_PROMPT)->end()
                ->floatNode('human_timeout_seconds')->defaultValue(900.0)->info('Deadline of every wait on a human: approval as well as question.')->end()
                ->floatNode('idle_timeout_seconds')->defaultValue(3600.0)->info('Silence after which the conversation ends.')->end()
                ->integerNode('rollover_after_turns')->defaultValue(40)->end()
                ->integerNode('context_tokens')->defaultValue(24_000)->end()
                ->scalarNode('instructions_file')
                    ->defaultValue('%kernel.project_dir%/AGENTS.md')
                    ->info('Project instructions appended to the system prompt when each conversation starts. Missing: ignored; null: disabled.')
                ->end()
                ->arrayNode('tool_rules')
                    ->info('The decision hooks: for a tool (fnmatch pattern) and arguments, allow, ask or deny. deny > ask > allow; with no rule, the mode.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('tool')->isRequired()->cannotBeEmpty()->end()
                            ->enumNode('decision')->values(['allow', 'ask', 'deny'])->isRequired()->end()
                            ->arrayNode('when')
                                ->info('argument → pattern its value must follow')
                                ->useAttributeAsKey('argument')
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('reason')->defaultValue('')->end()
                            ->arrayNode('modes')
                                ->info('the modes where the rule holds; empty: all of them')
                                ->enumPrototype()->values(['auto', 'edition', 'standard'])->end()
                            ->end()
                            ->arrayNode('unless')
                                ->info('argument → patterns that set the rule aside')
                                ->useAttributeAsKey('argument')
                                ->arrayPrototype()->scalarPrototype()->end()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sandbox')
                    ->info('The run_command, read_file and edit_file tools, run inside a bubblewrap sandbox: the workspace writable, no network and nothing else from the disk.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('workspace')->defaultValue('%kernel.project_dir%')->end()
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
                        ->arrayNode('checks')
                            // Layer names are what the model types: `component-unit` stays `component-unit`.
                            ->normalizeKeys(false)
                            ->info('The layers of the run_checks tool — static, unit, functional, integration, e2e… —: a command that writes a JUnit report to {report}, run in the sandbox. None: no run_checks.')
                            ->useAttributeAsKey('layer')
                            ->arrayPrototype()
                                ->children()
                                    ->arrayNode('command')
                                        ->info('One command, or a list run in turn. Split like a terminal line, no shell; {report} is replaced by the report path; without it, the command prints its report (e.g. "vendor/bin/phpstan analyse --error-format=junit --no-progress").')
                                        ->isRequired()
                                        ->requiresAtLeastOneElement()
                                        ->beforeNormalization()->castToArray()->end()
                                        ->scalarPrototype()->cannotBeEmpty()->end()
                                    ->end()
                                    ->scalarNode('cwd')->defaultValue('')->info('Directory to run in, relative to the workspace root.')->end()
                                    ->scalarNode('filter_option')->defaultNull()->info('The option that takes the model\'s filter, e.g. "--filter"; null: the layer runs whole.')->end()
                                    ->floatNode('timeout_seconds')->defaultValue(300.0)->end()
                                    ->scalarNode('description')->defaultValue('')->info('What the model reads about the layer.')->end()
                                    ->scalarNode('tests')->defaultValue('')->info('Where the tests of this layer live, and how they are named — the agent places its new tests by it.')->end()
                                    ->arrayNode('review')->info('The layers to run once this one is green: those a change here can break, and the static ones.')->scalarPrototype()->end()->end()
                                ->end()
                            ->end()
                        ->end()
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
                            ->enumNode('ceiling')->values(['standard', 'edition', 'auto'])->defaultValue('standard')->info('The most it may ever do; the strictest of this and the parent wins.')->end()
                            ->arrayNode('tools')->info('Patterns (fnmatch) of the tools it may use; empty = none.')->scalarPrototype()->end()->end()
                            ->integerNode('max_turns')->defaultValue(1)->end()
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
     * @param array{model: string, mistral_api_key: string, system_prompt: string, human_timeout_seconds: float, idle_timeout_seconds: float, rollover_after_turns: int, context_tokens: int, instructions_file: string|null, tool_rules: list<array<string, mixed>>, agents: array<string, array{description: string, prompt: string, model: string|null, ceiling: string, tools: list<string>, max_turns: int}>, mcp: array{servers: array<string, array{command: string|null, args: list<string>, cwd: string|null, env: array<string, string>, url: string|null, headers: array<string, string>, effects: array<string, string>, trust_annotations: bool, timeout_seconds: int}>}, sandbox: array{enabled: bool, workspace: string, hidden: list<string>, timeout_seconds: float, binary: string, worktrees: bool, shared: list<string>, auto_allow: list<string>, checks: array<string, array{command: list<string>, cwd: string, filter_option: string|null, timeout_seconds: float, description: string, tests: string, review: list<string>}>}, watch_subjects: array<string, string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(AgentTool::class)->addTag(self::TOOL_TAG);

        $services = $container->services();

        if ($config['sandbox']['enabled']) {
            $services->set(Bubblewrap::class)
                ->args([
                    $config['sandbox']['workspace'],
                    $config['sandbox']['hidden'],
                    $config['sandbox']['timeout_seconds'],
                    $config['sandbox']['binary'],
                ]);
            if ($config['sandbox']['worktrees']) {
                $services->set(Worktrees::class)
                    ->args([$config['sandbox']['workspace'], $config['sandbox']['shared']]);
            }
            $services->set(Workspaces::class)
                ->args([service(Bubblewrap::class), service(Worktrees::class)->nullOnInvalid()]);
            $services->set(ReadFileTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(EditFileTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            $services->set(RunCommandTool::class)
                ->args([service(Workspaces::class)])
                ->tag(self::TOOL_TAG);
            if ([] !== $config['sandbox']['checks']) {
                $services->set(RunChecksTool::class)
                    ->args([service(Workspaces::class), $config['sandbox']['checks']])
                    ->tag(self::TOOL_TAG);
            }

            // The auto-mode allowlist is a rule like the others: it goes to the journal with them,
            // and `/tools` shows it. An ask beats an allow: an `allow` in tool_rules does not widen
            // it, `auto_allow` is what has to be changed.
            $config['tool_rules'][] = [
                'tool' => RunCommandTool::TOOL,
                'decision' => 'ask',
                'modes' => ['auto'],
                'unless' => ['command' => $config['sandbox']['auto_allow']],
                'reason' => 'Command outside the auto-mode list (agentic.sandbox.auto_allow).',
            ];
        }

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
                    // With checks, the agent works along the test pyramid and the TDD cycle: said once,
                    // in the prompt every conversation starts with.
                    'systemPrompt' => [] === ($config['sandbox']['enabled'] ? $config['sandbox']['checks'] : [])
                        ? $config['system_prompt']
                        : $config['system_prompt']."\n\n".RunChecksTool::method($config['sandbox']['checks']),
                    'humanTimeoutSeconds' => $config['human_timeout_seconds'],
                    'idleTimeoutSeconds' => $config['idle_timeout_seconds'],
                    'rolloverAfterTurns' => $config['rollover_after_turns'],
                    'contextTokens' => $config['context_tokens'],
                    'watchSubjects' => $config['watch_subjects'],
                    'toolRules' => $config['tool_rules'],
                    'agents' => $config['agents'],
                ],
                service(Worktrees::class)->nullOnInvalid(),
            ]);
        $services->alias(Conversations::class, DurableConversations::class)->public();

        $services->set(InProcessWorker::class)
            ->args([service('messenger.receiver_locator'), service(MessageBusInterface::class)])
            ->public();

        // --- The TUI application
        $services->set(HelpScreen::class);
        $services->set(ChatScreen::class)
            ->args([service(Conversations::class), service(InProcessWorker::class), service(Bubblewrap::class)->nullOnInvalid(), service(McpCatalog::class)])
            ->public();

        // No `console.command` tag: these commands belong to the `agentic` application, and a
        // `help` registered in `bin/console` would replace Symfony's own there.
        $services->set(HelpCommand::class)
            ->args([service(HelpScreen::class)])
            ->tag(self::COMMAND_TAG);
        $services->set(ChatCommand::class)
            ->args([service(Conversations::class), service(ChatScreen::class)])
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
