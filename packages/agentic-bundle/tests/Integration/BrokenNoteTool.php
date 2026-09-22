<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * A tool that always fails: what the conversation shows once the retries are spent.
 */
final class BrokenNoteTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('save_note', 'Saves a note.', ToolEffect::Read);
    }

    public function __invoke(array $arguments): string
    {
        throw new \RuntimeException('The disk is full.');
    }
}
