<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** GST below 5% of the subtotal is normal (zero-rated/exempt items); above it is flagged (2026-10-11). */
final class GstMismatchRuleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('checkGstMismatch')) require_once __DIR__ . '/../../../app/Services/Receipts/AnomalyDetector.php';
    }

    public function testMixedBasketWithLowGstIsNotFlagged(): void
    {
        // Chevron 2026-09-22: only the $2.49 hot drink is taxable → $0.12 GST on a $15.57 subtotal.
        $this->assertNull(checkGstMismatch(['amount' => 15.57, 'gst_amount' => 0.12, 'total' => 15.69], new PDO('sqlite::memory:')));
    }

    public function testFullFivePercentIsNotFlagged(): void
    {
        $this->assertNull(checkGstMismatch(['amount' => 100.00, 'gst_amount' => 5.00, 'total' => 105.00], new PDO('sqlite::memory:')));
    }

    public function testMoreThanFivePercentIsFlagged(): void
    {
        $r = checkGstMismatch(['amount' => 150.00, 'gst_amount' => 9.52, 'total' => 159.52], new PDO('sqlite::memory:'));
        $this->assertSame('GST_MISMATCH', $r['code'] ?? null);
    }
}
