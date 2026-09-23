<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * The default guard: a deny list that always wins, then the rule of the mode
 * ({@see AgentMode::requiresApprovalFor()}).
 *
 * ponytail: no rules engine. An unknown tool is treated as `external` — the cautious default.
 * Conditions on arguments ("this path, not that one") would justify a second implementation of
 * {@see ToolGuardInterface}, not one more option here.
 */
final class ModeToolGuard implements ToolGuardInterface
{
    /**
     * @param Toolset      $tools  what the agent can do, and what each tool does
     * @param list<string> $denied tools refused whatever the mode
     */
    public function __construct(
        private readonly Toolset $tools = new Toolset(),
        private readonly array $denied = [],
    ) {
    }

    public function decide(ToolInvocation $toolCall, AgentMode $mode): ToolDecision
    {
        $name = $toolCall->name;

        if (\in_array($name, $this->denied, true)) {
            return ToolDecision::deny(\sprintf('Tool "%s" is forbidden by the agent policy.', $name));
        }

        $effect = $this->tools->effectOf($name);

        return match ($mode->verdictFor($effect)) {
            ToolVerdict::Allow => ToolDecision::allow(),
            ToolVerdict::Ask => ToolDecision::ask(\sprintf('"%s" (%s) needs approval in %s mode.', $name, $effect->value, $mode->value)),
            // The refusal is what the model reads back, so it says what to do instead. In plan mode
            // that costs nothing and spares the prompt a paragraph: the agent learns it is planning
            // from the first tool it reaches for, not from an instruction it may have drifted from.
            ToolVerdict::Deny => ToolDecision::deny(\sprintf(
                '"%s" (%s) is refused in %s mode: look and read all you need, then say what you would '
                .'do and why, without doing it. Leaving plan mode is the human\'s decision.',
                $name,
                $effect->value,
                $mode->value,
            )),
        };
    }
}
