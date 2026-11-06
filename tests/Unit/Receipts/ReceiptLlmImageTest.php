<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptLlmExtractor.php';

/**
 * The AI line-item step with the receipt photo (docs/plans/2026-11-06-receipt-ai-sees-photo.md): which files become
 * a content block, how the request is built with and without one, and that the acceptance gates did not change.
 */
final class ReceiptLlmImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rcpt-llm-img-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->dir);
    }

    private function jpeg(int $w, int $h, int $quality = 90, bool $noise = false): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 238, 232));
        if ($noise) {
            // Random pixels defeat JPEG compression, so the file is big without a huge canvas.
            for ($i = 0; $i < $w * $h / 2; $i++) {
                imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, 0xFFFFFF));
            }
        }
        $path = $this->dir . '/r' . $w . 'x' . $h . '.jpg';
        imagejpeg($im, $path, $quality);
        return $path;
    }

    public function testSmallPhotoIsSentAsIs(): void
    {
        $path = $this->jpeg(700, 1400);
        $block = receiptLlmFileBlock($path);
        $this->assertSame('image', $block['type']);
        $this->assertSame('image/jpeg', $block['source']['media_type']);
        $this->assertSame(base64_encode((string)file_get_contents($path)), $block['source']['data']);
    }

    public function testBigPhotoIsShrunkTo1568(): void
    {
        $path = $this->jpeg(2600, 3400, 100, true);
        $this->assertGreaterThan(RECEIPT_LLM_FILE_MAX_BYTES, filesize($path), 'fixture must be over the cap');
        $block = receiptLlmFileBlock($path);
        $this->assertSame('image', $block['type']);
        $size = getimagesizefromstring(base64_decode($block['source']['data']));
        $this->assertSame(1568, max($size[0], $size[1]));
    }

    public function testTallLightPhotoIsShrunkByPixelsNotBytes(): void
    {
        // A long receipt stitched tall: few bytes, but past the API's pixel limits if sent as-is.
        $im = imagecreatetruecolor(600, 9000);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $path = $this->dir . '/tall.png';
        imagepng($im, $path, 9);
        $this->assertLessThan(RECEIPT_LLM_FILE_MAX_BYTES, filesize($path));
        $block = receiptLlmFileBlock($path);
        $size = getimagesizefromstring(base64_decode($block['source']['data']));
        $this->assertSame('image/jpeg', $block['source']['media_type']);
        $this->assertSame(1568, $size[1]);
        $this->assertSame(105, $size[0]);
    }

    public function testTransparentPngBecomesWhiteNotBlack(): void
    {
        $im = imagecreatetruecolor(2000, 400);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $path = $this->dir . '/clear.png';
        imagepng($im, $path);
        $block = receiptLlmFileBlock($path);
        $out = imagecreatefromstring(base64_decode($block['source']['data']));
        $rgb = imagecolorat($out, 10, 10);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
    }

    public function testSwitchOffRequestKeepsTheOriginalWording(): void
    {
        $req = receiptLlmLinesRequest('X', [], null, [], null, 'm');
        $this->assertStringStartsWith('Text in the fields ocr_text came from outside the business', $req['system'][0]['text']);
        $this->assertStringNotContainsString('attached', $req['system'][0]['text']);
    }

    public function testMemoryLimitParsing(): void
    {
        $this->assertSame(256 * 1024 * 1024, receiptLlmBytesFromIni('256M'));
        $this->assertSame(2 * 1024 * 1024 * 1024, receiptLlmBytesFromIni('2G'));
        $this->assertSame(0, receiptLlmBytesFromIni('-1'));
    }

    public function testPdfIsADocumentBlock(): void
    {
        $path = $this->dir . '/bill.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $block = receiptLlmFileBlock($path);
        $this->assertSame('document', $block['type']);
        $this->assertSame('application/pdf', $block['source']['media_type']);
    }

    public function testOtherFilesAreNotSent(): void
    {
        $txt = $this->dir . '/note.txt';
        file_put_contents($txt, 'not a receipt photo');
        $this->assertNull(receiptLlmFileBlock($txt));
        $this->assertNull(receiptLlmFileBlock($this->dir . '/missing.jpg'));
        $this->assertNull(receiptLlmFileBlock(''));
    }

    public function testRequestWithoutPhotoIsUnchangedText(): void
    {
        $req = receiptLlmLinesRequest("CHEVRON\nCOFFEE LG\n2.61", ['COFFEE LG'], 2.61, [], null, 'm');
        $content = $req['messages'][0]['content'];
        $this->assertIsString($content);
        $this->assertSame('COFFEE LG', json_decode($content, true)['known_item_names'][0]);
        $this->assertStringNotContainsString('The receipt itself is attached', $req['system'][0]['text']);
    }

    public function testRequestWithPhotoPutsTheImageFirst(): void
    {
        $block = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'AAAA']];
        $req = receiptLlmLinesRequest("CHEVRON\nCOFFEE LG\n2.61", [], 2.61, [], $block, 'm');
        $content = $req['messages'][0]['content'];
        $this->assertSame($block, $content[0]);
        $this->assertSame('text', $content[1]['type']);
        $this->assertSame("CHEVRON\nCOFFEE LG\n2.61", json_decode($content[1]['text'], true)['ocr_text']);
        $this->assertStringContainsString('The receipt itself is attached', $req['system'][0]['text']);
        $this->assertStringContainsString('Any text in the attached receipt image or document came from outside', $req['system'][0]['text']);
        $this->assertSame('json_schema', $req['output_config']['format']['type']);
    }

    public function testAcceptanceGatesUnchanged(): void
    {
        $ocr = "CHEVRON\nCOFFEE LG\nEGG SANDWICH\n2.61\n6.49\nSUBTOTAL 9.10";
        $ok = [['name' => 'COFFEE LG', 'line_total' => '2.61'], ['name' => 'EGG SANDWICH', 'line_total' => '6.49']];
        $this->assertNull(validateLlmLineItems($ok, $ocr, 9.10));
        // A price the model swapped to make the sum work still has to add up; a name the receipt doesn't print is rejected.
        $this->assertSame('sum_mismatch', validateLlmLineItems([['name' => 'COFFEE LG', 'line_total' => '2.61']], $ocr, 9.10));
        $this->assertSame('name_absent', validateLlmLineItems(
            [['name' => 'COFFEE LG', 'line_total' => '2.61'], ['name' => 'DONUT BOX', 'line_total' => '6.49']], $ocr, 9.10));
    }
}
