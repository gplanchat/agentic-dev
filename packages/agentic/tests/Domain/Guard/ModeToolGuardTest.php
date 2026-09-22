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
            new ToolDefinition('weather', 'Météo', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Courriel', ToolEffect::External),
        );
    }

    public static function matrix(): \Generator
    {
        yield 'auto laisse tout passer' => [AgentMode::Auto, 'send_email', false];
        yield 'auto laisse passer une écriture' => [AgentMode::Auto, 'save_note', false];
        yield 'edition laisse passer une écriture' => [AgentMode::Edition, 'save_note', false];
        yield 'edition demande pour un effet externe' => [AgentMode::Edition, 'send_email', true];
        yield 'standard laisse passer une lecture' => [AgentMode::Standard, 'weather', false];
        yield 'standard demande pour une écriture' => [AgentMode::Standard, 'save_note', true];
        yield 'standard demande pour un effet externe' => [AgentMode::Standard, 'send_email', true];
        // Le défaut prudent : un outil non classé est traité comme externe.
        yield 'un outil inconnu est traité comme externe' => [AgentMode::Standard, 'rm_rf', true];
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
     * L'autorité ne grandit pas par délégation : le plus strict de la chaîne l'emporte.
     */
    public function testTheStrictestOfTheChainWins(): void
    {
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Auto, AgentMode::Standard));
        self::assertSame(AgentMode::Edition, AgentMode::strictest(AgentMode::Auto, AgentMode::Edition));
        self::assertSame(AgentMode::Standard, AgentMode::strictest(AgentMode::Standard, AgentMode::Edition, AgentMode::Auto));
    }

    /**
     * Un plafond refuse ce qui desserre, et seulement ça.
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
