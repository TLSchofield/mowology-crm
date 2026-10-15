<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Working receipts vendor by vendor (2026-10-14, the standard for every tenant): the card groups a
 * vendor's receipts, and a correction made the same way twice redoes that vendor's other waiting reads.
 */
class PennyVendorBatchTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, vendor_id INT, vendor_name_raw TEXT, status TEXT, expense_date TEXT)");
        $db->exec("CREATE TABLE expense_suggestions (id INTEGER PRIMARY KEY, expense_id INT, source TEXT, status TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY, site_id INT, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT,
                   said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $db->exec("INSERT INTO vendors VALUES (1, 'Chevron'), (2, 'Home Depot')");
        foreach ([[10, 1], [11, 1], [12, 1], [13, 2]] as [$id, $v]) {
            $db->exec("INSERT INTO expenses VALUES ($id, $v, NULL, 'pending_approval', '2026-03-0" . ($id - 9) . "')");
            $db->exec("INSERT INTO expense_suggestions (expense_id, source, status, created_at) VALUES ($id, 'live', 'pending', '2026-10-01 00:00:00')");
        }
        return $db;
    }

    private function lesson(PDO $db, int $ref, string $correct): void
    {
        $db->prepare("INSERT INTO head_lessons (site_id, head, subject_kind, subject_key, field, said, correct, source, ref_type, ref_id, created_at)
                      VALUES (1, 'penny', 'vendor', 'chevron', 'accounting_category', 'Fuel', ?, 'penny_card', 'expense', ?, '2026-10-14 09:00:00')")
           ->execute([$correct, $ref]);
    }

    public function test_one_correction_redoes_the_group_middle_path(): void
    {
        $db = $this->db();
        $this->lesson($db, 10, 'Meals');
        $r = (new BookkeeperDeskService($db))->learnedTwice(10, ['accounting_category' => ['accepted' => false, 'final' => 'Meals']]);
        $this->assertSame([2, 3], $r['others'], 'the other Chevron reads (11, 12)');
        $this->assertNull((new BookkeeperDeskService($db))->learnedTwice(10, ['asset_tag' => ['accepted' => true, 'final' => 'none']]), 'nothing corrected');
    }

    public function test_the_same_correction_twice_redoes_the_vendors_other_waiting_reads(): void
    {
        $db = $this->db();
        $this->lesson($db, 10, 'Meals');
        $this->lesson($db, 11, 'Meals');
        $r = (new BookkeeperDeskService($db))->learnedTwice(11, ['accounting_category' => ['accepted' => false, 'final' => 'Meals']]);
        $this->assertSame('Chevron', $r['vendor']);
        $this->assertSame(['accounting_category'], $r['fields']);
        $this->assertCount(2, $r['others'], 'receipts 10 and 12 — never this one, never Home Depot');
        $this->assertNull((new BookkeeperDeskService($db))->learnedTwice(11, ['accounting_category' => ['accepted' => true, 'final' => 'Meals']]));
    }

    public function test_a_big_group_is_finished_in_the_background_oldest_first(): void
    {
        $db = $this->db();
        $desk = new BookkeeperDeskService($db);
        // markRelearn() writes this with MySQL's upsert; the row as it leaves it:
        $db->exec("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('penny_relearn', '{\"chevron\":\"2026-10-14 10:00:00\"}')");
        $this->assertSame([1, 2], $desk->staleReads(2), 'Chevron reads made before the lesson');
        $db->exec("UPDATE expense_suggestions SET status = 'superseded' WHERE expense_id IN (10, 11, 12)");
        $this->assertSame([], $desk->staleReads(5));
        $this->assertSame('[]', $db->query("SELECT setting_value FROM ops_settings")->fetchColumn(), 'a finished vendor drops off');
    }

    /** The approve window compares her read with the receipt as it stands now. */
    public function test_read_vs_receipt_marks_each_field(): void
    {
        $sug = ['vendor' => ['value' => 'CHEVRON #4381'], 'accounting_category' => ['value' => 'Fuel'],
                'asset_tag' => ['value' => 'none'], 'job' => ['value' => null], 'total' => ['value' => 9.67]];
        $now = ['vendor' => 'Chevron', 'accounting_category' => 'Meals', 'asset_tag' => null, 'job_id' => null, 'total' => '9.67'];
        $o = BookkeeperDeskService::readVsReceipt($sug, $now);
        $this->assertTrue($o['vendor']['accepted'], 'same business');
        $this->assertFalse($o['accounting_category']['accepted']);
        $this->assertSame('Meals', $o['accounting_category']['final']);
        $this->assertTrue($o['asset_tag']['accepted'], '"none" and empty are the same');
        $this->assertTrue($o['job']['accepted']);
        $this->assertTrue($o['total']['accepted']);
    }
}
