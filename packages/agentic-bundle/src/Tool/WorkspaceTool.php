<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;

/**
 * A tool that acts in the conversation's workspace — its worktree, when there is one.
 *
 * The workspace comes from the conversation's start payload, never from the model: `AgentTools`
 * hands it over, and `__invoke()` alone means the configured workspace.
 */
interface WorkspaceTool extends AgentTool
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function inWorkspace(array $arguments, ?string $workspace): string;
}
