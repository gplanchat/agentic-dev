<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * La garde configurable : des règles d'abord, le mode ensuite.
 *
 * Parmi les règles qui s'appliquent à un appel, **le refus l'emporte sur la demande, qui l'emporte
 * sur l'accord** — l'ordre de déclaration ne compte pas, si bien qu'ajouter une règle ne peut jamais
 * desserrer un refus déjà posé. Aucune règle ne s'applique : c'est la garde de repli qui tranche,
 * c'est-à-dire le mode.
 */
final readonly class RuleBasedToolGuard implements ToolGuardInterface
{
    /**
     * @param list<ToolRule> $rules
     */
    public function __construct(
        private array $rules,
        private ToolGuardInterface $fallback,
    ) {
    }

    public function decide(ToolInvocation $toolCall, AgentMode $mode): ToolDecision
    {
        $matched = array_filter($this->rules, static fn (ToolRule $rule): bool => $rule->matches($toolCall));

        foreach ([ToolVerdict::Deny, ToolVerdict::Ask, ToolVerdict::Allow] as $verdict) {
            foreach ($matched as $rule) {
                if ($verdict === $rule->verdict) {
                    return self::decision($rule, $toolCall);
                }
            }
        }

        return $this->fallback->decide($toolCall, $mode);
    }

    /**
     * @param list<array<string, mixed>> $wire
     *
     * @return list<ToolRule>
     */
    public static function rulesFromWire(array $wire): array
    {
        return array_values(array_map(ToolRule::fromWire(...), $wire));
    }

    private static function decision(ToolRule $rule, ToolInvocation $call): ToolDecision
    {
        $reason = '' !== $rule->reason ? $rule->reason : \sprintf('Une règle du projet vise « %s ».', $call->name);

        return match ($rule->verdict) {
            ToolVerdict::Allow => ToolDecision::allow(),
            ToolVerdict::Ask => ToolDecision::ask($reason),
            ToolVerdict::Deny => ToolDecision::deny($reason),
        };
    }
}
