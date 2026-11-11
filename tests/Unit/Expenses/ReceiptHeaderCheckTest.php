<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLineItemIntelligence.php';

class ReceiptHeaderCheckTest extends TestCase
{
    private function clean(): array
    {
        return ['total' => '112.00', 'subtotal' => '100.00', 'gst' => '5.00', 'pst' => '7.00', 'date' => '2026-10-01'];
    }

    public function testCleanReceiptHasNoReasons(): void
    {
        $this->assertSame([], headersNeedBetterExtraction($this->clean()));
    }

    public function testTotalMissing(): void
    {
        $p = $this->clean();
        $p['total'] = null;
        $this->assertContains('total_missing', headersNeedBetterExtraction($p));
    }

    public function testDateMissing(): void
    {
        $p = $this->clean();
        $p['date'] = null;
        $this->assertSame(['date_missing'], headersNeedBetterExtraction($p));
    }

    public function testMathFailsOnPrintedValues(): void
    {
        $p = $this->clean();
        $p['total'] = '120.00';
        $this->assertSame(['math_fails'], headersNeedBetterExtraction($p));
    }

    public function testMathIgnoredWhenParserDerivedAField(): void
    {
        $p = $this->clean();
        $p['total'] = '120.00';
        $p['subtotal_derived'] = true;
        $this->assertNotContains('math_fails', headersNeedBetterExtraction($p));
    }

    public function testGstUnknownWhenNoneOrEstimated(): void
    {
        $p = $this->clean();
        $p['gst'] = null;
        $this->assertContains('gst_unknown', headersNeedBetterExtraction($p));
        $p['gst'] = '5.00';
        $p['gst_estimated'] = true;
        $this->assertContains('gst_unknown', headersNeedBetterExtraction($p));
    }

    public function testTaxFreeReceiptIsNotGstUnknown(): void
    {
        $p = $this->clean();
        $p['gst'] = null;
        $p['ocr_text'] = "Bag of ice\nTAX EXEMPT\nTOTAL 4.00";
        $this->assertNotContains('gst_unknown', headersNeedBetterExtraction($p));
    }

    public function testParserMarksDerivedFields(): void
    {
        $r = parseReceiptText("Hardware\n2026-10-01\nGST incl.\nTOTAL 10.50");
        $this->assertTrue($r['gst_estimated']);
        $this->assertTrue($r['subtotal_derived']);
    }
}
