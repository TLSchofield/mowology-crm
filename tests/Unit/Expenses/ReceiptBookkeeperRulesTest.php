<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The owner's bookkeeping rules (2026-10-05): diesel → truck, regular gas under
 * ~$50 → equipment, EGO → probably equipment. One Fuel category, truck/equipment tag.
 */
class ReceiptBookkeeperRulesTest extends TestCase
{
    private function byField(array $hits): array
    {
        $out = [];
        foreach ($hits as $h) $out[$h['field']] = $h;
        return $out;
    }

    public function test_diesel_is_fuel_for_the_truck(): void
    {
        $h = $this->byField(ReceiptBookkeeperRules::evaluate(['total' => 142.30], "ESSO\nPUMP 4 DIESEL\n85.210 L @ 1.669"));
        $this->assertSame('Fuel', $h['accounting_category']['value']);
        $this->assertSame('truck', $h['asset_tag']['value']);
        $this->assertSame('firm', $h['asset_tag']['strength']);
    }

    public function test_small_gas_fill_is_fuel_for_equipment(): void
    {
        $h = $this->byField(ReceiptBookkeeperRules::evaluate(['total' => 23.50], "SHELL\nREGULAR UNL 13.2 L"));
        $this->assertSame('Fuel', $h['accounting_category']['value']);
        $this->assertSame('equipment', $h['asset_tag']['value']);
        $this->assertSame('firm', $h['asset_tag']['strength']);
    }

    public function test_large_gas_fill_is_flagged_not_forced(): void
    {
        $h = $this->byField(ReceiptBookkeeperRules::evaluate(['total' => 78.00], "PETRO-CANADA\nUNLEADED 45.1 L"));
        $this->assertSame('equipment', $h['asset_tag']['value']);
        $this->assertSame('soft', $h['asset_tag']['strength']);
        $this->assertStringContainsString('check', $h['asset_tag']['reason']);
    }

    public function test_diesel_wins_when_both_appear(): void
    {
        $this->assertSame('diesel', ReceiptBookkeeperRules::fuelType("DIESEL 60 L\nUNLEADED 5 L"));
    }

    public function test_ego_item_is_probably_equipment(): void
    {
        $h = $this->byField(ReceiptBookkeeperRules::evaluate(['total' => 349.00], "CANADIAN TIRE", ['EGO 56V BLOWER']));
        $this->assertSame('Tools/Equipment', $h['accounting_category']['value']);
        $this->assertSame('soft', $h['accounting_category']['strength']);
    }

    public function test_unrelated_receipts_fire_no_rules(): void
    {
        $this->assertSame([], ReceiptBookkeeperRules::evaluate(['total' => 40], "HOME DEPOT\nMULCH BLACK 2CF"));
        $this->assertSame([], ReceiptBookkeeperRules::evaluate(['total' => 12], "SUBWAY\nREGULAR COMBO"), 'REGULAR without litres/pump is not gas');
        $this->assertSame([], ReceiptBookkeeperRules::evaluate(['total' => 12], "LEGO STORE"), 'EGO must be a whole word');
    }
}
