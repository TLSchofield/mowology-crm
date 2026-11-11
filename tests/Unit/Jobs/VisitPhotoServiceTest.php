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

    private function historyDb(): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, service_type TEXT, title TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, completed_at TEXT, status TEXT)");
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT, thumb_path TEXT, captured_at TEXT, created_at TEXT, created_by INT)");
        $db->exec("CREATE TABLE media_links (id INTEGER PRIMARY KEY, media_id INT, context_type TEXT, context_id INT, category TEXT, linked_by INT)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT)");
        $db->exec("INSERT INTO users VALUES (1, 'Trevor James'), (2, 'Nigel Cass')");
        $db->exec("CREATE TABLE media_variants (id INTEGER PRIMARY KEY, media_id INT, variant_type TEXT, file_path TEXT)");

        $db->exec("INSERT INTO job_plans VALUES (1, 7, 'lawn_care', ''), (2, 7, 'gardening', 'Garden Tidy'), (3, 8, 'lawn_care', '')");
        // Property 7: visits 10 (old), 11 (recent), 12 (today, the card being viewed), 13 (no photos).
        // Property 8: visit 20 — must never leak into property 7's history.
        $db->exec("INSERT INTO job_visits VALUES
            (10, 1, '2026-09-01', '2026-09-01 14:00:00', 'completed'),
            (11, 2, '2026-09-15', NULL, 'completed'),
            (12, 1, '2026-09-29', NULL, 'scheduled'),
            (13, 1, '2026-09-22', NULL, 'skipped'),
            (20, 3, '2026-09-20', NULL, 'completed')");
        $db->exec("INSERT INTO media_assets (id, file_path) VALUES
            (1, '/m/a.jpg'), (2, '/m/b.jpg'), (3, '/m/c.jpg'), (4, '/m/d.jpg'), (5, '/m/e.jpg'), (6, '/m/f.jpg')");
        $db->exec("INSERT INTO media_links (media_id, context_type, context_id, category) VALUES
            (1, 'job_visit', 10, 'after'),
            (2, 'job_visit', 10, 'before'),
            (3, 'job_visit', 11, 'additional'),
            (4, 'job_visit', 12, 'before'),
            (5, 'job_visit', 20, 'after'),
            (6, 'job_visit', 11, 'issue')");
        // Visit 10: both by Trevor (one via the link, one via the asset). Visit 11: nobody recorded.
        $db->exec("UPDATE media_links SET linked_by = 1 WHERE media_id = 1");
        $db->exec("UPDATE media_assets SET created_by = 1 WHERE id = 2");
        return $db;
    }

    public function testHistoryListsEarlierVisitsAtTheSamePropertyNewestFirst(): void
    {
        $history = (new VisitPhotoService($this->historyDb()))->historyForVisit(12);

        $this->assertSame([11, 10], array_column($history, 'visit_id'));
        $this->assertSame('2026-09-15', $history[0]['date']);
        $this->assertSame('Garden Tidy', $history[0]['service']);
        $this->assertSame('Lawn Care', $history[1]['service']);
    }

    public function testHistoryOrdersBeforeThenAfterAndLeavesOutIssuePhotos(): void
    {
        $history = (new VisitPhotoService($this->historyDb()))->historyForVisit(12);

        $this->assertSame(['before', 'after'], array_column($history[1]['photos'], 'photo_type'));
        // Visit 11 has an 'additional' and an 'issue' photo — only the proof one shows.
        $this->assertSame([3], array_column($history[0]['photos'], 'id'));
    }

    public function testHistoryNamesWhoTookThePhotos(): void
    {
        $history = (new VisitPhotoService($this->historyDb()))->historyForVisit(12);

        $this->assertSame(['Trevor James'], $history[1]['crew']);
        $this->assertSame('Trevor James', $history[1]['photos'][0]['taken_by']);
        // Unknown author is an empty list, never a made-up name.
        $this->assertSame([], $history[0]['crew']);
        $this->assertNull($history[0]['photos'][0]['taken_by']);
    }

    public function testHistoryRespectsTheVisitLimitAndUnknownVisits(): void
    {
        $svc = new VisitPhotoService($this->historyDb());

        $this->assertSame([11], array_column($svc->historyForVisit(12, 1), 'visit_id'));
        $this->assertSame([], $svc->historyForVisit(999));
    }
}
