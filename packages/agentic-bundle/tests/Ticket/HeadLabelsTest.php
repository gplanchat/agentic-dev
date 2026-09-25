<?php

declare(strict_types=1);

namespace Gplanchat\AgenticBundle\Tests\Ticket;

use Gplanchat\Agentic\Domain\Ticket\HeadKind;
use Gplanchat\AgenticBundle\Ticket\HeadLabels;
use PHPUnit\Framework\TestCase;

final class HeadLabelsTest extends TestCase
{
    public function testAFamilyLeftOutKeepsItsOwnName(): void
    {
        $labels = new HeadLabels(['defect' => ' anomalie ']);

        self::assertSame('anomalie', $labels->of(HeadKind::Defect));
        self::assertSame('debt', $labels->of(HeadKind::Debt));
        self::assertSame(HeadKind::Defect, $labels->kindOf(['p1', 'Anomalie']));
        self::assertNull($labels->kindOf(['p1']));
    }

    public function testATicketOfTwoFamiliesIsRefused(): void
    {
        $this->expectExceptionMessage('A ticket labelled as two head families: debt, groundwork.');

        (new HeadLabels())->kindOf(['groundwork', 'debt']);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function badTables(): iterable
    {
        yield 'an unknown family' => [['debt' => 'dette', 'feature' => 'x'], 'Unknown head families: feature (defect, debt, groundwork, capability, investigation).'];
        yield 'an empty label' => [['debt' => ' '], 'The label of the head family "debt" is empty.'];
        yield 'one label for two families' => [['debt' => 'Tech', 'groundwork' => 'tech'], 'Two head families share a label: a ticket would read as either.'];
    }

    /**
     * @param array<string, string> $labels
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badTables')]
    public function testABadTableIsRefused(array $labels, string $message): void
    {
        $this->expectExceptionMessage($message);

        new HeadLabels($labels);
    }
}
