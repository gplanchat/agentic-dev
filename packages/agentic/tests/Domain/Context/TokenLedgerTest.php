<?php

declare(strict_types=1);

namespace Gplanchat\Agentic\Tests\Domain\Context;

use Gplanchat\Agentic\Domain\Context\TokenLedger;
use PHPUnit\Framework\TestCase;

final class TokenLedgerTest extends TestCase
{
    public function testItAddsUpWhatTheProviderReported(): void
    {
        $ledger = new TokenLedger();
        $ledger->record(['usage' => ['total_tokens' => 120]]);
        $ledger->record(['usage' => ['total_tokens' => 80]]);

        self::assertSame(200, $ledger->spent());
    }

    public function testItAddsThePartsWhenNoTotalIsGiven(): void
    {
        $ledger = new TokenLedger();
        $ledger->record(['usage' => ['prompt_tokens' => 30, 'completion_tokens' => 12]]);
        // Another provider, another spelling for the same two numbers.
        $ledger->record(['usage' => ['input_tokens' => 5, 'output_tokens' => 3]]);

        self::assertSame(50, $ledger->spent());
    }

    /**
     * A reply that reports nothing adds nothing. Inventing a number would make the budget a
     * fiction, which is worse than not having one.
     */
    public function testAReplyWithoutUsageCostsNothing(): void
    {
        $ledger = new TokenLedger();
        $ledger->record(['choices' => [['message' => ['content' => 'hi']]]]);
        $ledger->record(['usage' => 'nonsense']);
        $ledger->record([]);

        self::assertSame(0, $ledger->spent());
    }

    public function testWithoutACeilingItCountsAndNeverStops(): void
    {
        $ledger = new TokenLedger();
        $ledger->record(['usage' => ['total_tokens' => 10_000_000]]);

        self::assertSame(10_000_000, $ledger->spent());
        self::assertFalse($ledger->isSpent(), '0 means no ceiling, not a ceiling of zero.');
    }

    public function testACeilingIsReachedOnceItIsMet(): void
    {
        $ledger = new TokenLedger(100);

        $ledger->record(['usage' => ['total_tokens' => 99]]);
        self::assertFalse($ledger->isSpent());

        $ledger->record(['usage' => ['total_tokens' => 1]]);
        self::assertTrue($ledger->isSpent());
    }

    public function testANegativeBudgetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TokenLedger(-1);
    }

    /**
     * The property the whole design rests on: the ledger is mutable, but it is fed from journaled
     * results in journal order, so replaying a run lands on the same total. It holds by
     * construction today, which is exactly when it is cheapest to pin.
     */
    public function testTheSameJournalGivesTheSameTotal(): void
    {
        $journal = [
            ['usage' => ['total_tokens' => 11]],
            ['choices' => []],
            ['usage' => ['prompt_tokens' => 7, 'completion_tokens' => 2]],
        ];

        $first = new TokenLedger(1_000);
        $second = new TokenLedger(1_000);
        foreach ($journal as $result) {
            $first->record($result);
            $second->record($result);
        }

        self::assertSame($first->spent(), $second->spent());
        self::assertSame(20, $first->spent());
    }
}
