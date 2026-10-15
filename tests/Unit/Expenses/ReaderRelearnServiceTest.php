<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Expenses/Services/ReaderRelearnService.php';

/** A layout lesson rescans the vendor's waiting receipts whose items are missing or wrong (Tim, 2026-10-15). */
class ReaderRelearnServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INT, status TEXT, receipt_media_id INT, total REAL, amount REAL, expense_date TEXT)");
        $db->exec("CREATE TABLE expense_line_items (id INTEGER PRIMARY KEY, expense_id INT, line_total REAL)");
        $db->exec("CREATE TABLE expense_change_log (id INTEGER PRIMARY KEY, expense_id INT, action TEXT, source TEXT, actor_user_id INT, actor_kind TEXT, fields TEXT, before_text TEXT, after_text TEXT, note TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("INSERT INTO vendors VALUES (5, 'CHEVRON')");
        $db->exec("INSERT INTO expenses VALUES
            (1, 5, 'draft', 11, 17.16, 16.34, '2026-09-01'),   -- no items
            (2, 5, 'draft', 12, 8.75, 8.33, '2026-09-02'),     -- items add up to the subtotal: leave it
            (3, 5, 'approved', 13, 9.00, NULL, '2026-09-03'),  -- approved: never
            (4, 5, 'draft', NULL, 5.00, NULL, '2026-09-04'),   -- no photo: can't rescan
            (5, 5, 'pending_approval', 15, 21.91, 20.87, '2026-09-05'),  -- items wrong
            (6, 9, 'draft', 16, 3.00, NULL, '2026-09-06')      -- another vendor");
        $db->exec("INSERT INTO expense_line_items (expense_id, line_total) VALUES (2, 8.33), (5, 0.14), (7, 1.00)");
        // 7: items wrong, but a person edited its lines — never rescanned
        $db->exec("INSERT INTO expenses VALUES (7, 5, 'draft', 17, 9.99, NULL, '2026-09-07')");
        $db->exec("INSERT INTO expense_change_log (expense_id, action, source, actor_kind, fields, created_at) VALUES (7, 'lines', 'desktop_line', 'user', 'line_items', '2026-09-08 10:00:00')");
        return $db;
    }

    public function testNeedsRescan(): void
    {
        $this->assertTrue(ReaderRelearnService::needsRescan(0, 0, null, 17.16));
        $this->assertFalse(ReaderRelearnService::needsRescan(2, 8.33, 8.33, 8.75), 'matches the subtotal');
        $this->assertFalse(ReaderRelearnService::needsRescan(1, 17.16, null, 17.16), 'matches the total');
        $this->assertTrue(ReaderRelearnService::needsRescan(1, 0.14, 20.87, 21.91));
    }

    public function testOnlyTheVendorsBrokenWaitingReceiptsOncePerLesson(): void
    {
        $db = $this->db();
        $done = [];
        $svc = new ReaderRelearnService($db, function (int $id) use (&$done) { $done[] = $id; });
        $svc->queue(5);
        $r = $svc->run(1);
        $this->assertSame([1], $done, 'one per run, oldest first');
        $this->assertSame(0, $r['vendors_done']);
        $r = $svc->run(3);
        $this->assertSame([1, 5], $done);
        $this->assertSame(1, $r['vendors_done'], 'nothing left: off the queue');
        $this->assertSame([], $svc->map());
        $this->assertSame(0, $svc->run(3)['rescanned'], 'not again for the same lesson');
        $svc->queue(5);   // a new lesson: the same receipts are due again (their items are still wrong here)
        $this->assertSame(2, $svc->run(3)['rescanned']);
        $this->assertNotContains(7, $done, 'hand-edited lines are never rescanned');
        // Penny's markRelearn (MySQL upsert) is exercised on production, not on SQLite.
    }

    public function testAFailureIsNotRetriedForTheSameLesson(): void
    {
        $db = $this->db();
        $svc = new ReaderRelearnService($db, function () { throw new RuntimeException('no image'); });
        $svc->queue(5);
        $this->assertSame(2, $svc->run(3)['failed']);
        $this->assertSame(0, $svc->run(3)['failed']);
    }
}
