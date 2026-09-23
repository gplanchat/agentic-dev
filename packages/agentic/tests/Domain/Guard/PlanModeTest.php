<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Guard;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Guard\ToolVerdict;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use PHPUnit\Framework\TestCase;

/**
 * `plan`: look all you want, change nothing — and be told so rather than kept waiting.
 */
final class PlanModeTest extends TestCase
{
    public function testReadsGoThroughAndEverythingElseIsRefusedRatherThanHeld(): void
    {
        self::assertSame(ToolVerdict::Allow, AgentMode::Plan->verdictFor(ToolEffect::Read));
        self::assertSame(ToolVerdict::Deny, AgentMode::Plan->verdictFor(ToolEffect::Write));
        self::assertSame(ToolVerdict::Deny, AgentMode::Plan->verdictFor(ToolEffect::External));
    }

    /**
     * The distinction that justifies the mode: `standard` suspends on a human, `plan` answers at
     * once. A refused agent can carry on planning; a suspended one waits for a card nobody meant
     * to be shown.
     */
    public function testStandardHoldsAWriteWherePlanRefusesIt(): void
    {
        self::assertSame(ToolVerdict::Ask, AgentMode::Standard->verdictFor(ToolEffect::Write));
        self::assertSame(ToolVerdict::Deny, AgentMode::Plan->verdictFor(ToolEffect::Write));
    }

    public function testTheRefusalTellsTheModelWhatToDoInstead(): void
    {
        $guard = new ModeToolGuard(new Toolset(
            new ToolDefinition('save_note', 'Writes a note.', ToolEffect::Write),
        ));

        $decision = $guard->decide(new ToolInvocation('1', 'save_note'), AgentMode::Plan);

        self::assertTrue($decision->isDenied());
        self::assertStringContainsString('say what you would', (string) $decision->reason);
    }

    /**
     * `plan` is the floor of the order, so it wins every comparison — which is what makes a
     * planning conversation planning all the way down, delegates included.
     */
    public function testPlanIsTheStrictestOfAll(): void
    {
        foreach (AgentMode::cases() as $mode) {
            self::assertSame(AgentMode::Plan, AgentMode::strictest(AgentMode::Plan, $mode), $mode->value);
        }
    }

    /**
     * The property the advisor asked to pin: a profile declared `edition` does not lift its
     * caller's plan. Naming a sub-agent must not be the way to act while planning — the same
     * reason the ceiling exists at all.
     */
    public function testAProfileCannotLiftAPlanningCallersMode(): void
    {
        $ceiling = AgentMode::strictest(AgentMode::Plan, AgentMode::Edition);

        self::assertSame(AgentMode::Plan, $ceiling);
        self::assertSame(ToolVerdict::Deny, $ceiling->verdictFor(ToolEffect::Write));
    }

    /**
     * And a `set_mode` along the way cannot lift it either: the ceiling holds afterwards, not only
     * on entry.
     */
    public function testAskingForAutoUnderAPlanCeilingIsALoosening(): void
    {
        self::assertTrue(AgentMode::Auto->loosens(AgentMode::Plan));
        self::assertTrue(AgentMode::Standard->loosens(AgentMode::Plan));
        self::assertFalse(AgentMode::Plan->loosens(AgentMode::Plan));
    }
}
