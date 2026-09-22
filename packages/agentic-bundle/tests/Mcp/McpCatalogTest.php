<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Mcp;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\AgenticBundle\Mcp\McpCatalog;
use Gplanchat\AgenticBundle\Mcp\McpServer;
use PHPUnit\Framework\TestCase;

/**
 * Against a real MCP server, spawned over stdio (tests/Mcp/fixtures/weather-server.php).
 */
final class McpCatalogTest extends TestCase
{
    public function testToolsOfAServerAreOfferedUnderAPrefixedName(): void
    {
        $catalog = $this->catalog();

        $names = array_map(static fn (AgentTool $tool): string => $tool->definition()->name, [...$catalog]);

        self::assertContains('mcp__weather__forecast', $names);
        self::assertContains('mcp__weather__drop_station', $names);
    }

    /**
     * The annotations of a server are its claims about itself, so they are only believed when the
     * configuration says so. By default every MCP tool is external — it asks in every mode but
     * `auto` — and the fixture shows why: `drop_station` destroys and calls itself read-only.
     */
    public function testAnnotationsAreOnlyBelievedWhenTheConfigurationSaysSo(): void
    {
        self::assertSame(ToolEffect::External, $this->effectOf($this->catalog(), 'mcp__weather__forecast'));
        self::assertSame(ToolEffect::External, $this->effectOf($this->catalog(), 'mcp__weather__drop_station'));

        $trusting = $this->catalog(trustAnnotations: true);
        self::assertSame(ToolEffect::Read, $this->effectOf($trusting, 'mcp__weather__forecast'));
        // Trusting a server means taking its word — including the lie of a destructive tool that
        // claims to be read-only. That is the whole cost of `trust_annotations`.
        self::assertSame(ToolEffect::Read, $this->effectOf($trusting, 'mcp__weather__drop_station'));

        // The configuration is authoritative, whatever the server says about itself.
        self::assertSame(ToolEffect::Write, $this->effectOf($this->catalog(effects: ['forecast' => 'write']), 'mcp__weather__forecast'));
        self::assertSame(ToolEffect::External, $this->effectOf($this->catalog(trustAnnotations: true, effects: ['drop_*' => 'external']), 'mcp__weather__drop_station'));
    }

    public function testAToolRunsAndAnswers(): void
    {
        $catalog = $this->catalog();

        self::assertSame('Lyon: 21°C, clear', $this->tool($catalog, 'mcp__weather__forecast')(['city' => 'Lyon']));
    }

    /**
     * A failing tool comes back as text: the model reads it and adapts. Throwing here would cost
     * three retries of a call that cannot succeed, then the conversation.
     */
    public function testAFailingToolComesBackAsAResult(): void
    {
        $answer = $this->tool($this->catalog(), 'mcp__weather__barometer')([]);

        self::assertStringContainsString('barometer', $answer);
        self::assertMatchesRegularExpression('/failed|error/i', $answer);
    }

    public function testAToolTheServerNoLongerOffersIsAResultToo(): void
    {
        self::assertStringContainsString('gone', $this->catalog()->call('weather', 'gone', []));
    }

    /**
     * A server that cannot be reached costs no conversation: no tool, an error `/mcp` shows.
     */
    public function testAnUnreachableServerIsReportedNotThrown(): void
    {
        $catalog = new McpCatalog([new McpServer('ghost', command: '/does/not/exist', timeoutSeconds: 2)]);

        self::assertSame([], [...$catalog]);

        $status = $catalog->status();
        self::assertFalse($status[0]['connected']);
        self::assertNotNull($status[0]['error']);
        self::assertStringContainsString('unreachable', $catalog->call('ghost', 'whatever', []));
    }

    public function testStatusTellsWhatIsConnectedAndHowMany(): void
    {
        $status = $this->catalog()->status();

        self::assertSame('weather', $status[0]['server']);
        self::assertSame('stdio', $status[0]['transport']);
        self::assertTrue($status[0]['connected']);
        self::assertSame(3, $status[0]['tools']);
    }

    /**
     * @param array<string, string> $effects
     */
    private function catalog(bool $trustAnnotations = false, array $effects = []): McpCatalog
    {
        return new McpCatalog([new McpServer(
            'weather',
            command: \PHP_BINARY,
            args: [__DIR__.'/fixtures/weather-server.php'],
            effects: $effects,
            trustAnnotations: $trustAnnotations,
            timeoutSeconds: 10,
        )]);
    }

    private function tool(McpCatalog $catalog, string $name): AgentTool
    {
        foreach ($catalog as $tool) {
            if ($name === $tool->definition()->name) {
                return $tool;
            }
        }

        self::fail(\sprintf('Tool "%s" was not offered.', $name));
    }

    private function effectOf(McpCatalog $catalog, string $name): ToolEffect
    {
        return $this->tool($catalog, $name)->definition()->effect;
    }
}
