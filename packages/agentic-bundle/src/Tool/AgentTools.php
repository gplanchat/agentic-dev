<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Tool\Toolset;

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
     * @param string|null          $workspace the conversation's working directory, from its start payload
     */
    public function call(string $name, array $arguments, ?string $workspace = null): string
    {
        $tool = $this->byName()[$name] ?? null;
        if (null === $tool) {
            // Handed back to the model rather than thrown: a conversation replays tools frozen in
            // its payload, and an MCP server may have dropped one since. Throwing would cost three
            // retries of a call that cannot succeed, then the conversation.
            return \sprintf('Unknown tool "%s": it is no longer offered.', $name);
        }

        // ponytail: a single tool acts in a workspace. The file tools will make it an interface.
        return $tool instanceof RunCommandTool ? $tool->inWorkspace($arguments, $workspace) : $tool($arguments);
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
                    $this->byName[$tool->definition()->name] = $tool;
                }
            }
        }

        return $this->byName;
    }
}
