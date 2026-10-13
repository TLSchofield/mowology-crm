<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Hand-made before/after pairs may only point at photos (Fable review 2026-10-12). */
class BeforeAfterImageMediaTest extends TestCase
{
    public function test_only_image_media_qualifies(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_type TEXT, mime_type TEXT)");
        $db->exec("INSERT INTO media_assets VALUES (1, 'image', 'image/jpeg'), (2, 'image', NULL),
                   (3, 'document', 'application/pdf'), (4, 'image', 'application/pdf')");
        $svc = new BeforeAfterService($db);
        $this->assertTrue($svc->isImageMedia(1));
        $this->assertTrue($svc->isImageMedia(2));
        $this->assertFalse($svc->isImageMedia(3));
        $this->assertFalse($svc->isImageMedia(4));
        $this->assertFalse($svc->isImageMedia(99));
        $this->assertFalse($svc->isImageMedia(0));
    }
}
