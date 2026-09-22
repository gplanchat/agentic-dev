<?php

declare(strict_types=1);

namespace App\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

final class SaveNoteTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('save_note', 'Enregistre une note dans le dossier courant.', ToolEffect::Write, [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ]);
    }

    public function __invoke(array $arguments): string
    {
        return \sprintf('Note enregistrée (%d caractères).', mb_strlen((string) ($arguments['text'] ?? '')));
    }
}
