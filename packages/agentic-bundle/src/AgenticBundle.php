<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle;

use Gplanchat\Agentic\Application\Chat\Conversations;
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
use Gplanchat\AgenticBundle\Chat\ProjectInstructions;
use Gplanchat\AgenticBundle\Console\AgenticApplication;
use Gplanchat\AgenticBundle\Console\ChatCommand;
use Gplanchat\AgenticBundle\Console\ConsoleCommandCatalog;
use Gplanchat\AgenticBundle\Console\HelpCommand;
use Gplanchat\AgenticBundle\Controller\HelpController;
use Gplanchat\AgenticBundle\Tool\AgentTools;
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
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Le bundle fournit deux adaptateurs primaires : l'application TUI `agentic` (sa propre application
 * Console, distincte de `bin/console`) et sa version web. Derrière, un agent durable : une
 * conversation est une exécution de {@see DurableAgentWorkflow}.
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
                ->scalarNode('mistral_api_key')->defaultValue('')->info('Vide : un client scripté répond, sans réseau.')->end()
                ->scalarNode('system_prompt')->defaultValue(DurableAgentWorkflow::SYSTEM_PROMPT)->end()
                ->floatNode('human_timeout_seconds')->defaultValue(900.0)->info('Échéance de toute attente humaine : validation comme question.')->end()
                ->floatNode('idle_timeout_seconds')->defaultValue(3600.0)->info('Silence au bout duquel la conversation se termine.')->end()
                ->integerNode('rollover_after_turns')->defaultValue(40)->end()
                ->integerNode('context_tokens')->defaultValue(24_000)->end()
                ->scalarNode('instructions_file')
                    ->defaultValue('%kernel.project_dir%/AGENTS.md')
                    ->info('Consignes du projet ajoutées au prompt système au démarrage de chaque conversation. Absent : ignoré ; null : désactivé.')
                ->end()
                ->arrayNode('tool_rules')
                    ->info('Les hooks de décision : pour un outil (motif fnmatch) et des arguments, allow, ask ou deny. deny > ask > allow ; sans règle, le mode.')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('tool')->isRequired()->cannotBeEmpty()->end()
                            ->enumNode('decision')->values(['allow', 'ask', 'deny'])->isRequired()->end()
                            ->arrayNode('when')
                                ->info('argument → motif que sa valeur doit suivre')
                                ->useAttributeAsKey('argument')
                                ->scalarPrototype()->end()
                            ->end()
                            ->scalarNode('reason')->defaultValue('')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('watch_subjects')
                    ->info('Le vocabulaire des veilles : sujet → ce que le modèle en lit.')
                    ->useAttributeAsKey('subject')
                    ->scalarPrototype()->end()
                ->end()
            ->end();
    }

    /**
     * @param array{model: string, mistral_api_key: string, system_prompt: string, human_timeout_seconds: float, idle_timeout_seconds: float, rollover_after_turns: int, context_tokens: int, instructions_file: string|null, tool_rules: list<array<string, mixed>>, watch_subjects: array<string, string>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(AgentTool::class)->addTag(self::TOOL_TAG);

        $services = $container->services();

        // --- L'agent durable
        $services->set(DurableAgentWorkflow::class)
            ->abstract()
            ->tag('durable.workflow');

        $services->set(AgentTools::class)
            ->args([tagged_iterator(self::TOOL_TAG)]);

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
                [
                    'model' => $config['model'],
                    'systemPrompt' => $config['system_prompt'],
                    'humanTimeoutSeconds' => $config['human_timeout_seconds'],
                    'idleTimeoutSeconds' => $config['idle_timeout_seconds'],
                    'rolloverAfterTurns' => $config['rollover_after_turns'],
                    'contextTokens' => $config['context_tokens'],
                    'watchSubjects' => $config['watch_subjects'],
                    'toolRules' => $config['tool_rules'],
                ],
            ]);
        $services->alias(Conversations::class, DurableConversations::class)->public();

        $services->set(InProcessWorker::class)
            ->args([service('messenger.receiver_locator'), service(MessageBusInterface::class)])
            ->public();

        // --- L'application TUI
        $services->set(HelpScreen::class);
        $services->set(ChatScreen::class)
            ->args([service(Conversations::class), service(InProcessWorker::class)])
            ->public();

        // Pas de tag `console.command` : ces commandes appartiennent à l'application `agentic`, et
        // un `help` enregistré dans `bin/console` y remplacerait celui de Symfony.
        $services->set(HelpCommand::class)
            ->args([service(HelpScreen::class)])
            ->tag(self::COMMAND_TAG);
        $services->set(ChatCommand::class)
            ->args([service(Conversations::class), service(ChatScreen::class)])
            ->tag(self::COMMAND_TAG);

        $services->set(AgenticApplication::class)
            ->args([tagged_iterator(self::COMMAND_TAG)])
            ->public();

        // --- La version web
        $services->set(ConsoleCommandCatalog::class)
            ->args([service(AgenticApplication::class)]);

        $services->set(ListCommands::class)
            ->args([service(ConsoleCommandCatalog::class)]);

        $services->set(HelpController::class)
            ->args([service(ListCommands::class)])
            ->tag('controller.service_arguments');
    }
}
