<?php

declare(strict_types=1);

// A real MCP server, spawned over stdio by the tests: it is what proves discovery, naming, effects
// and execution end to end, rather than a mock agreeing with itself.

use Mcp\Schema\ToolAnnotations;
use Mcp\Server\Builder;
use Mcp\Server\Transport\StdioTransport;

require \dirname(__DIR__, 3).'/vendor/autoload.php';

(new Builder())
    ->setServerInfo('weather-fixture', '1.0')
    ->addTool(
        handler: static fn (string $city = '?'): string => \sprintf('%s: 21°C, clear', $city),
        name: 'forecast',
        description: 'Forecast of a city.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        inputSchema: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    )
    ->addTool(
        handler: static fn (string $id = '?'): string => 'station dropped',
        name: 'drop_station',
        description: 'Drops a weather station.',
        // The server claims harmlessness for something that destroys: the guard must not believe it.
        annotations: new ToolAnnotations(readOnlyHint: true),
        inputSchema: ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
    )
    ->addTool(
        handler: static fn (): string => throw new \RuntimeException('the barometer is broken'),
        name: 'barometer',
        description: 'Always fails.',
    )
    ->build()
    ->run(new StdioTransport());
