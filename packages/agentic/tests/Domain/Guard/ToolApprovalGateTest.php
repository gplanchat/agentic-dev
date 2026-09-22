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

        $gate->decide('accorde', true);
        $gate->decide('refuse', false);
        $gate->timeout('expire');

        self::assertSame(ApprovalOutcome::Approved, $gate->outcome('accorde'));
        self::assertSame(ApprovalOutcome::Refused, $gate->outcome('refuse'));
        self::assertSame(ApprovalOutcome::Expired, $gate->outcome('expire'));
        self::assertNull($gate->outcome('jamais-demande'));
    }

    public function testAskingPutsTheCallInPendingUntilItIsSettled(): void
    {
        $gate = new ToolApprovalGate();
        $gate->ask(new ToolInvocation('c1', 'send_email', ['to' => 'a@b.test']), 'demande une validation');

        self::assertFalse($gate->isSettled('c1'));
        self::assertCount(1, $gate->pending());
        self::assertSame('send_email', $gate->pending()[0]->tool);

        $gate->timeout('c1');

        self::assertTrue($gate->isSettled('c1'));
        self::assertSame([], $gate->pending());
    }

    /**
     * Un signal arrivé après le tir de l'échéance ne doit pas ressusciter un appel déjà tranché :
     * au rejeu, l'ordre du journal donnerait le verdict inverse.
     */
    public function testTheFirstOutcomeWins(): void
    {
        $gate = new ToolApprovalGate();

        $gate->timeout('c1');
        $gate->decide('c1', true);

        self::assertSame(ApprovalOutcome::Expired, $gate->outcome('c1'));
    }
}
