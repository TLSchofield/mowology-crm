<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's statements check: walk the running balance, tell a missing line from another
 * account's line, fall back to counts without balances, month status, brief items.
 */
class StatementCoverageServiceTest extends TestCase
{
    private int $id = 0;

    /** A stored TD-style CSV line: date, desc, debit, credit, balance. */
    private function csv(int $session, string $date, float $signed, ?float $balance, bool $dup = false): array
    {
        $amt = number_format(abs($signed), 2, '.', '');
        $line = $date . ',LINE ' . (++$this->id) . ',' . ($signed < 0 ? $amt : '') . ',' . ($signed > 0 ? $amt : '')
              . ($balance !== null ? ',' . number_format($balance, 2, '.', '') : ',');
        $raw = ['date' => $date, 'amount' => abs($signed), 'type' => $signed > 0 ? 'income' : 'expense', 'raw_line' => $line];
        return StatementCoverageService::lineFromRow(
            ['id' => $this->id, 'session_id' => $session, 'transaction_date' => $date, 'type' => $raw['type'], 'amount' => abs($signed), 'is_duplicate' => $dup ? 1 : 0],
            $raw
        );
    }

    /** Lines that chain from $open, one per entry [date, signed]. */
    private function chain(int $session, float $open, array $entries): array
    {
        $out = [];
        $bal = $open;
        foreach ($entries as [$d, $s]) {
            $bal = round($bal + $s, 2);
            $out[] = $this->csv($session, $d, $s, $bal);
        }
        return $out;
    }

    public function test_balance_is_read_from_csv_and_pdf_lines(): void
    {
        $this->assertSame(1234.56, StatementCoverageService::parseBalance('2026-02-03,SHELL,45.00,,1234.56', 45.0));
        $this->assertSame(10.0, StatementCoverageService::parseBalance('01-Jan-2026,Test,5.00,,10.00', 5.0), 'Vancity Debits,Credits,Balance export');
        $this->assertSame(1050.0, StatementCoverageService::parseBalance('09/02/2025,E-TRANSFER DEP,,100.00,1050.00', 100.0), 'TD EasyWeb headerless CSV');
        $this->assertSame(950.0, StatementCoverageService::parseBalance('01 MAY POS A 50.00 950.00', 50.0), '3-column PDF line');
        $this->assertSame(1234.56, StatementCoverageService::parseBalance('"19-Mar-2026","Preauthorized credit DORSET",,409.92,"1,234.56"', 409.92));
        $this->assertNull(StatementCoverageService::parseBalance('2026-02-03,SHELL,45.00,', 45.0), 'no balance column');
        $this->assertNull(StatementCoverageService::parseBalance('2026-02-03,SHELL,-45.00', 45.0), 'single amount column');
        $this->assertSame(20145.33, StatementCoverageService::parseBalance('FEB 03 POINT OF SALE SHELL 45.00 20,145.33', 45.0));
        $this->assertSame(-120.5, StatementCoverageService::parseBalance('FEB 03 CHEQUE 0042 300.00 120.50-', 300.0), 'overdrawn');
        $this->assertNull(StatementCoverageService::parseBalance('FEB 03 POINT OF SALE SHELL 45.00', 45.0));
    }

    public function test_clean_chain_has_no_breaks(): void
    {
        $lines = $this->chain(1, 1000, [['2026-02-02', -45], ['2026-02-03', 500], ['2026-02-05', -12.34], ['2026-02-09', -100]]);
        $r = StatementCoverageService::walkChain(StatementCoverageService::orderLines($lines));
        $this->assertSame([], $r['breaks']);
        $this->assertSame([], $r['other_account']);
        $this->assertSame(3, $r['linked']);
    }

