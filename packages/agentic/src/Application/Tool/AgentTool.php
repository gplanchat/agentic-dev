<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Application\Tool;

use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * Port: a tool the application offers the agent.
 *
 * The definition goes out to the model and to the guard; the execution, for its part, only happens
 * inside an activity — never in workflow code, where it would be replayed on every resume.
 */
interface AgentTool
{
    public function definition(): ToolDefinition;

    /**
     * @param array<string, mixed> $arguments what the model asked for, nothing guarantees the schema
     */
    public function __invoke(array $arguments): string;
}
