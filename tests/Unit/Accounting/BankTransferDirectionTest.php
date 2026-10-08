<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BankLineMoveServiceTest.php';   // shared SQLite schema

/**
 * A 'transfer' bank line can be money IN (a deposit reconciliation flipped once its invoices
 * carry it) or money OUT (card payoff, loan payment). The journal posted them all as OUT
 * (live 2026-10-07: bank row 24438, $302.40 e-Transfer from John Hughes, tied to two invoices).
 */
class BankTransferDirectionTest extends TestCase
{
    // chart ids (BankLineMoveServiceTest::schema) + AR
    private const BANK = 1, OTHER = 5, CARD = 7, LOAN = 9, AR = 12;

    // ── direction ───────────────────────────────────────────────────────────────

    public function test_direction_comes_from_the_statement_not_the_row_type(): void
    {
        $this->assertSame('in', LedgerSyncService::transferDirection(['staged_type' => 'income']));
        $this->assertSame('in', LedgerSyncService::transferDirection(['settled' => true]), 'allocations name a deposit');
        $this->assertSame('out', LedgerSyncService::transferDirection(['staged_type' => 'expense']), 'card payoff staged as expense');
        $this->assertSame('out', LedgerSyncService::transferDirection([]), 'no evidence: as before');
    }

    // ── mapper ──────────────────────────────────────────────────────────────────

    public function test_transfer_out_debits_category_credits_bank(): void
    {
        $e = $this->map(['direction' => 'out', 'account_id' => self::CARD, 'account_type' => 'liability', 'amount' => 2700.00]);
        $this->assertSame([[self::CARD, 2700.0, 0.0], [self::BANK, 0.0, 2700.0]], $this->shape($e));
    }

    public function test_transfer_in_debits_bank_credits_category(): void
    {
        // money in from a loan / savings, not tied to any invoice
        $e = $this->map(['direction' => 'in', 'settled' => false, 'account_id' => self::LOAN, 'account_type' => 'liability', 'amount' => 5000.00]);
        $this->assertSame([[self::BANK, 5000.0, 0.0], [self::LOAN, 0.0, 5000.0]], $this->shape($e));
    }

    public function test_settled_deposit_posts_nothing(): void
    {
        // The $302.40 case: the invoice payments already debit the bank.
        $this->assertNull($this->map(['direction' => 'in', 'settled' => true, 'account_id' => self::OTHER, 'account_type' => 'revenue', 'amount' => 302.40]));
        $this->assertNull($this->map(['direction' => 'in', 'settled' => true, 'account_id' => self::LOAN, 'account_type' => 'liability', 'amount' => 302.40]));
    }

    public function test_unsettled_deposit_on_a_revenue_account_posts_nothing(): void
    {
        $this->assertNull($this->map(['direction' => 'in', 'settled' => false, 'account_id' => self::OTHER, 'account_type' => 'revenue', 'amount' => 90.00]));
    }

    public function test_a_moved_deposit_stays_a_transfer(): void
    {
        $this->assertSame('transfer', BankLineMoveService::typeFor('transfer', 'expense', true), 'money in never becomes an expense');
        $this->assertSame('expense', BankLineMoveService::typeFor('transfer', 'expense'), 'a card payoff moved to a cost is a cost');
    }

    // ── sync ────────────────────────────────────────────────────────────────────

    public function test_sync_posts_out_lines_and_never_doubles_an_allocated_deposit(): void
    {
        $db = $this->db();
        $this->fixtures($db, false);
        $ledger = $this->ledger($db);

        // The two invoice payments the deposit settles (syncInvoices posts DR Bank / CR AR).
        $ledger->postPayment(['id' => 901, 'invoice_id' => 901, 'date' => '2026-07-20', 'amount' => 151.20]);
        $ledger->postPayment(['id' => 902, 'invoice_id' => 902, 'date' => '2026-07-20', 'amount' => 151.20]);

        $r = (new LedgerSyncService($db, $ledger))->syncBankImports();

        $this->assertSame(0, $r['errors']);
        $this->assertSame([], $this->entriesFor($db, 24438), 'the allocated deposit is not posted');
        $this->assertSame([], $this->entriesFor($db, 24439), 'the "already recorded" deposit is not posted');
        $net = $this->net($db);
        // +302.40 (payments) − 2700 (card payoff) + 5000 (loan in)
        $this->assertEqualsWithDelta(2602.40, $net[self::BANK], 0.001);
        $this->assertEqualsWithDelta(2700.0, $net[self::CARD], 0.001);
        $this->assertEqualsWithDelta(-5000.0, $net[self::LOAN], 0.001);
    }

