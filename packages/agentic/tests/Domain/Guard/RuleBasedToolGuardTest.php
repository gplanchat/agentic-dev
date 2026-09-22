<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Guard;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleBasedToolGuard::class)]
#[CoversClass(ToolRule::class)]
final class RuleBasedToolGuardTest extends TestCase
{
    public function testAnAllowRuleLetsAWriteThroughInStandard(): void
    {
        $guard = $this->guard(['tool' => 'save_note', 'decision' => 'allow']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'save_note'), AgentMode::Standard)->isAllowed());
    }

    public function testAnAskRuleHoldsEvenInAuto(): void
    {
        $guard = $this->guard(['tool' => 'weather', 'decision' => 'ask', 'reason' => 'Relevé payant.']);

        $decision = $guard->decide(new ToolInvocation('c1', 'weather'), AgentMode::Auto);
        self::assertTrue($decision->needsApproval());
        self::assertSame('Relevé payant.', $decision->reason);
    }

    /**
     * L'ordre de déclaration ne compte pas : ajouter un accord ne desserre jamais un refus.
     */
    public function testDenyWinsOverAskWhichWinsOverAllow(): void
    {
        $guard = $this->guard(
            ['tool' => 'send_*', 'decision' => 'allow'],
            ['tool' => 'send_email', 'decision' => 'ask'],
            ['tool' => '*', 'when' => ['to' => '*@concurrent.test'], 'decision' => 'deny'],
        );

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email', ['to' => 'x@concurrent.test']), AgentMode::Auto)->isDenied());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'send_email', ['to' => 'x@example.test']), AgentMode::Auto)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c3', 'send_sms'), AgentMode::Standard)->isAllowed());
    }

    public function testArgumentConditionsMustAllMatch(): void
    {
        $guard = $this->guard(['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => 'allow']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email', ['to' => 'equipe@example.test']), AgentMode::Standard)->isAllowed());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'send_email', ['to' => 'client@ailleurs.test']), AgentMode::Standard)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c3', 'send_email', ['to' => ['liste']]), AgentMode::Standard)->needsApproval(), 'Une valeur composée ne correspond à aucun motif.');
    }

    public function testWithoutAMatchingRuleTheModeDecides(): void
    {
        $guard = $this->guard(['tool' => 'weather', 'decision' => 'deny']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'save_note'), AgentMode::Standard)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'save_note'), AgentMode::Edition)->isAllowed());
    }

    public function testTheWireSurvivesTheRoundTripAndABadDecisionIsRefused(): void
    {
        $wire = ['tool' => 'send_email', 'decision' => 'ask', 'when' => ['to' => '*@x.test'], 'reason' => 'r'];
        self::assertSame($wire, ToolRule::fromWire($wire)->toWire());

        $this->expectException(\InvalidArgumentException::class);
        ToolRule::fromWire(['tool' => 'x', 'decision' => 'peut-être']);
    }

    /**
     * @param array<string, mixed> ...$rules
     */
    private function guard(array ...$rules): RuleBasedToolGuard
    {
        return new RuleBasedToolGuard(RuleBasedToolGuard::rulesFromWire($rules), new ModeToolGuard(new Toolset(
            new ToolDefinition('weather', 'Météo', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Courriel', ToolEffect::External),
        )));
    }
}
