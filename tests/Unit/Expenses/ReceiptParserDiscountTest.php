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
}
