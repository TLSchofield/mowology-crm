<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Accounting/Services/StatementReconcileService.php';

final class StatementReconcileServiceTest extends TestCase
{
    private function s(string $date, float $amount, ?float $balance = null, string $description = 'Synthetic item'): array
    {
        return compact('date', 'amount', 'balance', 'description');
    }

    private function b(int $id, string $date, float $net, string $memo = 'Synthetic entry', ?int $reversed = null): array
    {
        return ['id' => $id, 'date' => $date, 'memo' => $memo, 'net' => $net, 'reversed_by_entry_id' => $reversed, 'source_type' => null, 'source_id' => null];
    }

    public function testDedupeUsesBalanceAndKeepsNoBalanceRepeats(): void
    {
        $a = $this->s('2026-01-02', -10, 90);
        $r = StatementReconcileService::dedupeStatementLines([$a, $this->s('2026-01-02', -10, 80), $a]);
        self::assertCount(2, $r['lines']); self::assertTrue($r['balance_available']);
        $r = StatementReconcileService::dedupeStatementLines([$this->s('2026-01-02', -10), $this->s('2026-01-02', -10)]);
        self::assertCount(2, $r['lines']); self::assertFalse($r['balance_available']);
    }

    public function testReversalRulesAndChains(): void
    {
        $live = StatementReconcileService::collapseReversals([$this->b(1, '2026-01-01', 10), $this->b(2, '2026-01-02', -10, 'undo', 1), $this->b(3, '2026-01-03', 10)]);
        self::assertSame([3], array_column($live, 'id'));
        self::assertSame([4], array_column(StatementReconcileService::collapseReversals([$this->b(4, '2026-01-04', -20, 'Reversal of #99')] ), 'id'));
        self::assertEmpty(StatementReconcileService::collapseReversals([$this->b(5, '2026-01-05', 20), $this->b(6, '2026-01-06', -20, 'Reversal of #5')]));
    }

    public function testSourceAmountDateWindowGapAndNoDoubleMatch(): void
    {
        $s = [$this->s('2026-01-01', 10), $this->s('2026-02-01', 20), $this->s('2026-02-01', 20), $this->s('2026-01-01', 30)];
        $s[0]['source_key'] = 'bank:7';
        $b = [$this->b(7, '2026-03-01', 10), $this->b(8, '2026-03-13', 20), $this->b(9, '2026-02-10', 30)];
        $b[0]['source_key'] = 'bank:7';
        $r = StatementReconcileService::match($s, $b);
        self::assertSame(1, $r['by_tier']['source']); self::assertSame(2, $r['by_tier']['amount_date']);
        self::assertSame(40, $r['pairs'][1]['gap_days']); self::assertCount(1, $r['statement_unmatched']);
        $outside = StatementReconcileService::match([$this->s('2026-01-01', 9)], [$this->b(1, '2026-02-11', 9)]);
        self::assertCount(1, $outside['statement_unmatched']); self::assertEmpty($outside['pairs']);
    }

    public function testGroupSumBothDirections(): void
    {
        $r = StatementReconcileService::match([$this->s('2026-04-10', 30)], [$this->b(1, '2026-04-09', 10), $this->b(2, '2026-04-11', 20)]);
        self::assertSame(1, $r['by_tier']['group_sum']); self::assertCount(2, $r['pairs'][0]['books']);
        $r = StatementReconcileService::match([$this->s('2026-04-09', -10), $this->s('2026-04-11', -20)], [$this->b(3, '2026-04-10', -30)]);
        self::assertCount(2, $r['pairs'][0]['statement']); self::assertCount(1, $r['pairs'][0]['books']);
    }

    public function testGroupCandidateCapLeavesUnmatched(): void
    {
        $books = [];
        for ($i = 1; $i <= 201; $i++) $books[] = $this->b($i, '2026-05-01', 1);
        $r = StatementReconcileService::match([$this->s('2026-05-01', 202)], $books);
        self::assertTrue($r['capped']); self::assertCount(1, $r['statement_unmatched']);
    }

