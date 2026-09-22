<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Guard;

use Gplanchat\Agentic\Domain\Guard\ApprovalOutcome;
use Gplanchat\Agentic\Domain\Guard\ToolApprovalGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Gplanchat\Agentic\Domain\Tool\ToolInvocation;

#[CoversClass(ToolApprovalGate::class)]
final class ToolApprovalGateTest extends TestCase
{
    public function testTheThreeOutcomesAreDistinguishable(): void
    {
        $gate = new ToolApprovalGate();

        $gate->decide('approved', true);
        $gate->decide('refused', false);
        $gate->timeout('expired');

        self::assertSame(ApprovalOutcome::Approved, $gate->outcome('approved'));
        self::assertSame(ApprovalOutcome::Refused, $gate->outcome('refused'));
        self::assertSame(ApprovalOutcome::Expired, $gate->outcome('expired'));
        self::assertNull($gate->outcome('never-asked'));
    }

    public function testAskingPutsTheCallInPendingUntilItIsSettled(): void
    {
        $gate = new ToolApprovalGate();
        $gate->ask(new ToolInvocation('c1', 'send_email', ['to' => 'a@b.test']), 'needs an approval');

        self::assertFalse($gate->isSettled('c1'));
        self::assertCount(1, $gate->pending());
        self::assertSame('send_email', $gate->pending()[0]->tool);

        $gate->timeout('c1');

        self::assertTrue($gate->isSettled('c1'));
        self::assertSame([], $gate->pending());
    }

    /**
     * A signal arriving after the deadline fired must not resurrect an already settled call: on
     * replay, the order of the journal would give the opposite verdict.
     */
    public function testTheFirstOutcomeWins(): void
    {
        $gate = new ToolApprovalGate();

        $gate->timeout('c1');
        $gate->decide('c1', true);

        self::assertSame(ApprovalOutcome::Expired, $gate->outcome('c1'));
    }
}
