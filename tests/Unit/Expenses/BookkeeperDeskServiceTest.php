<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's desk: the owner's decision over her suggestion (her scorecard and the
 * learning signal) and when a text-only read gets a second look with the photo.
 */
class BookkeeperDeskServiceTest extends TestCase
{
    private function s(): array
    {
        $f = fn($v) => ['value' => $v, 'reason' => 'r', 'confidence' => 'high'];
        return [
            'accounting_category' => $f('Fuel'), 'asset_tag' => $f('truck'), 'job' => $f(null),
            'subtotal' => $f(137.54), 'gst' => $f(6.88), 'pst' => $f(0), 'total' => $f(144.42),
        ];
    }

    public function test_approving_unchanged_accepts_every_field(): void
    {
        $r = BookkeeperDeskService::resolveFinal($this->s(), []);
        $this->assertSame('Fuel', $r['final']['accounting_category']);
        $this->assertSame('truck', $r['final']['asset_tag']);
        $this->assertNull($r['final']['job']);
        $this->assertSame(144.42, $r['final']['total']);
        $this->assertNotContains(false, array_column($r['outcome'], 'accepted'));
    }

    public function test_edits_override_and_are_recorded_as_overrides(): void
    {
        $r = BookkeeperDeskService::resolveFinal($this->s(), ['asset_tag' => 'equipment', 'job' => '42', 'total' => '144.42']);
        $this->assertSame('equipment', $r['final']['asset_tag']);
        $this->assertSame(42, $r['final']['job']);
        $this->assertFalse($r['outcome']['asset_tag']['accepted']);
        $this->assertFalse($r['outcome']['job']['accepted']);
        $this->assertTrue($r['outcome']['total']['accepted'], 'same amount typed back is not an edit');
    }

    public function test_none_tag_is_stored_as_no_tag(): void
    {
        $s = $this->s();
        $s['asset_tag']['value'] = 'none';
        $this->assertNull(BookkeeperDeskService::resolveFinal($s, [])['final']['asset_tag']);
    }

    public function test_shop_stock_is_a_valid_tag_and_clears_the_job(): void
    {
        $this->assertContains('stock', ReceiptBookkeeperRules::TAGS);
        $r = BookkeeperDeskService::resolveFinal($this->s(), ['asset_tag' => 'stock', 'job' => '']);
        $this->assertSame('stock', $r['final']['asset_tag']);
        $this->assertNull($r['final']['job']);
        $this->assertFalse($r['outcome']['asset_tag']['accepted']);
    }

    public function test_vendor_store_number_is_not_an_edit_but_a_different_business_is(): void
    {
        $s = $this->s();
        $s['vendor'] = ['value' => 'HOME DEPOT #7054', 'reason' => 'r', 'confidence' => 'high'];
        $this->assertTrue(BookkeeperDeskService::resolveFinal($s, ['vendor' => 'Home Depot'])['outcome']['vendor']['accepted']);
        $r = BookkeeperDeskService::resolveFinal($s, ['vendor' => ' Vital Auto Repair ']);
        $this->assertSame('Vital Auto Repair', $r['final']['vendor']);
        $this->assertFalse($r['outcome']['vendor']['accepted']);
        $this->assertNull(BookkeeperDeskService::resolveFinal($this->s(), ['vendor' => ''])['final']['vendor']);
    }

    public function test_pick_vendor_matches_name_then_alias_never_a_stranger(): void
    {
        $vendors = [
            ['id' => 1, 'name' => 'Home Depot', 'aliases' => 'THD, The Home Depot'],
            ['id' => 2, 'name' => 'Vital Auto Repair', 'aliases' => ''],
            ['id' => 3, 'name' => 'Rona', 'aliases' => null],
        ];
        $this->assertSame(2, BookkeeperDeskService::pickVendor($vendors, 'vital auto repair'));
        $this->assertSame(1, BookkeeperDeskService::pickVendor($vendors, 'HOME DEPOT #7054'));
        $this->assertSame(1, BookkeeperDeskService::pickVendor($vendors, 'THD'));
        $this->assertNull(BookkeeperDeskService::pickVendor($vendors, 'Lawnboy Supply'));
    }

    public function test_photo_retry_only_when_the_text_does_not_add_up(): void
    {
        $this->assertFalse(BookkeeperDeskService::needsPhoto([['check' => 'sum', 'ok' => true, 'message' => '']]));
        $this->assertTrue(BookkeeperDeskService::needsPhoto([['check' => 'sum', 'ok' => false, 'message' => '']]));
        $this->assertTrue(BookkeeperDeskService::needsPhoto([['check' => 'category_known', 'ok' => true, 'message' => '']]), 'amounts missing entirely');
    }

    public function test_pct(): void
    {
        $this->assertNull(BookkeeperDeskService::pct(0, 0));
        $this->assertSame(89, BookkeeperDeskService::pct(34, 38));
    }
}
