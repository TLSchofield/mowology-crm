<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pieces H, L, I, F, K of the 2026-11-03 accountancy harvest: the pure rules. */
final class HarvestPureRulesTest extends TestCase
{
    private const TODAY = '2026-11-03';

    public function testBankLineClassesAndBuckets(): void
    {
        $c = BankLineAge::classify(['transaction_date' => '2026-11-01', 'amount' => -40, 'description' => 'POS purchase'], self::TODAY);
        $this->assertSame(['timing', 'current'], [$c['class'], $c['bucket']]);
        $c = BankLineAge::classify(['transaction_date' => '2026-09-20', 'amount' => -14.95, 'description' => 'Monthly plan fee'], self::TODAY);
        $this->assertSame(['adjustment', 'aging', 44], [$c['class'], $c['bucket'], $c['age']]);
        $c = BankLineAge::classify(['transaction_date' => '2026-07-01', 'amount' => 500, 'description' => 'Deposit'], self::TODAY);
        $this->assertSame(['investigate', 'stale'], [$c['class'], $c['bucket']]);
        $s = BankLineAge::summarise([
            ['transaction_date' => '2026-07-01', 'amount' => 500, 'description' => 'Deposit'],
            ['transaction_date' => '2026-08-20', 'amount' => -75, 'description' => 'Unknown'],        // 75 d → overdue
            ['transaction_date' => '2026-09-20', 'amount' => -14.95, 'description' => 'Service charge'],
        ], self::TODAY);
        $this->assertSame(['1 bank line over 90 days unsorted ($500.00) — write off or adjust, but decide', '1 bank line 61–90 days old — needs a look this week', '1 looks like a fee, interest or reversal — a small entry each'], $s['say']);
    }

    public function testCloseItemKeyKeepsAccountCodesButNotAmountsOrCounts(): void
    {
        $this->assertSame(MonthCloseListService::itemKey('3 bank lines still to sort'), MonthCloseListService::itemKey('5 bank lines still to sort'));
        $this->assertSame(MonthCloseListService::itemKey('1010 Chequing Account: books are $7.01 above the bank'), MonthCloseListService::itemKey('1010 Chequing Account: books are $533.69 above the bank'));
        $this->assertNotSame(MonthCloseListService::itemKey('1010 Chequing Account: books are $7.01 above the bank'), MonthCloseListService::itemKey('2400 Credit Card Payable: books are $7.01 above the bank'));
    }

    public function testPulseBandsAndAbsentIsNotGreen(): void
    {
        $this->assertSame('green', PulseBands::runway(60000, 9000)['band']);
        $this->assertSame('yellow', PulseBands::runway(30000, 9000)['band']);
        $this->assertSame('red', PulseBands::runway(20000, 9000)['band']);
        $this->assertSame('na', PulseBands::runway(20000, null)['band']);
        $this->assertSame(['green', 'yellow', 'red'], [PulseBands::revenue(100, 100)['band'], PulseBands::revenue(96, 100)['band'], PulseBands::revenue(90, 100)['band']]);
        $ar = PulseBands::ar([['number' => 'INV-1', 'who' => 'A', 'balance' => 900, 'days_past_due' => 10], ['number' => 'INV-2', 'who' => 'B', 'balance' => 100, 'days_past_due' => 45]]);
        $this->assertSame('yellow', $ar['band']);
        $this->assertSame(['INV-2 · B — $100 (45 days)'], $ar['named']);
        $ar = PulseBands::ar([['number' => 'INV-1', 'who' => 'A', 'balance' => 900, 'days_past_due' => 10], ['number' => 'INV-3', 'who' => 'C', 'balance' => 101, 'days_past_due' => 70]]);
        $this->assertSame('red', $ar['band']);          // 70 days
        $this->assertSame('green', PulseBands::ar([])['band']);   // read and empty is green; n/a is for a failed read
        $this->assertSame('yellow', PulseBands::overall(['green', 'yellow', 'na']));
        $this->assertSame('red', PulseBands::overall(['green'], true));
    }

