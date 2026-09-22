<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Integration;

use Gplanchat\Agentic\Application\Chat\Conversations;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\AgenticBundle\Mcp\McpCatalog;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\AgenticBundle\Tui\SlashCommands;
use Gplanchat\AgenticBundle\Worker\InProcessWorker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An MCP server declared in the configuration, from discovery to what `/mcp` shows.
 */
final class McpIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return McpTestKernel::class;
    }

    public function testTheServersToolsAreOfferedToTheAgentWithTheConfiguredEffect(): void
    {
        $toolset = self::getContainer()->get(AgentTools::class)->toolset();

        self::assertSame(ToolEffect::Read, $toolset->effectOf('mcp__weather__forecast'), 'The configuration is authoritative.');
        self::assertSame(ToolEffect::External, $toolset->effectOf('mcp__weather__drop_station'), 'Without a rule, an MCP tool is external.');
        // The application's own tools are still there.
        self::assertSame(ToolEffect::Read, $toolset->effectOf('weather'));
    }

    public function testAConversationFreezesTheServersToolsInItsPayload(): void
    {
        $container = self::getContainer();
        $conversations = $container->get(Conversations::class);

        $id = $conversations->start();
        $container->get(InProcessWorker::class)->drain();

        $names = array_map(static fn ($tool): string => $tool->name, $conversations->transcript($id)->tools->definitions);
        self::assertContains('mcp__weather__forecast', $names);
    }

    public function testTheToolRunsThroughTheActivityHandler(): void
    {
        $answer = self::getContainer()->get(AgentTools::class)->call('mcp__weather__forecast', ['city' => 'Lyon']);

        self::assertSame('Lyon: 21°C, clear', $answer);
    }

    public function testAToolThatDisappearedIsAResultNotAFailure(): void
    {
        self::assertStringContainsString('no longer offered', self::getContainer()->get(AgentTools::class)->call('mcp__weather__gone', []));
    }

    public function testMcpListsTheServerAndItsTools(): void
    {
        $container = self::getContainer();
        $id = $container->get(Conversations::class)->start();
        $container->get(InProcessWorker::class)->drain();

        $notice = (new SlashCommands($container->get(Conversations::class), $container->get(McpCatalog::class)))->run($id, '/mcp')->notice;

        self::assertMatchesRegularExpression('/● +weather \(stdio\)/u', $notice);
        self::assertStringContainsString('3 tools', $notice);
        self::assertMatchesRegularExpression('/forecast\s+read/', $notice);
        self::assertMatchesRegularExpression('/drop_station\s+external/', $notice);
    }
}
