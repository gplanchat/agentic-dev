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
        return new WatchSubjects(new WatchSubject('order.shipped', 'an order has left the warehouse'));
    }

    public function testTheApplicationVocabularyIsWhatTheModelIsOffered(): void
    {
        $schema = WatchTool::definition(self::subjects())->parameters;

        self::assertSame(['order.shipped'], $schema['properties']['subject']['enum']);
    }

    /**
     * A visible refusal rather than a dead watch: no event would ever lift this subject.
     */
    public function testAnUnknownSubjectIsRefusedInsteadOfArmingADeadWatch(): void
    {
        $this->expectException(UnknownWatchSubject::class);

        Watch::fromArguments('c1', ['subject' => 'delivery.soon'], self::subjects());
    }

    public function testTheFirstAlertWins(): void
    {
        $desk = new WatchDesk();
        $desk->watch(Watch::fromArguments('c1', ['subject' => 'order.shipped', 'intent' => 'warn the customer'], self::subjects()));

        $desk->raise('c1', 'parcel gone');
        $desk->raise('c1', 'second signal');

        self::assertSame('parcel gone', $desk->observationOf('c1'));
        self::assertSame([], $desk->pending());
    }
}
