<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

final class WeatherTool implements AgentTool
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition('weather', 'Current weather of a city.', ToolEffect::Read, [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);
    }

    public function __invoke(array $arguments): string
    {
        return \sprintf('%s: 22°C, sunny', (string) ($arguments['city'] ?? '?'));
    }
}
