<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLineItemIntelligence.php';

class ReceiptReadModeTest extends TestCase
{
    public function testDefaultsToCheap(): void
    {
        $this->assertSame('cheap', receiptReadMode(null));
        $this->assertSame('cheap', receiptReadMode([]));
        $this->assertSame('cheap', receiptReadMode(['accuracy_rate' => '90.00', 'total_receipts' => 20]));
    }

    public function testPoorAccuracyOverEnoughReceiptsIsStrong(): void
    {
        $this->assertSame('strong', receiptReadMode(['accuracy_rate' => '59.99', 'total_receipts' => 5]));
        $this->assertSame('cheap', receiptReadMode(['accuracy_rate' => '40.00', 'total_receipts' => 4]), 'too few receipts to judge');
        $this->assertSame('cheap', receiptReadMode(['accuracy_rate' => '60.00', 'total_receipts' => 9]));
    }

    public function testOwnerFlagWins(): void
    {
        $this->assertSame('strong', receiptReadMode(['read_mode' => 'strong', 'accuracy_rate' => '99', 'total_receipts' => 50]));
        $this->assertSame('cheap', receiptReadMode(['read_mode' => 'cheap', 'accuracy_rate' => '10', 'total_receipts' => 50]));
    }

    public function testStrongModeAsksAboutAnEstimatedGst(): void
    {
        $asked = null;
        $transport = static function (array $req) use (&$asked): array {
            $asked = json_decode($req['messages'][0]['content'], true)['requested_fields'];
            return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode(['gst' => '1.11'])]]])];
        };
        $d = date('Y-m-d', strtotime('-1 day'));
        $parsed = ['total' => '23.28', 'subtotal' => '22.17', 'gst' => '1.11', 'pst' => null, 'date' => $d, 'gst_estimated' => true, 'subtotal_derived' => true];
        $text = "$d\nGST INCLUDED 1.11\nTOTAL 23.28";
        $cheap = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => $text], $transport);
        $this->assertNull($asked, 'cheap mode does not spend an AI call on a missing GST alone');
        $strong = enhanceHeaders(['parsed' => $parsed, 'ocr_text' => $text, 'read_mode' => 'strong'], $transport);
        $this->assertSame(['gst'], $asked);
        $this->assertFalse($strong['parsed']['gst_estimated']);
        $this->assertSame('printed', $strong['parsed']['gst_source']);
    }
}
