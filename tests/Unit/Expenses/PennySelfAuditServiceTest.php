<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's weekly self-check: an approved call that was corrected later counts as wrong.
 */
class PennySelfAuditServiceTest extends TestCase
{
    public function test_a_later_correction_is_found(): void
    {
        $outcome = ['accounting_category' => ['final' => 'Repairs/Maintenance'], 'asset_tag' => ['final' => 'none'],
                    'job' => ['final' => null], 'total' => ['final' => 1711.70]];
        $this->assertSame([], PennySelfAuditService::receiptDrift($outcome,
            ['accounting_category' => 'Repairs/Maintenance', 'asset_tag' => null, 'job_id' => null, 'total' => '1711.70']));
        $this->assertSame(['category', 'For tag'], PennySelfAuditService::receiptDrift($outcome,
            ['accounting_category' => 'Vehicle', 'asset_tag' => 'truck', 'job_id' => null, 'total' => '1711.70']));
    }
}
