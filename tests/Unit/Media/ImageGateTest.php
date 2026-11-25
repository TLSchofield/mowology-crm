<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Media/Services/ImageGate.php';

class ImageGateTest extends TestCase
{
    public function test_assess_reports_each_problem_and_accepts_a_good_image(): void
    {
        $cases = [
            'heavy' => ['path' => 'photo.jpg', 'bytes' => 409601, 'width' => 1200, 'height' => 800, 'alt' => 'A photo', 'above_fold' => false],
            'too_wide' => ['path' => 'photo.jpg', 'bytes' => 100, 'width' => 1921, 'height' => 800, 'alt' => 'A photo', 'above_fold' => false],
            'no_dimensions' => ['path' => 'photo.jpg', 'bytes' => 100, 'width' => 0, 'height' => 800, 'alt' => 'A photo', 'above_fold' => false],
            'no_alt' => ['path' => 'photo.jpg', 'bytes' => 100, 'width' => 1200, 'height' => 800, 'alt' => ' ', 'above_fold' => false],
            'not_web_format' => ['path' => 'photo.tiff', 'bytes' => 100, 'width' => 1200, 'height' => 800, 'alt' => 'A photo', 'above_fold' => false],
        ];
        foreach ($cases as $expected => $image) {
            $this->assertContains($expected, array_column(ImageGate::assess($image), 'code'));
        }
        $this->assertSame([], ImageGate::assess([
            'path' => 'photo.webp', 'bytes' => 409600, 'width' => 1920, 'height' => 1080,
            'alt' => 'A maintained property', 'above_fold' => true,
        ]));
    }

    /** @dataProvider widthProvider */
    public function test_web_widths_never_upscale_and_are_never_empty(int $original, array $expected): void
    {
        $this->assertSame($expected, ImageGate::webWidthsFor($original));
    }

    public static function widthProvider(): array
    {
        return [
            [4032, [320, 480, 640, 768, 1024, 1280, 1600, 1920]],
            [1000, [320, 480, 640, 768]],
            [300, [300]],
            [100, [100]],
        ];
    }

    public function test_variant_base_uses_uuid_or_media_id_without_collisions(): void
    {
        $this->assertSame('m41', mediaVariantBase('', 41));
        $this->assertSame('m42', mediaVariantBase('   ', 42));
        $this->assertSame('real-uuid', mediaVariantBase('real-uuid', 43));
        $this->assertNotSame(mediaVariantBase('', 41), mediaVariantBase('', 42));
    }

    public function test_ensure_web_copy_refuses_oversize_original_when_generation_fails(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT, file_size INT, image_width INT, image_height INT)');
        $db->exec('CREATE TABLE media_variants (media_id INT, variant_type TEXT, format TEXT, width INT, file_path TEXT)');
        $db->exec("INSERT INTO media_assets VALUES (7, '/uploads/original.jpg', 6291456, 4032, 3024)");

        $result = (new ImageGate($db))->ensureWebCopy(7, '/missing/synthetic-original.jpg');
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('path', $result);
    }

    public function test_a_variant_over_the_weight_limit_is_skipped_for_a_lighter_one(): void
    {
        if (!defined('PUBLIC_ROOT')) $this->markTestSkipped('PUBLIC_ROOT not defined');
        $dir = PUBLIC_ROOT . '/_media/variants/_imagegate_test';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/heavy_1280w.jpg', str_repeat('x', 500000));
        file_put_contents($dir . '/light_1024w.jpg', str_repeat('x', 1000));
        try {
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT)');
            $db->exec('CREATE TABLE media_variants (media_id INT, variant_type TEXT, format TEXT, width INT, file_path TEXT)');
            $db->exec("INSERT INTO media_variants VALUES (9,'responsive','jpeg',1280,'/_media/variants/_imagegate_test/heavy_1280w.jpg'),(9,'responsive','jpeg',1024,'/_media/variants/_imagegate_test/light_1024w.jpg')");
            $r = (new ImageGate($db))->ensureWebCopy(9, '/missing.jpg');
            $this->assertTrue($r['ok']);
            $this->assertSame('/_media/variants/_imagegate_test/light_1024w.jpg', $r['path']);
        } finally {
            @unlink($dir . '/heavy_1280w.jpg'); @unlink($dir . '/light_1024w.jpg'); @rmdir($dir);
        }
    }
}
