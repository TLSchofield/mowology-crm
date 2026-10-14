<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Receipt list thumbnails: small, cached, never larger than the photo (2026-10-14). */
class ReceiptThumbTest extends TestCase
{
    public function test_fit_and_support(): void
    {
        $this->assertSame([240, 320], ReceiptThumb::fit(3024, 4032));
        $this->assertSame([100, 50], ReceiptThumb::fit(100, 50), 'never enlarged');
        $this->assertTrue(ReceiptThumb::supports('image/jpeg'));
        $this->assertFalse(ReceiptThumb::supports('application/pdf'));
        $this->assertFalse(ReceiptThumb::supports('text/html'));
    }

    public function test_makes_once_and_reuses(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('GD not installed');
        $root = sys_get_temp_dir() . '/rt-' . bin2hex(random_bytes(4));
        mkdir($root);
        $src = $root . '/photo.jpg';
        $im = imagecreatetruecolor(2000, 3000);
        imagejpeg($im, $src, 90);
        unset($im);

        $t = ReceiptThumb::ensure($root, 7, $src, 'image/jpeg');
        $this->assertNotNull($t);
        $this->assertStringStartsWith($root . '/_media/thumbs/receipt-7-', $t);
        $this->assertSame([213, 320], array_slice(getimagesize($t), 0, 2));
        $this->assertLessThan(filesize($src), filesize($t));
        $m = filemtime($t);
        clearstatcache();
        $this->assertSame($t, ReceiptThumb::ensure($root, 7, $src, 'image/jpeg'), 'cached');
        $this->assertSame($m, filemtime($t));
        $this->assertNull(ReceiptThumb::ensure($root, 8, $root . '/missing.jpg', 'image/jpeg'));
        $this->assertNull(ReceiptThumb::ensure($root, 9, $src, 'application/pdf'));
    }
}