    public function test_chain_break_names_dates_and_missing_amount(): void
    {
        $a = $this->chain(1, 1000, [['2026-02-10', -45], ['2026-02-12', -100]]);       // ends at 855
        // 13–18 Feb never imported: the next statement line opens from 855 − 1,240 = −385
        $b = $this->chain(2, -385, [['2026-02-19', 200], ['2026-02-20', -50]]);
        $r = StatementCoverageService::walkChain(StatementCoverageService::orderLines(array_merge($a, $b)));
        $this->assertCount(1, $r['breaks']);
        $br = $r['breaks'][0];
        $this->assertSame('2026-02-12', $br['from']);
        $this->assertSame('2026-02-19', $br['to']);
        $this->assertSame(-1240.0, $br['missing']);
        $this->assertSame('TD chequing: 12–19 Feb, $1,240 missing between balances',
            StatementCoverageService::breakText('TD chequing', $br, '2026-10-07'));
    }

    public function test_lines_chaining_to_another_balance_are_another_account_not_a_gap(): void
    {
        // Vancity multi-account statement: chequing lines, then 3 lines of a small second account.
        $main1 = $this->chain(1, 20000, [['2025-12-01', -100], ['2025-12-03', 2500], ['2025-12-05', -40]]);
        $other = $this->chain(1, 8.59, [['2025-12-06', 0.01], ['2025-12-15', 5.00], ['2025-12-20', -2.00]]);
        $main2 = $this->chain(1, 22360, [['2025-12-08', -60], ['2025-12-18', -300], ['2025-12-29', 900]]);
        $r = StatementCoverageService::walkChain(StatementCoverageService::orderLines(array_merge($main1, $other, $main2)));
        $this->assertSame([], $r['breaks']);
        $this->assertCount(1, $r['other_account']);
        $this->assertSame(3, $r['other_account'][0]['lines']);
    }

    public function test_overlapping_reimport_counts_each_line_once(): void
    {
        $a = $this->chain(1, 500, [['2026-03-01', -10], ['2026-03-02', -20], ['2026-03-03', -30]]);
        // Session 2 re-covers 2–3 March (stored as duplicates) and carries on.
        $b = [$this->csv(2, '2026-03-02', -20, 470, true), $this->csv(2, '2026-03-03', -30, 440, true), $this->csv(2, '2026-03-04', -40, 400)];
        $ordered = StatementCoverageService::orderLines(array_merge($a, $b));
        $this->assertCount(4, $ordered);
        $this->assertSame([], StatementCoverageService::walkChain($ordered)['breaks']);
    }

    public function test_newest_first_export_is_walked_backwards(): void
    {
        $lines = array_reverse($this->chain(1, 1000, [['2026-04-01', -10], ['2026-04-02', -20], ['2026-04-03', 300]]));
        // ids in the session follow file order (newest first)
        foreach ($lines as $k => $l) $lines[$k]['id'] = $k + 1;
        $r = StatementCoverageService::walkChain(StatementCoverageService::orderLines($lines));
        $this->assertSame([], $r['breaks']);
    }

    public function test_no_balance_falls_back_to_quiet_days_and_count_drops(): void
    {
        $lines = [];
        foreach (['2026-01', '2026-02', '2026-03', '2026-04', '2026-05'] as $m) {
            $n = $m === '2026-03' ? 3 : 20;
            for ($d = 1; $d <= $n; $d++) {
                $day = $m === '2026-03' ? $d : min(28, $d);
                $lines[] = $this->csv(1, sprintf('%s-%02d', $m, $day), -10, null);
            }
        }
        $gaps = StatementCoverageService::fallbackGaps($lines, '2026-06-10');
        $types = array_column($gaps, 'type');
        $this->assertContains('quiet', $types, '4 March → 1 April has no lines');
        $drop = array_values(array_filter($gaps, fn($g) => $g['type'] === 'drop'));
        $this->assertSame('2026-03', $drop[0]['month']);

        $report = StatementCoverageService::analyse([$this->group('a5', 'Vancity chequing', 'bank', true)], ['a5' => $lines], '2026-06-10');
        $this->assertSame('counts', $report['accounts'][0]['method']);
        $this->assertNotEmpty($report['accounts'][0]['possible_gaps']);
        $this->assertStringContainsString('possible gap', StatementCoverageService::possibleGapText('Vancity', $drop[0]));
    }

