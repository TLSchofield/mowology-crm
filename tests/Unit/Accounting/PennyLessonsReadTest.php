<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Penny reads the owner's corrections back (plan 2026-10-14 penny-learning-and-crew-view A). */
class PennyLessonsReadTest extends TestCase
{
    public function test_only_one_at_a_time_corrections_of_learnable_fields_are_safe(): void
    {
        $this->assertTrue(PennyLessons::safeLesson(['field' => 'accounting_category', 'source' => 'expense_gate:desktop_modal']));
        $this->assertTrue(PennyLessons::safeLesson(['field' => 'rejected', 'source' => 'penny_reject']));
        $this->assertFalse(PennyLessons::safeLesson(['field' => 'job_id', 'source' => 'penny_card']), 'the job is per purchase');
        $this->assertFalse(PennyLessons::safeLesson(['field' => 'accounting_category', 'source' => 'expense_gate:owner_paid_import']), 'bulk import');
        $this->assertFalse(PennyLessons::safeLesson(['field' => 'accounting_category', 'source' => 'expense_gate:reconcile_visa']));
        $this->assertFalse(PennyLessons::safeLesson(['field' => 'vendor_name_raw', 'source' => 'expense_gate:desktop_modal']), 'a misread vendor is never learned');
        $this->assertFalse(PennyLessons::safeLesson(['field' => 'account', 'source' => 'visa_personal_to_1300']));
    }

    public function test_for_vendor_skips_this_receipt_later_lessons_and_bulk_rows(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY, site_id INT, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT,
                   said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");
        $ins = $db->prepare("INSERT INTO head_lessons (site_id, head, subject_kind, subject_key, field, said, correct, source, ref_type, ref_id, reason, created_at)
                             VALUES (1, 'penny', 'vendor', 'dunbar lumber', ?, ?, ?, ?, 'expense', ?, ?, ?)");
        $ins->execute(['accounting_category', 'Tools/Equipment', 'Materials', 'expense_gate:desktop_modal', 10, null, '2026-09-01 10:00:00']);
        $ins->execute(['accounting_category', 'Tools/Equipment', 'Materials', 'expense_gate:owner_paid_import', 11, null, '2026-09-02 10:00:00']);
        $ins->execute(['rejected', 'prepared', 'rejected', 'penny_reject', 12, 'personal purchase', '2026-09-03 10:00:00']);
        $ins->execute(['asset_tag', 'none', 'stock', 'expense_gate:desktop_modal', 20, null, '2026-09-04 10:00:00']);   // the receipt itself
        $ins->execute(['job_id', null, '55', 'expense_gate:desktop_modal', 13, null, '2026-10-01 10:00:00']);           // after the replay date

        $db->exec("INSERT INTO head_lessons (site_id, head, subject_kind, subject_key, field, said, correct, source, created_at)
                   VALUES (2, 'penny', 'vendor', 'dunbar lumber', 'accounting_category', 'x', 'Fuel', 'expense_gate:desktop_modal', '2026-09-05')");   // another company
        $all = PennyLessons::forVendor($db, 'Dunbar  Lumber', 20);
        $this->assertSame(['rejected', 'accounting_category'], array_column($all, 'field'), 'a job is never a vendor lesson');
        $this->assertSame('personal purchase', $all[0]['why']);

        $ins->execute(['asset_tag', 'none', 'truck', 'expense_gate:desktop_modal', 14, null, '2026-09-15 16:00:00']);   // the receipt's own day
        $replay = PennyLessons::forVendor($db, 'Dunbar Lumber', 20, '2026-09-15');
        $this->assertSame(['rejected', 'accounting_category'], array_column($replay, 'field'), 'a replay never sees later lessons');
    }

    public function test_track_record_counts_fields_kept(): void
    {
        $t = PennyLessons::tallyOutcomes([
            ['accounting_category' => ['accepted' => true], 'job' => ['accepted' => false]],
            ['accounting_category' => ['accepted' => false], 'job' => ['accepted' => true]],
            ['accounting_category' => ['accepted' => true]],
            ['draft' => ['total' => 5]],
        ]);
        $this->assertSame(['accounting_category' => '2 of 3 right', 'job' => '1 of 2 right'], $t);
    }
}
