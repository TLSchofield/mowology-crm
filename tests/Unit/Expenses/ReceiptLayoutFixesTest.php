<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

final class ReceiptLayoutFixesTest extends TestCase
{
    public function testLandfillUsesNearbyTotalFeeAndKeepsScaleDetails(): void
    {
        $r = parseReceiptText("Gross: 4800 kg\nTare:\nNet:\n4420 kg\n380 kg\nMaterial\n10 - Green Waste\n$47.12\n0.38 tonne @ $124.00/tonne\nExtra Fee: $0.00\nTotal Fee:\n$47.00\nDeposit:\n$0.00\nTotal Tendered: $47.00");
        $this->assertCount(1, $r['line_items']);
        $this->assertSame('Green Waste', $r['line_items'][0]['name']);
        $this->assertSame('47.00', $r['line_items'][0]['line_total']);
        $this->assertSame(0.38, $r['line_items'][0]['quantity']);
        $this->assertSame('tonne', $r['line_items'][0]['unit']);
        $this->assertSame(124.0, $r['line_items'][0]['unit_price']);
    }

    public function testLandfillDoesNotUseAUnrelatedTotalFee(): void
    {
        $items = extractLineItems(['Material', '10 - Green Waste', '$47.12', '0.38 tonne @ $124.00/tonne', 'Total Fee:', '$40.00']);
        $this->assertSame('47.12', $items[0]['amount']);
    }

    public function testLandfillConsumesOnlyItsMatchedLinesAndKeepsFollowingItem(): void
    {
        $items = extractLineItems([
            '10 - Green Waste',
            '$47.12',
            '0.38 tonne @ $124.00/tonne',
            'Total Fee: $47.00',
            'Safety Gloves',
            '$12.99',
        ]);

        $this->assertSame(['Green Waste', 'Safety Gloves'], array_column($items, 'name'));
        $this->assertSame(['47.00', '12.99'], array_column($items, 'amount'));
    }

    public function testPlainChevronBlocksAreConsumedOnce(): void
    {
        $text = "PRODUCT\nQTY PRICE\nAMOUNT\nCRF 0.07\n1 0.07\n0.07\nCRF 0.07\n1 0.07\n0.07\nCUP FEE VANCOUVER\n1\n0.25\n0.25PG\nBISTRO 16OZ HOT BE\n1\n2.29\n2.29P\nGST 5.0000 % 0.13\nPST 7.0000 % 0.16\nTOTAL CAD $\n2.97";
        $r = parseReceiptText($text);
        $this->assertSame(['CRF 0.07', 'CRF 0.07', 'CUP FEE VANCOUVER', 'BISTRO 16OZ HOT BE'], array_column($r['line_items'], 'name'));
        $this->assertSame(['0.07', '0.07', '0.25', '2.29'], array_column($r['line_items'], 'amount'));
    }

    public function testBottleDepositIsAPurchaseWithItsPrintedCount(): void
    {
        $items = extractLineItems(['2 Deposit ($0.20)']);
        $this->assertSame('Deposit', $items[0]['name']);
        $this->assertSame('0.20', $items[0]['amount']);
        $this->assertSame(2, $items[0]['quantity']);
    }

    public function testSeparatedColumnsReplaceOnlyAMismatchedReadWhenTheyFit(): void
    {
        $text = "PRODUCT\nCRF 0.07\nQTY PRICE\nAMOUNT\n2 0.07\n0.14\n1 2.49\n2.49P\n1\n6.00 6.00\n@ $ 3.00 PG\nGST 5.0000 %\n0.42\nPST 7.0000 %\n0.42\n9.67\nBISTRO 24OZ HOT BE\nBP COKE SPARKLING\n2 DIET COKE 500ML\n2 Deposit ($0.20)\nTOTAL CAD $";
        $r = parseReceiptText($text);
        $this->assertSame('columns', $r['line_items_source']);
        $this->assertSame(['CRF 0.07', 'BISTRO 24OZ HOT BE', 'BP COKE SPARKLING', 'Deposit'], array_column($r['line_items'], 'name'));
        $this->assertSame(['0.14', '2.49', '6.00', '0.20'], array_column($r['line_items'], 'amount'));
        $this->assertSame(2, $r['line_items'][0]['quantity']);
        $this->assertSame(2, $r['line_items'][3]['quantity']);
        $this->assertSame(['columns', 'columns', 'columns', 'columns'], array_column($r['line_items'], 'name_source'));
    }

