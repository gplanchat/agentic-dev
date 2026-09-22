<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Tool\Toolset;

/**
 * Les outils que l'application a déclarés (services {@see AgentTool}), indexés par nom.
 */
final class AgentTools
{
    /** @var array<string, AgentTool>|null */
    private ?array $byName = null;

    /**
     * @param iterable<AgentTool> $tools
     */
    public function __construct(private readonly iterable $tools)
    {
    }

    public function toolset(): Toolset
    {
        return new Toolset(...array_map(static fn (AgentTool $tool) => $tool->definition(), array_values($this->byName())));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments): string
    {
        $tool = $this->byName()[$name] ?? null;
        if (null === $tool) {
            // ponytail: l'échec remonte et tue l'appel agent. Le renvoyer au modèle comme résultat
            // d'outil est l'autre politique possible — c'est DUR011 qui doit trancher.
            throw new \InvalidArgumentException(\sprintf('Outil « %s » inconnu.', $name));
        }

        return $tool($arguments);
    }

    /**
     * @return array<string, AgentTool>
     */
    private function byName(): array
    {
        if (null === $this->byName) {
            $this->byName = [];
            foreach ($this->tools as $tool) {
                $this->byName[$tool->definition()->name] = $tool;
            }
        }

        return $this->byName;
    }
}
