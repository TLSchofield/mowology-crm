<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitPhotoServiceTest extends TestCase
{
    public function testAdditionalIsAnUploadTypeAndUnknownIsNot(): void
    {
        $this->assertTrue(VisitPhotoService::isUploadType('additional'));
        $this->assertTrue(VisitPhotoService::isUploadType('before'));
        $this->assertFalse(VisitPhotoService::isUploadType('selfie'));
    }

    public function testLegacyExtrasCollapseIntoAdditional(): void
    {
        $this->assertSame('before', VisitPhotoService::normalizeType('before'));
        $this->assertSame('after', VisitPhotoService::normalizeType('after'));
        $this->assertSame('additional', VisitPhotoService::normalizeType('during'));
        $this->assertSame('additional', VisitPhotoService::normalizeType('other'));
        $this->assertSame('additional', VisitPhotoService::normalizeType(''));
    }

    public function testIssuePhotosAreNeverProof(): void
    {
        // Recommendation photos upload as 'issue'; they must not appear in the proof strip.
        $this->assertNotContains('issue', VisitPhotoService::PROOF_TYPES);
    }

    public function testShapePrefersVariantThumbThenThumbPathThenFull(): void
    {
        $base = ['id' => '7', 'category' => 'after', 'file_path' => '/_media/original/a.jpg', 'created_at' => '2026-09-20 17:00:00'];

        $this->assertSame('/_media/v/a_t.jpg', VisitPhotoService::shape($base + ['variant_thumb' => '/_media/v/a_t.jpg', 'thumb_path' => '/t/a.webp'])['thumb_url']);
        $this->assertSame('/t/a.webp', VisitPhotoService::shape($base + ['variant_thumb' => null, 'thumb_path' => '/t/a.webp'])['thumb_url']);

        $plain = VisitPhotoService::shape($base);
        $this->assertSame('/_media/original/a.jpg', $plain['thumb_url']);
        $this->assertSame(7, $plain['id']);
        $this->assertSame('after', $plain['photo_type']);
        $this->assertSame('2026-09-20 17:00:00', $plain['taken_at']);
    }

    public function testShapeUsesCaptureTimeWhenKnown(): void
    {
        $row = ['id' => 1, 'category' => 'additional', 'file_path' => '/x.jpg', 'captured_at' => '2026-09-20 16:58:00', 'created_at' => '2026-09-20 17:30:00'];
        $this->assertSame('2026-09-20 16:58:00', VisitPhotoService::shape($row)['taken_at']);
    }
}
