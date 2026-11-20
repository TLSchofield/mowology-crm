<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLineItemIntelligence.php';

class ReceiptHeaderLlmTest extends TestCase
{
    private function transport(array $answer, ?int &$calls = null): callable
    {
        return static function (array $req) use ($answer, &$calls): array {
            $calls = ($calls ?? 0) + 1;
            return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($answer)]]])];
        };
    }

    private function recent(): string
    {
        return date('Y-m-d', strtotime('-3 days'));
    }

    public function testAcceptsValueThatIsPrintedInTheText(): void
    {
        $r = extractHeadersWithLlm("Pump 6\nTOTAL\nCAD\$ 23,28", ['total'], $this->transport(['total' => '23.28']));
        $this->assertTrue($r['success']);
        $this->assertSame(['total' => '23.28'], $r['fields']);
    }

    public function testRejectsValueNotInTheText(): void
    {
        $r = extractHeadersWithLlm("Pump 6\nTOTAL\nCAD\$ 23.28", ['total'], $this->transport(['total' => '24.28']));
        $this->assertFalse($r['success']);
        $this->assertSame('not_in_text:total', $r['rejected_reason']);
    }

    public function testRejectsAmountThatIsOnlyAFragmentOfALongerNumber(): void
    {
        $r = extractHeadersWithLlm('Ref 1123.28 paid', ['total'], $this->transport(['total' => '23.28']));
        $this->assertFalse($r['success']);
    }

    public function testDateRules(): void
    {
        $d = $this->recent();
        [$y, $m, $day] = explode('-', $d);
        $this->assertSame($d, receiptDatePrintedInText($d, "Date $day/$m/$y"));
        $this->assertNull(receiptDatePrintedInText($d, 'no date here'), 'digits must be printed');
        $future = date('Y-m-d', strtotime('+2 days'));
        $this->assertNull(receiptDatePrintedInText($future, $future));
        $old = date('Y-m-d', strtotime('-500 days'));
        $this->assertNull(receiptDatePrintedInText($old, $old));
        $this->assertNull(receiptDatePrintedInText('2026-02-30', '2026-02-30'));
    }

    public function testOnlyRequestedFieldsAreAccepted(): void
    {
        $r = extractHeadersWithLlm("TOTAL 23.28\nGST 1.11", ['total'], $this->transport(['total' => '23.28', 'gst' => '1.11']));
        $this->assertSame(['total' => '23.28'], $r['fields']);
    }

    public function testCapRespected(): void
    {
        $calls = 0;
        $r = extractHeadersWithLlm('TOTAL 23.28', ['total'], $this->transport(['total' => '23.28'], $calls), static function () { return false; });
        $this->assertFalse($r['success']);
        $this->assertSame('daily_cap', $r['rejected_reason']);
        $this->assertSame(0, $calls);
    }

    public function testEnhanceHeadersMergesMissingTotalOnly(): void
    {
        $d = $this->recent();
        $parsed = ['total' => null, 'subtotal' => null, 'gst' => '1.11', 'pst' => null, 'date' => $d];
        $out = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => "$d\nGST 1.11\nTOTAL\nCAD\$ 23.28"], $this->transport(['total' => '23.28', 'date' => '1999-01-01']));
        $this->assertSame('23.28', $out['parsed']['total']);
        $this->assertSame($d, $out['parsed']['date']);
        $this->assertSame('llm', $out['parsed']['header_source']);
        $this->assertSame(['total'], $out['parsed']['header_llm_fields']);
    }

    public function testNeverOverwritesAGoodValueAndDoesNotCallWhenFine(): void
    {
        $d = $this->recent();
        $parsed = ['total' => '112.00', 'subtotal' => '100.00', 'gst' => '5.00', 'pst' => '7.00', 'date' => $d];
        $calls = 0;
        $out = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => "TOTAL 112.00 $d"], $this->transport(['total' => '999.00'], $calls));
        $this->assertSame('112.00', $out['parsed']['total']);
        $this->assertSame(0, $calls);
        $this->assertSame([], $out['parsed']['header_flags']);
    }

    public function testMathFailReplacementKeptOnlyIfSumNowHolds(): void
    {
        $d = $this->recent();
        $parsed = ['total' => '120.00', 'subtotal' => '100.00', 'gst' => '5.00', 'pst' => '7.00', 'date' => $d];
        $text = "$d SUB 100.00 GST 5.00 PST 7.00 TOTAL 112.00";
        $out = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => $text], $this->transport(['total' => '112.00', 'subtotal' => '100.00', 'gst' => '5.00', 'pst' => '7.00']));
        $this->assertSame('112.00', $out['parsed']['total']);
        $this->assertNotContains('math_fails', $out['parsed']['header_flags']);

        // The model offers a total that is printed but still does not add up: keep the parser's value.
        $out = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => "$text 119.00"], $this->transport(['total' => '119.00', 'subtotal' => null, 'gst' => null, 'pst' => null]));
        $this->assertSame('120.00', $out['parsed']['total']);
    }
}
