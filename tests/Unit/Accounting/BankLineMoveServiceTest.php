<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Moving a bank line moves its journal entry too (2026-10-07: recategorise never did),
 * and "also put the other N lines from <payee> on <account>" picks only lines it may move.
 */
class BankLineMoveServiceTest extends TestCase
{
    // chart ids
    private const BANK = 1, ITC = 2, GSTC = 3, MISC = 4, OTHER = 5, WAGES = 6, CARD = 7, VEHICLE = 8, LOAN = 9, FUEL = 10, CONTRACT = 11;

    public function test_type_follows_the_account_like_the_journal_posts_it(): void
    {
        $this->assertSame('transfer', BankLineMoveService::typeFor('expense', 'liability'), 'loan payment');
        $this->assertSame('transfer', BankLineMoveService::typeFor('expense', 'equity'), "owner's draw");
        $this->assertSame('expense', BankLineMoveService::typeFor('transfer', 'expense'), 'bill booked as a card payoff');
        $this->assertSame('expense', BankLineMoveService::typeFor('expense', 'expense'));
        $this->assertSame('income', BankLineMoveService::typeFor('income', 'liability'), 'money in stays income (credit the liability)');
    }

    public function test_moving_a_line_moves_its_journal_line(): void
    {
        $db = $this->db();
        $this->line($db, 100, '2026-09-18', 'expense', 500.00, 'WAVE PYRL 0012345', self::MISC, 0);
        $this->post($db, 100, '2026-09-18', [[self::MISC, 500, 0], [self::BANK, 0, 500]]);

        $r = $this->mover($db)->move(100, self::WAGES, 1);

        $this->assertTrue($r['ok']);
        $this->assertSame('reposted', $r['journal']);
        $net = $this->net($db);
        $this->assertEqualsWithDelta(0.0, $net[self::MISC] ?? 0.0, 0.001, 'nothing left on Miscellaneous');
        $this->assertEqualsWithDelta(500.0, $net[self::WAGES] ?? 0.0, 0.001, 'Wages carries it');
        $this->assertEqualsWithDelta(-500.0, $net[self::BANK] ?? 0.0, 0.001, 'the bank side is unchanged');
        $this->assertSame(3, (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn(), 'original + reversal + re-post (append-only)');
        $this->assertSame(self::WAGES, (int)$db->query("SELECT account_id FROM accounting_transactions WHERE id = 100")->fetchColumn());
    }

    public function test_a_journal_line_left_on_an_older_account_moves_too(): void
    {
        // The TD loan stragglers: the bank list said Miscellaneous, the journal still debited 6130, with GST.
        $db = $this->db();
        $this->line($db, 200, '2026-09-18', 'expense', 363.08, 'TD ON-LINE LOANS 4455667', self::MISC, 17.29);
        $this->post($db, 200, '2026-09-18', [[self::VEHICLE, 345.79, 0, 17.29], [self::ITC, 17.29, 0, 17.29], [self::BANK, 0, 363.08]]);

        $r = $this->mover($db)->move(200, self::LOAN, 1);

        $this->assertSame('transfer', $r['type']);
        $net = $this->net($db);
        $this->assertEqualsWithDelta(0.0, $net[self::VEHICLE] ?? 0.0, 0.001, 'the 6130 straggler is gone');
        $this->assertEqualsWithDelta(0.0, $net[self::ITC] ?? 0.0, 0.001, 'no GST claimed on a loan payment');
        $this->assertEqualsWithDelta(363.08, $net[self::LOAN] ?? 0.0, 0.001, 'the whole payment is on the loan');
        $row = $db->query("SELECT type, gst_amount FROM accounting_transactions WHERE id = 200")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('transfer', $row['type']);
        $this->assertEqualsWithDelta(0.0, (float)$row['gst_amount'], 0.001);
    }

    public function test_a_line_never_posted_is_posted_and_a_revenue_deposit_is_reversed(): void
    {
        $db = $this->db();
        $this->line($db, 300, '2026-09-20', 'expense', 80.00, 'SHELL C01303', self::MISC, 0);
        $this->assertSame('posted', $this->mover($db)->move(300, self::FUEL, 1)['journal']);
        $this->assertEqualsWithDelta(80.0, $this->net($db)[self::FUEL] ?? 0.0, 0.001);

        // A deposit booked to a liability, moved to an income account: the invoice side carries income.
        $this->line($db, 301, '2026-09-21', 'income', 250.00, 'DEPOSIT ABC', self::LOAN, 0);
        $this->post($db, 301, '2026-09-21', [[self::BANK, 250, 0], [self::LOAN, 0, 250]]);
        $this->assertSame('reversed', $this->mover($db)->move(301, self::CONTRACT, 1)['journal']);
        $this->assertEqualsWithDelta(0.0, $this->net($db)[self::LOAN] ?? 0.0, 0.001);
    }

    public function test_a_locked_month_is_refused_and_nothing_changes(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO accounting_periods (year, month, status) VALUES (2026, 3, 'locked')");
        $this->line($db, 400, '2026-03-12', 'expense', 363.08, 'TD ON-LINE LOANS 1', self::MISC, 0);
        $r = $this->mover($db)->move(400, self::LOAN, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('2026-03 is locked', $r['message']);
        $this->assertSame(self::MISC, (int)$db->query("SELECT account_id FROM accounting_transactions WHERE id = 400")->fetchColumn());
    }

    public function test_bulk_picks_only_the_payees_other_movable_lines(): void
    {
        $key = BankImportService::descriptionKey('WAVE PYRL 0012345');
        $rows = [
            ['id' => 1, 'transaction_date' => '2026-09-04', 'type' => 'expense', 'description' => 'WAVE PYRL 0012345', 'account_id' => self::MISC],   // the decided one
            ['id' => 2, 'transaction_date' => '2026-08-21', 'type' => 'expense', 'description' => 'WAVE PYRL 0099887', 'account_id' => self::MISC],   // yes
            ['id' => 3, 'transaction_date' => '2026-08-07', 'type' => 'transfer', 'description' => 'WAVE PYRL 0011223', 'account_id' => self::CARD],  // yes: the wrong 2400 rule
            ['id' => 4, 'transaction_date' => '2026-07-24', 'type' => 'expense', 'description' => 'WAVE PYRL 0044556', 'account_id' => self::WAGES],  // already there
            ['id' => 5, 'transaction_date' => '2026-07-10', 'type' => 'expense', 'description' => 'WAVE PYRL 0077889', 'account_id' => self::MISC, 'matched_expense_id' => 9],
            ['id' => 6, 'transaction_date' => '2026-06-26', 'type' => 'expense', 'description' => 'WAVE PYRL 0033221', 'account_id' => self::MISC, 'matched_invoice_id' => 4],
            ['id' => 7, 'transaction_date' => '2026-03-13', 'type' => 'expense', 'description' => 'WAVE PYRL 0066554', 'account_id' => self::MISC],   // locked month
            ['id' => 8, 'transaction_date' => '2026-09-01', 'type' => 'expense', 'description' => 'WAVE HR 0012345', 'account_id' => self::MISC],     // another payee
            ['id' => 9, 'transaction_date' => '2026-09-02', 'type' => 'income', 'description' => 'WAVE PYRL 0012399', 'account_id' => self::OTHER],    // money in, not out
            ['id' => 10, 'transaction_date' => '2026-09-03', 'type' => 'transfer', 'description' => 'WAVE PYRL 0012388', 'account_id' => self::CARD, 'status' => 'reconciled'],
        ];
        $picked = array_column(BankLineMoveService::pickBulk($rows, $key, self::WAGES, ['2026-03'], 1, false), 'id');
        $this->assertSame([2, 3], $picked);
    }

    public function test_bulk_never_files_client_payments_together(): void
    {
        // TD e-Transfer credits all read the same ("our" name); each one pays some invoice.
        $key = BankImportService::descriptionKey('e-Transfer credit Ref 123456 TimatMowology');
        $rows = [['id' => 2, 'transaction_date' => '2026-09-04', 'type' => 'income', 'description' => 'e-Transfer credit Ref 998877 TimatMowology', 'account_id' => self::OTHER]];
        $this->assertSame([], BankLineMoveService::pickBulk($rows, $key, self::CONTRACT, [], 1, true));
    }

    public function test_bulk_apply_moves_each_line_with_its_journal_and_marks_it_reviewed(): void
    {
        $db = $this->db();
        $this->line($db, 1, '2026-09-18', 'expense', 500.00, 'WAVE PYRL 0012345', self::WAGES, 0);         // already decided
        $this->line($db, 2, '2026-09-04', 'expense', 480.00, 'WAVE PYRL 0012000', self::MISC, 0);
        $this->line($db, 3, '2026-08-21', 'transfer', 470.00, 'WAVE PYRL 0011000', self::CARD, 0);
        $this->line($db, 4, '2026-08-07', 'expense', 460.00, 'WAVE PYRL 0010000', self::MISC, 0);           // reviewed already → left alone
        $db->exec("INSERT INTO bank_line_reviews (transaction_id, final_account_id, outcome, decided_by) VALUES (4, 4, 'kept', 1)");
        $this->post($db, 2, '2026-09-04', [[self::MISC, 480, 0], [self::BANK, 0, 480]]);
        $this->post($db, 3, '2026-08-21', [[self::CARD, 470, 0], [self::BANK, 0, 470]]);

        $mover = $this->mover($db);
        $offer = $mover->bulkOffer(1, self::WAGES);
        $this->assertSame(2, $offer['count']);
        $this->assertSame('Wave PYRL', $offer['payee']);
        $this->assertSame('5100 Wages', $offer['account']);

        $r = $mover->bulkApply(1, self::WAGES, 1);
        $this->assertSame(2, $r['moved']);
        $net = $this->net($db);
        $this->assertEqualsWithDelta(950.0, $net[self::WAGES] ?? 0.0, 0.001);
        $this->assertEqualsWithDelta(0.0, $net[self::CARD] ?? 0.0, 0.001, 'off Credit Card Payable');
        $this->assertSame('expense', $db->query("SELECT type FROM accounting_transactions WHERE id = 3")->fetchColumn(), 'a cost again, not a card payoff');
        $this->assertSame(['2' => 'bulk', '3' => 'bulk', '4' => 'kept'],
            $db->query("SELECT transaction_id, outcome FROM bank_line_reviews ORDER BY transaction_id")->fetchAll(PDO::FETCH_KEY_PAIR));
        $this->assertNull($mover->bulkOffer(1, self::WAGES), 'nothing left to offer');
    }

    public function test_no_bulk_onto_a_default_account(): void
    {
        $db = $this->db();
        $this->line($db, 1, '2026-09-18', 'expense', 10.00, 'SOME SHOP 1', self::MISC, 0);
        $this->line($db, 2, '2026-09-19', 'expense', 11.00, 'SOME SHOP 2', self::FUEL, 0);
        $this->assertSame([], $this->mover($db)->bulkCandidates(1, self::MISC));
    }

    public function test_payee_label_reads_like_the_bank_wrote_it(): void
    {
        $this->assertSame('Wave PYRL', BankLineMoveService::payeeLabel('WAVE PYRL 0012345', 'wave pyrl'));
        $this->assertSame('Point Sale Shell', BankLineMoveService::payeeLabel('POINT OF SALE (SHELL CO1303 VANCOUVER BCCA)', 'point sale shell'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function mover(PDO $db): BankLineMoveService
    {
        return new BankLineMoveService($db, new class($db) extends LedgerService {
            private PDO $pdo;
            public function __construct(PDO $db) { parent::__construct($db); $this->pdo = $db; }
            public function canRepostSource(): bool { return true; }          // migration 1131 ran (no SHOW INDEX in SQLite)
            public function accountId(string $code): int                       // no static cache across tests
            {
                $s = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code = ?");
                $s->execute([$code]);
                return (int)$s->fetchColumn();
            }
        });
    }

    /** Net debit − credit per account over every entry (reversals included). */
    private function net(PDO $db): array
    {
        $out = [];
        foreach ($db->query("SELECT account_id, SUM(debit) - SUM(credit) AS n FROM journal_lines GROUP BY account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['account_id']] = round((float)$r['n'], 2);
        }
        return $out;
    }

    private function line(PDO $db, int $id, string $date, string $type, float $amount, string $desc, int $account, float $gst): void
    {
        $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, gst_amount, description, account_id, reference_type, bank_account_id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, 'bank_import', 1)")->execute([$id, $date, $type, $amount, $gst, $desc, $account]);
    }

    private function post(PDO $db, int $txId, string $date, array $lines): void
    {
        $db->prepare("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status) VALUES (?, 'bank', 'bank_import', ?, 'posted')")
           ->execute([$date, $txId]);
        $e = (int)$db->lastInsertId();
        foreach ($lines as $l) {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit, gst_amount) VALUES (?, ?, ?, ?, ?)")
               ->execute([$e, $l[0], $l[1], $l[2], $l[3] ?? 0]);
        }
    }

    public static function schema(PDO $db): void
    {
        $now = static fn() => '2026-10-07 09:00:00';
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, is_active INTEGER DEFAULT 1, expense_category_alias TEXT)");
        foreach ([[1, '1010', 'Chequing', 'asset'], [2, '2210', 'GST ITC', 'asset'], [3, '2200', 'GST Collected', 'liability'],
                  [4, '6900', 'Miscellaneous', 'expense'], [5, '4900', 'Other Services', 'revenue'], [6, '5100', 'Wages', 'expense'],
                  [7, '2400', 'Credit Card Payable', 'liability'], [8, '6130', 'Vehicle Loan', 'expense'], [9, '2610', 'Loan Payable RAM 3500HD', 'liability'],
                  [10, '6100', 'Fuel', 'expense'], [11, '4050', 'Contract Income', 'revenue']] as $a) {
            $db->prepare("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (?, ?, ?, ?)")->execute($a);
        }
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, gst_amount REAL DEFAULT 0,
                   pst_amount REAL DEFAULT 0, description TEXT, account_id INTEGER, reference_type TEXT, bank_account_id INTEGER, job_id INTEGER,
                   contact_id INTEGER, vendor_id INTEGER, status TEXT DEFAULT 'cleared', matched_expense_id INTEGER, matched_invoice_id INTEGER,
                   is_auto_categorized INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_date TEXT, memo TEXT, source_type TEXT, source_id INTEGER,
                   period_id INTEGER, status TEXT, is_adjusting INTEGER DEFAULT 0, created_by INTEGER, reversed_by_entry_id INTEGER)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER, account_id INTEGER, debit REAL DEFAULT 0, credit REAL DEFAULT 0,
                   gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0, description TEXT, job_id INTEGER, contact_id INTEGER, vendor_id INTEGER,
                   crew_user_id INTEGER, cost_type_id INTEGER, service_type TEXT)");
        $db->exec("CREATE TABLE accounting_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, year INTEGER, month INTEGER, status TEXT)");
        $db->exec("CREATE TABLE cost_types (id INTEGER PRIMARY KEY, name TEXT, is_active INTEGER DEFAULT 1)");
        $db->exec("CREATE TABLE bank_line_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER UNIQUE, suggested_account_id INTEGER,
                   final_account_id INTEGER, outcome TEXT, decided_by INTEGER, decided_at TEXT)");
        $db->exec("CREATE TABLE transaction_rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, priority INTEGER, applies_to TEXT, condition_field TEXT,
                   condition_operator TEXT, condition_value TEXT, account_id INTEGER, transaction_type TEXT, is_active INTEGER DEFAULT 1, source TEXT DEFAULT 'manual',
                   learned_count INTEGER DEFAULT 0, owner_confirmations INTEGER NOT NULL DEFAULT 0, last_confirmed_tx_id INTEGER, last_learned_at TEXT,
                   created_by INTEGER, created_at TEXT)");
    }

    private function db(): PDO
    {
        $db = method_exists(PDO::class, 'connect') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');   // Pdo\Sqlite on PHP 8.4+
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::schema($db);
        return $db;
    }
}
