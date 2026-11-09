<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A 2025 receipt traces itself to its bank line only when the line is certain (Tim, 2026-11-06). */
final class ClosedYearReceiptTraceTest extends TestCase
{
    public function testOneExactLineIsPicked(): void
    {
        $c = [['transaction_id' => 5360, 'amount' => 59.90, 'date' => '2025-04-03', 'confidence' => 90], ['transaction_id' => 9, 'amount' => 59.40, 'date' => '2025-04-03', 'confidence' => 60]];
        $this->assertSame(5360, ClosedYearReceiptTrace::pick($c, 59.90, '2025-04-02')['transaction_id']);
    }

    public function testNoExactLineIsNoPick(): void
    {
        // Within the candidate window but not the exact amount (a misread total): a person decides.
        $this->assertNull(ClosedYearReceiptTrace::pick([['transaction_id' => 5377, 'amount' => 41.00, 'date' => '2025-04-02', 'confidence' => 90]], 39.05, '2025-04-02'));
        $this->assertNull(ClosedYearReceiptTrace::pick([], 10.04, '2025-04-02'));
    }

    public function testTwoExactLinesAreLeftToAPerson(): void
    {
        $two = [['transaction_id' => 1, 'amount' => 12.00, 'date' => '2025-04-02', 'confidence' => 90], ['transaction_id' => 2, 'amount' => 12.00, 'date' => '2025-04-09', 'confidence' => 40]];
        $this->assertNull(ClosedYearReceiptTrace::pick($two, 12.00, '2025-04-02'));
    }

