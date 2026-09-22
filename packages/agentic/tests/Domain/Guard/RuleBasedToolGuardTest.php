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
        $guard = $this->guard(['tool' => 'weather', 'decision' => 'ask', 'reason' => 'Paid reading.']);

        $decision = $guard->decide(new ToolInvocation('c1', 'weather'), AgentMode::Auto);
        self::assertTrue($decision->needsApproval());
        self::assertSame('Paid reading.', $decision->reason);
    }

    /**
     * The declaration order does not count: adding an approval never loosens a refusal.
     */
    public function testDenyWinsOverAskWhichWinsOverAllow(): void
    {
        $guard = $this->guard(
            ['tool' => 'send_*', 'decision' => 'allow'],
            ['tool' => 'send_email', 'decision' => 'ask'],
            ['tool' => '*', 'when' => ['to' => '*@competitor.test'], 'decision' => 'deny'],
        );

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email', ['to' => 'x@competitor.test']), AgentMode::Auto)->isDenied());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'send_email', ['to' => 'x@example.test']), AgentMode::Auto)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c3', 'send_sms'), AgentMode::Standard)->isAllowed());
    }

    public function testArgumentConditionsMustAllMatch(): void
    {
        $guard = $this->guard(['tool' => 'send_email', 'when' => ['to' => '*@example.test'], 'decision' => 'allow']);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'send_email', ['to' => 'team@example.test']), AgentMode::Standard)->isAllowed());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'send_email', ['to' => 'client@elsewhere.test']), AgentMode::Standard)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c3', 'send_email', ['to' => ['list']]), AgentMode::Standard)->needsApproval(), 'A compound value matches no pattern.');
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
        ToolRule::fromWire(['tool' => 'x', 'decision' => 'maybe']);
    }

    /**
     * The allow list of `auto` mode: ask, except for the listed commands — and only in `auto`, the
     * other modes keeping their rule.
     */
    public function testAnAutoAllowlistAsksForEverythingElseInAutoOnly(): void
    {
        $guard = $this->guard(['tool' => 'run_command', 'decision' => 'ask', 'modes' => ['auto'], 'unless' => ['command' => ['git status', 'git status *']]]);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'run_command', ['command' => 'git status --short']), AgentMode::Auto)->isAllowed());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'run_command', ['command' => 'rm -rf src']), AgentMode::Auto)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c3', 'run_command', ['command' => 'git status']), AgentMode::Standard)->needsApproval(), 'Outside `auto`, the mode decides: an unknown tool is external.');
    }

    public function testAMissingOrCompositeArgumentNeverOpensAnAllowlist(): void
    {
        $guard = $this->guard(['tool' => 'run_command', 'decision' => 'ask', 'unless' => ['command' => ['*']]]);

        self::assertTrue($guard->decide(new ToolInvocation('c1', 'run_command'), AgentMode::Auto)->needsApproval());
        self::assertTrue($guard->decide(new ToolInvocation('c2', 'run_command', ['command' => ['git', 'status']]), AgentMode::Auto)->needsApproval());
    }

    public function testModesAndUnlessSurviveTheWireAndAnUnknownModeIsRefused(): void
    {
        $wire = ['tool' => 'run_command', 'decision' => 'ask', 'when' => [], 'reason' => '', 'modes' => ['auto'], 'unless' => ['command' => ['git *']]];
        self::assertSame($wire, ToolRule::fromWire($wire)->toWire());

        $this->expectException(\InvalidArgumentException::class);
        ToolRule::fromWire(['tool' => 'x', 'decision' => 'allow', 'modes' => ['automatic']]);
    }

    /**
     * @param array<string, mixed> ...$rules
     */
    private function guard(array ...$rules): RuleBasedToolGuard
    {
        return new RuleBasedToolGuard(RuleBasedToolGuard::rulesFromWire($rules), new ModeToolGuard(new Toolset(
            new ToolDefinition('weather', 'Weather', ToolEffect::Read),
            new ToolDefinition('save_note', 'Note', ToolEffect::Write),
            new ToolDefinition('send_email', 'Email', ToolEffect::External),
        )));
    }
}
