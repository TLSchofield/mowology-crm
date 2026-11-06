<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Expenses/Services/MissingReceiptService.php';

/**
 * "It's already in" on a missing-receipt card (Codex nightly 2026-11-06): moving a receipt off
 * Penny's guessed charge and onto the one the crew picked happens in one go — if the picked
 * charge was answered meanwhile, Penny's match stays where it was.
 */
final class MissingReceiptAttachTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, created_by INTEGER, status TEXT, total REAL)");
        $db->exec("CREATE TABLE penny_missing_receipts (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, expense_id INTEGER,
                   resolved_by INTEGER, resolved_at TEXT, reason_note TEXT, next_nudge_at TEXT)");
        $db->exec("INSERT INTO users VALUES (7, 'Crew A')");
        $db->exec("INSERT INTO expenses VALUES (50, 7, 'pending', 42.00)");
        // #1 is the charge the crew picks; #2 holds Penny's amount-and-date guess for receipt #50.
        $db->exec("INSERT INTO penny_missing_receipts VALUES (1, 7, 'open', NULL, NULL, NULL, NULL, NULL)");
        $db->exec("INSERT INTO penny_missing_receipts VALUES (2, 7, 'received', 50, NULL, '2026-11-01 09:00:00', 'Receipt #50 came in', NULL)");
        return $db;
    }

    private function row(PDO $db, int $id): array
    {
        return $db->query("SELECT status, expense_id FROM penny_missing_receipts WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testAttachMovesThePennyGuessToThePickedCharge(): void
    {
        $db = $this->db();
        $r = (new MissingReceiptService($db))->attach(1, 50, ['id' => 7, 'role' => 'crew']);
        $this->assertSame('received', $r['status']);
        $this->assertSame('received', $this->row($db, 1)['status']);
        $this->assertSame(50, (int)$this->row($db, 1)['expense_id']);
        $this->assertSame('open', $this->row($db, 2)['status']);
        $this->assertFalse($db->inTransaction());
    }

    public function testAChargeAnsweredMeanwhileLeavesPennysMatchAlone(): void
    {
        $db = $this->db();
        // The picked charge is resolved between the read and the close: its UPDATE matches nothing.
        $db->exec("CREATE TRIGGER answered_meanwhile BEFORE UPDATE ON penny_missing_receipts
                   WHEN NEW.id = 1 AND NEW.status = 'received' BEGIN SELECT RAISE(IGNORE); END");
        $r = (new MissingReceiptService($db))->attach(1, 50, ['id' => 7, 'role' => 'crew']);
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString('Already sorted', $r['message']);
        $this->assertSame('received', $this->row($db, 2)['status'], 'Penny\'s match was not reopened');
        $this->assertSame(50, (int)$this->row($db, 2)['expense_id']);
        $this->assertFalse($db->inTransaction());
    }

    public function testAReceiptThePersonMatchedElsewhereIsRefused(): void
    {
        $db = $this->db();
        $db->exec("UPDATE penny_missing_receipts SET reason_note = 'Receipt #50 — from the crew card' WHERE id = 2");
        $r = (new MissingReceiptService($db))->attach(1, 50, ['id' => 7, 'role' => 'crew']);
        $this->assertFalse($r['ok']);
        $this->assertSame('open', $this->row($db, 1)['status']);
        $this->assertFalse($db->inTransaction());
    }
}
