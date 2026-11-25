<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fable review 2026-10-12: serve-receipt.php / receipt-status.php let any expenses.view holder
 * read any receipt by walking media ids. Now the office sees all; others only their own.
 */
class ReceiptMediaAccessTest extends TestCase
{
    public function test_the_office_sees_every_receipt(): void
    {
        $this->assertTrue(ExpenseService::canSeeReceiptMedia(true, 3, 9, [9]));
        $this->assertTrue(ExpenseService::canSeeReceiptMedia(true, 3, null, []));
    }

    public function test_crew_see_a_photo_they_uploaded_or_a_receipt_they_created(): void
    {
        $this->assertTrue(ExpenseService::canSeeReceiptMedia(false, 8, 8, []));
        $this->assertTrue(ExpenseService::canSeeReceiptMedia(false, 8, 1, [1, '8']));
    }

    public function test_crew_never_see_someone_elses_receipt(): void
    {
        $this->assertFalse(ExpenseService::canSeeReceiptMedia(false, 8, 1, [1]));
        $this->assertFalse(ExpenseService::canSeeReceiptMedia(false, 8, null, []));
        $this->assertFalse(ExpenseService::canSeeReceiptMedia(false, 0, 0, [0]), 'no user id, no access');
    }
}
