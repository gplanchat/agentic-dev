<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * Un outil qui échoue toujours : ce que la conversation montre quand les tentatives sont épuisées.
 */
final class BrokenNoteTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('save_note', 'Enregistre une note.', ToolEffect::Read);
    }

    public function __invoke(array $arguments): string
    {
        throw new \RuntimeException('Le disque est plein.');
    }
}
