<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Tool;

use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * Port : un outil que l'application offre à l'agent.
 *
 * La définition part au modèle et à la garde ; l'exécution, elle, n'a lieu que dans une activité —
 * jamais en code workflow, où elle serait rejouée à chaque reprise.
 */
interface AgentTool
{
    public function definition(): ToolDefinition;

    /**
     * @param array<string, mixed> $arguments ce que le modèle a demandé, rien ne garantit le schéma
     */
    public function __invoke(array $arguments): string;
}
