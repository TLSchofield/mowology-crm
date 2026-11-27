<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Receipt photo links for the iOS app: the web card's session-only serve-receipt links
 * re-signed as the app's /api/expenses/receipt-image links (bookkeeper-mobile.php), in
 * the exact format expense-list.php has always produced and receipt-image.php checks.
 */
class ReceiptImageLinksTest extends TestCase
{
    private const SECRET = 'test-secret';
    private const EXP = 1900000000;

    public function test_app_url_matches_what_receipt_image_php_verifies(): void
    {
        $url = ReceiptImageLinks::appUrl(42, self::EXP, self::SECRET);
        $this->assertSame(
            'https://mowology.ca/api/expenses/receipt-image?m=42&e=1900000000&s=' . hash_hmac('sha256', '42.1900000000', self::SECRET),
            $url
        );
    }

    public function test_media_id_is_read_from_a_signed_or_plain_serve_receipt_link(): void
    {
        $this->assertSame(17, ReceiptImageLinks::mediaIdFrom('/crm/api/serve-receipt.php?id=17&exp=123&sig=abc'));
        $this->assertSame(17, ReceiptImageLinks::mediaIdFrom('/crm/api/serve-receipt.php?id=17'));
        $this->assertNull(ReceiptImageLinks::mediaIdFrom(null));
        $this->assertNull(ReceiptImageLinks::mediaIdFrom(''));
        $this->assertNull(ReceiptImageLinks::mediaIdFrom('/crm/api/serve-receipt.php'));
        $this->assertNull(ReceiptImageLinks::mediaIdFrom('/crm/api/serve-receipt.php?id=0'));
    }

    public function test_resign_desk_swaps_every_photo_link_and_nothing_else(): void
    {
        $queue = [
            ['suggestion_id' => 1, 'image_url' => '/crm/api/serve-receipt.php?id=5&exp=1&sig=x', 'vendor' => 'Home Depot'],
            ['suggestion_id' => 2, 'image_url' => null],
        ];
        $dupes = [[
            'pairs'   => [[7, 9]],
            'members' => [
                ['id' => 7, 'receipt_media_id' => 70, 'receipt_path' => '/crm/api/serve-receipt.php?id=70'],
                ['id' => 9, 'receipt_media_id' => null],      // a twin with no photo
                ['id' => 11, 'receipt_path' => '/crm/api/serve-receipt.php?id=110'],
            ],
        ]];
        [$q, $d] = ReceiptImageLinks::resignDesk($queue, $dupes, self::EXP, self::SECRET);

        $this->assertSame(ReceiptImageLinks::appUrl(5, self::EXP, self::SECRET), $q[0]['image_url']);
        $this->assertNull($q[1]['image_url']);
        $this->assertSame('Home Depot', $q[0]['vendor']);
        $this->assertSame([[7, 9]], $d[0]['pairs']);
        $this->assertSame(ReceiptImageLinks::appUrl(70, self::EXP, self::SECRET), $d[0]['members'][0]['receipt_path']);
        $this->assertNull($d[0]['members'][1]['receipt_path']);
        $this->assertSame(ReceiptImageLinks::appUrl(110, self::EXP, self::SECRET), $d[0]['members'][2]['receipt_path']);
    }

    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function test_original_business_link_is_unchanged(): void
    {
        Tenant::forTests(['key' => Tenant::DEFAULT]);
        $this->assertSame(
            'https://mowology.ca/api/expenses/receipt-image?m=42&e=1900000000&s=' . hash_hmac('sha256', '42.1900000000', self::SECRET),
            ReceiptImageLinks::appUrl(42, self::EXP, self::SECRET)
        );
        $this->assertSame(ReceiptImageLinks::APP_IMAGE_URL, 'https://mowology.ca/api/expenses/receipt-image');
    }

    public function test_other_business_link_uses_its_own_host(): void
    {
        Tenant::forTests(['key' => 'samtest', 'cfg' => ['hosts' => ['samtest.ittai.ca'], 'app_url' => 'https://samtest.ittai.ca']]);
        $this->assertStringStartsWith('https://samtest.ittai.ca/api/expenses/receipt-image?m=42&e=', ReceiptImageLinks::appUrl(42, self::EXP, self::SECRET));
        $this->assertStringStartsWith('https://samtest.ittai.ca/api/expenses/receipt-image?m=5&e=', (string)ReceiptImageLinks::resign('/crm/api/serve-receipt.php?id=5', self::EXP, self::SECRET));
    }

    public function test_cron_without_app_url_falls_back_to_the_tenants_registered_host_never_mowology(): void
    {
        Tenant::forTests(['key' => 'samtest', 'cfg' => ['hosts' => ['SamTest.ittai.ca']]]);
        $this->assertStringStartsWith('https://samtest.ittai.ca/api/', ReceiptImageLinks::appUrl(1, self::EXP, self::SECRET));
    }

    public function test_origin_rejects_anything_that_is_not_a_plain_https_origin(): void
    {
        $this->assertSame('https://x.ittai.ca', ReceiptImageLinks::originFor(false, 'https://x.ittai.ca/', ''));
        $this->assertSame('https://x.ittai.ca', ReceiptImageLinks::originFor(false, 'http://evil.com', 'https://x.ittai.ca'));
        $this->assertSame('https://mowology.ca', ReceiptImageLinks::originFor(false, 'https://evil.com/path?x', ''));
        $this->assertSame('https://mowology.ca', ReceiptImageLinks::originFor(true, 'https://x.ittai.ca', 'https://x.ittai.ca'));
    }

    public function test_link_is_not_valid_across_tenants(): void
    {
        $keyA = ReceiptImageLinks::key(hash_hmac('sha256', 'tenant:a', 'master'));
        $keyB = ReceiptImageLinks::key(hash_hmac('sha256', 'tenant:b', 'master'));
        $now = self::EXP - 100;
        parse_str((string)parse_url(ReceiptImageLinks::appUrl(7, self::EXP, $keyA, 'https://a.ittai.ca'), PHP_URL_QUERY), $q);
        $this->assertTrue(ReceiptImageLinks::verify(7, (int)$q['e'], $q['s'], $keyA, $now));
        $this->assertFalse(ReceiptImageLinks::verify(7, (int)$q['e'], $q['s'], $keyB, $now));
    }
}
