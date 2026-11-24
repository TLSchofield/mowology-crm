<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BankLineMoveServiceTest.php';
require_once __DIR__ . '/../../../app/Modules/Accounting/Services/BankLineReceipts.php';

/**
 * One bank line paid by several receipts (Champion, Aug 31 2026: sharpening $134.40 incl. $6 GST + blades $50.00
 * with no tax = one $184.40 debit). The line's own posting goes only once every receipt is booked and they add up.
 */
class BankLineReceiptsTest extends TestCase
{
    private const BANK = 1, MISC = 4, GST = 2;

    public function test_the_receipts_must_add_up_to_the_line_exactly(): void
    {
        $this->assertTrue(BankLineReceipts::fits([134.40, 50.00], 184.40));
        $this->assertTrue(BankLineReceipts::fits([134.40, 50.00], -184.40), 'the sign of the line does not matter');
        $this->assertFalse(BankLineReceipts::fits([134.40, 56.00], 184.40), 'tax the shop never charged is a wrong receipt, not a discount');
        $this->assertSame(6.0, BankLineReceipts::gap([134.40, 56.00], 184.40));
        $this->assertFalse(BankLineReceipts::fits([184.40], 184.40), 'one receipt uses the ordinary match');
        $this->assertSame(363, BankLineReceipts::mainOf([359 => 50.00, 363 => 134.40]));
        $this->assertSame(359, BankLineReceipts::mainOf([363 => 20.00, 359 => 20.00]), 'a tie goes to the older receipt');
    }

    public function test_champion_two_receipts_one_debit_books_once_with_the_gst_credit(): void
    {
        $db = $this->db();
        $this->line($db, 36605, 184.40);
        $this->bankEntry($db, 36605, [[self::MISC, 184.40, 0], [self::BANK, 0, 184.40]]);
        $this->expense($db, 359, 134.40);
        $this->expense($db, 363, 50.00);

        $r = (new BankLineReceipts($db))->attach(36605, [363, 359], 1);
        $this->assertSame(359, $r['main']);
        $this->assertSame(0, $r['bank_postings_changed'], 'drafts: nothing booked yet, the line still stands in');
        $this->assertSame(['reconciled', 359], array_values($db->query("SELECT status, matched_expense_id FROM accounting_transactions WHERE id = 36605")->fetch(PDO::FETCH_NUM)));

        $this->receipt($db, 359, [[self::MISC, 128.40, 0], [self::GST, 6.00, 0], [self::BANK, 0, 134.40]]);
        $this->assertEqualsWithDelta(-184.40, $this->net($db)[self::BANK], 0.001, 'one booked: the line keeps only the $50 not yet covered');
        $this->assertSame(1, $this->liveBank($db, 36605));

        $this->receipt($db, 363, [[self::MISC, 50.00, 0], [self::BANK, 0, 50.00]]);
        $net = $this->net($db);
        $this->assertEqualsWithDelta(-184.40, $net[self::BANK], 0.001, 'the debit leaves chequing once');
        $this->assertEqualsWithDelta(6.00, $net[self::GST], 0.001, 'the $6 GST credit on the sharpening is claimed');
        $this->assertEqualsWithDelta(178.40, $net[self::MISC], 0.001);
        $this->assertSame(0, $this->liveBank($db, 36605), 'both booked: the line has no posting of its own');
        $this->assertStringContainsString('booked by receipts #359 + #363',
            (string)$db->query("SELECT memo FROM journal_entries WHERE memo LIKE 'Reversal%' ORDER BY id DESC LIMIT 1")->fetchColumn());
    }

