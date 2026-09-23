<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Tool;

use Gplanchat\Agentic\Application\Tool\AgentTool;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\AgenticBundle\Sandbox\Bubblewrap;
use Gplanchat\AgenticBundle\Sandbox\Workspaces;
use Gplanchat\AgenticBundle\Tool\AgentTools;
use Gplanchat\AgenticBundle\Tool\RunChecksTool;
use PHPUnit\Framework\TestCase;

final class AgentToolsTest extends TestCase
{
    /**
     * A project with no check layer: run_checks is not offered — and the tools after it still are.
     */
    public function testRunChecksWithoutLayersIsLeftOutAndNothingElse(): void
    {
        $noLayers = new RunChecksTool(new Workspaces(new Bubblewrap(__DIR__)), []);
        $after = new class implements AgentTool {
            public function definition(): ToolDefinition
            {
                return new ToolDefinition('after', 'A tool declared after run_checks.');
            }

            public function __invoke(array $arguments): string
            {
                return '';
            }
        };

        $names = array_map(static fn (ToolDefinition $tool): string => $tool->name, (new AgentTools([$noLayers, $after]))->toolset()->definitions);

        self::assertSame(['after'], $names);
    }
}
