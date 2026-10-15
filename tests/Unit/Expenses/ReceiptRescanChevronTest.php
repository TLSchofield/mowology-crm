<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';
require_once __DIR__ . '/../../../app/Modules/Expenses/Services/ExpenseRescanService.php';
require_once __DIR__ . '/../../../app/Modules/Expenses/Services/ExpenseLookupService.php';

/**
 * Chevron #355 (production, 2026-10-13): a $2.61 coffee. Stored with GST 5.00 and subtotal −2.39;
 * a rescan read GST 2.61 / subtotal 0.00 and saved "GST 5.0000 %" as the only line item, and the
 * Add Item picker offered Crusher Dust and Road Base. The OCR text below is #355's Vision text
 * (item block only — the address and payment lines are trimmed).
 */
class ReceiptRescanChevronTest extends TestCase
{
    private const RECEIPT_355 = <<<TXT
Paypoint: 01K
STORE #: 43016
GST:
R743318321
GSTV:
See GST #
DATE: 2026-08-28 TIME: 08:05:09
PSTV: See PST #
PRODUCT
QTY PRICE
AMOUNT
BISTRO 24OZ HOT BE 1 2.49 2.49P
GST 5.0000 %
0.12
TOTAL
CAD $
2.61
Debit Purchase
$ 2.61
Interac
P - GST
TXT;

    public function testSplitLineGstWithSpacedRateIsRead(): void
    {
        $r = parseReceiptText(self::RECEIPT_355);
        $this->assertSame('2.61', $r['total']);
        $this->assertSame('0.12', $r['gst']);
        $this->assertSame('2.49', $r['subtotal']);
    }

    public function testTaxLineIsNeverAnItemAndTheFlaggedItemIsRead(): void
    {
        $r = parseReceiptText(self::RECEIPT_355);
        $this->assertSame(['BISTRO 24OZ HOT BE'], array_column($r['line_items'], 'name'));
        $this->assertSame('2.49', $r['line_items'][0]['amount']);
        $this->assertSame('match', $r['line_items_quality']);
    }

    public function testSameLineRateStillReadsTheAmount(): void
    {
        $r = parseReceiptText("BISTRO 24OZ HOT BE  1  2.49  2.49P\nGST 5.0000 %  0.12\nTOTAL CAD \$ 2.61");
        $this->assertSame('0.12', $r['gst']);
        $this->assertSame('2.49', $r['subtotal']);
    }

    public function testFlaggedItemPatternNeedsTheFlag(): void
    {
        // A tilted photo's rebuilt row (#424) adds up by chance but has no tax flag: not an item.
        $items = extractLineItems(['BISTRO 24OZ 2 0.07 0.14']);
        $this->assertSame([], $items);
    }

    public function testImpossibleAmountsFallBack(): void
    {
        $r = sanitizeReceiptAmounts(['total' => '2.61', 'gst' => '5.00', 'pst' => null, 'subtotal' => '-2.39']);
        $this->assertTrue($r['amounts_repaired']);
        $this->assertTrue($r['gst_estimated']);
        $this->assertSame('0.12', $r['gst']);
        $this->assertSame('2.49', $r['subtotal']);

        $r = sanitizeReceiptAmounts(['total' => '2.61', 'gst' => '2.61', 'pst' => null, 'subtotal' => '0.00']);
        $this->assertGreaterThan(0, (float)$r['subtotal']);

        $ok = ['total' => '9.67', 'gst' => '0.42', 'pst' => '0.42', 'subtotal' => '8.83'];
        $this->assertSame($ok, sanitizeReceiptAmounts($ok));
    }

    public function testTaxLabels(): void
    {
        foreach (['GST 5.0000 %', 'PST 7%', 'GST/HST', 'TAX', 'SUBTOTAL', 'TOTAL CAD $', 'GST:'] as $l) {
            $this->assertTrue(isTaxOrSummaryLabel($l), $l);
        }
        foreach (['BISTRO 24OZ HOT BE', 'Diet Coke 500ml', 'Taxi fare', 'Total Lawn Fertilizer'] as $l) {
            $this->assertFalse(isTaxOrSummaryLabel($l), $l);
        }
        $this->assertSame([['name' => 'Coffee']], dropTaxLineItems([['name' => 'GST 5.0000 %'], ['name' => 'Coffee']]));
    }

