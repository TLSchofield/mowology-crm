<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptArchiveService — the pure, DB-free decision/path helpers that
 * govern which receipts get archived, how bundles are split, and who receives them.
 * These are the bits that must be correct for the "delete only after a confirmed
 * off-server copy exists" safety guarantee to hold.
 */
class ReceiptArchiveServiceTest extends TestCase
{
    // ── isConfirmedStatus ────────────────────────────────────────────────

    public function test_confirmed_statuses(): void
    {
        $this->assertTrue(ReceiptArchiveService::isConfirmedStatus('approved'));
        $this->assertTrue(ReceiptArchiveService::isConfirmedStatus('forwarded'));
    }

    public function test_unconfirmed_statuses(): void
    {
        $this->assertFalse(ReceiptArchiveService::isConfirmedStatus('draft'));
        $this->assertFalse(ReceiptArchiveService::isConfirmedStatus('cancelled'));
        $this->assertFalse(ReceiptArchiveService::isConfirmedStatus(''));
        $this->assertFalse(ReceiptArchiveService::isConfirmedStatus(null));
    }

    // ── archiveRelPath ───────────────────────────────────────────────────

    public function test_archive_rel_path_partitions_by_month(): void
    {
        $this->assertSame(
            'Storage/receipt-archive/2026/01/receipt-x.jpg',
            ReceiptArchiveService::archiveRelPath('2026-01-15', 'receipt-x.jpg')
        );
    }

    public function test_archive_rel_path_strips_directories_from_filename(): void
    {
        $this->assertSame(
            'Storage/receipt-archive/2026/12/r.png',
            ReceiptArchiveService::archiveRelPath('2026-12-01', '/uploads/receipts/r.png')
        );
    }

    // ── planZipSplit ─────────────────────────────────────────────────────

    public function test_plan_zip_split_empty(): void
    {
        $this->assertSame([], ReceiptArchiveService::planZipSplit([], 1000));
    }

    public function test_plan_zip_split_single_chunk_under_cap(): void
    {
        $items  = [['size' => 100], ['size' => 200], ['size' => 300]];
        $chunks = ReceiptArchiveService::planZipSplit($items, 1000);
        $this->assertCount(1, $chunks);
        $this->assertCount(3, $chunks[0]);
    }

    public function test_plan_zip_split_breaks_on_cap(): void
    {
        $items  = [['size' => 600], ['size' => 600], ['size' => 600]];
        $chunks = ReceiptArchiveService::planZipSplit($items, 1000);
        // 600 | 600 | 600 — each new item would exceed 1000 with the prior, so 3 chunks.
        $this->assertCount(3, $chunks);
    }

    public function test_plan_zip_split_oversize_item_emitted_alone(): void
    {
        $items  = [['size' => 50], ['size' => 5000], ['size' => 50]];
        $chunks = ReceiptArchiveService::planZipSplit($items, 1000);
        // [50] then [5000] (alone, exceeds cap) then [50].
        $this->assertCount(3, $chunks);
        $this->assertSame(5000, $chunks[1][0]['size']);
    }

    // ── recipientList ────────────────────────────────────────────────────

    public function test_recipient_list_collects_all_three_fields(): void
    {
        $list = ReceiptArchiveService::recipientList([
            'receipt_export_email_owner'      => 'owner@example.com',
            'receipt_export_email_accountant' => 'cpa@example.com',
            'receipt_accounting_email'        => 'books@example.com',
        ]);
        $this->assertSame(['owner@example.com', 'cpa@example.com', 'books@example.com'], $list);
    }

    public function test_recipient_list_filters_blank_and_invalid(): void
    {
        $list = ReceiptArchiveService::recipientList([
            'receipt_export_email_owner'      => '',
            'receipt_export_email_accountant' => 'not-an-email',
            'receipt_accounting_email'        => 'books@example.com',
        ]);
        $this->assertSame(['books@example.com'], $list);
    }

    public function test_recipient_list_dedupes_and_lowercases(): void
    {
        $list = ReceiptArchiveService::recipientList([
            'receipt_export_email_owner'      => 'Owner@Example.com',
            'receipt_export_email_accountant' => 'owner@example.com',
            'receipt_accounting_email'        => '',
        ]);
        $this->assertSame(['owner@example.com'], $list);
    }

