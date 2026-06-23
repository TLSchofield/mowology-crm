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
}
