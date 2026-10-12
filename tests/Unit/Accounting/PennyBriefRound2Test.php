<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Expenses/Services/MissingReceiptService.php';

/**
 * Review round 2 (2026-10-12) — Penny's card and brief say only what is true:
 * chase invoices late for that client, GST to claim back the way the return counts it,
 * "asking the crew" only about the receipts she really asks about.
 */
class PennyBriefRound2Test extends TestCase
{
    public function test_late_means_past_due_and_past_the_clients_habit(): void
    {
        $this->assertFalse(CashFlowForecastService::isLateForClient(-5, 0));   // not due yet
        $this->assertFalse(CashFlowForecastService::isLateForClient(10, 14));  // usually pays 14 days late
        $this->assertTrue(CashFlowForecastService::isLateForClient(20, 14));
        $this->assertTrue(CashFlowForecastService::isLateForClient(1, 0));
    }

    public function test_chase_list_names_only_late_invoices_biggest_first(): void
    {
        $events = [
            ['kind' => 'invoice', 'label' => 'INV-1', 'amount' => 900.0, 'late' => false, 'id' => 1],
            ['kind' => 'invoice', 'label' => 'INV-2', 'amount' => 300.0, 'late' => true, 'id' => 2],
            ['kind' => 'invoice', 'label' => 'INV-3', 'amount' => 500.0, 'late' => true, 'id' => 3],
            ['kind' => 'staff', 'label' => 'Pay', 'amount' => -800.0],
        ];
        $this->assertSame(['INV-3', 'INV-2'], array_column(CashFlowForecastService::chaseList($events), 'label'));
    }

    public function test_gst_claim_back_halves_meals_and_skips_held_duplicates(): void
    {
        $rows = [
            ['id' => 1, 'status' => 'approved', 'gst_amount' => '10.00', 'category' => 'Materials'],
            ['id' => 2, 'status' => 'approved', 'gst_amount' => '4.00', 'category' => 'Meals & Entertainment'],
            ['id' => 3, 'status' => 'draft', 'gst_amount' => '6.00', 'category' => 'Fuel'],
            ['id' => 4, 'status' => 'pending_approval', 'gst_amount' => '6.00', 'category' => 'Fuel'],   // held copy of #3
        ];
        $settings = ['meals_rate' => 0.5, 'meals_categories' => ['Meals & Entertainment']];
        $this->assertSame([12.0, 6.0], BookkeeperDeskService::gstClaimBack($rows, [4], $settings));
    }

    public function test_brief_says_who_is_actually_being_asked(): void
    {
        $this->assertStringEndsWith('Penny is asking the crew', MissingReceiptService::briefText(3, 120.0, 3));
        $this->assertStringContainsString('asking the crew about 1, the rest are on your list', MissingReceiptService::briefText(3, 120.0, 1));
        $this->assertStringContainsString('too old to ask the crew', MissingReceiptService::briefText(2, 50.0, 0));
    }
}