    public function testJournalChecklist(): void
    {
        $ok = ['entry_date' => '2026-11-02', 'memo' => 'Bank fee October statement, 1010 to 6800', 'lines' => [['account' => '6800', 'debit' => 14.95, 'credit' => 0], ['account' => '1010', 'debit' => 0, 'credit' => 14.95]]];
        $this->assertSame([], JournalEntryCheck::check($ok, [], ['2026-09'], self::TODAY));
        $bad = ['entry_date' => '2026-08-15', 'memo' => 'accrual', 'lines' => [['account' => '', 'debit' => 1000, 'credit' => 0], ['account' => '2100', 'debit' => 0, 'credit' => 900]]];
        $w = JournalEntryCheck::check($bad, [], ['2026-08'], self::TODAY);
        $joined = implode("\n", $w);
        foreach (['no account', 'do not balance', 'memo is too short', '2026-08 is locked', '80 days back', 'Round amounts ($1,000)', 'should say when it reverses'] as $needle) $this->assertStringContainsString($needle, $joined);
        $dup = JournalEntryCheck::check($ok, [['entry_date' => '2026-11-02', 'lines' => [['account' => '1010', 'debit' => 0, 'credit' => 14.95], ['account' => '6800', 'debit' => 14.95, 'credit' => 0]]]], [], self::TODAY);
        $this->assertSame(['An identical entry (same date, accounts and amounts) is already posted — this looks like a duplicate.'], $dup);
    }

    public function testNarrativeHasSixPartsAndNamesMoves(): void
    {
        $cur = ['income' => 20000, 'expenses' => 15000, 'lines' => ['Landscaping' => 18000, 'Snow' => 2000, 'Fuel' => -1500, 'Wages' => -10000, 'Repairs' => -3500]];
        $prior = ['income' => 18000, 'expenses' => 14000, 'lines' => ['Landscaping' => 18000, 'Fuel' => -1200, 'Wages' => -10000, 'Repairs' => -2800]];
        $t = CloseNarrative::write('2026-10', $cur, $prior, ['3 bank lines still to sort', 'waiting until 2026-11-15 for late statements']);
        $this->assertStringStartsWith('October 2026 came in at CAD 5,000 net, up 25% from the month before.', $t);
        $this->assertStringContainsString('Income was CAD 20,000, up 11% from last month; the biggest line was Landscaping at CAD 18,000.', $t);
        $this->assertStringContainsString('Fuel rose 25% to CAD 1,500 (from 1,200)', $t);
        $this->assertStringContainsString('Repairs rose 25% to CAD 3,500 (from 2,800)', $t);
        $this->assertStringNotContainsString('Wages', $t);                                   // unchanged line earns no sentence
        $this->assertStringContainsString('Bottom line: a profit of CAD 5,000 before tax.', $t);
        $this->assertStringContainsString('Still open before this month is exact: 3 bank lines still to sort.', $t);
        $words = str_word_count($t);
        $this->assertGreaterThan(60, $words); $this->assertLessThan(250, $words);
        $this->assertStringContainsString('no earlier month to compare', CloseNarrative::write('2026-01', $cur, null));
        $flip = CloseNarrative::write('2026-10', ['income' => 20000, 'expenses' => 25849, 'lines' => []], ['income' => 18000, 'expenses' => 15200, 'lines' => []]);
        $this->assertStringStartsWith('October 2026 came in at CAD -5,849 net, from CAD 2,800 the month before.', $flip);   // no "down 308%"
        $two = CloseNarrative::write('2026-10', ['income' => 20000, 'expenses' => 25849, 'lines' => []], ['income' => 18000, 'expenses' => 19400, 'lines' => []]);
        $this->assertStringStartsWith('October 2026 came in at CAD -5,849 net, a deeper loss than CAD 1,400 the month before.', $two);
    }

    public function testInstalmentNoteStatesYearAssumptionsAndMissedPayments(): void
    {
        $n = TaxInstalmentNote::build(2026, 30000, 10, true, [['due' => '2026-09-30', 'amount' => 2000, 'paid_on' => '2026-09-28'], ['due' => '2026-10-31', 'amount' => 2000, 'paid_on' => null]], 0.11, self::TODAY);
        $this->assertSame(36000.0, $n['annualised']);
        $this->assertSame(1, $n['missed']);
        foreach (['Tax year 2026', 'seasonal business', 'CAD 3,960 for the year', 'rate from Settings', 'Missed instalment: CAD 2,000 was due 2026-10-31', 'not for this note to guess', 'GST is outside'] as $needle) $this->assertStringContainsString($needle, $n['text']);
        $this->assertStringContainsString('No tax rate on file — the tax figure is not estimated.', TaxInstalmentNote::build(2026, 1000, 2, false, [], null, self::TODAY)['text']);
    }
}
