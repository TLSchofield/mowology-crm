<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/DeviceOcrCompare.php';

/**
 * Rows from the phone's line boxes (reconstructLinesFromDeviceBoxes) on invented receipts. The phone reads a
 * Chevron-like slip as one block of names and one block of prices; the boxes put them back on their rows.
 */
final class DeviceBoxRowsTest extends TestCase
{
    /** An invented Chevron-like slip: names in a left column, prices in a right one, one row each. */
    private function chevronRows(): array
    {
        return [
            ['CHEVRON #20145', null],
            ['2026-11-02 14:31', null],
            ['COFFEE LG', '2.61'],
            ['EGG SANDWICH', '6.49'],
            ['WASHER FLUID', '7.99'],
            ['SUBTOTAL', '17.09'],
            ['GST 5%', '0.85'],
            ['TOTAL', '17.94'],
            ['DEBIT', '17.94'],
        ];
    }

    /** Boxes as the phone sends them, names first and prices after (its reading order), optionally tilted. */
    private function chevronBoxes(float $deg = 0.0): array
    {
        $names = [];
        $prices = [];
        $tan = tan(deg2rad($deg));
        foreach ($this->chevronRows() as $i => [$name, $price]) {
            $y = 0.10 + $i * 0.04;
            // A tilted photo: the further right, the lower (positive deg); the box keeps its row's own height.
            $names[] = ['text' => $name, 'x' => 0.08, 'y' => $y + 0.08 * $tan, 'w' => 0.40, 'h' => 0.018, 'deg' => $deg];
            if ($price !== null) {
                $prices[] = ['text' => $price, 'x' => 0.78, 'y' => $y + 0.78 * $tan, 'w' => 0.12, 'h' => 0.018];
            }
        }
        return array_merge($names, $prices);
    }

    private function chevronPlainText(): string
    {
        return implode("\n", array_column($this->chevronBoxes(), 'text'));
    }

    public function testNamesPairWithPricesOnTheirRows(): void
    {
        $rows = reconstructLinesFromDeviceBoxes($this->chevronBoxes());
        $this->assertSame([
            'CHEVRON #20145',
            '2026-11-02 14:31',
            'COFFEE LG  2.61',
            'EGG SANDWICH  6.49',
            'WASHER FLUID  7.99',
            'SUBTOTAL  17.09',
            'GST 5%  0.85',
            'TOTAL  17.94',
            'DEBIT  17.94',
        ], $rows);
    }

    public function testTiltedPhotoStillPairs(): void
    {
        // 2° over a 1000x2000 photo: the price sits about half a row lower than its name.
        $boxes = $this->chevronBoxes(2.0);
        $rows = reconstructLinesFromDeviceBoxes($boxes, ['w' => 1000, 'h' => 1000]);
        $this->assertContains('EGG SANDWICH  6.49', $rows);
        $this->assertContains('TOTAL  17.94', $rows);
        $this->assertCount(9, $rows);
    }

    public function testStackedBoxesThatOverlapSideBySideAreSeparateRows(): void
    {
        // Two lines almost on top of each other (tight print) but at the same x: never one row.
        $rows = reconstructLinesFromDeviceBoxes([
            ['text' => 'UPPER LINE', 'x' => 0.1, 'y' => 0.100, 'w' => 0.5, 'h' => 0.02],
            ['text' => 'LOWER LINE', 'x' => 0.1, 'y' => 0.108, 'w' => 0.5, 'h' => 0.02],
        ]);
        $this->assertSame(['UPPER LINE', 'LOWER LINE'], $rows);
    }

    public function testBadBoxesAreSkipped(): void
    {
        $rows = reconstructLinesFromDeviceBoxes([
            ['text' => 'KEEP', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.02],
            ['text' => '', 'x' => 0.1, 'y' => 0.2, 'w' => 0.2, 'h' => 0.02],
            ['text' => 'NO BOX'],
            ['text' => 'ZERO', 'x' => 0.1, 'y' => 0.3, 'w' => 0, 'h' => 0.02],
            'not an array',
        ]);
        $this->assertSame(['KEEP'], $rows);
        $this->assertSame([], reconstructLinesFromDeviceBoxes([]));
    }

