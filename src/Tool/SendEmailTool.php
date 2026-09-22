<?php

declare(strict_types=1);

namespace App\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * The tool the guard protects: sending is not undone by a click. In `standard` mode as in
 * `edition`, it asks for a human approval — and the workflow stays suspended until then.
 */
final class SendEmailTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('send_email', 'Sends an email. External effect: nothing catches it back.', ToolEffect::External, [
            'type' => 'object',
            'properties' => ['to' => ['type' => 'string'], 'body' => ['type' => 'string']],
            'required' => ['to', 'body'],
        ]);
    }

    public function __invoke(array $arguments): string
    {
        return \sprintf('Email sent to %s.', (string) ($arguments['to'] ?? '?'));
    }
}
