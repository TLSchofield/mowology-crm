<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The statement-vs-books checker: every month's gap splits into named items that add up to the cent. */
class StatementBooksCheckServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (2, '1010', 'Chequing', 'asset'), (9, '4000', 'Sales', 'revenue'), (10, '6100', 'Fuel', 'expense'), (11, '1100', 'AR', 'asset')");
        $db->exec("CREATE TABLE bank_statement_sections (id INTEGER PRIMARY KEY, bank_account_id INT, period_from TEXT, period_to TEXT,
                   opening_balance REAL, closing_balance REAL, line_count INT)");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, source_type TEXT, source_id INT, memo TEXT,
                   status TEXT DEFAULT 'posted', reversed_by_entry_id INT)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INT, account_id INT, debit REAL DEFAULT 0, credit REAL DEFAULT 0)");
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, description TEXT,
                   account_id INT, bank_account_id INT, matched_expense_id INT, matched_invoice_id INT, reference_type TEXT, status TEXT)");
        $db->exec("CREATE TABLE bank_import_rows (id INTEGER PRIMARY KEY, transaction_id INT, type TEXT)");
        return $db;
    }

    private function entry(PDO $db, int $id, string $date, string $src, ?int $srcId, float $dr, float $cr, string $memo = ''): void
    {
        $db->prepare("INSERT INTO journal_entries (id, entry_date, source_type, source_id, memo) VALUES (?, ?, ?, ?, ?)")->execute([$id, $date, $src, $srcId, $memo]);
        $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, 2, ?, ?)")->execute([$id, $dr, $cr]);
    }

    public function test_a_month_splits_into_named_items_that_add_up(): void
    {
        $db = $this->db();
        // Statement: opening 1,000; a $200 deposit, a $50 fuel purchase, a $30 purchase we never imported → closing 1,120.
        $db->exec("INSERT INTO bank_statement_sections VALUES (1, 2, '2026-05-01', '2026-05-31', 1000, 1120, 3)");
        $db->exec("INSERT INTO accounting_transactions VALUES
            (1, '2026-05-10', 'transfer', 200, 'DEPOSIT', 9, 2, NULL, 5, 'bank_import', 'cleared'),
            (2, '2026-05-12', 'expense', 50, 'SHELL', 10, 2, 77, NULL, 'bank_import', 'cleared')");
        $db->exec("INSERT INTO bank_import_rows VALUES (1, 1, 'income'), (2, 2, 'expense')");
        // Books: the deposit is settled (posts nothing); its invoice payment posts $200 AND a second $200 that isn't deposited;
        // the fuel line posts through its receipt; and nothing for the unimported $30.
        $this->entry($db, 10, '2026-05-09', 'payment', 5, 200, 0, 'Payment for invoice #5');
        $this->entry($db, 11, '2026-05-20', 'payment', 6, 200, 0, 'Payment for invoice #6');
        $this->entry($db, 12, '2026-05-12', 'expense', 77, 0, 50, 'Expense #77');

        $r = (new StatementBooksCheckService($db))->check(2, '2026-05-01', '2026-05-31');
        $this->assertEquals(120.0, $r['statement']['move']);
        $this->assertEquals(350.0, $r['book_move']);
        $this->assertEquals(230.0, $r['gap']);
        $this->assertTrue($r['adds_up']);
        $this->assertCount(1, $r['lines'], 'the settled deposit: nothing posted for it');
        $this->assertEquals(-200.0, $r['lines'][0]['diff']);
        $this->assertSame([400.0], [array_sum(array_column($r['others'], 'effect'))], 'two payments booked straight to the bank');
        $this->assertEquals(-30.0, $r['missing'], 'a statement line we never imported');
    }

    public function test_a_reversal_stays_with_its_line(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO bank_statement_sections VALUES (1, 2, '2026-06-01', '2026-06-30', 0, -150, 1)");
        $db->exec("INSERT INTO accounting_transactions VALUES (1, '2026-06-03', 'transfer', 150, 'TO SAVINGS', 9, 2, NULL, NULL, 'bank_import', 'cleared')");
        $db->exec("INSERT INTO bank_import_rows VALUES (1, 1, 'expense')");
        $this->entry($db, 20, '2026-06-03', 'bank_import', 1, 0, 150);
        $this->entry($db, 21, '2026-06-05', 'adjusting', null, 150, 0, 'Reversal of #20');
        $db->exec("UPDATE journal_entries SET reversed_by_entry_id = 21 WHERE id = 20");
        $r = (new StatementBooksCheckService($db))->check(2, '2026-06-01', '2026-06-30');
        $this->assertTrue($r['adds_up']);
        $this->assertCount(1, $r['lines']);
        $this->assertStringContainsString('reversed', $r['lines'][0]['why']);
        $this->assertSame([], $r['others'], 'the reversal is not a separate item');
    }
}
