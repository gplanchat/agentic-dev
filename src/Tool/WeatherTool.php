<?php

declare(strict_types=1);

namespace App\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;

final class WeatherTool implements AgentTool
{
    private const RELEVES = ['Paris' => '22°C, ensoleillé', 'Lyon' => '25°C, nuageux', 'Marseille' => '27°C, mistral'];

    public function definition(): ToolDefinition
    {
        return new ToolDefinition('weather', 'Météo courante d’une ville.', ToolEffect::Read, [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]);
    }

    public function __invoke(array $arguments): string
    {
        $city = (string) ($arguments['city'] ?? '');

        return \sprintf('%s : %s', $city, self::RELEVES[$city] ?? 'relevé indisponible');
    }
}
