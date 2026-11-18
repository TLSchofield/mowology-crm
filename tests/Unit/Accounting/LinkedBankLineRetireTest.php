<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . "/BankLineMoveServiceTest.php";

/**
 * A receipt linked to a bank line that had already posted its own entry: the receipt's entry books the
 * purchase, the line's posting is reversed (Home Depot #321, Flute & Fromage #349 counted twice; 2026-10-15).
 */
class LinkedBankLineRetireTest extends TestCase
{
    private const BANK = 1, MISC = 4, CARD = 7;

    public function test_posting_the_receipt_retires_the_lines_own_entry(): void
    {
        $db = $this->db();
        $this->line($db, 100, 199.71, 1, 321);
        $this->bankEntry($db, 100, [[self::MISC, 199.71, 0], [self::BANK, 0, 199.71]]);

        $this->receipt($db, 321, [[self::MISC, 199.71, 0], [self::BANK, 0, 199.71]]);

        $this->assertEqualsWithDelta(-199.71, $this->net($db)[self::BANK], 0.001, 'the purchase leaves chequing once');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'bank_import' AND reversed_by_entry_id IS NOT NULL")->fetchColumn());
    }

    public function test_a_receipt_paid_from_another_account_or_amount_is_left_for_a_person(): void
    {
        $db = $this->db();
        $this->line($db, 101, 50.00, 1, 322);
        $this->bankEntry($db, 101, [[self::MISC, 50, 0], [self::BANK, 0, 50]]);
        $this->receipt($db, 322, [[self::MISC, 50, 0], [self::CARD, 0, 50]]);            // receipt says card
        $this->line($db, 102, 40.00, 1, 323);
        $this->bankEntry($db, 102, [[self::MISC, 40, 0], [self::BANK, 0, 40]]);
        $this->receipt($db, 323, [[self::MISC, 45, 0], [self::BANK, 0, 45]]);            // different amount

        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE reversed_by_entry_id IS NOT NULL")->fetchColumn());
    }

    public function test_a_link_made_after_both_posted_is_caught_by_the_sweep_and_the_sweep_is_idempotent(): void
    {
        $db = $this->db();
        $ledger = new LedgerService($db);
        $this->receipt($db, 324, [[self::MISC, 80, 0], [self::BANK, 0, 80]]);
        $this->line($db, 103, 80.00, 1, null);
        $this->bankEntry($db, 103, [[self::MISC, 80, 0], [self::BANK, 0, 80]]);
        $db->exec("UPDATE accounting_transactions SET matched_expense_id = 324 WHERE id = 103");   // linked later

        $this->assertSame(1, $ledger->retireLinkedBankEntries(324));
        $this->assertSame(0, $ledger->retireLinkedBankEntries(324), 'already retired');
        $this->assertEqualsWithDelta(-80.0, $this->net($db)[self::BANK], 0.001);
    }

    public function test_a_refund_line_is_never_retired(): void
    {
        $db = $this->db();
        $this->line($db, 104, 30.00, 1, 325);
        $this->bankEntry($db, 104, [[self::BANK, 30, 0], [self::MISC, 0, 30]]);          // money back IN
        $this->receipt($db, 325, [[self::MISC, 30, 0], [self::BANK, 0, 30]]);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM journal_entries WHERE reversed_by_entry_id IS NOT NULL")->fetchColumn());
    }

    public function test_a_receipt_split_over_two_lines_retires_both_or_neither(): void
    {
        $db = $this->db();
        $this->line($db, 105, 60.00, 1, 326);
        $this->bankEntry($db, 105, [[self::MISC, 60, 0], [self::BANK, 0, 60]]);
        $this->line($db, 106, 40.00, 1, 326);
        $this->bankEntry($db, 106, [[self::MISC, 40, 0], [self::BANK, 0, 40]]);
        $this->receipt($db, 326, [[self::MISC, 100, 0], [self::BANK, 0, 100]]);
        $this->assertEqualsWithDelta(-100.0, $this->net($db)[self::BANK], 0.001, '$60 + $40 = the receipt: both retired');

        $db2 = $this->db();
        $this->line($db2, 107, 100.00, 1, 327);
        $this->bankEntry($db2, 107, [[self::MISC, 100, 0], [self::BANK, 0, 100]]);
        $this->line($db2, 108, 100.00, 1, 327);
        $this->bankEntry($db2, 108, [[self::MISC, 100, 0], [self::BANK, 0, 100]]);
        $this->receipt($db2, 327, [[self::MISC, 100, 0], [self::BANK, 0, 100]]);
        $this->assertSame(0, (int)$db2->query("SELECT COUNT(*) FROM journal_entries WHERE reversed_by_entry_id IS NOT NULL")->fetchColumn(),
            'two $100 lines for one $100 receipt: left for a person');
    }

    private function receipt(PDO $db, int $id, array $lines): void
    {
        $l = [];
        foreach ($lines as $x) $l[] = ['account_id' => $x[0], 'debit' => $x[1], 'credit' => $x[2]];
        (new LedgerService($db))->postManual(['entry_date' => '2026-08-23', 'memo' => 'Expense #' . $id, 'source_type' => 'expense',
                                             'source_id' => $id, 'lines' => $l]);
    }

    private function line(PDO $db, int $id, float $amount, int $bankId, ?int $expenseId): void
    {
        $db->prepare("INSERT INTO accounting_transactions (id, transaction_date, type, amount, description, account_id, reference_type, bank_account_id, matched_expense_id)
                      VALUES (?, '2026-08-23', 'expense', ?, 'POINT OF SALE', 4, 'bank_import', ?, ?)")->execute([$id, $amount, $bankId, $expenseId]);
    }

    private function bankEntry(PDO $db, int $txId, array $lines): void
    {
        $db->prepare("INSERT INTO journal_entries (entry_date, memo, source_type, source_id, status) VALUES ('2026-08-23', 'bank', 'bank_import', ?, 'posted')")->execute([$txId]);
        $e = (int)$db->lastInsertId();
        foreach ($lines as $x) {
            $db->prepare("INSERT INTO journal_lines (entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)")->execute([$e, $x[0], $x[1], $x[2]]);
        }
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
        return $db;
    }
}