    public function test_a_receipt_rejected_or_edited_after_matching_gives_the_line_back_its_share(): void
    {
        $db = $this->db();
        $ledger = new LedgerService($db);
        $this->line($db, 600, 184.40);
        $this->bankEntry($db, 600, [[self::MISC, 184.40, 0], [self::BANK, 0, 184.40]]);
        $this->expense($db, 61, 134.40);
        $this->expense($db, 62, 50.00);
        (new BankLineReceipts($db))->attach(600, [61, 62], 1);
        $this->receipt($db, 61, [[self::MISC, 128.40, 0], [self::GST, 6.00, 0], [self::BANK, 0, 134.40]]);
        $this->receipt($db, 62, [[self::MISC, 50.00, 0], [self::BANK, 0, 50.00]]);
        $this->assertEqualsWithDelta(-184.40, $this->net($db)[self::BANK], 0.001);

        // The blades receipt rejected: its entry reversed, the line takes the $50 back.
        $ledger->reverseEntry((int)$ledger->findEntryIdBySource('expense', 62), 1, 'rejected');
        $ledger->settleGroupFor(62, 1);
        $this->assertEqualsWithDelta(-184.40, $this->net($db)[self::BANK], 0.001, 'still the one debit, not $134.40');

        // Approved again at $45 (corrected): the line keeps $5.
        $db->exec("UPDATE expenses SET total = 45 WHERE id = 62");
        $this->receipt($db, 62, [[self::MISC, 45.00, 0], [self::BANK, 0, 45.00]]);
        $this->assertEqualsWithDelta(-184.40, $this->net($db)[self::BANK], 0.001, 'still exact: $134.40 + $45 + $5 on the line');
        $this->assertSame(1, $this->liveBank($db, 600));
        $this->assertSame(0, $ledger->settleGroupLine(600, 1), 'settled lines stay put (idempotent)');
    }

    public function test_a_remainder_keeps_the_lines_gst_split(): void
    {
        $db = $this->db();
        $this->line($db, 700, 105.00);
        $this->bankEntry($db, 700, [[self::MISC, 100.00, 0], [self::GST, 5.00, 0], [self::BANK, 0, 105.00]]);
        $this->expense($db, 71, 52.50);
        $this->expense($db, 72, 52.50);
        (new BankLineReceipts($db))->attach(700, [71, 72], 1);
        $this->receipt($db, 71, [[self::MISC, 50.00, 0], [self::GST, 2.50, 0], [self::BANK, 0, 52.50]]);
        $net = $this->net($db);
        $this->assertEqualsWithDelta(-105.00, $net[self::BANK], 0.001);
        $this->assertEqualsWithDelta(5.00, $net[self::GST], 0.001, 'half the GST from the receipt, half kept on the line');
        $this->assertEqualsWithDelta(100.00, $net[self::MISC], 0.001);
    }

    public function test_deleting_a_grouped_receipt_takes_it_off_the_line(): void
    {
        $db = $this->db();
        $this->line($db, 800, 30.00);
        $this->bankEntry($db, 800, [[self::MISC, 30.00, 0], [self::BANK, 0, 30.00]]);
        $this->expense($db, 81, 20.00);
        $this->expense($db, 82, 10.00);
        $svc = new BankLineReceipts($db);
        $svc->attach(800, [81, 82], 1);
        $this->receipt($db, 81, [[self::MISC, 20.00, 0], [self::BANK, 0, 20.00]]);
        $this->receipt($db, 82, [[self::MISC, 10.00, 0], [self::BANK, 0, 10.00]]);
        // the main receipt (#81) is deleted: its entry reversed, then off the line, which books what's left
        $ledger = new LedgerService($db);
        $ledger->reverseEntry((int)$ledger->findEntryIdBySource('expense', 81), 1, 'deleted');
        $this->assertSame(800, $svc->forget(81));
        $ledger->settleGroupLine(800, 1);
        $this->assertSame(82, (int)$db->query("SELECT matched_expense_id FROM accounting_transactions WHERE id = 800")->fetchColumn(), '#82 is now the line\'s receipt');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM bank_line_receipts")->fetchColumn());
        $this->assertEqualsWithDelta(-30.00, $this->net($db)[self::BANK], 0.001, '$10 receipt + $20 kept on the line');
    }

    public function test_a_gap_changes_nothing(): void
    {
        $db = $this->db();
        $this->line($db, 200, 184.40);
        $this->expense($db, 1, 134.40);
        $this->expense($db, 2, 56.00);
        try {
            (new BankLineReceipts($db))->attach(200, [1, 2], 1);
            $this->fail('a $6 gap must be refused');
        } catch (BankLineReceiptsRefused $e) {
            $this->assertStringContainsString('$6.00 apart', $e->getMessage());
        }
        $this->assertNull($db->query("SELECT matched_expense_id FROM accounting_transactions WHERE id = 200")->fetchColumn() ?: null);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM bank_line_receipts")->fetchColumn());
    }

