<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Accounting/Services/PdfSecurityHandler.php';

class PdfSecurityHandlerTest extends TestCase
{
    private const PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";
    private const TEXT = 'JAN 05 TEST VENDOR 12.34';

    /** @dataProvider encryptionProvider */
    public function testDecryptsSupportedStandardSecurityHandlers(int $version, int $revision, string $method): void
    {
        $encrypted = $this->encryptedPdf($version, $revision, $method, '');

        $this->assertTrue(PdfSecurityHandler::isEncrypted($encrypted));
        $decrypted = PdfSecurityHandler::decrypt($encrypted);

        $this->assertFalse(PdfSecurityHandler::isEncrypted($decrypted));
        $this->assertStringNotContainsString('/Encrypt ', $decrypted);
        $this->assertStringContainsString('(' . self::TEXT . ') Tj', $this->contentStream($decrypted));
    }

    public function encryptionProvider(): array
    {
        return [
            'RC4-40' => [1, 2, 'rc4'],
            'RC4-128' => [2, 3, 'rc4'],
            'AESV2' => [4, 4, 'aes'],
        ];
    }

    public function testRejectsPdfWhoseUserPasswordIsNotEmpty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a user password');
        PdfSecurityHandler::decrypt($this->encryptedPdf(2, 3, 'rc4', 'not-empty'));
    }

    public function testRejectsGarbageInput(): void
    {
        $this->expectException(RuntimeException::class);
        PdfSecurityHandler::decrypt('invented garbage');
    }

    public function testReportsUnencryptedPdf(): void
    {
        $this->assertFalse(PdfSecurityHandler::isEncrypted($this->plainPdf()));
    }

    /**
     * Smalot is production-vendor code and is not part of the unit under test.
     * Keep its parser state out of the shared PHPUnit process: later HTTP tests
     * inspect http_response_code(), which otherwise remains 0 after this parse.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSmalotParsesDecryptedPdfWhenAvailable(): void
    {
        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            $this->markTestSkipped('smalot/pdfparser is not installed.');
        }
        $bytes = PdfSecurityHandler::decrypt($this->encryptedPdf(2, 3, 'rc4', ''));
        $text = (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText();
        $this->assertStringContainsString(self::TEXT, $text);
    }

    private function encryptedPdf(int $version, int $revision, string $method, string $userPassword): string
    {
        $id = md5('invented-pdf-identifier', true);
        $length = $revision === 2 ? 5 : 16;
        $owner = $this->ownerEntry('invented-owner-password', $userPassword, $revision, $length);
        $permissions = -4;
        $fileKey = $this->fileKey($userPassword, $owner, $permissions, $id, $revision, $length);
        $user = $this->userEntry($fileKey, $id, $revision);
        $plainStream = "BT /F1 12 Tf 20 100 Td (" . self::TEXT . ") Tj ET";
        $stream = $this->encryptObjectValue($plainStream, $fileKey, 4, 0, $method);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << /Font << /F1 6 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream",
            5 => $this->encryptionDictionary($version, $revision, $length, $permissions, $owner, $user, $method),
            6 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        return $this->buildPdf($objects, '/Root 1 0 R /Encrypt 5 0 R /ID [<' . bin2hex($id) . '><' . bin2hex($id) . '>]');
    }

    private function plainPdf(): string
    {
        $stream = "BT /F1 12 Tf 20 100 Td (" . self::TEXT . ") Tj ET";
        return $this->buildPdf([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>',
            4 => '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream",
        ], '/Root 1 0 R');
    }

    private function encryptionDictionary(int $version, int $revision, int $length, int $permissions, string $owner, string $user, string $method): string
    {
        $dictionary = '<< /Filter /Standard /V ' . $version . ' /R ' . $revision;
        if ($revision !== 2) $dictionary .= ' /Length ' . ($length * 8);
        $dictionary .= ' /O <' . bin2hex($owner) . '> /U <' . bin2hex($user) . '> /P ' . $permissions;
        if ($version === 4) {
            $cfm = $method === 'aes' ? '/AESV2' : '/V2';
            $dictionary .= ' /EncryptMetadata false /CF << /StdCF << /CFM ' . $cfm . ' /Length 16 >> >> /StmF /StdCF /StrF /StdCF';
        }
        return $dictionary . ' >>';
    }

    private function ownerEntry(string $ownerPassword, string $userPassword, int $revision, int $length): string
    {
        $digest = md5($this->pad($ownerPassword), true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) $digest = md5($digest, true);
        }
        $key = substr($digest, 0, $length);
        $value = $this->rc4($this->pad($userPassword), $key);
        if ($revision >= 3) {
            for ($i = 1; $i <= 19; $i++) $value = $this->rc4($value, $this->xorKey($key, $i));
        }
        return $value;
    }

    private function fileKey(string $userPassword, string $owner, int $permissions, string $id, int $revision, int $length): string
    {
        $material = $this->pad($userPassword) . $owner . pack('V', $permissions & 0xffffffff) . $id;
        if ($revision >= 4) $material .= "\xFF\xFF\xFF\xFF";
        $digest = md5($material, true);
        if ($revision >= 3) {
            for ($i = 0; $i < 50; $i++) $digest = md5(substr($digest, 0, $length), true);
        }
        return substr($digest, 0, $length);
    }

    private function userEntry(string $fileKey, string $id, int $revision): string
    {
        if ($revision === 2) return $this->rc4(self::PADDING, $fileKey);
        $value = $this->rc4(md5(self::PADDING . $id, true), $fileKey);
        for ($i = 1; $i <= 19; $i++) $value = $this->rc4($value, $this->xorKey($fileKey, $i));
        return $value . str_repeat("\0", 16);
    }

    private function encryptObjectValue(string $value, string $fileKey, int $number, int $generation, string $method): string
    {
        $salt = $method === 'aes' ? 'sAlT' : '';
        $key = substr(md5($fileKey . substr(pack('V', $number), 0, 3) . substr(pack('V', $generation), 0, 2) . $salt, true), 0, min(strlen($fileKey) + 5, 16));
        if ($method === 'rc4') return $this->rc4($value, $key);
        $padding = 16 - (strlen($value) % 16);
        $iv = substr(md5('invented-initialization-vector', true), 0, 16);
        $encrypted = openssl_encrypt($value . str_repeat(chr($padding), $padding), 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        $this->assertNotFalse($encrypted);
        return $iv . $encrypted;
    }

    private function contentStream(string $pdf): string
    {
        $this->assertSame(1, preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $match));
        return $match[1];
    }

    private function buildPdf(array $objects, string $trailerEntries): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $pdf .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 00000 f \n";
        }
        return $pdf . "trailer\n<< /Size " . $size . ' ' . $trailerEntries . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private function pad(string $password): string
    {
        return substr($password . self::PADDING, 0, 32);
    }

    private function xorKey(string $key, int $value): string
    {
        $out = '';
        for ($i = 0; $i < strlen($key); $i++) $out .= chr(ord($key[$i]) ^ $value);
        return $out;
    }

    private function rc4(string $data, string $key): string
    {
        $state = range(0, 255);
        $j = 0;
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $state[$i] + ord($key[$i % strlen($key)])) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
        }
        $out = '';
        $i = $j = 0;
        for ($n = 0; $n < strlen($data); $n++) {
            $i = ($i + 1) & 255;
            $j = ($j + $state[$i]) & 255;
            [$state[$i], $state[$j]] = [$state[$j], $state[$i]];
            $out .= chr(ord($data[$n]) ^ $state[($state[$i] + $state[$j]) & 255]);
        }
        return $out;
    }
}
