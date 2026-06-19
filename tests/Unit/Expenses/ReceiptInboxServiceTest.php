<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptInboxService — the email-in expense capture safety logic.
 *
 * Focuses on the pure, DB-free decision helpers: the auto-post gate
 * (isCleanMatch) and the dedup-key derivation. These are the bits that decide
 * whether a forwarded receipt posts straight to the books, so they are the most
 * important to pin down.
 */
class ReceiptInboxServiceTest extends TestCase
{
    // ── Auto-post gate (isCleanMatch) ─────────────────────────────────────

    public function test_clean_match_when_all_fields_present(): void
    {
        $this->assertTrue(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => 145.67,
            'expense_date'            => '2026-06-19',
            'vendor_default_category' => 'Materials',
        ]));
    }

    public function test_not_clean_without_vendor(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => null,
            'total'                   => 145.67,
            'expense_date'            => '2026-06-19',
            'vendor_default_category' => 'Materials',
        ]));
    }

    public function test_not_clean_without_total(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => 0,
            'expense_date'            => '2026-06-19',
            'vendor_default_category' => 'Materials',
        ]));
    }

    public function test_not_clean_without_date(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => 145.67,
            'expense_date'            => null,
            'vendor_default_category' => 'Materials',
        ]));
    }

    public function test_not_clean_with_invalid_date(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => 145.67,
            'expense_date'            => '2026-13-45',
            'vendor_default_category' => 'Materials',
        ]));
    }

    public function test_not_clean_without_vendor_category(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => 145.67,
            'expense_date'            => '2026-06-19',
            'vendor_default_category' => '',
        ]));
    }

    public function test_not_clean_with_negative_total(): void
    {
        $this->assertFalse(ReceiptInboxService::isCleanMatch([
            'vendor_id'               => 3,
            'total'                   => -10.0,
            'expense_date'            => '2026-06-19',
            'vendor_default_category' => 'Fuel',
        ]));
    }

    // ── Date validation ───────────────────────────────────────────────────

    public function test_valid_date(): void
    {
        $this->assertTrue(ReceiptInboxService::isValidDate('2026-06-19'));
    }

    public function test_invalid_dates(): void
    {
        $this->assertFalse(ReceiptInboxService::isValidDate(null));
        $this->assertFalse(ReceiptInboxService::isValidDate(''));
        $this->assertFalse(ReceiptInboxService::isValidDate('19/06/2026'));
        $this->assertFalse(ReceiptInboxService::isValidDate('2026-02-30')); // not a real day
    }

    // ── Dedup key derivation ──────────────────────────────────────────────

    public function test_dedup_uses_message_id_and_hash_when_present(): void
    {
        $key = ReceiptInboxService::deriveDedupKey('<abc@mail>', 'deadbeef', 'receipt.pdf');
        $this->assertSame('<abc@mail>:deadbeef', $key);
    }

    public function test_dedup_falls_back_to_hash_without_message_id(): void
    {
        $this->assertSame('sha:deadbeef', ReceiptInboxService::deriveDedupKey(null, 'deadbeef', 'receipt.pdf'));
        $this->assertSame('sha:deadbeef', ReceiptInboxService::deriveDedupKey('', 'deadbeef', 'receipt.pdf'));
    }

    public function test_dedup_same_email_same_attachment_collapses(): void
    {
        $a = ReceiptInboxService::deriveDedupKey('<m1@x>', 'hashA', 'invoice.pdf');
        $b = ReceiptInboxService::deriveDedupKey('<m1@x>', 'hashA', 'invoice.pdf');
        $this->assertSame($a, $b);
    }

    public function test_dedup_two_attachments_on_one_email_differ(): void
    {
        $a = ReceiptInboxService::deriveDedupKey('<m1@x>', 'hashA', 'invoice.pdf');
        $b = ReceiptInboxService::deriveDedupKey('<m1@x>', 'hashB', 'receipt.jpg');
        $this->assertNotSame($a, $b);
    }
}
