<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Guard;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Team\DelegateTool;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModeToolGuard::class)]
#[CoversClass(AgentMode::class)]
final class ModeToolGuardTest extends TestCase
{
    private static function tools(): Toolset
    {
        return new Toolset(
            new ToolDefinition('weather', 'Weather', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Email', ToolEffect::External),
        );
    }

    public static function matrix(): \Generator
    {
        yield 'auto lets everything through' => [AgentMode::Auto, 'send_email', false];
        yield 'auto lets a write through' => [AgentMode::Auto, 'save_note', false];
        yield 'edition lets a write through' => [AgentMode::Edition, 'save_note', false];
        yield 'edition asks for an external effect' => [AgentMode::Edition, 'send_email', true];
        yield 'standard lets a read through' => [AgentMode::Standard, 'weather', false];
        yield 'standard asks for a write' => [AgentMode::Standard, 'save_note', true];
        yield 'standard asks for an external effect' => [AgentMode::Standard, 'send_email', true];
        // The cautious default: an unclassified tool is treated as external.
        yield 'an unknown tool is treated as external' => [AgentMode::Standard, 'rm_rf', true];
    }

    #[DataProvider('matrix')]
    public function testTheModeDecidesFromTheDeclaredEffect(AgentMode $mode, string $tool, bool $needsApproval): void
    {
        $decision = (new ModeToolGuard(self::tools()))->decide(new ToolInvocation('c1', $tool), $mode);

        self::assertSame($needsApproval, $decision->needsApproval());
        self::assertSame(!$needsApproval, $decision->isAllowed());
    }

    public function testADenyListWinsOverEveryMode(): void
    {
        $guard = new ModeToolGuard(self::tools(), ['send_email']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email'), AgentMode::Auto)->isDenied());
    }

    /**
     * Authority does not grow by delegation: the strictest of the chain wins.
     */
    public function testTheStrictestOfTheChainWins(): void
    {
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Auto, AgentMode::Standard));
        self::assertSame(AgentMode::Edition, AgentMode::strictest(AgentMode::Auto, AgentMode::Edition));
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Standard, AgentMode::Edition, AgentMode::Auto));
    }

    /**
     * A ceiling refuses what loosens it, and only that.
     */
    public function testOnlyALooserModeIsRefusedByACeiling(): void
    {
        self::assertTrue(AgentMode::Auto->loosens(AgentMode::Standard));
        self::assertTrue(AgentMode::Edition->loosens(AgentMode::Standard));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Auto));
        self::assertFalse(AgentMode::Standard->loosens(AgentMode::Standard));
    }

    public function testDelegatingIsHarmlessByItself(): void
    {
        self::assertFalse(AgentMode::Standard->requiresApprovalFor(DelegateTool::definition()->effect));
    }
}