    public function testParseUsesBoxesForLineItems(): void
    {
        $text = $this->chevronPlainText();
        $withBoxes = parseReceiptText($text, ['device_lines' => $this->chevronBoxes()]);
        $byName = [];
        foreach ($withBoxes['line_items'] as $it) {
            $byName[strtoupper(trim($it['name']))] = $it['amount'];
        }
        $this->assertSame('2.61', (string)($byName['COFFEE LG'] ?? ''));
        $this->assertSame('6.49', (string)($byName['EGG SANDWICH'] ?? ''));
        $this->assertSame('7.99', (string)($byName['WASHER FLUID'] ?? ''));
        // Without boxes the same text cannot pair them: names and prices are two separate blocks.
        $plain = parseReceiptText($text, null);
        $plainPairs = array_filter($plain['line_items'], static fn($it) => strtoupper(trim($it['name'])) === 'COFFEE LG' && (string)$it['amount'] === '2.61');
        $this->assertSame([], array_values($plainPairs));
    }

    public function testCleanDeviceOcrKeepsLinesAndStaysCompatible(): void
    {
        $post = [
            'client_ocr_text'    => $this->chevronPlainText(),
            'client_ocr_engine'  => 'apple_vision',
            'client_ocr_version' => '1.3.80',
            'client_ocr_lines'   => json_encode(['v' => 1, 'image' => ['w' => 1200, 'h' => 2600], 'lines' => $this->chevronBoxes()]),
        ];
        $d = cleanDeviceOcr($post);
        $this->assertCount(16, $d['lines']);
        $this->assertSame(['w' => 1200, 'h' => 2600], $d['image']);
        $this->assertSame(['device_lines' => $d['lines'], 'device_image' => $d['image']], deviceOcrRaw($d));

        // Older apps: no boxes field, the same result as before and no raw for the parser.
        unset($post['client_ocr_lines']);
        $old = cleanDeviceOcr($post);
        $this->assertArrayNotHasKey('lines', $old);
        $this->assertNull(deviceOcrRaw($old));

        // Junk boxes are dropped, never trusted.
        $post['client_ocr_lines'] = '{not json';
        $this->assertArrayNotHasKey('lines', cleanDeviceOcr($post));
        $post['client_ocr_lines'] = json_encode([['text' => 'A', 'x' => 2, 'y' => 0, 'w' => 0.1, 'h' => 0.1]]);
        $this->assertArrayNotHasKey('lines', cleanDeviceOcr($post));

        // Wrong types never raise a warning (an error handler that throws would fail the upload).
        set_error_handler(static function (int $no, string $msg) { throw new ErrorException($msg, 0, $no); });
        try {
            $post['client_ocr_lines'] = json_encode(['image' => 'big', 'lines' => [
                ['text' => ['nested'], 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.02],
                ['text' => 'A', 'x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.02],
                ['text' => 'B', 'x' => 0.5, 'y' => 0.1, 'w' => 0.2, 'h' => 0.02],
            ]]);
            $d = cleanDeviceOcr($post);
            $this->assertCount(2, $d['lines']);
            $this->assertSame([], $d['image']);
        } finally {
            restore_error_handler();
        }
    }

    public function testCaptureQualityTravelsCleaned(): void
    {
        $post = ['client_ocr_text' => "SHOP\nTOTAL 1.00", 'client_ocr_engine' => 'apple_vision', 'client_capture' => '{"chars":412,"sharpness":88.26,"used_anyway":true,"extra":"x"}'];
        $this->assertSame(['chars' => 412, 'sharpness' => 88.3, 'used_anyway' => true], cleanDeviceOcr($post)['capture']);
        $this->assertNull(cleanDeviceCapture('not json'));
        $this->assertNull(cleanDeviceCapture('{"sharpness":-3}'));
    }
}
