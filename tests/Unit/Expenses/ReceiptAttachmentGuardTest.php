<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ReceiptAttachmentGuard: the bytes decide what a file is; the sender's name and labels never do. */
class ReceiptAttachmentGuardTest extends TestCase
{
    /** Tiny but structurally real files (1x1 pixel), so the decoder check has something to decode. */
    private function real(string $name): string
    {
        $b64 = [
            'png'  => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            'gif'  => 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
            'webp' => 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==',
            'jpg'  => '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
        ];
        return base64_decode($b64[$name]);
    }

    public function testRealFilesAreRecognisedAndGetTheirOwnExtension(): void
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\nstartxref\n0\n%%EOF\n";
        $heic = "\0\0\0\x18" . 'ftypheic' . str_repeat("\0", 36);
        $cases = [
            'pdf'  => [$pdf, 'pdf', 'pdf'],
            'jpg'  => [$this->real('jpg'), 'image', 'jpg'],
            'png'  => [$this->real('png'), 'image', 'png'],
            'gif'  => [$this->real('gif'), 'image', 'gif'],
            'webp' => [$this->real('webp'), 'image', 'webp'],
            'heic' => [$heic, 'image', 'heic'],
        ];
        foreach ($cases as $name => [$bytes, $kind, $ext]) {
            $r = ReceiptAttachmentGuard::inspect($bytes);
            $this->assertTrue($r['ok'], $name . ': ' . ($r['problem'] ?? ''));
            $this->assertSame($kind, $r['kind'], $name);
            $this->assertSame($ext, $r['ext'], $name);
            $this->assertSame($ext === 'jpg' ? 'image/jpeg' : ($kind === 'pdf' ? 'application/pdf' : 'image/' . $ext), $r['mime'], $name);
        }
    }

    public function testASignatureAloneIsNotEnough(): void
    {
        foreach ([
            "\xFF\xD8\xFF",                                  // three bytes of a JPEG
            "\x89PNG\r\n\x1A\n",                              // just the PNG signature
            "\0\0\0\x18" . 'ftypheicJUNK',                   // a HEIC brand with a box size that is not real
            "<html><script>alert(1)</script>%PDF-1.7",         // PDF marker behind other content
            "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n",              // a PDF cut off before its end marker
        ] as $i => $bytes) {
            $this->assertFalse(ReceiptAttachmentGuard::inspect($bytes)['ok'], 'case ' . $i);
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
        $pdf = "%PDF-1.4\n" . str_repeat('a', 2000) . "\n%%EOF\n";
        $this->assertTrue(ReceiptAttachmentGuard::inspect($pdf, 5000)['ok']);
        $this->assertFalse(ReceiptAttachmentGuard::inspect($pdf, 1000)['ok']);
        $this->assertStringContainsString('too large', ReceiptAttachmentGuard::inspect($pdf, 1000)['problem']);
        $this->assertSame(15728640, ReceiptAttachmentGuard::MAX_BYTES);
    }

    public function testPdfMayStartWithABomOrNewlineButNotFarIn(): void
    {
        $tail = "1 0 obj\n<<>>\nendobj\n%%EOF\n";
        $this->assertTrue(ReceiptAttachmentGuard::inspect("\xEF\xBB\xBF\n%PDF-1.5\n" . $tail)['ok']);
        // a printer/scanner header before the marker is tolerated; markup before it is not
        $this->assertTrue(ReceiptAttachmentGuard::inspect(str_repeat('@PJL JOB ', 20) . "%PDF-1.5\n" . $tail)['ok']);
        $this->assertFalse(ReceiptAttachmentGuard::inspect('<html><body>' . "%PDF-1.5\n" . $tail)['ok']);
        $this->assertFalse(ReceiptAttachmentGuard::inspect(str_repeat('x', 2000) . "%PDF-1.5\n" . $tail)['ok']);
    }
}
