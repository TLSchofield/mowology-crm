<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLearning.php';

/**
 * A phone save teaches; the desk's approval confirms it or takes part of it back (amendmentPlan).
 */
class ReceiptAmendmentPlanTest extends TestCase
{
    private const BASELINE = ['total' => null, 'gst' => '1.11', 'subtotal' => '22.17', 'date' => '2026-10-28', 'vendor_hint' => 'Chevron'];

    private function row(array $over = []): array
    {
        return $over + ['total' => '23.28', 'gst_amount' => '1.11', 'pst_amount' => null, 'amount' => '22.17',
                        'expense_date' => '2026-10-28', 'accounting_category' => null];
    }

    public function test_unchanged_approval_confirms_and_changes_nothing(): void
    {
        $snap = $this->row() + ['vendor' => 'Chevron'];
        $plan = amendmentPlan(self::BASELINE, $snap, $this->row(), 'Chevron');
        $this->assertSame([], $plan['forget']);
        $this->assertSame([], $plan['add']);
        $this->assertSame(0, $plan['delta']);
    }

    public function test_desk_correcting_a_phone_value_takes_the_phone_lesson_back(): void
    {
        $snap = $this->row(['total' => '23.20']) + ['vendor' => 'Chevron'];          // phone typed 23.20
        $plan = amendmentPlan(self::BASELINE, $snap, $this->row(['total' => '23.28']), 'Chevron');
        $this->assertSame(['ocr' => null, 'user' => '23.20'], $plan['forget']['total']);
        $this->assertSame(['ocr' => null, 'user' => '23.28'], $plan['add']['total']);
        $this->assertSame(0, $plan['delta'], 'one wrong field swapped for one corrected field');
    }

    public function test_desk_agreeing_with_the_parser_against_the_phone_lowers_corrections(): void
    {
        $base = ['total' => '23.28'] + self::BASELINE;
        $snap = $this->row(['total' => '23.20']) + ['vendor' => 'Chevron'];
        $plan = amendmentPlan($base, $snap, $this->row(['total' => '23.28']), 'Chevron');
        $this->assertArrayHasKey('total', $plan['forget']);
        $this->assertArrayNotHasKey('total', $plan['add'], 'the parser was right, so there is no correction to record');
        $this->assertSame(-1, $plan['delta']);
    }

    public function test_desk_changing_a_field_the_phone_left_alone_adds_a_correction(): void
    {
        $snap = $this->row() + ['vendor' => 'Chevron'];
        $plan = amendmentPlan(self::BASELINE, $snap, $this->row(['expense_date' => '2026-10-27']), 'Chevron');
        $this->assertSame([], $plan['forget']);
        $this->assertSame(['ocr' => '2026-10-28', 'user' => '2026-10-27'], $plan['add']['date']);
        $this->assertSame(1, $plan['delta']);
    }

    public function test_desk_moving_the_vendor_takes_every_phone_lesson_with_it(): void
    {
        $snap = $this->row(['total' => '23.20']) + ['vendor' => 'Chevron'];
        $plan = amendmentPlan(self::BASELINE, $snap, $this->row(['total' => '23.20']), 'Shell', true);
        $this->assertArrayHasKey('total', $plan['forget'], 'forgotten under the old vendor even though the value is unchanged');
        $this->assertArrayHasKey('total', $plan['add'], 'and recorded under the new one');
        $this->assertGreaterThan(0, $plan['old_corrections']);
        $this->assertGreaterThan(0, $plan['new_corrections']);
    }
}