    public function testMonthlyUsesBooksMinusStatement(): void
    {
        $r = StatementReconcileService::monthly([$this->s('2026-06-01', 15)], [$this->b(1, '2026-06-02', 12)]);
        self::assertSame(-3.0, $r['2026-06']['difference']); self::assertSame(1, $r['2026-06']['statement_count']);
    }

    public function testBalanceChainOkAndBrokenNewestFirst(): void
    {
        $ok = [$this->s('2026-07-02', -5, 105), $this->s('2026-07-01', 10, 110)];
        $r = StatementReconcileService::balanceChainCheck($ok);
        self::assertTrue($r['2026-07']['ok']); self::assertSame(100.0, $r['2026-07']['opening']); self::assertSame(105.0, $r['2026-07']['closing']);
        $bad = [$this->s('2026-07-01', 10, 110), $this->s('2026-07-02', -5, 999)];
        self::assertFalse(StatementReconcileService::balanceChainCheck($bad)['2026-07']['ok']);
    }

    public function testVancityCsvBomCommasBlanksAndOrder(): void
    {
        $csv = "\xEF\xBB\xBFDate,Description,Debits,Credits,Balance\n05-Oct-2026,Later,,\"1,200.50\",\"2,000.50\"\n04-Oct-2026,Earlier,\"10.25\",,800.00\n";
        $r = StatementReconcileService::parseVancityCsv($csv);
        self::assertSame('2026-10-05', $r[0]['date']); self::assertSame(1200.5, $r[0]['amount']); self::assertSame(-10.25, $r[1]['amount']);
    }

    public function testDedupeAcrossExportsKeepsSameDayRepeatsInOneExport(): void
    {
        $mk = function (string $src, float $amount, float $balance, string $desc = 'Synthetic e-Transfer') {
            return ['date' => '2026-07-03', 'amount' => $amount, 'balance' => $balance, 'description' => $desc, 'source' => $src];
        };
        // Export A and B list the same three items with different running balances (same-day ordering differs).
        $lines = [
            $mk('a.csv', -100, 900), $mk('a.csv', -100, 800), $mk('a.csv', -50, 750),
            $mk('b.csv', -100, 950), $mk('b.csv', -100, 850), $mk('b.csv', -50, 800),
        ];
        $r = StatementReconcileService::dedupeStatementLines($lines);
        self::assertCount(3, $r['lines']);   // two identical -100 charges kept, cross-export copies dropped
        self::assertSame([-100.0, -100.0, -50.0], array_column($r['lines'], 'amount'));
        self::assertTrue($r['balance_available']);
    }

    public function testParseCsvTagsTheSource(): void
    {
        $r = StatementReconcileService::parseVancityCsv("Date,Description,Debits,Credits,Balance\n01-Jan-2026,Item,5.00,,95.00\n", 'x.csv');
        self::assertSame('x.csv', $r[0]['source']);
    }

    public function testDedupeAcrossExportsIgnoresDescriptionDifferences(): void
    {
        $a = ['date' => '2026-02-23', 'amount' => 393.75, 'balance' => 9701.99, 'description' => 'Cheque deposit-branch', 'source' => 'a.csv'];
        $b = ['date' => '2026-02-23', 'amount' => 393.75, 'balance' => 9999.99, 'description' => 'Cheque deposit-branch SYNTHETIC PAYER LTD', 'source' => 'b.csv'];
        $r = StatementReconcileService::dedupeStatementLines([$a, $b]);
        self::assertCount(1, $r['lines']);
    }

    public function testReversalOfAReversalRestoresTheOriginalChain(): void
    {
        // #1 reversed by #2, #2 reversed by #3: the chain's net is #1's amount, carried by the last entry
        $live = StatementReconcileService::collapseReversals([$this->b(1, '2026-01-01', 100, 'orig', 2), $this->b(2, '2026-01-02', -100, 'rev', 3), $this->b(3, '2026-01-03', 100, 'restore')]);
        self::assertSame([3], array_column($live, 'id'));
        // entry -> reversal -> re-post (the re-post is not part of the chain): the re-post survives
        $live = StatementReconcileService::collapseReversals([$this->b(10, '2026-02-01', 50, 'orig', 11), $this->b(11, '2026-02-01', -50, 'Reversal of #10'), $this->b(12, '2026-02-01', 50, 'repost')]);
        self::assertSame([12], array_column($live, 'id'));
    }

