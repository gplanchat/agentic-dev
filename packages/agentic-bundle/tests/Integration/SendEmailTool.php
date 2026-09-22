<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

final class SendEmailTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('send_email', 'Envoie un courriel.', ToolEffect::External, [
            'type' => 'object',
            'properties' => ['to' => ['type' => 'string'], 'body' => ['type' => 'string']],
            'required' => ['to', 'body'],
        ]);
    }

    public function __invoke(array $arguments): string
    {
        return \sprintf('Courriel envoyé à %s.', (string) ($arguments['to'] ?? '?'));
    }
}
