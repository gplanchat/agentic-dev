<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Identity;

use Gplanchat\Agentic\Domain\Identity\Principal;
use PHPUnit\Framework\TestCase;

final class PrincipalTest extends TestCase
{
    public function testAPrincipalNeedsAnIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Principal('  ');
    }

    public function testOwnershipIsDecidedOnTheIdentifierAlone(): void
    {
        // A role lost between two sessions must not lock someone out of their own conversation.
        self::assertTrue((new Principal('alice', ['admin']))->is(new Principal('alice')));
        self::assertFalse((new Principal('alice'))->is(new Principal('bob')));
    }

    public function testItSurvivesTheJournalBothWays(): void
    {
        $principal = new Principal('alice', ['support']);

        self::assertSame(['id' => 'alice', 'roles' => ['support']], $principal->toWire());
        self::assertTrue(Principal::fromWire($principal->toWire())?->is($principal));
    }

    public function testAnOwnerWithNoRoleKeepsTheShapeItWasWrittenWith(): void
    {
        self::assertSame(['id' => 'alice'], (new Principal('alice'))->toWire());
        self::assertSame([], Principal::fromWire(['id' => 'alice'])?->roles);
    }

    /**
     * A conversation opened before owners existed carries none: the projection must read that as
     * "nobody", not as a crash nor as an empty principal that would match nobody's id.
     */
    public function testAJournalWithNoOwnerReadsAsNothing(): void
    {
        self::assertNull(Principal::fromWire(null));
        self::assertNull(Principal::fromWire([]));
        self::assertNull(Principal::fromWire(['id' => '']));
        self::assertNull(Principal::fromWire('alice'));
    }
}
