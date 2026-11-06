<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';
require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLearning.php';

/**
 * Day/month order of a receipt date: proven by the receipt itself, else the vendor's learned
 * habit, else month first. All receipt text here is invented.
 */
class ReceiptDateOrderTest extends TestCase
{
    private const AMBIGUOUS = "Example Hardware\n12/02/25 09:00\n12/02/2025\nTotal 10.00\n";
    private const PROVEN    = "Example Hardware\n12/02/25 09:00\n12/02/2025\nTotal 10.00\nReturns by 13/05/2025\n";

    public function test_a_day_over_twelve_elsewhere_proves_day_first(): void
    {
        $this->assertSame('dmy', receiptDateOrder(self::PROVEN));
        $this->assertSame('2025-02-12', extractDate(self::PROVEN));
    }

    public function test_without_evidence_the_default_is_month_first(): void
    {
        $this->assertNull(receiptDateOrder(self::AMBIGUOUS));
        $this->assertSame('2025-12-02', extractDate(self::AMBIGUOUS));
    }

    public function test_vendor_habit_is_used_without_evidence(): void
    {
        $this->assertSame('2025-02-12', extractDate(self::AMBIGUOUS, 'dmy'));
        $this->assertSame('2025-12-02', extractDate(self::AMBIGUOUS, 'mdy'));
        $this->assertSame('2025-12-02', extractDate(self::AMBIGUOUS, 'junk'));
    }

    public function test_evidence_beats_the_vendor_habit(): void
    {
        $this->assertSame('2025-02-12', extractDate(self::PROVEN, 'mdy'));
        $text = "Shop\n12/02/2025\nExpires 05/13/2025\n";
        $this->assertSame('mdy', receiptDateOrder($text));
        $this->assertSame('2025-12-02', extractDate($text, 'dmy'));
    }

    public function test_conflicting_votes_give_no_order(): void
    {
        $text = "Shop\n12/02/2025\n13/05/2025\n05/13/2025\n";
        $this->assertNull(receiptDateOrder($text));
        $this->assertSame('2025-12-02', extractDate($text));
        $this->assertSame('2025-02-12', extractDate($text, 'dmy'));
    }

    public function test_an_unambiguous_date_is_read_as_printed(): void
    {
        $this->assertSame('2025-02-25', extractDate('25/02/2025', 'mdy'));
        $this->assertSame('2025-02-25', extractDate('25/02/2025', 'dmy'));
        $this->assertSame('2025-12-25', extractDate('12/25/2025', 'dmy'));
        $this->assertSame('2025-02-12', extractDate('2025-02-12 10:00', 'dmy'));
    }

    public function test_parse_receipt_text_reports_order_and_source(): void
    {
        $p = parseReceiptText(self::PROVEN);
        $this->assertSame('2025-02-12', $p['date']);
        $this->assertSame('dmy', $p['date_order']);
        $this->assertSame('receipt', $p['date_order_source']);

        $p = parseReceiptText(self::AMBIGUOUS, null, ['date_order' => 'dmy']);
        $this->assertSame('2025-02-12', $p['date']);
        $this->assertNull($p['date_order']);
        $this->assertSame('vendor', $p['date_order_source']);

        $p = parseReceiptText(self::AMBIGUOUS);
        $this->assertSame('2025-12-02', $p['date']);
        $this->assertSame('default', $p['date_order_source']);

        $p = parseReceiptText("Shop\n25/02/2025\nTotal 10.00\n", null, ['date_order' => 'mdy']);
        $this->assertSame('2025-02-25', $p['date']);
        $this->assertNull($p['date_order_source']);

        $p = parseReceiptText("Shop\nTotal 10.00\n");
        $this->assertNull($p['date']);
        $this->assertNull($p['date_order_source']);
    }

    // ── Learning ──────────────────────────────────────────────────────

    public function test_proven_order_is_the_lesson(): void
    {
        $this->assertSame('dmy', dateOrderLesson(self::PROVEN, '2025-12-02'));
    }

    public function test_ambiguous_date_approved_as_one_reading_teaches_that_order(): void
    {
        $this->assertSame('dmy', dateOrderLesson(self::AMBIGUOUS, '2025-02-12'));
        $this->assertSame('mdy', dateOrderLesson(self::AMBIGUOUS, '2025-12-02'));
        $this->assertSame('dmy', dateOrderLesson(self::AMBIGUOUS, '2025-02-12 00:00:00'));
    }

    public function test_approved_date_matching_neither_reading_teaches_nothing(): void
    {
        $this->assertNull(dateOrderLesson(self::AMBIGUOUS, '2025-03-04'));
        $this->assertNull(dateOrderLesson(self::AMBIGUOUS, null));
        $this->assertNull(dateOrderLesson("Shop\n05/05/25\n", '2025-05-05'));
    }

    public function test_tied_counts_give_no_order(): void
    {
        $this->assertNull(dateOrderFromCounts(0, 0));
        $this->assertNull(dateOrderFromCounts(2, 2));
        $this->assertSame('dmy', dateOrderFromCounts(3, 1));
        $this->assertSame('mdy', dateOrderFromCounts(0, 1));
    }

    private function lessonDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->sqliteCreateFunction('NOW', function () { return '2026-01-01 00:00:00'; }, 0);
        $db->exec("CREATE TABLE receipt_parse_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, vendor_id INTEGER, vendor_name TEXT,
            field_name TEXT, ocr_value TEXT, corrected_value TEXT, ocr_context TEXT, learned_pattern TEXT,
            times_seen INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)");
        $db->exec('CREATE TABLE vendor_parse_profiles (id INTEGER PRIMARY KEY, vendor_id INTEGER)');
        return $db;
    }

    public function test_approval_records_the_vendor_order_and_the_profile_reads_it(): void
    {
        $db = $this->lessonDb();
        $final = ['expense_date' => '2025-02-12'];
        recordHeaderLessons($db, 7, 'Example Hardware', ['date' => '2025-02-12'], $final, self::PROVEN);
        $row = $db->query("SELECT corrected_value, times_seen FROM receipt_parse_lessons WHERE field_name = 'date_order'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([['corrected_value' => 'dmy', 'times_seen' => 1]], $row);
        $this->assertSame('dmy', vendorDateOrderFromLessons($db, 7));
        $this->assertNull(vendorDateOrderFromLessons($db, 8));

        // Same order again counts; an opposite order tying it cancels the habit.
        recordHeaderLessons($db, 7, 'Example Hardware', ['date' => '2025-02-12'], $final, self::AMBIGUOUS);
        $this->assertSame('2', (string)$db->query("SELECT times_seen FROM receipt_parse_lessons WHERE corrected_value = 'dmy'")->fetchColumn());
        recordHeaderLessons($db, 7, 'Example Hardware', ['date' => '2025-12-02'], ['expense_date' => '2025-12-02'], self::AMBIGUOUS);
        recordHeaderLessons($db, 7, 'Example Hardware', ['date' => '2025-12-02'], ['expense_date' => '2025-12-02'], self::AMBIGUOUS);
        $this->assertNull(vendorDateOrderFromLessons($db, 7));
    }

    public function test_approval_with_an_unmatched_date_records_no_order(): void
    {
        $db = $this->lessonDb();
        recordHeaderLessons($db, 7, 'Example Hardware', ['date' => '2025-12-02'], ['expense_date' => '2025-03-04'], self::AMBIGUOUS);
        $this->assertSame('0', (string)$db->query("SELECT COUNT(*) FROM receipt_parse_lessons WHERE field_name = 'date_order'")->fetchColumn());
    }
}
