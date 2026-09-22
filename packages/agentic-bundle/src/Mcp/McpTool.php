<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Mcp;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Mcp\Schema\Tool;

/**
 * A tool of an MCP server, seen by the agent as any other tool: a name, a description, a schema and
 * an effect the guard reads.
 */
final readonly class McpTool implements AgentTool
{
    public function __construct(
        private McpCatalog $catalog,
        private McpServer $server,
        private Tool $tool,
    ) {
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            $this->server->toolName($this->tool->name),
            \sprintf('[%s] %s', $this->server->name, $this->tool->description ?? $this->tool->title ?? $this->tool->name),
            $this->server->effectOf($this->tool),
            // The schema travels to the provider as it is: it is the server's, and rewriting it here
            // would be inventing a contract we do not own.
            [] === $this->tool->inputSchema ? null : $this->tool->inputSchema,
        );
    }

    public function __invoke(array $arguments): string
    {
        return $this->catalog->call($this->server->name, $this->tool->name, $arguments);
    }
}
