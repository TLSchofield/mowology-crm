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

    /** 31 Mar 2026: a fee from the PDF and three lines from a CSV, one day — ordered by their balances (2026-10-13). */
    public function test_lines_from_two_imports_on_one_day_chain_in_balance_order(): void
    {
        $l = fn($id, $s, $d, $signed, $bal) => ['id' => $id, 'session' => $s, 'date' => $d, 'amount' => abs($signed),
                                                'signed' => $signed, 'balance' => $bal, 'duplicate' => false];
        $lines = [
            $l(1, 42, '2026-03-30', -760.17, 18879.97),
            $l(2, 3, '2026-03-31', -46.50, 19203.36),     // the PDF's fee, last of the day at the bank
            $l(3, 42, '2026-03-31', -13.80, 18866.17),
            $l(4, 42, '2026-03-31', 399.00, 19265.17),
            $l(5, 42, '2026-03-31', -15.31, 19249.86),
            $l(6, 16, '2026-04-01', -16.00, 19187.36),
        ];
        $ordered = StatementCoverageService::orderLines($lines);
        $this->assertSame([1, 3, 4, 5, 2, 6], array_column($ordered, 'id'));
        $walk = StatementCoverageService::walkChain($ordered);
        $this->assertSame([], $walk['breaks']);
        $this->assertSame([], $walk['other_account']);
    }

    /** 3 Jul 2026: chargebacks dated 3 Jul posted on 6 Jul — the chain follows posting order (2026-10-13). */
    public function test_backdated_lines_chain_where_the_bank_posted_them(): void
    {
        $l = fn($id, $d, $signed, $bal) => ['id' => $id, 'session' => 1, 'date' => $d, 'amount' => abs($signed),
                                            'signed' => $signed, 'balance' => $bal, 'duplicate' => false];
        $lines = [
            $l(1, '2026-07-03', -13.34, 4909.10),
            $l(2, '2026-07-03', -7.50, 4848.15),
            $l(3, '2026-07-03', -456.84, 4391.31),
            $l(4, '2026-07-06', 76.12, 4985.22),
            $l(5, '2026-07-06', -129.57, 4855.65),
            $l(6, '2026-07-06', -33.00, 4358.31),
            $l(7, '2026-07-06', -2480.82, 1877.49),
        ];
        $ordered = StatementCoverageService::orderLines($lines);
        $this->assertSame([1, 4, 5, 2, 3, 6, 7], array_column($ordered, 'id'));
        $this->assertSame([], StatementCoverageService::walkChain($ordered)['breaks']);
    }

    /** 30 Mar 2026: −6.47, refund +6.47, −6.47 again — the third line has the first's key and is real. */
    public function test_identical_lines_in_one_statement_are_both_kept(): void
    {
        $l = fn($id, $s, $signed, $bal) => ['id' => $id, 'session' => $s, 'date' => '2026-03-30', 'amount' => abs($signed),
                                            'signed' => $signed, 'balance' => $bal, 'duplicate' => false];
        $lines = [$l(1, 3, -6.47, 19640.14), $l(2, 3, 6.47, 19646.61), $l(3, 3, -6.47, 19640.14)];
        $ordered = StatementCoverageService::orderLines(array_merge([$l(0, 3, -10.0, 19646.61)], $lines));
        $this->assertCount(4, $ordered);
        $this->assertSame([], StatementCoverageService::walkChain($ordered)['breaks'], 'no false "$6.47 missing"');
        // A later import of the same day: copies only up to the count already in.
        $again = [$l(11, 7, -6.47, 19640.14), $l(12, 7, 6.47, 19646.61), $l(13, 7, -6.47, 19640.14)];
        $this->assertCount(4, StatementCoverageService::orderLines(array_merge([$l(0, 3, -10.0, 19646.61)], $lines, $again)));
    }

    /** Reserve funds ••6819: no lines since 2025, but the monthly statement printed it with no activity. */
    public function test_a_statement_section_with_no_lines_is_in_with_no_activity(): void
    {
        $old = $this->chain(1, 0.6, [['2025-11-28', 0.09]]);   // last line: 28 Nov 2025, balance 0.69
        $sep = ['from' => '2026-09-01', 'to' => '2026-09-30', 'lines' => 0, 'opening' => 0.69, 'closing' => 0.69];
        $st = StatementCoverageService::monthStatus($old, '2026-09', null, [], [$sep]);
        $this->assertSame('quiet', $st['status']);
        $this->assertSame(0.69, $st['balance']);
        $this->assertSame('missing', StatementCoverageService::monthStatus($old, '2026-09', null)['status'], 'without the section: not imported');
        $this->assertSame('quiet', StatementCoverageService::monthStatus([], '2026-09', null, [], [$sep])['status'], 'never had a line, statement says quiet');
        $this->assertSame('missing', StatementCoverageService::monthStatus($old, '2026-09', null, [], [['lines' => 2] + $sep])['status'], 'the statement printed lines the CRM lacks');
        // Lines stopping early are fine when the statement runs to the month end.
        $gst = $this->chain(1, 8.63, [['2026-09-10', 0.01]]);
        $this->assertSame('partial', StatementCoverageService::monthStatus($gst, '2026-09', null)['status']);
        $this->assertSame('ok', StatementCoverageService::monthStatus($gst, '2026-09', null, [], [['lines' => 1] + $sep])['status']);

        $sections = [];
        foreach (['01' => 31, '02' => 28, '03' => 31, '09' => 30] as $m => $d) {
            $sections[] = ['from' => "2026-$m-01", 'to' => "2026-$m-$d", 'lines' => 0, 'opening' => 0.69, 'closing' => 0.69];
        }
        $report = StatementCoverageService::analyse([$this->group('a3', 'Reserve funds', 'bank', true)], ['a3' => $old], '2026-10-13', ['a3' => $sections]);
        $a = $report['accounts'][0];
        $this->assertSame('quiet', $a['last_month']['status']);
        $this->assertSame([], $a['breaks'], 'quiet months that open at the last known balance are not a break');
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-09'], array_keys($a['quiet_months']));
        $strip = StatementCoverageService::strip($report);
        $this->assertTrue($strip['accounts'][0]['ok']);
        $this->assertSame('no activity', $strip['accounts'][0]['note']);
        $this->assertSame([], StatementCoverageService::briefItems($report, '2026-10-13'));

        // A quiet month that opens away from the last balance: lines are missing before it.
        $sections[0]['opening'] = 3003.15; $sections[0]['closing'] = 3003.15;
        $report = StatementCoverageService::analyse([$this->group('a3', 'Reserve funds', 'bank', true)], ['a3' => $old], '2026-10-13', ['a3' => [$sections[0]]]);
        $b = $report['accounts'][0]['breaks'];
        $this->assertCount(1, $b);
        $this->assertSame(['from' => '2025-11-28', 'to' => '2026-01-01', 'missing' => 3002.46, 'before' => 0.69, 'after' => 3003.15], $b[0]);
    }

    /** FY2025 is filed and locked: its differences are summed into one quiet line, not listed. */
    public function test_closed_year_breaks_are_one_quiet_line(): void
    {
        $lines = array_merge(
            $this->chain(1, 1000, [['2025-06-02', -10]]), $this->chain(2, 900, [['2025-07-01', -5]]),   // −90 missing
            $this->chain(3, 880, [['2025-08-01', -5]]),                                                   // −15 missing
            $this->chain(4, 800, [['2026-02-02', -10]])                                                   // −75 missing, open year
        );
        $report = StatementCoverageService::analyse([$this->group('a1', 'Chequing', 'bank', true)], ['a1' => $lines], '2026-03-05');
        $a = $report['accounts'][0];
        $this->assertCount(1, $a['breaks'], 'only the open year is listed');
        $this->assertSame('2026-02-02', $a['breaks'][0]['to']);
        $this->assertCount(2, $a['closed_breaks'], 'kept in the data');
        $this->assertSame('FY2025 (closed): 2 small differences, net −$105.00 — not checked', $a['closed_text']);
        $strip = StatementCoverageService::strip($report);
        $this->assertCount(1, $strip['gaps']);
        $this->assertSame('2026-01-01', $report['open_from']);
        // A later year locked in full moves the line.
        $later = StatementCoverageService::analyse([$this->group('a1', 'Chequing', 'bank', true)], ['a1' => $lines], '2026-03-05', [], '2027-01-01');
        $this->assertSame([], $later['accounts'][0]['breaks']);
        $this->assertStringStartsWith('FY2025–FY2026 (closed): 3 small differences', $later['accounts'][0]['closed_text']);
    }
}
