<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

/**
 * Tests for extractLineItems()'s backward-lookback recovery of a bare
 * product-name line — one with no barcode/SKU prefix and no price on its
 * own line, which no other pattern in the function captures.
 *
 * Bug this covers: such a line was silently dropped entirely. Combined with
 * a discount line right after, the real product never appeared as a line
 * item at all — only a bogus standalone "Discount" row (the original
 * production bug this session started from). Fixed via
 * findBareItemNameBackward(), only ever consulted once a confirmed price
 * line is found with nothing already queued to attach it to (never
 * speculative), mirroring the backward-lookback the "Qty: Price:" pattern
 * already uses safely elsewhere in this file.
 */
class ReceiptParserBareNameTest extends TestCase
{
    public function test_bare_name_directly_above_a_price_is_recovered(): void
    {
        $items = extractLineItems(['Mulch Bag', '20.00']);

        $this->assertCount(1, $items);
        $this->assertSame('Mulch Bag', $items[0]['name']);
        $this->assertEquals(20.00, (float)$items[0]['amount']);
    }

    public function test_full_receipt_recovers_the_product_and_nets_the_discount(): void
    {
        // Reproduces the real production shape: name split across two bare
        // lines, an "Original Price" label, then the price, then a discount.
        $items = extractLineItems([
            'Southlands Nursery',
            '6550 Balaclava street',
            'VANCOUVER, BC',
            'V6N1L9',
            '(604) 261-6411',
            'southlandsnursery.com',
            '',
            'Receipt: btss',
            'Authorization: 349690',
            'GST/HST # 104943949',
            'QST # 103-2368',
            'PST # 103-2368',
            '',
            'Interac',
            'AID A0 00 00 02 77 10 10',
            '',
            'Topsoil x4',
            '30L Bag',
            'Original Price',
            '$59.96',
            'Discount: Landscapers (25%) -$14.99',
            '($14.99 each)',
        ]);

        $this->assertCount(1, $items, 'Header/vendor/tax-ID noise must not leak in as extra items');
        $this->assertSame('30L Bag', $items[0]['name']);
        $this->assertEquals(44.97, (float)$items[0]['amount']);
        $this->assertEquals(59.96, (float)$items[0]['original_unit_price']);
    }

    public function test_generic_price_label_is_skipped_in_favor_of_the_real_name(): void
    {
        $items = extractLineItems(['Cedar Mulch', 'Original Price', '$15.00']);

        $this->assertCount(1, $items);
        $this->assertSame('Cedar Mulch', $items[0]['name']);
    }

    public function test_attribute_line_is_skipped_in_favor_of_the_real_name(): void
    {
        $items = extractLineItems(['Garden Hose', 'Clr: Green', '25.00']);

        $this->assertCount(1, $items);
        $this->assertSame('Garden Hose', $items[0]['name']);
    }

    public function test_no_items_when_only_header_and_totals_present(): void
    {
        $items = extractLineItems([
            'Some Store', '123 Main St', '', 'Subtotal $20.00', 'GST $1.00', 'Total $21.00',
        ]);

        $this->assertSame([], $items);
    }

    public function test_city_province_postal_and_website_lines_are_never_used_as_a_name(): void
    {
        // A price immediately preceded ONLY by noise lines (no plausible
        // name within the lookback window) should produce no item at all,
        // not misattribute to a header line.
        $items = extractLineItems([
            'VANCOUVER, BC',
            'V6N1L9',
            'southlandsnursery.com',
            '(604) 261-6411',
            '5.00',
        ]);

        $this->assertSame([], $items);
    }

    public function test_barcode_prefixed_items_are_unaffected_by_the_new_fallback(): void
    {
        // Regression: the existing barcode+name path (which already commits
        // the item directly) must still take precedence and behave
        // identically to before this change.
        $items = extractLineItems(['012345678 Topsoil 30L Bag', '59.96', 'Discount: Landscapers (25%) -14.99']);

        $this->assertCount(1, $items);
        $this->assertSame('Topsoil 30L Bag', $items[0]['name']);
        $this->assertEquals(44.97, (float)$items[0]['amount']);
    }

    public function test_known_limitation_short_receipt_can_still_misattribute_to_vendor_name(): void
    {
        // Documents a real, accepted residual risk rather than hiding it:
        // on an artificially short receipt with no buffer content between
        // the vendor name and a stray price, the vendor name itself can be
        // mistaken for a product name — there's no reliable regex-only way
        // to distinguish them. Real receipts have enough header content
        // (address/phone/tax IDs, all excluded) that this is unlikely in
        // practice; this test exists so a future change to the exclusion
        // list has to consciously decide to keep or close this gap, not
        // discover it by accident.
        $items = extractLineItems(['Some Store', '5.00']);

        $this->assertCount(1, $items);
        $this->assertSame('Some Store', $items[0]['name']);
    }
}
