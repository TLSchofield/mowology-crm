<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';

class ReceiptFuelPumpTest extends TestCase
{
    private const CHEVRON = <<<TXT
TRANSACTION RECORD
16&Macdonld Twn Pnty
(604) 730-2785
2026-10-28 08:09:41
STORE #:
43016
PUMP 6
Supreme (91)
10.124L AT $2.299/L
Fuel sales $ 23.28
GST INCLUDED $ 1.11
TOTAL
CAD$ 23.28
TXT;

    public function testChevronPumpReceipt(): void
    {
        $r = parseReceiptText(self::CHEVRON);
        $this->assertSame('23.28', $r['total']);
        $this->assertSame('1.11', $r['gst']);
        $this->assertSame('2026-10-28', $r['date']);
        $this->assertCount(1, $r['line_items']);
        $it = $r['line_items'][0];
        $this->assertSame('Supreme (91) fuel', $it['name']);
        $this->assertSame(10.124, $it['quantity']);
        $this->assertSame(2.299, $it['unit_price']);
        $this->assertSame('23.28', $it['amount']);
        $this->assertSame('fuel_pump', $r['line_items_source']);
        $this->assertSame('match', $r['line_items_quality']);
    }

    public function testAlternateLabels(): void
    {
        $it = extractFuelPumpItem(['REGULAR', 'VOLUME 18.421 L', 'PRICE/L 1.264', 'Fuel sales $ 23.28']);
        $this->assertSame('Regular fuel', $it['name']);
        $this->assertSame(18.421, $it['quantity']);
        $this->assertSame(1.264, $it['unit_price']);
    }

    public function testRejectedWhenTheMathDoesNotHold(): void
    {
        $this->assertNull(extractFuelPumpItem(['Supreme (91)', '10.124L AT $2.299/L', 'Fuel sales $ 40.00']));
    }

    public function testNotUsedWhenItIsNotTheWholePurchase(): void
    {
        $r = parseReceiptText(str_replace("TOTAL\nCAD\$ 23.28", "TOTAL\nCAD\$ 30.00", self::CHEVRON));
        $this->assertNotSame('fuel_pump', $r['line_items_source'] ?? null);
    }

    public function testDieselIsRecognised(): void
    {
        $it = extractFuelPumpItem(['DIESEL', '30.000L AT $2.000/L', 'Fuel sales $ 60.00']);
        $this->assertSame('Diesel fuel', $it['name']);
    }

    public function testShellTotalFuelOnTheNextLineIsRecognised(): void
    {
        $r = parseReceiptText("Diesel\nPUMP No.\n10\nLITRES\n52.418\nPRICE/L\n$2.019\nTOTAL FUEL\n$105.83\nTOTAL SALE\n$105.83\nGST - Fuel $5.04");
        $this->assertSame(['Diesel fuel'], array_column($r['line_items'], 'name'));
        $this->assertSame('105.83', $r['line_items'][0]['line_total']);
        $this->assertSame('fuel_pump', $r['line_items_source']);

        $this->assertNotNull(extractFuelPumpItem(['REGULAR', 'LITRES 10.000', 'PRICE/L 2.000', 'FUEL TOTAL', '20.00']));
    }
}
