<?php

declare(strict_types=1);

namespace App\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

/**
 * An agent tool is a side effect: it runs inside an activity, never in workflow code.
 */
final class WeatherTool implements AgentTool
{
    private const READINGS = ['Paris' => '22°C, sunny', 'Lyon' => '25°C, cloudy', 'Marseille' => '27°C, mistral wind'];

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
        $city = (string) ($arguments['city'] ?? '');

        return \sprintf('%s: %s', $city, self::READINGS[$city] ?? 'reading unavailable');
    }
}