    // ── repair ──────────────────────────────────────────────────────────────────

    public function test_repair_dry_run_selects_only_wrong_deposits(): void
    {
        $db = $this->db();
        $this->fixtures($db, true);

        $p = $this->repair($db)->plan();

        $byId = array_column($p['items'], null, 'id');
        $this->assertSame([24438, 24439, 24441, 24442], array_keys($byId));
        $this->assertSame('backwards', $byId[24438]['kind']);
        $this->assertEqualsWithDelta(302.40, $byId[24438]['bank_effect'], 0.001, 'cash was understated by the deposit');
        $this->assertFalse($byId[24438]['repost'], 'its invoices carry it');
        $this->assertSame('double_cash', $byId[24439]['kind']);
        $this->assertEqualsWithDelta(-120.0, $byId[24439]['bank_effect'], 0.001);
        $this->assertSame('backwards', $byId[24441]['kind']);
        $this->assertEqualsWithDelta(10000.0, $byId[24441]['bank_effect'], 0.001, 'loan proceeds: out → in = 2 × amount');
        $this->assertTrue($byId[24441]['repost']);
        $this->assertSame('backwards', $byId[24442]['kind'], 'a matched deposit posted before the match');
        $this->assertSame([24443], array_column($p['locked'], 'id'), 'locked month reported, not selected');
        $this->assertSame(1, $p['locked_count']);
        $this->assertEqualsWithDelta(302.40 + 120.0 + 5000.0 + 75.0, $p['amount'], 0.001);
        $this->assertSame(6, (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(), 'dry run writes nothing');
    }

    public function test_repair_reverses_and_reposts_then_finds_nothing(): void
    {
        $db = $this->db();
        $this->fixtures($db, true);
        $before = $this->net($db);

        $r = $this->repair($db)->apply(1);

        $this->assertSame(4, $r['fixed']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame(1, $r['skipped_locked']);
        $after = $this->net($db);
        $this->assertEqualsWithDelta($before[self::BANK] + 302.40 - 120.0 + 10000.0 + 75.0, $after[self::BANK], 0.001);
        $this->assertEqualsWithDelta(-5000.0, $after[self::LOAN], 0.001, 'loan proceeds credit the loan');
        $this->assertEqualsWithDelta(2700.0, $after[self::CARD], 0.001, 'the card payoff is untouched');
        // locked line keeps its (wrong) live entry
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_import' AND source_id = 24443
                                              AND reversed_by_entry_id IS NULL")->fetchColumn());
        // append-only: originals are still there, linked to their reversals
        $this->assertSame(4, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE reversed_by_entry_id IS NOT NULL")->fetchColumn());
        $this->assertSame(0, $this->repair($db)->plan()['count'], 'a second run finds nothing');
    }

    public function test_judge_ignores_an_entry_that_is_already_right(): void
    {
        $want = ['lines' => [['account_id' => self::BANK, 'debit' => 50, 'credit' => 0], ['account_id' => self::LOAN, 'debit' => 0, 'credit' => 50]]];
        $this->assertNull(BankTransferDirectionRepair::judge(['id' => 1, 'entry_id' => 2, 'transaction_date' => '2026-05-01', 'amount' => 50],
            [['account_id' => self::BANK, 'debit' => 50, 'credit' => 0], ['account_id' => self::LOAN, 'debit' => 0, 'credit' => 50]], $want, self::BANK, false));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 24438  $302.40 e-Transfer credit, 'transfer', 2 allocations, no matched_invoice_id (the live case)
     * 24439  $120 deposit "Marked as already recorded"
     * 24440  $2700 card payoff: 'transfer', staged 'expense' with a POSITIVE raw_amount (commit() quirk)
     * 24441  $5000 loan proceeds: 'transfer' to 2610, staged 'income', no invoice
     * 24442  $75 deposit matched to one invoice (sync skips it; the old entry predates the match)
     * 24443  $60 allocated deposit in locked 2026-03
     * $posted: the entries the old mapper wrote (24438/24441/24442/24443 backwards, 24439 as income DR bank).
     */
    private function fixtures(PDO $db, bool $posted): void
    {
        $tx = [
            [24438, '2026-07-20', 302.40, 'e-Transfer credit Ref 1 JOHN HUGHES', self::OTHER, 'income', null, null],
            [24439, '2026-07-22', 120.00, 'e-Transfer credit Ref 2 DORSET', self::OTHER, 'income', null, 'Marked as already recorded on 2026-10-06 by user #1'],
            [24440, '2026-07-25', 2700.00, 'VISA PAYMENT VANCITY', self::CARD, 'expense', null, null],
            [24441, '2026-07-28', 5000.00, 'LOAN CREDIT TD', self::LOAN, 'income', null, null],
            [24442, '2026-08-02', 75.00, 'e-Transfer credit Ref 3 SMITH', self::OTHER, 'income', 903, null],
            [24443, '2026-03-10', 60.00, 'e-Transfer credit Ref 4 JONES', self::OTHER, 'income', null, null],
        ];
        foreach ($tx as [$id, $date, $amt, $desc, $acct, $staged, $matched, $notes]) {
            $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type,
                          bank_account_id, matched_invoice_id, notes) VALUES (?, ?, 'transfer', ?, ?, ?, 'bank_import', 1, ?, ?)")
               ->execute([$id, $date, $amt, $desc, $acct, $matched, $notes]);
            $db->prepare("INSERT INTO bank_import_rows (transaction_id, type, raw_amount, amount) VALUES (?, ?, ?, ?)")
               ->execute([$id, $staged, $amt, $amt]);   // raw_amount positive for all — never trusted
        }
        foreach ([[901, 24438, 151.20], [902, 24438, 151.20], [904, 24443, 60.00]] as [$inv, $txId, $amt]) {
            $db->prepare("INSERT INTO invoice_payment_allocations (invoice_id, transaction_id, amount) VALUES (?, ?, ?)")->execute([$inv, $txId, $amt]);
        }
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2026, 3, 'locked')");
        if (!$posted) return;
        $this->post($db, 24438, '2026-07-20', [[self::OTHER, 302.40, 0], [self::BANK, 0, 302.40]]);
        $this->post($db, 24439, '2026-07-22', [[self::BANK, 120.00, 0], [self::OTHER, 0, 120.00]]);
        $this->post($db, 24440, '2026-07-25', [[self::CARD, 2700.00, 0], [self::BANK, 0, 2700.00]]);
        $this->post($db, 24441, '2026-07-28', [[self::LOAN, 5000.00, 0], [self::BANK, 0, 5000.00]]);
        $this->post($db, 24442, '2026-08-02', [[self::OTHER, 75.00, 0], [self::BANK, 0, 75.00]]);
        $this->post($db, 24443, '2026-03-10', [[self::OTHER, 60.00, 0], [self::BANK, 0, 60.00]]);
        // 24439's entry DEBITS the bank (double_cash); 24440 is money out and right.
    }

    private function map(array $over): ?array
    {
        $pdo = $this->createMock(PDO::class);
        $row = array_merge(['id' => 77, 'transaction_date' => '2026-07-20', 'type' => 'transfer', 'account_code' => '',
                            'bank_account_id' => self::BANK, 'gst_amount' => 0, 'pst_amount' => 0, 'description' => 'x'], $over);
        return (new LedgerSyncService($pdo, new LedgerService($pdo)))->bankRowToEntryArgs($row, 2, 3, self::BANK);
    }

    private function shape(?array $e): array
    {
        $this->assertNotNull($e);
        return array_map(fn($l) => [(int)$l['account_id'], (float)$l['debit'], (float)$l['credit']], $e['lines']);
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

    private function repair(PDO $db): BankTransferDirectionRepair
    {
        return new BankTransferDirectionRepair($db, $this->ledger($db));
    }

    private function entriesFor(PDO $db, int $txId): array
    {
        $s = $db->prepare("SELECT id FROM journal_entries WHERE source_type = 'bank_import' AND source_id = ?");
        $s->execute([$txId]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    private function net(PDO $db): array
    {
        $out = [];
        foreach ($db->query("SELECT account_id, SUM(debit) - SUM(credit) AS n FROM journal_lines GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['account_id']] = round((float)$r['n'], 2);
        }
        return $out + [self::BANK => 0.0];
    }

    private function post(PDO $db, int $txId, string $date, array $lines): void
    {
        $db->prepare("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status) VALUES (?, 'bank', 'bank_import', ?, 'posted')")
           ->execute([$date, $txId]);
        $e = (int)$db->lastInsertId();
        foreach ($lines as $l) {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)")->execute([$e, $l[0], $l[1], $l[2]]);
        }
    }

    private function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        BankLineMoveServiceTest::schema($db);
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (12, '1100', 'Accounts Receivable', 'asset')");
        $db->exec("ALTER TABLE accounting_transactions ADD COLUMN notes TEXT");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER DEFAULT 1, transaction_id INTEGER,
                   type TEXT, raw_amount REAL, amount REAL, raw_row TEXT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, transaction_id INTEGER, amount REAL)");
        return $db;
    }
}