    public function test_recipient_list_empty_when_nothing_configured(): void
    {
        $this->assertSame([], ReceiptArchiveService::recipientList([]));
    }

    // ── archiveAndPurge: an original that won't delete ───────────────────

    public function test_original_that_cannot_be_removed_is_not_marked_removed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) $this->markTestSkipped('root can unlink anything');
        $db = class_exists('Pdo\Sqlite') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $now = function () { return date('Y-m-d H:i:s'); };
        if ($db instanceof Pdo\Sqlite) $db->createFunction('NOW', $now, 0);
        else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, archived_at TEXT, archive_path TEXT, archive_batch_id INTEGER,
                   original_removed INTEGER DEFAULT 0, thumb_path TEXT)");
        $db->exec("INSERT INTO media_assets (id) VALUES (9)");
        $dir = sys_get_temp_dir() . '/mw-archive-test-' . getmypid();
        @mkdir($dir);
        $file = $dir . '/r.jpg';
        file_put_contents($file, 'x');
        chmod($dir, 0555);   // the file can't be unlinked
        try {
            $res = (new ReceiptArchiveService($db))->archiveAndPurge([[
                'media_id' => 9, '_abs' => $file, 'file_path' => 'uploads/receipts/r.jpg', 'stored_filename' => 'r.jpg',
                'mime_type' => 'image/jpeg', 'expense_date' => '2026-01-15', 'size' => 1,
            ]], 3, false);
        } finally {
            chmod($dir, 0755);
            @unlink($file);
            @rmdir($dir);
        }
        $row = $db->query("SELECT archived_at, original_removed, archive_batch_id FROM media_assets WHERE id = 9")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['archived_at'], 'the archive copy is recorded');
        $this->assertSame(3, (int)$row['archive_batch_id']);
        $this->assertSame(0, (int)$row['original_removed'], 'the original is still on disk');
        $this->assertIsArray($res);
    }

    // ── receipt_archive_enabled is off until a business turns it on ──────

    private function settingsDb(?string $value): PDO
    {
        $db = class_exists('Pdo\Sqlite') ? PDO::connect('sqlite::memory:') : new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        if ($value !== null) {
            $db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES ('receipt_archive_enabled', ?)")->execute([$value]);
        }
        return $db;
    }

    public function test_no_row_means_archive_disabled(): void
    {
        $this->assertFalse((new ReceiptArchiveService($this->settingsDb(null)))->loadSettings()['receipt_archive_enabled']);
    }

    public function test_row_1_enables_and_row_0_disables(): void
    {
        $this->assertTrue((new ReceiptArchiveService($this->settingsDb('1')))->loadSettings()['receipt_archive_enabled']);
        $this->assertTrue((new ReceiptArchiveService($this->settingsDb('true')))->loadSettings()['receipt_archive_enabled']);
        $this->assertFalse((new ReceiptArchiveService($this->settingsDb('0')))->loadSettings()['receipt_archive_enabled']);
    }

    public function test_run_with_no_row_returns_disabled_and_touches_nothing(): void
    {
        // No media_assets table exists: any select or delete would throw.
        $res = (new ReceiptArchiveService($this->settingsDb(null)))->run(null, null, 'cron', null);
        $this->assertSame('disabled', $res['status']);
        $this->assertSame(0, $res['archived']);
    }

    public function test_run_with_row_0_returns_disabled(): void
    {
        $res = (new ReceiptArchiveService($this->settingsDb('0')))->run(null, null, 'cron', null);
        $this->assertSame('disabled', $res['status']);
    }

    public function test_run_with_row_1_gets_past_the_disabled_gate(): void
    {
        $db = $this->settingsDb('1');
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY)");
        // Enabled: it goes on to select receipts. The select needs tables this test DB lacks,
        // so it throws about them; a 'disabled' result would mean the gate stopped it.
        try {
            $res = (new ReceiptArchiveService($db))->run(null, null, 'cron', null);
            $this->assertNotSame('disabled', $res['status'] ?? null);
        } catch (Throwable $e) {
            $this->assertMatchesRegularExpression('/no such (table|column)|syntax error|SQLSTATE/i', $e->getMessage());
        }
    }
}
