<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Codex 2026-11-11: editing a manual ledger line skipped the checks creating one has. */
class ManualEditValidationTest extends TestCase
{
    public function test_a_normal_edit_passes(): void
    {
        AccountingService::validateManualEdit(['amount' => '25.50', 'gst_amount' => '', 'status' => 'cleared', 'notes' => 'x']);
        $this->addToAssertionCount(1);
    }

    public function test_negative_or_zero_amount_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualEdit(['amount' => '-10000']);
    }

    public function test_negative_tax_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualEdit(['gst_amount' => '-500']);
    }

    public function test_unknown_status_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualEdit(['status' => 'bogus']);
    }

    public function test_create_also_refuses_negative_tax(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AccountingService::validateManualTransaction(['amount' => 10, 'type' => 'expense', 'gst_amount' => -1]);
    }
}
