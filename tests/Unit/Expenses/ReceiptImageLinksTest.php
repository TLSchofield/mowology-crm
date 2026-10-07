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
}
