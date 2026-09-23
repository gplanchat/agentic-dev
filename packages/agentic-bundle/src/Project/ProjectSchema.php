<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Project;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * The nodes the installation's configuration and a project's `.agentic/config.*` share: said once, so
 * a rule or a check layer is written and validated the same way in both.
 */
final class ProjectSchema
{
    public static function toolRules(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('tool_rules', 'array'))->getRootNode();
        $node
            ->info('The decision hooks: for a tool (fnmatch pattern) and arguments, allow, ask or deny. deny > ask > allow; with no rule, the mode.')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('tool')->isRequired()->cannotBeEmpty()->end()
                    ->enumNode('decision')->values(['allow', 'ask', 'deny'])->isRequired()->end()
                    ->arrayNode('when')
                        ->info('argument → pattern its value must follow')
                        ->beforeNormalization()->ifArray()->then(static fn (array $v): array => self::fromXmlArguments($v, false))->end()
                        ->useAttributeAsKey('argument')
                        ->scalarPrototype()->end()
                    ->end()
                    ->scalarNode('reason')->defaultValue('')->end()
                    ->arrayNode('modes')
                        ->info('the modes where the rule holds; empty: all of them')
                        ->enumPrototype()->values(['auto', 'edition', 'standard', 'plan'])->end()
                    ->end()
                    ->arrayNode('unless')
                        ->info('argument → patterns that set the rule aside')
                        ->beforeNormalization()->ifArray()->then(static fn (array $v): array => self::fromXmlArguments($v, true))->end()
                        ->useAttributeAsKey('argument')
                        ->arrayPrototype()->scalarPrototype()->end()->end()
                    ->end()
                    ->arrayNode('unless_roles')
                        ->info('roles that set the rule aside — "refused, except to finance". Negative and not positive because deny wins: "refuse to everyone but X" cannot be written as two rules.')
                        ->scalarPrototype()->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    public static function checks(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('checks', 'array'))->getRootNode();
        $node
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
                    ->arrayNode('review')->info('The layers to run once this one is green: those a change here can break, and the static ones.')->beforeNormalization()->castToArray()->end()->scalarPrototype()->end()->end()
                ->end()
            ->end();

        return $node;
    }

    /**
     * XML says `<when argument="command">rm *</when>`: one element per argument, read back as
     * `{argument, value}` — or a list of them. Folded here into the `argument → pattern` map the other
     * formats write directly; what is not in that shape passes untouched.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function fromXmlArguments(array $value, bool $patterns): array
    {
        $items = isset($value['argument']) ? [$value] : $value;
        foreach ($items as $item) {
            if (!\is_array($item) || !isset($item['argument']) || !\array_key_exists('value', $item)) {
                return $value;
            }
        }

        $folded = [];
        foreach ($items as $item) {
            if ($patterns) {
                $folded[$item['argument']][] = $item['value'];
            } else {
                $folded[$item['argument']] = $item['value'];
            }
        }

        return $folded;
    }
}
