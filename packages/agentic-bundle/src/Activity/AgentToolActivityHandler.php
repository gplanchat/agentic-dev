<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Activity;

use Gplanchat\Agentic\Infrastructure\Durable\Activity\AgentToolActivityInterface;
use Gplanchat\AgenticBundle\Tool\AgentTools;

/**
 * Route un appel d'outil vers son implémentation. Chaque appel est une activité : journalisée,
 * retentée selon ses `ActivityOptions`, et jamais ré-exécutée au rejeu.
 */
final readonly class AgentToolActivityHandler implements AgentToolActivityInterface
{
    public function __construct(private AgentTools $tools)
    {
    }

    public function callTool(string $callId, string $name, array $arguments): string
    {
        return $this->tools->call($name, $arguments);
    }
}
