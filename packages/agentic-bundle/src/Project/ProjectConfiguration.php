<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Gplanchat\AgenticBundle\Ticket\Forge;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * What a project may say in its `.agentic/config.*`: its check layers, its instructions, and — once
 * the file is approved — its share of the guard. Laid over the installation's configuration: layers
 * and rules add up, masked and borrowed paths and the auto-mode list are joined, never narrowed.
 */
final class ProjectConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('agentic');
        $tree->getRootNode()
            ->fixXmlConfig('check', 'checks')
            ->fixXmlConfig('tool_rule', 'tool_rules')
            ->children()
                ->scalarNode('instructions_file')
                    ->info('The project\'s instructions for the agent, relative to the project root; empty: none.')
                    ->defaultValue('AGENTS.md')
                ->end()
                ->append(ProjectSchema::checks())
                ->append(ProjectSchema::toolRules())
                ->arrayNode('tickets')
                    ->info('The forge where the project keeps its plan: head tickets, their work tickets, what waits on what (EWA-002). Absent: no ticket tools. The token is the installation\'s (tickets_token), never the project\'s.')
                    ->children()
                        ->enumNode('forge')->values(array_column(Forge::cases(), 'value'))->isRequired()->end()
                        ->scalarNode('repository')->info('owner/name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('url')->info('The forge\'s root, e.g. https://codeberg.org — required for forgejo. For github, its API: https://api.github.com by default.')->defaultNull()->end()
                        ->arrayNode('labels')
                            ->info('The forge label of each head family (defect, debt, groundwork, capability, investigation), in the project\'s vocabulary. A family left out is labelled with its own name. The labels must exist on the forge: none is created.')
                            ->useAttributeAsKey('family')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sandbox')
                    ->addDefaultsIfNotSet()
                    ->fixXmlConfig('hide', 'hidden')
                    ->fixXmlConfig('share', 'shared')
                    ->fixXmlConfig('allow', 'auto_allow')
                    ->children()
                        ->arrayNode('hidden')->info('More paths masked in the sandbox (glob patterns).')->scalarPrototype()->end()->end()
                        ->arrayNode('shared')->info('More ignored directories a worktree borrows read-only (glob patterns).')->scalarPrototype()->end()->end()
                        ->arrayNode('auto_allow')->info('More commands that pass without approval in auto mode.')->scalarPrototype()->end()->end()
                        ->booleanNode('worktrees')->info('One worktree per conversation; null: as the installation says.')->defaultNull()->end()
                    ->end()
                ->end()
            ->end();

        return $tree;
    }
}
