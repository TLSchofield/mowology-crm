<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's job pick for a material receipt from a supplier stop (shadow mode, migration 1359).
 * Real shape, 2026-10-07: Nigel's GPS was tagged to the regular maintenance visit at 6015 Tisdall
 * (PLN-2026-0045) before and after each Lawnboy stop; Tim put the mulch on the one-off landscaping
 * job at the same address (PLN-2026-0109), where the last material receipt (#397, Oct 2) had gone.
 */
class SupplierJobSuggestServiceTest extends TestCase
{
    private function tisdall(bool $learned = true): array
    {
        return [
            ['plan_id' => 59, 'plan_number' => 'PLN-2026-0045', 'street' => '6015 Tisdall Street', 'is_recurring' => true, 'quote_lines' => [],
             'before' => true, 'after' => true, 'gps' => true, 'visit' => true, 'learned' => null],
            ['plan_id' => 124, 'plan_number' => 'PLN-2026-0109', 'street' => '6015 Tisdall Street', 'is_recurring' => false,
             'quote_lines' => [['description' => 'Chafer Grub Treatment', 'product_id' => null]],
             'before' => true, 'after' => true, 'gps' => true, 'visit' => false,
             'learned' => $learned ? ['id' => 397, 'expense_date' => '2026-10-02', 'job_id' => 124] : null],
        ];
    }

    private const MULCH = [['name' => 'Black Composted Bark Mulch', 'product_id' => 11]];

    public function testTheLastMaterialReceiptAtThePropertyPicksTheJob(): void
    {
        $best = SupplierJobSuggestService::rank($this->tisdall(), self::MULCH);
        $this->assertSame(124, $best['plan_id']);
        $why = SupplierJobSuggestService::reason($best, 'Nigel', 'LAWNBOY', 1);
        $this->assertStringContainsString('Nigel was at 6015 Tisdall Street before and after the LAWNBOY stop', $why);
        $this->assertStringContainsString('#397, Oct 2', $why);
        $this->assertStringContainsString('Tim + Nigel were at', SupplierJobSuggestService::reason($best, 'Tim + Nigel', 'LAWNBOY', 1));
    }

    public function testWithNoHistoryTheVisitHeWasOnWins(): void
    {
        $this->assertSame(59, SupplierJobSuggestService::rank($this->tisdall(false), self::MULCH)['plan_id']);
    }

    public function testAQuoteThatListsTheMaterialBeatsEverything(): void
    {
        $c = $this->tisdall();
        $c[] = ['plan_id' => 130, 'plan_number' => 'PLN-2026-0115', 'street' => '3029 West 15th Avenue', 'is_recurring' => false,
                'quote_lines' => [['description' => 'Black Earth', 'service_type' => '', 'product_id' => null],
                                  ['description' => 'Bark mulch top-up', 'service_type' => '', 'product_id' => null]],
                'before' => false, 'after' => false, 'gps' => false, 'visit' => true, 'learned' => null];
        $best = SupplierJobSuggestService::rank($c, self::MULCH);
        $this->assertSame(130, $best['plan_id']);
        $this->assertSame(['mulch', 'bark'], $best['quote_words']);
        $this->assertStringContainsString('its quote lists mulch, bark', SupplierJobSuggestService::reason($best, 'Nigel', 'LAWNBOY', 2));
    }

    public function testOnlyMaterialsGetAPick(): void
    {
        $this->assertTrue(SupplierJobSuggestService::isMaterial('Materials', 'supplier', []));
        $this->assertFalse(SupplierJobSuggestService::isMaterial('Fuel', 'supplier', ['Mulch']));
        $this->assertFalse(SupplierJobSuggestService::isMaterial('Meals', 'fuel', ['Coffee']));
        $this->assertTrue(SupplierJobSuggestService::isMaterial(null, 'supplier', ['Garden Mix 3 yd']));
        $this->assertFalse(SupplierJobSuggestService::isMaterial('Tools/Equipment', 'supplier', ['Gloves']));
    }

    public function testShadowScoreBar(): void
    {
        $this->assertFalse(SupplierJobSuggestService::score([])['meets_bar']);
        $this->assertSame(0, SupplierJobSuggestService::score([])['total']);
        $this->assertFalse(SupplierJobSuggestService::score(['confirmed' => 9, 'changed' => 0])['meets_bar'], 'needs 10');
        $this->assertTrue(SupplierJobSuggestService::score(['confirmed' => 10])['meets_bar']);
        $this->assertFalse(SupplierJobSuggestService::score(['confirmed' => 18, 'changed' => 1, 'rejected' => 1])['meets_bar'], '90% < 95%');
        $this->assertStringContainsString('3 of 4 right', SupplierJobSuggestService::score(['confirmed' => 3, 'rejected' => 1])['line']);
    }

    public function testStreet(): void
    {
        $this->assertSame('6015 Tisdall Street', SupplierJobSuggestService::street('6015 Tisdall Street, Vancouver, BC'));
        $this->assertSame('the property', SupplierJobSuggestService::street(''));
    }
}