    public function testSeparatedColumnsRejectPaymentStoreAndFooterLinesAsNames(): void
    {
        $items = pairSeparatedColumns([
            'PRODUCT',
            'Safety Gloves',
            'STORE #1234',
            'VISA',
            'APPROVED',
            'THANK YOU',
            '12.99',
            'TOTAL CAD $',
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('Safety Gloves', $items[0]['name']);
        $this->assertSame('12.99', $items[0]['amount']);
    }

    public function testInvoiceTableReadsCurrencyPrefixTaxAndDigitsInName(): void
    {
        $text = "Description Qty Unit price Tax Amount\nCloud credits, Studio tier for 2 years 1 CA$1,240.00 13% CA$1,240.00\n\nSubtotal CA$1,240.00\nGST - Canada CA$62.00\nPST - Province CA$86.80\nTotal CA$1,388.80";
        $r = parseReceiptText($text);

        $this->assertSame('invoice_table', $r['line_items_source']);
        $this->assertCount(1, $r['line_items']);
        $this->assertSame('Cloud credits, Studio tier for 2 years', $r['line_items'][0]['name']);
        $this->assertSame(1, $r['line_items'][0]['quantity']);
        $this->assertSame(1240.0, $r['line_items'][0]['unit_price']);
        $this->assertSame('1240.00', $r['line_items'][0]['amount']);
        $this->assertSame('invoice_table', $r['line_items'][0]['name_source']);
    }

    public function testInvoiceTableReadsMultipleRowsAndStopsAtSummary(): void
    {
        $text = "PRODUCT QUANTITY UNIT PRICE AMT\nDomain (invented.test) Renewal for 1 year 1 US$18.50 US$18.50\nServer Plan Small (monthly) 2 C$9.99 C$19.98\nSubtotal $38.48\nTotal $43.10\nFooter Service 1 $4.62 $4.62";
        $r = parseReceiptText($text);

        $this->assertSame(['Domain (invented.test) Renewal for 1 year', 'Server Plan Small (monthly)'], array_column($r['line_items'], 'name'));
        $this->assertSame([1, 2], array_column($r['line_items'], 'quantity'));
        $this->assertSame([18.5, 9.99], array_column($r['line_items'], 'unit_price'));
        $this->assertSame(['18.50', '19.98'], array_column($r['line_items'], 'amount'));
    }

    public function testInvoiceTableRequiresValidRowAndSubtotalArithmetic(): void
    {
        $badRow = extractInvoiceTableItems([
            'ACTIVITY QTY UNIT PRICE TOTAL',
            'Consulting block 3 $20.00 $70.00',
            'Subtotal $70.00',
        ]);
        $this->assertSame([], $badRow);

        $r = parseReceiptText("ITEM QTY UNIT PRICE AMOUNT\nNotebook pack 2 $5.00 $10.00\nSubtotal $12.00\nTotal $12.60");
        $this->assertNotSame('invoice_table', $r['line_items_source'] ?? null);
        $this->assertNotContains('invoice_table', array_column($r['line_items'], 'name_source'));
    }

    public function testItemsThatAlreadyAddUpAreNotReplacedByAnInvoiceTable(): void
    {
        $text = "DESCRIPTION QTY UNIT PRICE AMOUNT\nWidget A 1 $10.00 $10.00\nWidget B 1 $5.00 $5.00\nSubtotal $15.00\nGST $0.75\nTotal $15.75";
        $first = parseReceiptText($text, null);
        $this->assertEqualsWithDelta(15.00, array_sum(array_map(fn($i) => (float)($i['line_total'] ?? $i['amount']), $first['line_items'])), 0.001);
        // Whatever pass read them, a second parse is identical: the table never swaps out items that add up.
        $this->assertSame($first['line_items'], parseReceiptText($text, null)['line_items']);
    }
}