    public function testOnlyOpenClosedYearReceiptsApply(): void
    {
        $this->assertTrue(ClosedYearReceiptTrace::applies(['status' => 'pending_approval', 'expense_date' => '2025-04-02', 'total' => '59.90']));
        $this->assertTrue(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-12-31', 'total' => '1.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'pending_approval', 'expense_date' => '2026-01-01', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'approved', 'expense_date' => '2025-04-02', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'rejected', 'expense_date' => '2025-04-02', 'total' => '9.00']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '0']));
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '9.00', 'payment_method' => 'owner_paid']));
    }

    public function testAStrangerMoreThanThreeDaysAwayIsNoPick(): void
    {
        $line = [['transaction_id' => 1, 'amount' => 38.00, 'date' => '2025-04-06', 'confidence' => 52]];
        $this->assertNotNull(ClosedYearReceiptTrace::pick($line, 38.00, '2025-04-03'));
        $this->assertNull(ClosedYearReceiptTrace::pick($line, 38.00, '2025-04-02'));
    }

    public function testALineInTheOpenYearIsNotInTheFiledBooks(): void
    {
        $line = [['transaction_id' => 1, 'amount' => 38.00, 'date' => '2026-01-01', 'confidence' => 70]];
        $this->assertNull(ClosedYearReceiptTrace::pick($line, 38.00, '2025-12-31'));
    }

    public function testCashHasNoBankLineToFind(): void
    {
        $this->assertFalse(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '9.00', 'payment_method' => 'cash']));
        $this->assertTrue(ClosedYearReceiptTrace::applies(['status' => 'draft', 'expense_date' => '2025-04-02', 'total' => '9.00', 'payment_method' => 'debit']));
    }

    public function testAskingFirstIsTheDefault(): void
    {
        $this->assertSame('propose', ClosedYearReceiptTrace::mode(null));
        $this->assertSame('propose', ClosedYearReceiptTrace::mode('yes'));
        $this->assertSame('auto', ClosedYearReceiptTrace::mode(' Auto '));
    }

    public function testEachDoubtIsNamed(): void
    {
        $clean = ['office' => true, 'vendor_stop_on_upload_day' => false, 'printed_date' => '2025-04-02', 'paper_years' => [2025]];
        $this->assertSame([], ClosedYearReceiptTrace::doubts('2025-04-02', $clean));
        $this->assertCount(1, ClosedYearReceiptTrace::doubts('2025-04-02', ['office' => false] + $clean));
        $this->assertCount(1, ClosedYearReceiptTrace::doubts('2025-04-02', ['vendor_stop_on_upload_day' => true] + $clean));
        $this->assertCount(1, ClosedYearReceiptTrace::doubts('2025-04-02', ['printed_date' => '2026-04-02'] + $clean));
        $this->assertCount(1, ClosedYearReceiptTrace::doubts('2025-04-02', ['paper_years' => [2025, 2026]] + $clean));
    }

    private function kit(array $total = ['total' => 38.00, 'expense_date' => '2025-04-02']): array
    {
        $db = ExpenseGateTestDb::make();
        $db->exec("CREATE TABLE penny_questions (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, subject TEXT, expense_id INT, vendor_name TEXT, category TEXT,
                   amount REAL, question TEXT, status TEXT DEFAULT 'open', answer TEXT, answered_by INT, answered_at TEXT, plan_id INT, UNIQUE (kind, expense_id))");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, can_approve_own_expenses INT DEFAULT 0)");
        $db->exec("INSERT INTO users (id, role) VALUES (2, 'admin'), (3, 'user'), (4, 'admin')");
        $db->prepare("INSERT INTO expenses (id, expense_date, total, status, created_by, created_at, vendor_name_raw, payment_method) VALUES (7, ?, ?, 'pending_approval', 2, '2026-11-08 09:00:00', 'Shop', 'debit')")
            ->execute([$total['expense_date'], $total['total']]);
        $db->exec("INSERT INTO accounting_transactions (id, transaction_date, type, amount, reference_type, description) VALUES (50, '2025-04-03', 'expense', 38.00, 'bank_import', 'SHOP 123')");
        $bank = new class($db) {
            public array $attached = [];
            public array $detached = [];
            private PDO $db;
            public function __construct(PDO $db) { $this->db = $db; }
            public function candidateTransactionsForExpense(int $id, int $limit): array {
                return [['transaction_id' => 50, 'amount' => 38.00, 'date' => '2025-04-03', 'description' => 'SHOP 123']];
            }
            public function attachExpenseMatch(int $t, int $e, int $u): array {
                $this->attached[] = [$t, $e, $u];
                $this->db->prepare("UPDATE accounting_transactions SET matched_expense_id = ? WHERE id = ?")->execute([$e, $t]);
                return [];
            }
            public function detachExpenseMatch(int $t, int $u): array {
                $this->detached[] = [$t, $u];
                $this->db->prepare("UPDATE accounting_transactions SET matched_expense_id = NULL WHERE id = ?")->execute([$t]);
                return [];
            }
        };
        return [$db, $bank];
    }

    private function receiptStatus(PDO $db): string
    {
        return (string)$db->query("SELECT status FROM expenses WHERE id = 7")->fetchColumn();
    }

    public function testByDefaultPennyAsksAndTheReceiptKeepsWaiting(): void
    {
        [$db, $bank] = $this->kit();
        $r = (new ClosedYearReceiptTrace($db, $bank))->trace(7);
        $this->assertSame('proposed', $r['action']);
        $this->assertSame('pending_approval', $this->receiptStatus($db));
        $this->assertSame([], $bank->attached);
        $q = $db->query("SELECT kind, subject, category, status FROM penny_questions")->fetch();
        $this->assertSame(['file_closed_year', 'line:50', 'question', 'open'], [$q['kind'], $q['subject'], $q['category'], $q['status']]);
    }

    public function testAskedOnceEvenWhenSavedAgain(): void
    {
        [$db, $bank] = $this->kit();
        $t = new ClosedYearReceiptTrace($db, $bank);
        $t->trace(7);
        $this->assertSame('proposed', $t->trace(7)['action']);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM penny_questions")->fetchColumn());
    }

    public function testAutoFilesOnlyWithNoDoubtAndLeavesANotice(): void
    {
        [$db, $bank] = $this->kit();
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('closed_year_autofile', 'auto')");
        $r = (new ClosedYearReceiptTrace($db, $bank))->trace(7);
        $this->assertSame('traced', $r['action']);
        $this->assertSame('rejected', $this->receiptStatus($db));
        $this->assertSame([[50, 7, 0]], $bank->attached);
        $this->assertSame('notice', $db->query("SELECT category FROM penny_questions")->fetchColumn());
    }

    public function testAutoStillAsksAboutACrewPhoto(): void
    {
        [$db, $bank] = $this->kit();
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('closed_year_autofile', 'auto')");
        $db->exec("UPDATE expenses SET created_by = 3 WHERE id = 7");
        $this->assertSame('proposed', (new ClosedYearReceiptTrace($db, $bank))->trace(7)['action']);
        $this->assertSame('pending_approval', $this->receiptStatus($db));
        $this->assertStringContainsString('crew', (string)$db->query("SELECT question FROM penny_questions")->fetchColumn());
    }

    public function testYesFilesAndLinksAsThePerson(): void
    {
        [$db, $bank] = $this->kit();
        $r = (new ClosedYearReceiptTrace($db, $bank))->fileProposed(7, 50, 4);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('rejected', $this->receiptStatus($db));
        $this->assertSame([[50, 7, 4]], $bank->attached);
    }

    public function testUndoFreesTheLineAndIsNeverFiledAgain(): void
    {
        [$db, $bank] = $this->kit();
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('closed_year_autofile', 'auto')");
        $t = new ClosedYearReceiptTrace($db, $bank);
        $t->trace(7);
        $db->exec("UPDATE penny_questions SET status = 'answered', answer = 'undo'");
        $r = $t->undo(7, 50, 4);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('pending_approval', $this->receiptStatus($db));
        $this->assertSame([[50, 4]], $bank->detached);
        $this->assertSame('held', $t->trace(7)['action']);
    }
}