    public function testRescanRepairsOnlyImpossibleStoredAmounts(): void
    {
        $stored = ['amount' => '-2.39', 'gst_amount' => '5.00', 'pst_amount' => '0.00', 'total' => '2.61'];
        $parsed = parseReceiptText(self::RECEIPT_355);
        $this->assertSame(['amount' => 2.49, 'gst_amount' => 0.12, 'pst_amount' => 0.0], ExpenseRescanService::amountRepair($stored, $parsed));

        // Sane stored amounts are the person's — never overwritten.
        $this->assertNull(ExpenseRescanService::amountRepair(['amount' => '2.49', 'gst_amount' => '0.12', 'pst_amount' => '0', 'total' => '2.61'], $parsed));
        // A read that disagrees on the total isn't trusted for it.
        $this->assertNull(ExpenseRescanService::amountRepair($stored, ['total' => '3.00', 'subtotal' => '2.86', 'gst' => '0.14']));
    }

    public function testUsualItemsRankByFrequencyAndRecencyWithoutJunk(): void
    {
        $rows = [
            ['name' => 'BISTRO 24OZ HOT BE', 'n' => 7, 'confirmed' => 2, 'last' => '2026-10-08', 'last_total' => '2.49', 'last_qty' => '1'],
            ['name' => 'Bistro 24oz hot beverage', 'n' => 1, 'confirmed' => 1, 'last' => '2026-10-13', 'last_total' => '2.49', 'last_qty' => '1'],
            ['name' => 'Diet Coke 500ml', 'n' => 1, 'confirmed' => 1, 'last' => '2026-10-13', 'last_total' => '6.00', 'last_qty' => '2'],
            ['name' => 'CRF 0.84', 'n' => 4, 'confirmed' => 0, 'last' => '2026-10-05', 'last_total' => '0.84', 'last_qty' => '1'],
            ['name' => 'CRF', 'n' => 2, 'confirmed' => 0, 'last' => '2026-09-22', 'last_total' => '0.84', 'last_qty' => '1'],
            ['name' => 'AMOUNT', 'n' => 2, 'confirmed' => 0, 'last' => '2026-10-06', 'last_total' => '1.67', 'last_qty' => '1'],
            ['name' => 'GST 5.0000 %', 'n' => 1, 'confirmed' => 0, 'last' => '2026-10-13', 'last_total' => '0.12', 'last_qty' => '1'],
            ['name' => 'TYLENOL EX STR TAB 1', 'n' => 1, 'confirmed' => 0, 'last' => '2026-03-01', 'last_total' => '9.99', 'last_qty' => '1'],
        ];
        $u = ExpenseLookupService::rankUsualItems($rows, '2026-10-13');
        $names = array_column($u, 'name');
        $this->assertSame('BISTRO 24OZ HOT BE', $names[0]);
        $this->assertContains('CRF', $names);                    // "CRF 0.84" + "CRF" are one item
        $this->assertNotContains('AMOUNT', $names);
        $this->assertNotContains('GST 5.0000 %', $names);
        $this->assertSame('TYLENOL EX STR TAB 1', end($names));  // old and once: last
        $diet = $u[array_search('Diet Coke 500ml', $names, true)];
        $this->assertSame(3.0, $diet['price']);                  // per unit

        $this->assertSame(['Diet Coke 500ml'], array_column(ExpenseLookupService::rankUsualItems($rows, '2026-10-13', 'coke'), 'name'));
    }

    public function testColumnHeadingsAreNotItems(): void
    {
        $this->assertTrue(isColumnHeading('QTY PRICE AMOUNT'));
        $this->assertTrue(isColumnHeading('Description Qty Amt'));
        $this->assertFalse(isColumnHeading('UNIT PRICE'), 'two words could be a real item');
        $this->assertFalse(isColumnHeading('PRODUCT DESCRIPTION ITEM'), 'no quantity / amount column');
        $this->assertFalse(isColumnHeading('SUPREME (91) 9.483L AT $2.109/L'));
        $this->assertFalse(isColumnHeading('Price tag'), 'a real item that happens to start with a heading word');
        $this->assertFalse(isColumnHeading('TOTAL'), 'one word is left to the tax/total filter');
        $kept = dropTaxLineItems([['name' => 'QTY PRICE AMOUNT', 'line_total' => 12.49], ['name' => 'HOT BE', 'line_total' => 2.49]]);
        $this->assertSame(['HOT BE'], array_column($kept, 'name'));
    }
}
