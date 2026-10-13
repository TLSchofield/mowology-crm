<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

/**
 * Tests for extractLineItems()'s discount/markdown netting behavior.
 *
 * Bug this covers: a receipt line like "Discount: Landscapers (25%) -$14.99"
 * used to unconditionally become its own standalone item named "Discount",
 * silently replacing the actual purchased product ("Topsoil x4") in the
 * parsed output instead of reducing its price. See
 * app/Services/Receipts/ReceiptParser.php's netDiscountIntoLastItem().
 *
 * Note: this only nets a discount when the preceding product line was
 * itself captured by one of extractLineItems()'s recognized patterns
 * (same-line "Name  $price", barcode-prefixed name + separate price line,
 * etc). A bare product-name line with no barcode/SKU and no price on the
 * same line is never captured as a pending item by this parser at all —
 * that's a separate, pre-existing gap this fix does not address.
 */
class ReceiptParserDiscountTest extends TestCase
{
    public function test_discount_nets_into_a_barcode_prefixed_product_line(): void
    {
        $lines = [
            '012345678 Topsoil 30L Bag',
            '59.96',
            'Discount: Landscapers (25%) -14.99',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(1, $items, 'Expected the discount to net into one item, not create a second "Discount" row');
        $this->assertSame('Topsoil 30L Bag', $items[0]['name']);
        $this->assertEquals(44.97, (float)$items[0]['amount']);
        $this->assertEquals(59.96, (float)$items[0]['original_unit_price']);
        $this->assertFalse($items[0]['is_adjustment'] ?? false);
    }

    public function test_discount_nets_into_a_same_line_name_and_price_item(): void
    {
        $lines = [
            'Topsoil x4  59.96',
            'Discount: Landscapers (25%) -14.99',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(1, $items);
        $this->assertSame('Topsoil x4', $items[0]['name']);
        $this->assertEquals(44.97, (float)$items[0]['amount']);
    }

    public function test_rsn_prefixed_discount_line_nets_into_preceding_item(): void
    {
        $lines = [
            'Mulch Bag  20.00',
            'RSN: Member discount -5.00',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(1, $items);
        $this->assertEquals(15.00, (float)$items[0]['amount']);
    }

    public function test_standalone_discount_with_no_preceding_item_falls_back_to_adjustment_row(): void
    {
        $lines = [
            'Discount: Loyalty coupon -3.00',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(1, $items);
        $this->assertSame('Discount', $items[0]['name']);
        $this->assertEquals(-3.00, (float)$items[0]['amount']);
        $this->assertTrue($items[0]['is_adjustment']);
    }

    public function test_deposit_line_stays_a_standalone_adjustment_row_not_netted(): void
    {
        $lines = [
            'Widget  10.00',
            'DEPOSIT',
            '2.50',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(2, $items, 'Deposit is a genuine separate charge, not a price reduction — should not net into Widget');
        $this->assertSame('Widget', $items[0]['name']);
        $this->assertEquals(10.00, (float)$items[0]['amount']);
        $this->assertFalse($items[0]['is_adjustment'] ?? false);
        $this->assertSame('Deposit', $items[1]['name']);
        $this->assertEquals(2.50, (float)$items[1]['amount']);
        $this->assertTrue($items[1]['is_adjustment']);
    }

    public function test_discount_does_not_net_into_a_prior_adjustment_row(): void
    {
        // Two discounts back-to-back with nothing real to net the second one
        // into (the first fell back to a standalone row since there was no
        // preceding product) — the second should also fall back, not net
        // into the first discount row.
        $lines = [
            'Discount: Coupon A -3.00',
            'Discount: Coupon B -2.00',
        ];

        $items = extractLineItems($lines);

        $this->assertCount(2, $items);
        $this->assertTrue($items[0]['is_adjustment']);
        $this->assertTrue($items[1]['is_adjustment']);
    }

    /** Home Depot Feb 26: "Buy 10 Get 10%" nets into the lime; "4@12.98" alone gives the quantity (2026-10-13). */
    public function test_home_depot_multi_buy_discount_and_split_quantity_lines(): void
    {
        $lines = [
            '065797202099 MOSS CONTROL <A>', '4@12.98', '51.92',
            '-------Buy 10 Get 10% Bagged Lime-------',
            '058262309331 CILPrillLime <A>', '10@14.98', '149.80G',
            'MAX REFUND VALUE $134.82/10',
            'Bu 10 Get 10% Bagged Lim -14.98',
            'SUBTOTAL 186.74',
        ];
        $items = extractLineItems($lines);
        $this->assertCount(2, $items);
        $this->assertSame(['MOSS CONTROL', 4, 12.98, '51.92'], [$items[0]['name'], $items[0]['quantity'], $items[0]['unit_price'], $items[0]['amount']]);
        $this->assertSame(['CILPrillLime', 10, 13.48, '134.82'], [$items[1]['name'], $items[1]['quantity'], $items[1]['unit_price'], $items[1]['amount']]);
        $this->assertSame(14.98, $items[1]['original_unit_price']);
    }

    public function test_multi_buy_discount_after_a_one_line_quantity_total(): void
    {
        $items = extractLineItems(['058262309331 CILPrillLime <A>', '10@14.98   149.80G', 'Buy 10 Get 10% Bagged Lim -14.98', 'SUBTOTAL 134.82']);
        $this->assertCount(1, $items);
        $this->assertSame('134.82', $items[0]['amount']);
    }

    public function test_percent_off_line_with_trailing_minus_nets_in(): void
    {
        $items = extractLineItems(['012345678 Mulch Black', '20.00', '20% OFF MULCH 4.00-', 'SUBTOTAL 16.00']);
        $this->assertSame('16.00', $items[0]['amount']);
    }
}