    public function test_a_receipt_already_on_a_line_and_a_line_already_matched_are_refused(): void
    {
        $db = $this->db();
        $this->line($db, 300, 30.00);
        $this->line($db, 301, 30.00);
        $this->expense($db, 10, 20.00);
        $this->expense($db, 11, 10.00);
        $this->expense($db, 12, 10.00);
        $svc = new BankLineReceipts($db);
        $svc->attach(300, [10, 11], 1);
        $this->assertSame(300, $svc->lineFor(11), 'the extra receipt knows its line');
        $this->assertSame(['line' => 300, 'main' => 10, 'receipts' => [10, 11]], $svc->group(11));

        $this->expectException(BankLineReceiptsRefused::class);
        $svc->attach(301, [11, 12], 1);
    }

    public function test_unmatching_the_line_frees_every_receipt(): void
    {
        $db = $this->db();
        $this->line($db, 400, 30.00);
        $this->expense($db, 20, 20.00);
        $this->expense($db, 21, 10.00);
        (new BankLineReceipts($db))->attach(400, [20, 21], 1);
        $r = (new BankImportService($db))->detachExpenseMatch(400, 1);
        $this->assertSame([21], $r['also_unmatched']);
        $svc = new BankLineReceipts($db);
        $this->assertNull($svc->lineFor(20));
        $this->assertNull($svc->lineFor(21));
    }

    public function test_linked_sql_counts_one_of_several(): void
    {
        $db = $this->db();
        $this->line($db, 500, 30.00);
        $this->expense($db, 30, 20.00);
        $this->expense($db, 31, 10.00);
        $this->expense($db, 32, 10.00);
        (new BankLineReceipts($db))->attach(500, [30, 31], 1);
        $linked = $db->query("SELECT e.id FROM expenses e WHERE " . BankLineReceipts::linkedSql($db, 'e.id') . " ORDER BY e.id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([30, 31], array_map('intval', $linked));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function receipt(PDO $db, int $id, array $lines): void
    {
        $l = [];
        foreach ($lines as $x) $l[] = ['account_id' => $x[0], 'debit' => $x[1], 'credit' => $x[2]];
        (new LedgerService($db))->postManual(['entry_date' => '2026-08-31', 'memo' => 'Expense #' . $id, 'source_type' => 'expense',
                                             'source_id' => $id, 'lines' => $l]);
    }

    private function line(PDO $db, int $id, float $amount): void
    {
        $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id)
                      VALUES (?, '2026-08-31', 'expense', ?, 'Point of sale SQ CHAMPION LAWN MOWE', 4, 'bank_import', 1)")->execute([$id, $amount]);
    }

    private function expense(PDO $db, int $id, float $total): void
    {
        $db->prepare("INSERT INTO expenses (id, expense_date, total, status, payment_method) VALUES (?, '2026-08-31', ?, 'draft', 'debit')")->execute([$id, $total]);
    }

    private function bankEntry(PDO $db, int $txId, array $lines): void
    {
        $db->prepare("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status) VALUES ('2026-08-31', 'bank', 'bank_import', ?, 'posted')")->execute([$txId]);
        $e = (int)$db->lastInsertId();
        foreach ($lines as $x) {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)")->execute([$e, $x[0], $x[1], $x[2]]);
        }
    }

    private function liveBank(PDO $db, int $line): int
    {
        return (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_import' AND source_id = $line AND reversed_by_entry_id IS NULL")->fetchColumn();
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
        foreach (['match_confidence INTEGER', 'matched_at TEXT', 'matched_by TEXT'] as $c) $db->exec("ALTER TABLE accounting_transactions ADD COLUMN $c");
        $db->exec("CREATE TABLE IF NOT EXISTS expenses (id INTEGER PRIMARY KEY, expense_date TEXT, total REAL, gst_amount REAL DEFAULT 0, pst_amount REAL DEFAULT 0,
                   status TEXT, payment_method TEXT, vendor_id INTEGER, vendor_name_raw TEXT, description TEXT, accounting_category TEXT)");
        $db->exec("CREATE TABLE bank_line_receipts (id INTEGER PRIMARY KEY AUTOINCREMENT, transaction_id INTEGER NOT NULL, expense_id INTEGER NOT NULL UNIQUE,
                   created_by INTEGER, created_at TEXT)");
        return $db;
    }
}
