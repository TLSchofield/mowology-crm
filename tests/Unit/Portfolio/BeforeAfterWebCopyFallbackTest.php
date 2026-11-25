<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Guardian found the public portfolio page weighing 17 MB: pairs approved before web copies existed served raw phone photos. */
class BeforeAfterWebCopyFallbackTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE media_assets (id INTEGER PRIMARY KEY, file_path TEXT)");
        $db->exec("CREATE TABLE media_variants (id INTEGER PRIMARY KEY, media_id INT, variant_type TEXT, format TEXT, width INT, file_path TEXT)");
        $db->exec("CREATE TABLE ba_pairs (id INTEGER PRIMARY KEY, before_id INT, after_id INT, status TEXT, published INT, sort_order INT, approved_at TEXT, created_at TEXT,
                   label TEXT, service TEXT, category TEXT, area TEXT, alt_before TEXT, alt_after TEXT, web_before_path TEXT, web_after_path TEXT)");
        $db->exec("INSERT INTO media_assets VALUES (1,'/uploads/photos/raw_before.jpg'),(2,'/uploads/photos/raw_after.jpg'),(3,'/uploads/photos/b3.jpg'),(4,'/uploads/photos/a3.jpg')");
        // media 1 and 2 have responsive variants; 3 and 4 have none
        $db->exec("INSERT INTO media_variants (media_id,variant_type,format,width,file_path) VALUES
            (1,'responsive','jpeg',640,'/_media/variants/b_640w.jpg'),(1,'responsive','jpeg',1280,'/_media/variants/b_1280w.jpg'),(1,'responsive','jpeg',1920,'/_media/variants/b_1920w.jpg'),
            (2,'responsive','jpeg',1280,'/_media/variants/a_1280w.jpg'),(2,'responsive','webp',1280,'/_media/variants/a_1280w.webp')");
        $db->exec("INSERT INTO ba_pairs VALUES (10,1,2,'approved',1,2,'2026-10-01','2026-10-01','x','s','general','a','','',NULL,NULL),
                                               (11,3,4,'approved',1,1,'2026-10-02','2026-10-02','x','s','general','a','','',NULL,NULL)");
        return $db;
    }

    public function test_pair_without_web_copy_uses_an_existing_web_sized_variant_not_the_raw_photo(): void
    {
        $out = (new BeforeAfterService($this->db()))->published(10);
        $byId = array_column($out, null, 'id');
        $this->assertSame('/_media/variants/b_1280w.jpg', $byId[10]['before_url']);   // 1280 wins; 1920 is too big
        $this->assertSame('/_media/variants/a_1280w.jpg', $byId[10]['after_url']);    // jpeg only, never the webp
    }

    public function test_pair_with_no_variants_still_shows_the_original_rather_than_breaking(): void
    {
        $byId = array_column((new BeforeAfterService($this->db()))->published(10), null, 'id');
        $this->assertSame('/uploads/photos/b3.jpg', $byId[11]['before_url']);
    }

    public function test_a_stored_web_copy_is_never_overridden_and_nothing_is_written_on_read(): void
    {
        $db = $this->db();
        $db->exec("UPDATE ba_pairs SET web_before_path='/_media/variants/stored.jpg', web_after_path='/_media/variants/stored_a.jpg' WHERE id=10");
        $byId = array_column((new BeforeAfterService($db))->published(10), null, 'id');
        $this->assertSame('/_media/variants/stored.jpg', $byId[10]['before_url']);
        $this->assertNull($db->query("SELECT web_before_path FROM ba_pairs WHERE id=11")->fetchColumn() ?: null);
    }

    public function test_backfill_records_web_copies_for_pairs_that_can_have_them_and_is_idempotent(): void
    {
        $db = $this->db();
        $svc = new BeforeAfterService($db);
        $this->assertSame(2, $svc->backfillWebCopies());
        $this->assertSame('/_media/variants/b_1280w.jpg', $db->query("SELECT web_before_path FROM ba_pairs WHERE id=10")->fetchColumn());
        $this->assertSame('/uploads/photos/b3.jpg', $db->query("SELECT web_before_path FROM ba_pairs WHERE id=11")->fetchColumn());   // no variants and no generator here: original kept
        $this->assertSame(0, $svc->backfillWebCopies());
    }
}
