<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptLearning.php';
require_once __DIR__ . '/../../../app/Services/Receipts/DeviceOcrCompare.php';

class DeviceOcrCompareTest extends TestCase
{
    private const CHEVRON = "TRANSACTION RECORD\n16&Macdonld Twn Pnty\n(604) 730-2785\n2026-10-28 08:09:41\nPUMP 6\nSupreme (91)\n10.124L AT \$2.299/L\nFuel sales \$ 23.28\nGST INCLUDED \$ 1.11\nTOTAL\nCAD\$ 23.28";

    public function test_modes(): void
    {
        $this->assertSame('shadow', deviceOcrMode(null));
        $this->assertSame('shadow', deviceOcrMode('banana'));
        $this->assertSame('off', deviceOcrMode(' OFF '));
        $this->assertSame('on', deviceOcrMode('on'));
        $this->assertSame('on', deviceOcrMode('1'));
    }

    public function test_clean_input_keeps_known_engines_only(): void
    {
        $ok = cleanDeviceOcr(['client_ocr_text' => self::CHEVRON, 'client_ocr_engine' => 'Apple_Vision', 'client_ocr_version' => '17.2 <b>']);
        $this->assertSame('apple_vision', $ok['engine']);
        $this->assertSame('17.2b', $ok['version']);
        $this->assertNull(cleanDeviceOcr(['client_ocr_text' => self::CHEVRON, 'client_ocr_engine' => 'tesseract']));
        $this->assertNull(cleanDeviceOcr(['client_ocr_engine' => 'mlkit']));
        $big = cleanDeviceOcr(['client_ocr_text' => str_repeat("line 1.00\n", 5000), 'client_ocr_engine' => 'mlkit']);
        $this->assertLessThanOrEqual(DEVICE_OCR_MAX_CHARS, strlen($big['text']));
    }

    public function test_gate_wants_lines_money_and_a_date(): void
    {
        $this->assertTrue(deviceTextUsable(self::CHEVRON));
        $this->assertFalse(deviceTextUsable('hello'));
        $this->assertFalse(deviceTextUsable("a\nb\nc\nd\nsome long line of words without any amount at all here ok\n2026-10-28"));
        $this->assertFalse(deviceTextUsable("shop\nthing 5.00\nother 3.00\nTOTAL 8.00\nthank you for shopping with us today, bye"));
    }

    public function test_acceptable_only_when_the_header_reads_complete(): void
    {
        $this->assertTrue(deviceTextAcceptable(self::CHEVRON, []));
        $this->assertTrue(deviceTextAcceptable(self::CHEVRON, ['gst_unknown']));
        $this->assertFalse(deviceTextAcceptable(self::CHEVRON, ['total_missing']));
        $this->assertFalse(deviceTextAcceptable(self::CHEVRON, ['math_fails', 'gst_unknown']));
    }

    public function test_compare_headers_field_by_field(): void
    {
        $server = ['total' => '23.28', 'gst' => '1.11', 'date' => '2026-10-28', 'vendor_hint' => 'Chevron'];
        $device = ['total' => '23.28', 'gst' => '1.10', 'date' => '2026-10-28', 'vendor_hint' => 'CHEVRON #12'];
        $cmp = compareParsedHeaders($server, $device);
        $this->assertTrue($cmp['total']['match']);
        $this->assertFalse($cmp['gst']['match']);
        $this->assertTrue($cmp['vendor_hint']['match']);
        $this->assertArrayNotHasKey('subtotal', $cmp, 'a field neither side read is not compared');
    }

    public function test_crew_fields_carry_device_text_only_when_sent(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Expenses/Services/ReceiptCaptureService.php';
        $plain = ReceiptCaptureService::crewFields(['total' => '5.00']);
        $this->assertArrayNotHasKey('device_ocr', $plain);
        $with = ReceiptCaptureService::crewFields(['client_ocr_text' => self::CHEVRON, 'client_ocr_engine' => 'mlkit']);
        $this->assertSame('mlkit', $with['device_ocr']['engine']);
    }

    public function test_spot_check_rejects_text_that_disagrees_on_total_or_date(): void
    {
        $this->assertTrue(deviceMatchesServer(['total' => '23.28', 'date' => '2026-10-28'], ['total' => '23.28', 'date' => '2026-10-28', 'gst' => '1.11']));
        $this->assertFalse(deviceMatchesServer(['total' => '99.99', 'date' => '2026-10-28'], ['total' => '23.28', 'date' => '2026-10-28']));
        $this->assertFalse(deviceMatchesServer(['total' => '23.28', 'date' => '2026-01-01'], ['total' => '23.28', 'date' => '2026-10-28']));
        $this->assertTrue(deviceMatchesServer(['total' => '23.28'], ['date' => '2026-10-28']), 'a field one side did not read is not a disagreement');
    }

    /** Migration 1433: the phone's text is kept when the column exists, and the length-only insert still works before it runs. */
    public function test_compare_row_keeps_device_text_once_the_column_exists(): void
    {
        foreach ([true, false] as $withText) {
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE receipt_ocr_compare (id INTEGER PRIMARY KEY, media_id INT, vendor_id INT, engine TEXT, engine_version TEXT,
                server_source TEXT, fields_compared INT, fields_agree INT, detail_json TEXT, device_text_chars INT'
                . ($withText ? ', device_text TEXT' : '') . ')');
            $device = ['text' => "SHOP\n2026-11-04\nTOTAL 14.49", 'engine' => 'apple_vision', 'version' => '1'];
            logDeviceOcrCompare($db, 7, null, $device, ['total' => '14.49', 'date' => '2026-11-04'], 'vision');
            $row = $db->query('SELECT * FROM receipt_ocr_compare')->fetch(PDO::FETCH_ASSOC);
            $this->assertSame(strlen($device['text']), (int)$row['device_text_chars']);
            if ($withText) {
                $this->assertSame($device['text'], $row['device_text']);
            } else {
                $this->assertArrayNotHasKey('device_text', $row);
            }
        }
    }
}
