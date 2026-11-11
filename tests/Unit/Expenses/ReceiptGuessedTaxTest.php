<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptLlmExtractor.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptLineItemIntelligence.php';

/** A receipt that prints its taxes never keeps a guessed one (Lordco #538, 2026-11-06). Invented text, same layout. */
final class ReceiptGuessedTaxTest extends TestCase
{
    private const STACKED = "LORDCO\nAUTO PARTS\nDate: 04/07/25\nQty Product\n2\nBOS40722A\n13.990\nMICRO EDGE WIPER BLA\nGoods Total\nGST Total\n27.98\n1.40\nPST Total\n1.96\nInvoice Total\n31.34\nMethod of Payment";

    private function transport(array $answer, ?int &$calls = null): callable
    {
        return static function (array $req) use ($answer, &$calls): array {
            $calls = ($calls ?? 0) + 1;
            return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($answer)]]])];
        };
    }

    public function testTheRulesGuessOnThisLayout(): void
    {
        $p = parseReceiptText(self::STACKED);
        $this->assertSame('31.34', $p['total']);
        $this->assertTrue(!empty($p['gst_estimated']), 'the stacked labels defeat the rules: the case this fixes');
    }

    public function testPrintedTaxesReplaceTheGuess(): void
    {
        $calls = 0;
        $ctx = enhanceHeaders(['parsed' => parseReceiptText(self::STACKED), 'ocr_text' => self::STACKED],
            $this->transport(['subtotal' => '27.98', 'gst' => '1.40', 'pst' => '1.96'], $calls), static fn() => true);
        $p = $ctx['parsed'];
        $this->assertSame(1, $calls);
        $this->assertSame('27.98', (string)$p['subtotal']);
        $this->assertSame('1.40', (string)$p['gst']);
        $this->assertSame('1.96', (string)$p['pst']);
        $this->assertFalse($p['gst_estimated']);
        $this->assertSame('31.34', $p['total']);
    }

    public function testAnswerThatDoesNotAddUpKeepsTheGuess(): void
    {
        // All printed, but 27.98 + 1.96 + 1.96 is not 31.34: the guess stays.
        $ctx = enhanceHeaders(['parsed' => parseReceiptText(self::STACKED), 'ocr_text' => self::STACKED],
            $this->transport(['subtotal' => '27.98', 'gst' => '1.96', 'pst' => '1.96']), static fn() => true);
        $this->assertTrue(!empty($ctx['parsed']['gst_estimated']));
    }

    public function testNoTaxReceiptAsksNothing(): void
    {
        $calls = 0;
        $text = "CITY OF\nVANCOUVER\nDate: 04/07/25\n10 - Green Waste\n\$40.71\nTotal Fee:\n\$41.00";
        enhanceHeaders(['parsed' => parseReceiptText($text), 'ocr_text' => $text], $this->transport(['gst' => '1.95'], $calls), static fn() => true);
        $this->assertSame(0, $calls);
    }
}
