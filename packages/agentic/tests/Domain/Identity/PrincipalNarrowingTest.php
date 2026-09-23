<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Identity;

use Gplanchat\Agentic\Domain\Identity\Principal;
use PHPUnit\Framework\TestCase;

/**
 * Narrowing an identity, the counterpart on the identity axis of
 * {@see \Gplanchat\Agentic\Domain\Guard\AgentMode::strictest()} on the mode one.
 */
final class PrincipalNarrowingTest extends TestCase
{
    public function testItKeepsOnlyWhatWasAlreadyHeld(): void
    {
        $alice = new Principal('alice', ['support', 'finance']);

        self::assertSame(['support'], $alice->restrictedTo(['support'])->roles);
        self::assertSame(['support', 'finance'], $alice->restrictedTo(['support', 'finance', 'admin'])->roles);
    }

    /**
     * The property the whole thing exists for: a profile naming a role its caller does not hold
     * grants nothing. Otherwise declaring a sub-agent would be the way to hand it what one may not
     * do oneself.
     */
    public function testAProfileCannotGrantARoleItsCallerDoesNotHold(): void
    {
        $intern = new Principal('bob', ['reader']);

        self::assertSame([], $intern->restrictedTo(['admin'])->roles);
        self::assertSame([], $intern->restrictedTo(['admin', 'finance'])->roles);
    }

    public function testNarrowingToNothingClaimsNothing(): void
    {
        self::assertSame([], (new Principal('alice', ['finance']))->restrictedTo([])->roles);
    }

    /**
     * A sub-agent acts *for* the same person: the journal must keep saying whose work it was.
     */
    public function testTheIdentityItselfDoesNotMove(): void
    {
        $narrowed = (new Principal('alice', ['finance']))->restrictedTo([]);

        self::assertSame('alice', $narrowed->id);
        self::assertTrue($narrowed->is(new Principal('alice')));
    }

    /**
     * Narrowing twice cannot widen: the chain of delegations only ever loses.
     */
    public function testNarrowingIsMonotonic(): void
    {
        $alice = new Principal('alice', ['support', 'finance']);
        $once = $alice->restrictedTo(['support']);

        self::assertSame([], $once->restrictedTo(['finance'])->roles);
    }

    /**
     * A narrowed principal must come back from the journal as a principal claiming nothing — never
     * as `null`, which would leave the child's guard with no subject at all.
     */
    public function testAnEmptyNarrowingSurvivesTheJournalAsAPrincipal(): void
    {
        $narrowed = (new Principal('alice', ['finance']))->restrictedTo([]);
        $read = Principal::fromWire($narrowed->toWire());

        self::assertNotNull($read);
        self::assertSame('alice', $read->id);
        self::assertSame([], $read->roles);
    }
}