    public function test_month_status(): void
    {
        $sept = $this->chain(1, 100, [['2026-09-02', -10], ['2026-09-29', -10]]);
        $this->assertSame('ok', StatementCoverageService::monthStatus($sept, '2026-09', null)['status']);
        $this->assertSame('partial', StatementCoverageService::monthStatus(array_slice($sept, 0, 1), '2026-09', null)['status']);
        $this->assertSame('missing', StatementCoverageService::monthStatus($this->chain(1, 100, [['2026-08-20', -1]]), '2026-09', null)['status']);
        $this->assertSame('never', StatementCoverageService::monthStatus([], '2026-09', null)['status']);
        // Card closing on the 20th: 21 Aug – 20 Sep is "September".
        $card = $this->chain(1, 0, [['2026-08-22', 5], ['2026-09-18', 5]]);
        $this->assertSame('ok', StatementCoverageService::monthStatus($card, '2026-09', 20)['status']);
        $gap = StatementCoverageService::monthStatus($sept, '2026-09', null, [['from' => '2026-09-02', 'to' => '2026-09-29', 'missing' => -5]]);
        $this->assertSame('gap', $gap['status']);
    }

    public function test_expected_card_never_imported(): void
    {
        $td = $this->chain(1, 1000, [['2026-09-01', -10], ['2026-09-30', -10]]);
        $report = StatementCoverageService::analyse([
            $this->group('a1', 'TD chequing', 'bank', true),
            $this->group('a9', 'Visa', 'card', true),
        ], ['a1' => $td], '2026-10-07');
        $by = array_column($report['accounts'], null, 'label');
        $this->assertSame('ok', $by['TD chequing']['last_month']['status']);
        $this->assertSame('never', $by['Visa']['last_month']['status']);
        $this->assertSame('none', $by['Visa']['method']);
        $strip = StatementCoverageService::strip($report);
        $this->assertSame('September', $strip['month']);
        $visa = array_values(array_filter($strip['accounts'], fn($a) => $a['label'] === 'Visa'))[0];
        $this->assertFalse($visa['ok']);
        $this->assertSame('never imported', $visa['note']);
    }

    public function test_brief_item_only_from_the_third(): void
    {
        $report = StatementCoverageService::analyse([
            $this->group('a1', 'TD chequing', 'bank', true),
            $this->group('a9', 'Visa', 'card', true),
            $this->group('a3', 'Old savings', 'bank', false),
        ], ['a1' => $this->chain(1, 1000, [['2026-09-01', -10], ['2026-09-30', -10]])], '2026-10-02');
        $this->assertSame([], StatementCoverageService::briefItems($report, '2026-10-02'));

        $report['date'] = '2026-10-03';
        $items = StatementCoverageService::briefItems($report, '2026-10-03');
        $this->assertCount(1, $items, 'only the expected account that is not in; TD is in, savings is not expected');
        $it = $items[0];
        foreach (['key', 'kind', 'value', 'since', 'text', 'url', 'priority'] as $k) $this->assertArrayHasKey($k, $it);
        $this->assertSame('penny:statement:a9:2026-09', $it['key']);
        $this->assertSame('2026-10-03', $it['since']);
        $this->assertStringContainsString('Visa has never been imported', $it['text']);

        // A report from last month is stale: no items rather than wrong ones.
        $this->assertSame([], StatementCoverageService::briefItems($report, '2026-11-05'));
    }

    public function test_future_dated_lines_are_counted_not_walked(): void
    {
        $lines = $this->chain(1, 100, [['2026-09-02', -10], ['2026-09-29', -10]]);
        $lines[] = $this->csv(3, '2026-12-05', -99, 5000);
        $report = StatementCoverageService::analyse([$this->group('a1', 'Vancity', 'bank', true)], ['a1' => $lines], '2026-10-07');
        $this->assertSame(1, $report['accounts'][0]['future_lines']);
        $this->assertSame([], $report['accounts'][0]['breaks']);
    }

    private function group(string $key, string $label, string $kind, bool $expected, ?int $day = null): array
    {
        return ['key' => $key, 'account_id' => (int)substr($key, 1), 'list_id' => 1, 'label' => $label, 'kind' => $kind,
                'expected' => $expected, 'statement_day' => $day, 'sessions' => []];
    }
}
