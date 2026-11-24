<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLineItemIntelligence.php';

/**
 * Header layouts learned from corrections (structure, never values) and the fuel-content category rule.
 */
class ReceiptHeaderLayoutTest extends TestCase
{
    /** A vendor whose total sits two lines under "PAY AMOUNT" — the generic extractor does not read it. */
    private function receipt(string $total, string $date): string
    {
        return "ACME FUEL\n$date 08:09:41\nPump 6\nSubtotal item\nPAY AMOUNT\nCAD\$ $total\nThanks";
    }

    private function lessons(): array
    {
        $out = [];
        foreach ([['23.28', '2026-10-27'], ['41.10', '2026-10-28'], ['9.99', '2026-10-29']] as [$t, $d]) {
            $ctx = "Pump 6\nSubtotal item\nPAY AMOUNT\nCAD\$ $t\nThanks";
            $out[] = ['field_name' => 'total', 'ocr_context' => $ctx, 'corrected_value' => $t, 'times_seen' => 1];
        }
        return $out;
    }

    public function testLayoutIsLearnedFromConsistentCorrections(): void
    {
        $l = deriveHeaderLayouts($this->lessons());
        $this->assertSame('PAY AMOUNT', $l['total'][0]['label']);
        $this->assertSame('next_line', $l['total'][0]['pos']);
        $this->assertSame('CAD$', $l['total'][0]['cur']);
        $this->assertSame(3, $l['total'][0]['seen']);
    }

    public function testOneCorrectionIsNotEnough(): void
    {
        $this->assertSame([], deriveHeaderLayouts([$this->lessons()[0]]));
    }

    public function testLayoutReadsAReceiptTheGenericParserMisses(): void
    {
        $text = $this->receipt('57.31', '2026-10-30');
        $generic = parseReceiptText($text);
        // precondition: the generic parser does not read this layout
        $this->assertNotSame('57.31', $generic['total']);

        $fixed = applyHeaderLayouts($generic, $text, deriveHeaderLayouts($this->lessons()));
        $this->assertSame('57.31', $fixed['total']);
        $this->assertSame('learned', $fixed['total_source']);
    }

    public function testLayoutIsIgnoredWhenTheGenericParserSucceeds(): void
    {
        $text = "ACME\nPAY AMOUNT\nCAD\$ 99.99\nTOTAL 50.00\nGST 2.38\nSUBTOTAL 47.62";
        $parsed = ['total' => '50.00', 'subtotal' => '47.62', 'gst' => '2.38', 'pst' => null, 'date' => '2026-10-28'];
        $out = applyHeaderLayouts($parsed, $text, deriveHeaderLayouts($this->lessons()));
        $this->assertSame('50.00', $out['total']);
        $this->assertArrayNotHasKey('header_layout_fields', $out);
    }

    public function testLayoutNeverReplaysALiteralAmount(): void
    {
        $text = "ACME\nPAY AMOUNT\nno amount printed";
        $out = applyHeaderLayouts(['total' => null, 'gst' => null, 'subtotal' => null, 'pst' => null], $text, deriveHeaderLayouts($this->lessons()));
        $this->assertNull($out['total']);
    }

    public function testChevronTotalLayoutIsObserved(): void
    {
        $ctx = "Fuel sales \$ 23.28\nGST INCLUDED \$ 1.11\nTOTAL\nCAD\$ 23.28\nPurchase";
        $o = observeHeaderLayout('total', $ctx, '23.28');
        $this->assertSame(['label' => 'TOTAL', 'pos' => 'next_line', 'cur' => 'CAD$'], $o);
        $g = observeHeaderLayout('gst', $ctx, '1.11');
        $this->assertSame('GST INCLUDED', $g['label']);
        $this->assertSame('same_line', $g['pos']);
    }

    public function testContextPrefersTheOccurrenceUnderItsOwnLabel(): void
    {
        $text = "Supreme (91)\n10.124L AT \$2.299/L\nFuel sales \$ 23.28\nGST INCLUDED \$ 1.11\nTOTAL\nCAD\$ 23.28\nDone";
        $ctx = extractOcrContext($text, 'total', '', '23.28');
        $this->assertStringContainsString("TOTAL\nCAD\$ 23.28", (string)$ctx);
    }

    public function testDateFormatNeedsTwoConfirmations(): void
    {
        $mk = static function (string $iso, string $ctx) {
            return ['field_name' => 'date', 'ocr_context' => $ctx, 'corrected_value' => $iso, 'times_seen' => 1];
        };
        $one = [$mk('2026-10-27', 'Date 27/10/2026')];
        $this->assertNull(deriveDateFormat($one));
        $two = [$mk('2026-10-27', 'Date 27/10/2026'), $mk('2026-10-15', "x\n15/10/2026")];
        $this->assertSame('DD/MM/YYYY', deriveDateFormat($two));
        $this->assertSame('2026-10-28', extractDateWithFormat('on 28/10/2026', 'DD/MM/YYYY'));
    }

    public function testPstIsACorrectedHeaderField(): void
    {
        $d = headerCorrections(['pst' => '3.15'], ['pst_amount' => '3.50'], null);
        $this->assertTrue($d['fields']['pst']['corrected']);
        $d = headerCorrections(['pst' => null], ['pst_amount' => '0.00'], null);
        $this->assertArrayNotHasKey('pst', $d['fields']);
    }

    public function testFuelReceiptKeepsFuelDespiteVendorMealsLesson(): void
    {
        $profile = ['learned_accounting_category' => 'Meals', 'category_correction_count' => 20];
        $fuel = resolveLearnedCategory([], $profile, "PUMP 6\nSupreme (91)\n10.124L AT \$2.299/L");
        $this->assertSame('Fuel', $fuel['accounting_category']);
        $coffee = resolveLearnedCategory([], $profile, "BISTRO 24OZ HOT BE 1 2.49");
        $this->assertSame('Meals', $coffee['accounting_category']);
    }
}
