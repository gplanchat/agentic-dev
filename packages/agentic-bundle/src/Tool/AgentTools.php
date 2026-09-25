<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Application\Tool\ContextualTool;
use Gplanchat\Agentic\Application\Tool\ToolContext;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use Gplanchat\AgenticBundle\Ticket\TicketTool;

/**
 * The tools the application has declared ({@see AgentTool} services), indexed by name.
 */
final class AgentTools
{
    /** @var array<string, AgentTool>|null */
    private ?array $byName = null;

    /**
     * @param iterable<AgentTool> $tools     the tools declared as services
     * @param iterable<AgentTool> $discovered the ones found at runtime — the MCP servers' tools
     */
    public function __construct(
        private readonly iterable $tools,
        private readonly iterable $discovered = [],
    ) {
    }

    public function toolset(): Toolset
    {
        return new Toolset(...array_map(static fn (AgentTool $tool) => $tool->definition(), array_values($this->byName())));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param ToolContext|null     $context what the conversation says about this call — where to act,
     *                                      for whom, which call; `null` for a plain invocation
     */
    public function call(string $name, array $arguments, ?ToolContext $context = null): string
    {
        $tool = $this->byName()[$name] ?? null;
        if (null === $tool) {
            // Handed back to the model rather than thrown: a conversation replays tools frozen in
            // its payload, and an MCP server may have dropped one since. Throwing would cost three
            // retries of a call that cannot succeed, then the conversation.
            return \sprintf('Unknown tool "%s": it is no longer offered.', $name);
        }

        return $tool instanceof ContextualTool && null !== $context ? $tool->inContext($arguments, $context) : $tool($arguments);
    }

    /**
     * @return array<string, AgentTool>
     */
    private function byName(): array
    {
        if (null === $this->byName) {
            $this->byName = [];
            foreach ([$this->tools, $this->discovered] as $source) {
                foreach ($source as $tool) {
                    // A project with no check layer has nothing for run_checks to run.
                    if ($tool instanceof RunChecksTool && !$tool->hasLayers()) {
                        continue;
                    }
                    // A project with no ticket tracker has no tickets to work on.
                    if ($tool instanceof TicketTool && !$tool->isOffered()) {
                        continue;
                    }
                    $this->byName[$tool->definition()->name] = $tool;
                }
            }
        }

        return $this->byName;
    }
}
