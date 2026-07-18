<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptIntakeService — the pure, DB-free validation rules shared by the
 * web (receipt-intake.php) and iOS (receipt-upload.php) upload endpoints. intake()
 * itself does real file I/O, DB writes, and OCR calls, so — matching this codebase's
 * convention for services like ReceiptArchiveService — only the deterministic helper
 * methods are unit tested here, not the full upload pipeline.
 */
class ReceiptIntakeServiceTest extends TestCase
{
    // ── isAllowedMimeType ────────────────────────────────────────────────

    public function test_allowed_mime_types(): void
    {
        $this->assertTrue(ReceiptIntakeService::isAllowedMimeType('image/jpeg'));
        $this->assertTrue(ReceiptIntakeService::isAllowedMimeType('image/png'));
        $this->assertTrue(ReceiptIntakeService::isAllowedMimeType('image/heic'));
    }

    public function test_rejected_mime_types(): void
    {
        $this->assertFalse(ReceiptIntakeService::isAllowedMimeType('application/pdf'));
        $this->assertFalse(ReceiptIntakeService::isAllowedMimeType('text/html'));
        $this->assertFalse(ReceiptIntakeService::isAllowedMimeType(''));
    }

    // ── resolveStoredExtension ───────────────────────────────────────────

    public function test_resolves_known_extensions(): void
    {
        $this->assertSame('jpg', ReceiptIntakeService::resolveStoredExtension('receipt.jpg'));
        $this->assertSame('png', ReceiptIntakeService::resolveStoredExtension('receipt.PNG'));
        $this->assertSame('heic', ReceiptIntakeService::resolveStoredExtension('IMG_1234.heic'));
    }

    public function test_falls_back_to_jpg_for_unknown_or_missing_extension(): void
    {
        $this->assertSame('jpg', ReceiptIntakeService::resolveStoredExtension('receipt.exe'));
        $this->assertSame('jpg', ReceiptIntakeService::resolveStoredExtension('no-extension'));
        $this->assertSame('jpg', ReceiptIntakeService::resolveStoredExtension(''));
    }

    // ── isRateLimited ────────────────────────────────────────────────────

    public function test_under_limit_is_not_rate_limited(): void
    {
        $this->assertFalse(ReceiptIntakeService::isRateLimited(0));
        $this->assertFalse(ReceiptIntakeService::isRateLimited(19));
    }

    public function test_at_or_over_limit_is_rate_limited(): void
    {
        $this->assertTrue(ReceiptIntakeService::isRateLimited(20));
        $this->assertTrue(ReceiptIntakeService::isRateLimited(25));
    }
}
