<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BankLineMoveServiceTest.php';   // its schema()

/**
 * The Vancity savings accounts' lines out of 1010 Chequing (2026-10-07): the balance chains
 * are told apart, a chequing ↔ savings transfer printed in both sections becomes one transfer,
 * the books are corrected append-only (reverse + repost), locked months are left alone, undo
 * puts it all back. Plus the importer's section headers and migration 1234's renames.
 *
 * Fixture modelled on the live evidence: chequing ~$19K with March lines, then chequing
 * missing April–June; a GST Reserves chain at ~$8.6 earning $0.01 a month, printed on the same
 * statements, with a $500 set-aside in and back out in March.
 */
class BankAccountSplitServiceTest extends TestCase
{
    // chart ids
    private const BANK = 1, ITC = 2, GSTC = 3, MISC = 4, OTHER = 5, RESERVE = 20, GSTRES = 25;

    /** [session, date, desc, signed, balance, category] — chequing and GST Reserves interleaved. */
    private const FIXTURE = [
        // March statement: chequing section …
        [1, '2026-03-03', 'E-TRANSFER DORSET', 1500.00, 19500.00, self::OTHER],
        [1, '2026-03-05', 'FUNDS TRANSFER - ONLINE TO # 10100058186827', -500.00, 19000.00, self::MISC],
        [1, '2026-03-18', 'STRIPE PAYOUT', 300.00, 19300.00, self::OTHER],
        [1, '2026-03-20', 'FUNDS TRANSFER - ONLINE FROM # 10100058186827', 500.00, 19800.00, self::OTHER],
        [1, '2026-03-31', 'POINT OF SALE SHELL', -550.14, 19249.86, self::MISC],
        // … then the GST RESERVES section of the same statement
        [1, '2026-03-05', 'FUNDS TRANSFER - ONLINE FROM # 10100058186801', 500.00, 508.58, self::OTHER],
        [1, '2026-03-20', 'FUNDS TRANSFER - ONLINE TO # 10100058186801', -500.00, 8.58, self::MISC],
        [1, '2026-03-31', 'CREDIT INTEREST', 0.01, 8.59, self::OTHER],
        // April and May: only the savings interest came in (chequing statements missing)
        [2, '2026-04-30', 'CREDIT INTEREST', 0.01, 8.60, self::OTHER],
        [3, '2026-05-31', 'CREDIT INTEREST', 0.01, 8.61, self::OTHER],
        // July: chequing again
        [4, '2026-07-03', 'POS HOME DEPOT', -100.00, 4848.15, self::MISC],
        [4, '2026-07-10', 'E-TRANSFER XYZ', 200.00, 5048.15, self::OTHER],
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Pure: the chain split
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_savings_chain_is_separated_and_chequing_links_except_the_real_gap(): void
    {
        $r = BankAccountSplitService::split($this->pureLines());

        // Two segments besides chequing: the savings one, and July chequing (after the missing months).
        $this->assertCount(2, $r['chains']);
        [$c, $july] = $r['chains'];
        $this->assertSame('keep', $july['suggest'], 'chequing activity after a gap is never joined to anything');
        $this->assertSame('strong', $july['confidence']);
        $this->assertSame(5, $c['count']);
        $this->assertSame('2026-03-05', $c['from']);
        $this->assertSame('2026-05-31', $c['to']);
        $this->assertSame(8.58, $c['min_balance']);
        $this->assertSame(508.58, $c['max_balance']);
        $this->assertSame(8.58, $c['opening']);
        $this->assertSame(8.61, $c['closing']);
        $this->assertSame('1020', $c['suggest'], 'transfers naming #…6801, in and out → Reserve funds guess');
        $this->assertSame('strong', $c['confidence']);
        $this->assertSame(2, $c['evidence']['savings_transfer']);

        // Without it, chequing links except where chequing statements really are missing.
        $this->assertSame([], $r['after']['other_account']);
        $this->assertCount(1, $r['after']['breaks']);
        $this->assertSame('2026-03-31', $r['after']['breaks'][0]['from']);
        $this->assertSame('2026-07-03', $r['after']['breaks'][0]['to']);
        $this->assertSame(19249.86, $r['after']['breaks'][0]['before']);
        $this->assertFalse($r['after']['end_to_end']);
        // Before: the $8.60 / $8.61 lines broke chequing's chain (the live "19,249.86 → 8.60").
        $this->assertNotEmpty($r['before']['other_account']);
    }

    public function test_each_transfer_is_paired_with_its_chequing_twin(): void
    {
        $r = BankAccountSplitService::split($this->pureLines());
        $mirrors = [];
        foreach ($r['chains'][0]['lines'] as $l) {
            if (!empty($l['mirror'])) $mirrors[$l['description']] = $l['mirror']['description'];
        }
        $this->assertSame(['FUNDS TRANSFER - ONLINE FROM # 10100058186801' => 'FUNDS TRANSFER - ONLINE TO # 10100058186827',
                           'FUNDS TRANSFER - ONLINE TO # 10100058186801' => 'FUNDS TRANSFER - ONLINE FROM # 10100058186827'], $mirrors);
    }

    public function test_statement_named_lines_go_by_their_account_not_by_guess(): void
    {
        $lines = $this->pureLines();
        foreach ($lines as &$l) if ($l['description'] === 'CREDIT INTEREST') $l['marker'] = '6827';
        unset($l);
        $r = BankAccountSplitService::split($lines);
        $named = array_values(array_filter($r['chains'], fn($c) => $c['confidence'] === 'statement'));
        $this->assertCount(1, $named);
        $this->assertSame('1025', $named[0]['suggest']);
        $this->assertSame('6827', $named[0]['marker']);
        $this->assertSame(3, $named[0]['count']);
    }

    public function test_guess(): void
    {
        $l = fn(float $signed) => ['amount' => abs($signed), 'signed' => $signed, 'dir' => $signed >= 0 ? 1 : -1, 'balance' => 1.0];
        $this->assertSame('1025', BankAccountSplitService::guess([$l(0.01), $l(0.01)])[0], 'interest credits only → GST Reserves');
        $this->assertSame('1025', BankAccountSplitService::guess([$l(500), $l(750), $l(0.01)])[0], 'set-asides in round amounts');
        $this->assertSame('1020', BankAccountSplitService::guess([$l(500), $l(-200)])[0], 'money out too → Reserve funds');
        $this->assertSame('1020', BankAccountSplitService::guess([$l(123.45), $l(67.89)])[0], 'not round → Reserve funds');
    }

    public function test_two_savings_chains_stay_apart(): void
    {
        // A Reserve funds chain at ~$1,000 alongside the $8.6 GST chain.
        $lines = $this->pureLines();
        $id = 100;
        foreach ([['2026-03-10', -400.00, 600.69], ['2026-03-25', 0.05, 600.74]] as [$d, $s, $b]) {
            $lines[] = $this->line(++$id, 1, $d, 'FUNDS TRANSFER TO # 10100058186801 ' . $id, $s, $b);
        }
        $r = BankAccountSplitService::split($lines);
        $moving = array_values(array_filter($r['chains'], fn($c) => $c['suggest'] !== 'keep'));
        $this->assertSame([5, 2], array_map(fn($c) => $c['count'], $moving));
        $this->assertSame('1020', $moving[1]['suggest'], 'money out of it → Reserve funds');
    }

    public function test_live_case_segments_are_never_joined_across_a_gap(): void
    {
        // 2026-10-07 live dry run: a Nov-2025 savings segment (opens $0.05, funded from chequing)
        // got joined to 500+ Mar–Jul 2026 chequing lines by balance size. Each segment alone now.
        $id = 0; $lines = [];
        foreach ([['2025-11-05', 'FUNDS TRANSFER - ONLINE FROM # 10100058186801 ($ 1,500.00)', 1500.00, 1500.05],
                  ['2025-11-20', 'FUNDS TRANSFER - ONLINE FROM # 10100058186801', 2503.10, 4003.15],
                  ['2025-11-30', 'FUNDS TRANSFER - ONLINE TO # 10100058186801 ($ 1,000.00)', -1000.00, 3003.15]] as [$d, $desc, $sg, $b]) {
            $lines[] = $this->line(++$id, 10, $d, $desc, $sg, $b);
        }
        foreach ([['2026-03-03', 'STRIPE PAYOUT', 400.00, 21665.57], ['2026-03-10', 'ICBC PAYMENT', -1200.00, 20465.57],
                  ['2026-03-18', 'PREAUTHORIZED DEBIT WAVE', -11081.51, 9384.06]] as [$d, $desc, $sg, $b]) {
            $lines[] = $this->line(++$id, 11, $d, $desc, $sg, $b);
        }
        foreach ([['2026-03-24', 'E-TRANSFER CREDIT', 300.00, 10555.29], ['2026-03-26', 'POINT OF SALE SHELL', -55.29, 10500.00],
                  ['2026-03-27', 'STRIPE PAYOUT', 250.00, 10750.00], ['2026-03-30', 'ICBC', -150.00, 10600.00]] as [$d, $desc, $sg, $b]) {
            $lines[] = $this->line(++$id, 12, $d, $desc, $sg, $b);
        }
        $r = BankAccountSplitService::split($lines);
        $this->assertSame('2026-03-24', $r['main_segment']['from'], 'the largest chequing-looking segment is chequing');
        $bySuggest = [];
        foreach ($r['chains'] as $c) $bySuggest[$c['from']] = [$c['suggest'], $c['count']];
        $this->assertSame(['2025-11-05' => ['1020', 3], '2026-03-03' => ['keep', 3]], $bySuggest,
            'savings segment suggested to move; the other chequing segment stays');
        $this->assertSame(0.05, $r['chains'][0]['opening']);
    }

    public function test_membership_shares_are_never_moved(): void
    {
        $lines = $this->pureLines();
        $lines[] = $this->line(200, 9, '2025-03-27', 'CLASS B MEMBERSHIP SHARES DIVIDEND', 0.29, 7.29);
        $r = BankAccountSplitService::split($lines);
        $shares = array_values(array_filter($r['chains'], fn($c) => $c['suggest'] === 'exclude'));
        $this->assertCount(1, $shares);
        $this->assertSame(1, $shares[0]['count']);
        $this->assertSame(7.29, $shares[0]['max_balance']);
        foreach ($r['chains'] as $c) {
            if ($c['suggest'] !== 'exclude') $this->assertNotContains(200, array_column($c['lines'], 'id'), 'not joined to the $8.6 GST chain');
        }
    }

    public function test_parallel_series_names_the_same_lines_read_twice(): void
    {
        // March read by two imports: same lines, balances $3,883.16 apart.
        $a = []; $b = []; $bal = 19000.0;
        foreach ([['2026-03-24', 120.00], ['2026-03-25', -40.00], ['2026-03-27', 300.00], ['2026-03-31', -80.00]] as $i => [$d, $sg]) {
            $bal = round($bal + $sg, 2);
            $a[] = $this->line(300 + $i, 42, $d, 'LINE ' . $i, $sg, $bal);
            $b[] = $this->line(400 + $i, 43, $d, 'LINE ' . $i, $sg, round($bal + 3883.16, 2));
        }
        $p = BankAccountSplitService::parallelSeries(BankAccountSplitService::segment(StatementCoverageService::orderLines(array_merge($a, $b))));
        $this->assertCount(1, $p);
        $this->assertTrue($p[0]['constant']);
        $this->assertSame(4, $p[0]['shared']);
        $this->assertEqualsWithDelta(3883.16, abs((float)$p[0]['offset']), 0.001);
        $this->assertSame([[42], [43]], [$p[0]['sessions_a'], $p[0]['sessions_b']]);
    }

    public function test_pair_mirrors(): void
    {
        $rows = [
            'a' => ['date' => '2026-03-05', 'amount' => 500.0, 'in' => false, 'other' => false],
            'b' => ['date' => '2026-03-05', 'amount' => 500.0, 'in' => true, 'other' => true],
            'c' => ['date' => '2026-03-05', 'amount' => 500.0, 'in' => true, 'other' => true],   // no second twin
            'd' => ['date' => '2026-03-06', 'amount' => 20.0, 'in' => false, 'other' => true],
            'e' => ['date' => '2026-03-06', 'amount' => 20.0, 'in' => false, 'other' => false], // same direction: not a twin
        ];
        $this->assertSame(['b' => 'a'], BankAccountSplitService::pairMirrors($rows));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB: apply / undo with the journal
    // ─────────────────────────────────────────────────────────────────────────

    public function test_apply_moves_the_chain_makes_one_transfer_and_undo_puts_it_back(): void
    {
        $db = $this->db();
        $this->load($db);
        $before = $this->net($db);
        $this->assertEqualsWithDelta(-1650.14, $before[self::BANK] ?? 0.0, 0.001, 'as imported: every money-out line on chequing (revenue deposits post nothing)');

        $svc = $this->svc($db);
        $plan = $svc->plan();
        $this->assertTrue($plan['ready'], implode(' ', $plan['problems']));
        $this->assertCount(2, $plan['chains']);
        $key = $plan['chains'][0]['key'];
        $this->assertSame(5, $plan['chains'][0]['movable']);
        $this->assertSame('keep', $plan['chains'][1]['suggest']);

        // No pick → nothing moves.
        $none = $svc->apply([$key => 'keep'], 1);
        $this->assertFalse($none['ok']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM accounting_transactions WHERE bank_account_id = " . self::GSTRES)->fetchColumn());

        $r = $svc->apply([$key => '1025'], 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(5, $r['moved']);
        $this->assertSame(2, $r['transfers']);
        $this->assertSame(5, (int)$db->query("SELECT COUNT(*) FROM accounting_transactions WHERE bank_account_id = " . self::GSTRES)->fetchColumn());

        $net = $this->net($db);
        // The two transfers: chequing −500 (out) +500 (back) → 0; GST Reserves +500 −500 → 0.
        // SHELL and HOME DEPOT stay. Neither $500 is a cost any more.
        $this->assertEqualsWithDelta(-650.14, $net[self::BANK] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(650.14, $net[self::MISC] ?? 0.0, 0.001, 'the set-aside and its return are no longer costs');
        $this->assertEqualsWithDelta(0.0, $net[self::GSTRES] ?? 0.0, 0.001);
        $twin = $db->query("SELECT account_id, type FROM accounting_transactions WHERE description = 'FUNDS TRANSFER - ONLINE TO # 10100058186827'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([self::GSTRES, 'transfer'], [(int)$twin['account_id'], $twin['type']]);
        $back = $db->query("SELECT account_id, type FROM accounting_transactions WHERE description = 'FUNDS TRANSFER - ONLINE FROM # 10100058186827'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([self::GSTRES, 'income'], [(int)$back['account_id'], $back['type']], 'money in to chequing: DR chequing / CR savings');
        $mirror = $db->query("SELECT account_id, type, bank_account_id FROM accounting_transactions WHERE description = 'FUNDS TRANSFER - ONLINE TO # 10100058186801'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([self::GSTRES, 'transfer', self::GSTRES], [(int)$mirror['account_id'], $mirror['type'], (int)$mirror['bank_account_id']], 'savings side posts nothing');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE status = 'deleted'")->fetchColumn());
        $this->assertGreaterThan(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'adjusting'")->fetchColumn(), 'corrected with reversals');

        // Nothing left to move: only the July chequing segment, suggested to stay.
        $this->assertSame(['keep'], array_column($svc->plan()['chains'], 'suggest'));

        $u = $svc->undo($r['batch'], 1);
        $this->assertTrue($u['ok'], $u['message']);
        $this->assertSame(7, $u['undone'], '5 moves + 2 chequing twins');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM accounting_transactions WHERE bank_account_id = " . self::GSTRES)->fetchColumn());
        $after = $this->net($db);
        foreach ([self::BANK, self::MISC, self::GSTRES] as $a) {
            $this->assertEqualsWithDelta($before[$a] ?? 0.0, $after[$a] ?? 0.0, 0.001, "account $a back where it was");
        }
        $twin = $db->query("SELECT account_id, type FROM accounting_transactions WHERE description = 'FUNDS TRANSFER - ONLINE TO # 10100058186827'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([self::MISC, 'expense'], [(int)$twin['account_id'], $twin['type']]);
    }

    public function test_locked_months_are_skipped_and_reported(): void
    {
        $db = $this->db();
        $this->load($db);
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2026, 3, 'locked')");
        $svc = $this->svc($db);
        $plan = $svc->plan();
        $this->assertSame(3, $plan['chains'][0]['locked']);
        $this->assertSame(2, $plan['chains'][0]['movable']);

        $r = $svc->apply([$plan['chains'][0]['key'] => '1025'], 1);
        $this->assertSame(2, $r['moved'], 'April and May interest');
        $this->assertSame(3, $r['skipped_locked']);
        $this->assertSame(1, (int)$db->query("SELECT bank_account_id FROM accounting_transactions WHERE description = 'FUNDS TRANSFER - ONLINE TO # 10100058186801'")->fetchColumn(), 'March untouched');
    }

    public function test_a_line_filed_on_its_own_bank_account_posts_nothing(): void
    {
        $sync = new LedgerSyncService($this->createMock(PDO::class), $this->createMock(LedgerService::class));
        $row = ['id' => 1, 'transaction_date' => '2026-03-20', 'type' => 'transfer', 'account_id' => self::GSTRES, 'account_type' => 'asset',
                'bank_account_id' => self::GSTRES, 'amount' => 500, 'direction' => 'out'];
        $this->assertNull($sync->bankRowToEntryArgs($row, self::ITC, self::GSTC, self::BANK));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Migration 1234: rename only while the name is the default
    // ─────────────────────────────────────────────────────────────────────────

    public function test_migration_renames_only_default_names(): void
    {
        $db = $this->db();
        $db->exec("UPDATE chart_of_accounts SET name = 'Savings Account' WHERE id = " . self::RESERVE);
        $db->exec("UPDATE chart_of_accounts SET name = 'GST money (mine)' WHERE id = " . self::GSTRES);
        $sql = (string)file_get_contents(__DIR__ . '/../../../public/database/migrations/1234_reserve_savings_accounts.sql');
        preg_match_all('/^UPDATE chart_of_accounts SET name = [^;]+;/ms', $sql, $m);
        $this->assertCount(2, $m[0]);
        foreach ($m[0] as $stmt) $db->exec($stmt);
        $names = $db->query("SELECT id, name FROM chart_of_accounts WHERE id IN (20, 25) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('Reserve funds (Savings ••6819)', $names[self::RESERVE]);
        $this->assertSame('GST money (mine)', $names[self::GSTRES], 'an owner-chosen name is kept');
        $this->assertSame(file_get_contents(__DIR__ . '/../../../public/database/migrations/1234_reserve_savings_accounts.sql'),
                          file_get_contents(__DIR__ . '/../../../database/migrations/1234_reserve_savings_accounts.sql'), 'both migration folders agree');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Importer: section headers, routing, one transfer
    // ─────────────────────────────────────────────────────────────────────────

    public function test_section_headers(): void
    {
        $h = fn($l) => BankImportService::accountSectionHeader($l);
        $this->assertSame(['suffix' => '6801', 'kind' => 'primary', 'opening' => null], $h('INDEPENDENT BUSINESS ACCOUNT #100058186801 (CONT.)'));
        $this->assertSame(['suffix' => '6819', 'kind' => 'savings', 'opening' => null], $h('BUSINESS INVESTMENT SAVINGS #100058186819 (RESERVE FUNDS)'));
        $this->assertSame(['suffix' => '6827', 'kind' => 'savings', 'opening' => 8.58], $h('BUSINESS JUMPSTART SAVINGS # 100058186827 (GST RESERVES) OPENING BALANCE 8.58'));
        $this->assertSame('shares', $h('SHARES CLASS B MEMBERSHIP SHARES #100013640901 OPENING BALANCE 7.29')['kind']);
        $this->assertSame('6827', $h('BUSINESS JUMPSTART SAVINGS (GST RESERVES)')['suffix'], 'number lost in extraction: by name');
        $this->assertNull($h('05 MAR TRANSFER TO GST RESERVES SAVINGS #6827 500.00 19,000.00'), 'a transaction line is never a header');
        $this->assertNull($h('POINT OF SALE SHELL'));
    }

    public function test_pdf_sections_tag_route_and_pair_lines(): void
    {
        $text = "STATEMENT PERIOD 01 SEP 2026 to 30 SEP 2026\nWITHDRAWALS DEPOSITS BALANCE\n"
              . "INDEPENDENT BUSINESS ACCOUNT #100058186801\nOPENING BALANCE 19,000.00\n"
              . "03 SEP DEPOSIT DORSET 1,500.00 20,500.00\n"
              . "05 SEP TRANSFER TO 6827 500.00 20,000.00\n"
              . "29 SEP POINT OF SALE SHELL 45.00 19,955.00\n"
              . "BUSINESS INVESTMENT SAVINGS #100058186819 (RESERVE FUNDS)\nOPENING BALANCE 0.69\n"
              . "BUSINESS JUMPSTART SAVINGS #100058186827 (GST RESERVES)\nOPENING BALANCE 8.64\n"
              . "05 SEP TRANSFER FROM 6801 500.00 508.64\n"
              . "30 SEP INTEREST CREDITED TO ACCOUNT (CREDIT INTEREST) 0.01 508.65\n"
              . "SHARES CLASS B MEMBERSHIP SHARES #100013640901\nOPENING BALANCE 7.29\n"
              . "30 SEP DIVIDEND 0.10 7.39\n";
        $svc = new BankImportService($this->db());
        $ref = new ReflectionClass(BankImportService::class);
        $p = $ref->getProperty('today'); $p->setValue($svc, '2026-10-07');
        $m = $ref->getMethod('parsePdfText');
        $parsed = $m->invoke($svc, $text, false);
        $rows = $parsed['rows'];

        $this->assertCount(5, $rows, 'the shares line is never a transaction');
        $this->assertSame(['6801', '6801', '6801', '6827', '6827'], array_column($rows, 'statement_account'));
        $this->assertSame('income', $rows[4]['type'], 'interest read from the GST balance, not chequing\'s');
        $this->assertSame('income', $rows[3]['type']);
        $this->assertSame(19955.0, $parsed['balance_meta']['closing'], 'chequing closing balance, not a savings one');

        $routed = $svc->routeOtherAccountRows($rows);
        $this->assertArrayNotHasKey('other_account', $routed[0]);
        $this->assertSame(self::GSTRES, $routed[4]['bank_account_id'], 'GST Reserves ••6827 → 1025, preselected');
        $this->assertSame('GST Reserves ••6827', $routed[4]['other_account']['label']);
        // the $500 printed in both sections: one transfer
        $this->assertSame('transfer', $routed[1]['type']);
        $this->assertSame(self::GSTRES, $routed[1]['account_id'], 'chequing line: DR GST Reserves / CR chequing');
        $this->assertSame(self::GSTRES, $routed[3]['account_id'], 'savings line filed on its own account (posts nothing)');
        $this->assertSame('transfer', $routed[3]['type']);
        $this->assertArrayNotHasKey('internal_transfer', $routed[4], 'interest is not a transfer');
    }

    public function test_single_account_statement_is_parsed_as_before(): void
    {
        $text = "WITHDRAWALS DEPOSITS BALANCE\nOPENING BALANCE 1000.00\n01 MAY POS A 50.00 950.00\n02 MAY DEPOSIT 100.00 1,050.00\n";
        $svc = new BankImportService($this->db());
        $ref = new ReflectionClass(BankImportService::class);
        $p = $ref->getProperty('today'); $p->setValue($svc, '2026-10-07');
        $m = $ref->getMethod('parsePdfText');
        $rows = $m->invoke($svc, $text, false)['rows'];
        $this->assertSame([null, null], array_column($rows, 'statement_account'));
        $this->assertSame($rows, $svc->routeOtherAccountRows($rows), 'nothing routed');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function line(int $id, int $session, string $date, string $desc, float $signed, float $balance): array
    {
        $amt = number_format(abs($signed), 2, '.', '');
        $raw = ['date' => $date, 'amount' => abs($signed), 'type' => $signed > 0 ? 'income' : 'expense',
                'raw_line' => $date . ',' . $desc . ',' . ($signed < 0 ? $amt : '') . ',' . ($signed > 0 ? $amt : '') . ',' . number_format($balance, 2, '.', '')];
        $l = StatementCoverageService::lineFromRow(['id' => $id, 'session_id' => $session, 'transaction_date' => $date, 'type' => $raw['type'], 'amount' => abs($signed)], $raw);
        $l['tx_id'] = $id;
        $l['description'] = $desc;
        return $l;
    }

    private function pureLines(): array
    {
        $out = [];
        foreach (self::FIXTURE as $i => [$s, $d, $desc, $signed, $bal]) $out[] = $this->line($i + 1, $s, $d, $desc, $signed, $bal);
        return $out;
    }

    /** The fixture as imported: sessions on 1010, rows, transactions on 1010, journal as LedgerSyncService posts it. */
    private function load(PDO $db): void
    {
        foreach ([1, 2, 3, 4] as $sid) {
            $db->exec("INSERT INTO bank_import_sessions (id, bank_account_id, bank_name, status) VALUES ($sid, " . self::BANK . ", 'Vancity', 'imported')");
        }
        $ledger = $this->ledger($db);
        $sync = new LedgerSyncService($db, $ledger);
        foreach (self::FIXTURE as $i => [$s, $d, $desc, $signed, $bal, $cat]) {
            $id = $i + 1;
            $type = $signed > 0 ? 'income' : 'expense';
            $amt = abs($signed);
            $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id)
                          VALUES (?, ?, ?, ?, ?, ?, 'bank_import', ?)")->execute([$id, $d, $type, $amt, $desc, $cat, self::BANK]);
            $raw = ['date' => $d, 'amount' => $amt, 'type' => $type, 'description' => $desc,
                    'raw_line' => $d . ',' . $desc . ',' . ($signed < 0 ? number_format($amt, 2, '.', '') : '') . ',' . ($signed > 0 ? number_format($amt, 2, '.', '') : '') . ',' . number_format($bal, 2, '.', '')];
            $db->prepare("INSERT INTO bank_import_rows (id, session_id, transaction_date, type, amount, raw_amount, is_duplicate, raw_row, transaction_id)
                          VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")->execute([$id, $s, $d, $type, $amt, $signed, json_encode($raw), $id]);
        }
        $sync->syncBankImports();
    }

    private function ledger(PDO $db): LedgerService
    {
        return new class($db) extends LedgerService {
            private PDO $pdo;
            public function __construct(PDO $db) { parent::__construct($db); $this->pdo = $db; }
            public function canRepostSource(): bool { return true; }
            public function accountId(string $code): int
            {
                $s = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
                $s->execute([$code]);
                return (int)$s->fetchColumn();
            }
        };
    }

    private function svc(PDO $db): BankAccountSplitService
    {
        return new BankAccountSplitService($db, $this->ledger($db));
    }

    private function net(PDO $db): array
    {
        $out = [];
        foreach ($db->query("SELECT account_id, SUM(debit) - SUM(credit) AS n FROM journal_lines GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['account_id']] = round((float)$r['n'], 2);
        }
        return $out;
    }

    private function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        $db->exec("ALTER TABLE chart_of_accounts ADD COLUMN sub_type TEXT");
        $db->exec("UPDATE chart_of_accounts SET sub_type = 'bank' WHERE id = 1");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type, sub_type) VALUES (20, '1020', 'Reserve funds (Savings ••6819)', 'asset', 'bank'),
                   (25, '1025', 'GST Reserves (Savings ••6827)', 'asset', 'bank')");
        $db->exec("CREATE TABLE bank_import_sessions (id INTEGER PRIMARY KEY, bank_account_id INTEGER, bank_name TEXT, status TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, session_id INTEGER, transaction_date TEXT, type TEXT, amount REAL, raw_amount REAL,
                   is_duplicate INTEGER DEFAULT 0, raw_row TEXT, transaction_id INTEGER)");
        $db->exec("CREATE TABLE bank_account_split_log (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id TEXT, chain_key TEXT, kind TEXT DEFAULT 'move',
                   transaction_id INTEGER, from_bank_account_id INTEGER, to_bank_account_id INTEGER, old_account_id INTEGER, old_type TEXT,
                   old_gst_amount REAL, new_account_id INTEGER, old_entry_id INTEGER, reversal_entry_id INTEGER, new_entry_id INTEGER,
                   moved_by INTEGER, moved_at TEXT, undone_at TEXT, undone_by INTEGER, undo_reversal_entry_id INTEGER, undo_entry_id INTEGER)");
        return $db;
    }
}
