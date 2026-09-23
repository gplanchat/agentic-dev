<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

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
