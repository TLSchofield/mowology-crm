<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Expenses/Services/ReceiptImageLinks.php';

/** Receipt links: 15-minute TTL and a derived sub-key, never the raw JWT secret (Fable audit 2026-10-12). */
final class ReceiptLinkKeyTest extends TestCase
{
    private const MASTER = '0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testDerivedKeyVerifiesAndRawSecretDoesNot(): void
    {
        $now = 1_800_000_000;
        $key = ReceiptImageLinks::key(self::MASTER);
        $this->assertNotSame(self::MASTER, $key);
        $exp = $now + ReceiptImageLinks::TTL;
        parse_str((string)parse_url(ReceiptImageLinks::appUrl(7, $exp, $key), PHP_URL_QUERY), $q);
        $this->assertTrue(ReceiptImageLinks::verify(7, $exp, $q['s'], $key, $now));
        $this->assertFalse(ReceiptImageLinks::verify(8, $exp, $q['s'], $key, $now));
        $this->assertFalse(ReceiptImageLinks::verify(7, $exp, $q['s'], $key, $exp + 1));       // expired
        parse_str((string)parse_url(ReceiptImageLinks::appUrl(7, $exp, self::MASTER), PHP_URL_QUERY), $raw);
        $this->assertFalse(ReceiptImageLinks::verify(7, $exp, $raw['s'], $key, $now));          // old raw-key link
    }

    public function testLongLivedLinkRefused(): void
    {
        $now = 1_800_000_000;
        $key = ReceiptImageLinks::key(self::MASTER);
        $exp = $now + 21600;
        parse_str((string)parse_url(ReceiptImageLinks::appUrl(7, $exp, $key), PHP_URL_QUERY), $q);
        $this->assertFalse(ReceiptImageLinks::verify(7, $exp, $q['s'], $key, $now));
        $this->assertSame(900, ReceiptImageLinks::TTL);
    }

    public function testCallersUseTheSubKeyAndTtl(): void
    {
        $root = __DIR__ . '/../../../';
        $img = file_get_contents($root . 'app/Modules/Expenses/Api/receipt-image.php');
        $this->assertStringContainsString('ReceiptImageLinks::verify(', $img);
        $this->assertStringContainsString('ReceiptImageLinks::key(jwtSecret())', $img);
        foreach (['app/Modules/Expenses/Api/expense-list.php', 'app/Modules/Expenses/Api/bookkeeper-mobile.php'] as $f) {
            $src = file_get_contents($root . $f);
            $this->assertStringNotContainsString('$urlExpiry = time() + 21600', $src, $f);
            $this->assertStringNotContainsString('groups($pairs), time() + 21600', $src, $f);
            $this->assertStringContainsString('ReceiptImageLinks::key(jwtSecret())', $src, $f);
        }
        $signer = file_get_contents($root . 'app/Services/Receipts/ReceiptUrlSigner.php');
        $this->assertStringContainsString("hash_hmac('sha256', 'receipt-image', jwtSecret())", $signer);
    }

    public function testQuoteNonceFallbackIsDerived(): void
    {
        $this->assertNotSame(self::MASTER, QuoteService::derivedFormSecret(self::MASTER));
        $this->assertSame(64, strlen(QuoteService::derivedFormSecret(self::MASTER)));
    }
}
