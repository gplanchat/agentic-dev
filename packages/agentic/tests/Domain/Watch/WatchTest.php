<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Watch;

use Gplanchat\Agentic\Domain\Watch\UnknownWatchSubject;
use Gplanchat\Agentic\Domain\Watch\Watch;
use Gplanchat\Agentic\Domain\Watch\WatchDesk;
use Gplanchat\Agentic\Domain\Watch\WatchSubject;
use Gplanchat\Agentic\Domain\Watch\WatchSubjects;
use Gplanchat\Agentic\Domain\Watch\WatchTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Watch::class)]
#[CoversClass(WatchDesk::class)]
#[CoversClass(WatchTool::class)]
final class WatchTest extends TestCase
{
    private static function subjects(): WatchSubjects
    {
        return new WatchSubjects(new WatchSubject('commande.expediee', 'une commande a quitté l’entrepôt'));
    }

    public function testTheApplicationVocabularyIsWhatTheModelIsOffered(): void
    {
        $schema = WatchTool::definition(self::subjects())->parameters;

        self::assertSame(['commande.expediee'], $schema['properties']['sujet']['enum']);
    }

    /**
     * Refus visible plutôt que veille morte : aucun événement ne lèverait jamais ce sujet.
     */
    public function testAnUnknownSubjectIsRefusedInsteadOfArmingADeadWatch(): void
    {
        $this->expectException(UnknownWatchSubject::class);

        Watch::fromArguments('c1', ['sujet' => 'livraison.bientot'], self::subjects());
    }

    public function testTheFirstAlertWins(): void
    {
        $desk = new WatchDesk();
        $desk->watch(Watch::fromArguments('c1', ['sujet' => 'commande.expediee', 'intention' => 'prévenir le client'], self::subjects()));

        $desk->raise('c1', 'colis parti');
        $desk->raise('c1', 'second signal');

        self::assertSame('colis parti', $desk->observationOf('c1'));
        self::assertSame([], $desk->pending());
    }
}