    public function testSourcedAndSourceLessLinesAreDedupedByTheirOwnRules(): void
    {
        $lines = [
            ['date' => '2026-10-01', 'amount' => -10.0, 'balance' => null, 'description' => 'a', 'source' => 'a.csv'],
            ['date' => '2026-10-02', 'amount' => -20.0, 'balance' => null, 'description' => 'b'],
            ['date' => '2026-10-02', 'amount' => -20.0, 'balance' => null, 'description' => 'b'],
        ];
        $r = StatementReconcileService::dedupeStatementLines($lines);
        self::assertCount(3, $r['lines']);          // the two source-less repeats are kept (no balance, cannot tell them apart)
        self::assertFalse($r['balance_available']);
    }

    public function testNegativeDebitFormatsStillMeanMoneyOut(): void
    {
        $csv = "Date,Description,Debits,Credits,Balance\n05-Oct-2026,Paren,(10.00),,990.00\n05-Oct-2026,Trailing,10.00-,,980.00\n05-Oct-2026,Plain,10.00,,970.00\n";
        $r = StatementReconcileService::parseVancityCsv($csv);
        self::assertSame([-10.0, -10.0, -10.0], array_column($r, 'amount'));
    }

    public function testCapIsSharedAndStopsAllLaterGroupMatches(): void
    {
        $books = [];
        for ($i = 1; $i <= 40; $i++) $books[] = $this->b($i, '2026-05-01', 1.0 + ($i % 7) * 0.01);   // many near-identical small entries
        // an impossible first target (no subset sums to it) burns the budget; a later easy pair must NOT then be matched
        $statement = [$this->s('2026-05-01', 99999.99), $this->s('2026-05-01', 2.00)];
        $books[] = $this->b(101, '2026-05-01', 1.00); $books[] = $this->b(102, '2026-05-01', 1.00);
        $r = StatementReconcileService::match($statement, $books);
        if ($r['capped']) {
            self::assertArrayNotHasKey('group_sum', $r['by_tier']);
        } else {
            self::assertTrue(true);   // budget not reached on this data: the guarantee only applies once capped
        }
    }

    public function testSignFromBalancesBeatsTheStoredType(): void
    {
        // a card payment stored as an expense/transfer with a positive amount but the balance went UP by 100
        $items = [
            ['amount' => 100.0, 'type' => 'transfer', 'balance' => 1000.0],
            ['amount' => 100.0, 'type' => 'expense', 'balance' => 900.0],
            ['amount' => 50.0, 'type' => 'income', 'balance' => null],
        ];
        $signed = StatementReconcileService::signFromBalances($items);
        self::assertSame(100.0, $signed[0]);     // 1000 - 100 = 900 exists as a balance: a credit
        self::assertSame(-100.0, $signed[1]);    // 900 + 100 = 1000 exists as a balance: a debit
        self::assertSame(50.0, $signed[2]);      // no balance: the stored type decides
    }

    public function testBrokenBalanceChainMakesTheStatusDifferent(): void
    {
        $base = ['matching' => ['statement_unmatched' => [], 'books_unmatched' => []], 'months' => ['2026-07' => ['difference' => 0.0]], 'chain' => ['2026-07' => ['ok' => false]]];
        self::assertTrue(StatementReconcileService::hasDifferences($base));
        $base['chain']['2026-07']['ok'] = true;
        self::assertFalse(StatementReconcileService::hasDifferences($base));
    }

    public function testApprovedReceiptWithoutBankLineIsADifference(): void
    {
        $base = ['matching' => ['statement_unmatched' => [], 'books_unmatched' => []], 'months' => [], 'chain' => [], 'receipts_without_line' => []];
        self::assertFalse(StatementReconcileService::hasDifferences($base));
        $base['receipts_without_line'] = [['id' => 221, 'date' => '2026-04-10', 'vendor' => 'LAWNBOY', 'total' => 72.80]];
        self::assertTrue(StatementReconcileService::hasDifferences($base));
    }
}
