<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Domain\Guard;

use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

/**
 * La garde par défaut : une liste de refus qui l'emporte toujours, puis la règle du mode
 * ({@see AgentMode::requiresApprovalFor()}).
 *
 * ponytail: pas de moteur de règles. Un outil inconnu est traité comme `external` — le défaut
 * prudent. Des conditions sur les arguments (« ce chemin, pas cet autre ») justifieraient une
 * seconde implémentation de {@see ToolGuardInterface}, pas une option de plus ici.
 */
final class ModeToolGuard implements ToolGuardInterface
{
    /**
     * @param Toolset      $tools  ce que l'agent peut faire, et ce que chaque outil fait
     * @param list<string> $denied outils refusés quel que soit le mode
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
            return ToolDecision::deny(\sprintf('L\'outil « %s » est interdit par la politique de l\'agent.', $name));
        }

        $effect = $this->tools->effectOf($name);

        return $mode->requiresApprovalFor($effect)
            ? ToolDecision::ask(\sprintf('« %s » (%s) demande une validation en mode %s.', $name, $effect->value, $mode->value))
            : ToolDecision::allow();
    }
}
