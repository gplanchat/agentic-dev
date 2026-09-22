<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * The test kernel, plus one MCP server — the stdio fixture of tests/Mcp.
 *
 * A kernel of its own so that the other integration tests do not spawn a server process on every
 * boot: declaring an MCP server costs a process, and only this test needs one.
 */
final class McpTestKernel extends TestKernel
{
    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 2).'/var/cache-mcp';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        parent::configureContainer($container);

        $container->extension('agentic', [
            'mcp' => [
                'servers' => [
                    'weather' => [
                        'command' => \PHP_BINARY,
                        'args' => [\dirname(__DIR__).'/Mcp/fixtures/weather-server.php'],
                        'effects' => ['forecast' => 'read'],
                        'timeout_seconds' => 10,
                    ],
                ],
            ],
        ]);
    }
}
