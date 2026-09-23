<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * The configurable guard: rules first, the mode afterwards.
 *
 * Among the rules that apply to a call, **deny wins over ask, which wins over allow** — the
 * declaration order does not count, so that adding a rule can never loosen a refusal already set.
 * No rule applies: the fallback guard decides, that is to say the mode.
 */
final readonly class RuleBasedToolGuard implements ToolGuardInterface
{
    /**
     * @param list<ToolRule> $rules
     * @param Principal|null $principal on whose behalf the conversation runs; constructor data and
     *                                  not a parameter of {@see decide()}, because it does not
     *                                  change from one call to the next — and because it must reach
     *                                  the guard as journaled data, never as a lookup the replay
     *                                  would redo against the database of the day
     */
    public function __construct(
        private array $rules,
        private ToolGuardInterface $fallback,
        private ?Principal $principal = null,
    ) {
    }

    public function decide(ToolInvocation $toolCall, AgentMode $mode): ToolDecision
    {
        $principal = $this->principal;
        $matched = array_filter($this->rules, static fn (ToolRule $rule): bool => $rule->matches($toolCall, $mode, $principal));

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
        $reason = '' !== $rule->reason ? $rule->reason : \sprintf('A project rule targets "%s".', $call->name);

        return match ($rule->verdict) {
            ToolVerdict::Allow => ToolDecision::allow(),
            ToolVerdict::Ask => ToolDecision::ask($reason),
            ToolVerdict::Deny => ToolDecision::deny($reason),
        };
    }
}
