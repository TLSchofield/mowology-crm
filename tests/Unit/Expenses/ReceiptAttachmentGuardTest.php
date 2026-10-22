<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ReceiptAttachmentGuard: the bytes decide what a file is; the sender's name and labels never do. */
class ReceiptAttachmentGuardTest extends TestCase
{
    public function testRealFilesAreRecognisedAndGetTheirOwnExtension(): void
    {
        $cases = [
            'pdf'  => ["%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj", 'pdf', 'pdf'],
            'jpg'  => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", 'image', 'jpg'],
            'png'  => ["\x89PNG\r\n\x1A\n\x00\x00", 'image', 'png'],
            'gif'  => ['GIF89a' . str_repeat("\0", 10), 'image', 'gif'],
            'webp' => ['RIFF' . "\x10\0\0\0" . 'WEBPVP8 ', 'image', 'webp'],
            'heic' => ["\0\0\0\x18" . 'ftypheic' . "\0\0\0\0", 'image', 'heic'],
        ];
        foreach ($cases as $name => [$bytes, $kind, $ext]) {
            $r = ReceiptAttachmentGuard::inspect($bytes);
            $this->assertTrue($r['ok'], $name);
            $this->assertSame($kind, $r['kind'], $name);
            $this->assertSame($ext, $r['ext'], $name);
        }
    }

    public function testAPhpScriptLabelledAsAnImageIsRefused(): void
    {
        $r = ReceiptAttachmentGuard::inspect("<?php system(\$_GET['c']); ?>");
        $this->assertFalse($r['ok']);
        $this->assertNull($r['ext']);
        $this->assertSame('not a PDF or image', $r['problem']);
    }

    public function testHtmlAndZipAndTextAreRefused(): void
    {
        foreach (["<html><script>alert(1)</script>", "PK\x03\x04zipdata", "just some text", "MZ\x90\x00exe"] as $b) {
            $this->assertFalse(ReceiptAttachmentGuard::inspect($b)['ok']);
        }
    }

    public function testEmptyAndOversizeAreRefused(): void
    {
        $this->assertSame('empty file', ReceiptAttachmentGuard::inspect('')['problem']);
        $pdf = "%PDF-1.4\n" . str_repeat('a', 2000);
        $this->assertTrue(ReceiptAttachmentGuard::inspect($pdf, 5000)['ok']);
        $this->assertFalse(ReceiptAttachmentGuard::inspect($pdf, 1000)['ok']);
        $this->assertStringContainsString('too large', ReceiptAttachmentGuard::inspect($pdf, 1000)['problem']);
        $this->assertSame(15728640, ReceiptAttachmentGuard::MAX_BYTES);
    }

    public function testPdfSignatureMayFollowAFewBytesOfJunk(): void
    {
        $this->assertTrue(ReceiptAttachmentGuard::inspect("\xEF\xBB\xBF\n%PDF-1.5\n")['ok']);
        // ...but not buried deep in an otherwise unrelated file.
        $this->assertFalse(ReceiptAttachmentGuard::inspect(str_repeat('x', 2000) . "%PDF-1.5")['ok']);
    }
}
