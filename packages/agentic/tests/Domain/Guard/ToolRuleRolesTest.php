<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Guard;

use Gplanchat\Agentic\Domain\Guard\AgentMode;
use Gplanchat\Agentic\Domain\Guard\ModeToolGuard;
use Gplanchat\Agentic\Domain\Guard\RuleBasedToolGuard;
use Gplanchat\Agentic\Domain\Guard\ToolRule;
use Gplanchat\Agentic\Domain\Guard\ToolVerdict;
use Gplanchat\Agentic\Domain\Identity\Principal;
use Gplanchat\Agentic\Domain\Tool\ToolDefinition;
use Gplanchat\Agentic\Domain\Guard\ToolEffect;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;
use Gplanchat\Agentic\Domain\Tool\Toolset;
use PHPUnit\Framework\TestCase;

/**
 * `unless_roles`: the exemption lives on the refusing rule, because deny wins over everything and
 * "refused to everyone but finance" therefore cannot be two rules.
 */
final class ToolRuleRolesTest extends TestCase
{
    private const REFUND = 'refund_order';

    public function testTheRuleHoldsForWhoeverDoesNotHoldTheRole(): void
    {
        $guard = self::guard(new Principal('bob', ['support']));

        self::assertTrue($guard->decide(new ToolInvocation('1', self::REFUND), AgentMode::Auto)->isDenied());
    }

    public function testTheRuleIsSetAsideForWhoeverHoldsIt(): void
    {
        $guard = self::guard(new Principal('alice', ['finance']));

        // Set aside, so nothing refuses: the mode decides, and `auto` lets it through.
        self::assertFalse($guard->decide(new ToolInvocation('1', self::REFUND), AgentMode::Auto)->isDenied());
    }

    public function testOneRoleAmongSeveralIsEnough(): void
    {
        $guard = self::guard(new Principal('alice', ['support', 'finance']));

        self::assertFalse($guard->decide(new ToolInvocation('1', self::REFUND), AgentMode::Auto)->isDenied());
    }

    /**
     * The safe direction, and the one every principal takes today since nothing fills roles in yet:
     * claiming nothing exempts from nothing.
     */
    public function testAPrincipalWithNoRoleIsExemptFromNothing(): void
    {
        self::assertTrue(self::guard(new Principal('alice'))->decide(new ToolInvocation('1', self::REFUND), AgentMode::Auto)->isDenied());
    }

    /**
     * No principal at all must not open the exemption either — a guard with no subject is the
     * state that must never let more through, not less.
     */
    public function testNoPrincipalAtAllIsExemptFromNothing(): void
    {
        self::assertTrue(self::guard(null)->decide(new ToolInvocation('1', self::REFUND), AgentMode::Auto)->isDenied());
    }

    public function testTheExemptionSurvivesTheJournal(): void
    {
        $rule = new ToolRule(self::REFUND, ToolVerdict::Deny, unlessRoles: ['finance']);
        $read = ToolRule::fromWire($rule->toWire());

        self::assertSame(['finance'], $read->unlessRoles);
    }

    /**
     * A rule that says nothing about roles keeps the shape it had in the journal before roles
     * existed.
     */
    public function testARuleWithoutRolesKeepsItsOldShape(): void
    {
        self::assertArrayNotHasKey('unless_roles', (new ToolRule(self::REFUND, ToolVerdict::Deny))->toWire());
    }

    private static function guard(?Principal $principal): RuleBasedToolGuard
    {
        return new RuleBasedToolGuard(
            [new ToolRule(self::REFUND, ToolVerdict::Deny, unlessRoles: ['finance'], reason: 'Only finance refunds.')],
            new ModeToolGuard(new Toolset(new ToolDefinition(self::REFUND, 'Refunds an order.', ToolEffect::External))),
            $principal,
        );
    }
}
