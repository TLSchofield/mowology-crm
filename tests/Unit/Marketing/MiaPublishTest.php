<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/Support/MiaDemo.php';

/**
 * Phase 2.1: applying an approved title and description to the CMS, and undoing it.
 * SQLite stand-ins for MySQL; the live page is a scripted reader, so nothing touches the network.
 */
final class MiaPublishTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $now;
    private const PAGE = 'https://mowology.ca/about';
    private const OLD_T = 'About Us | Mowology Landscaping';
    private const OLD_D = 'Learn about Mowology - Metro Vancouver landscaping company since 2010.';
    private const NEW_T = 'About Mowology | Vancouver Landscaping Since 2010';
    private const NEW_D = 'Meet the Mowology team: reliable landscaping and grounds care for Metro Vancouver homes and strata properties.';
    /** @var array<string,?string> what the pretend live page shows right now */
    private array $live;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-26 09:00:00');
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        MiaDemo::schema($this->db);
        $this->db->exec("INSERT INTO users VALUES (7)");
        $this->db->exec("CREATE TABLE cms_sites (id INTEGER PRIMARY KEY, domain TEXT)");
        $this->db->exec("INSERT INTO cms_sites VALUES (1, 'mowology.ca')");
        $this->db->exec("CREATE TABLE cms_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT DEFAULT 1, slug TEXT, title TEXT, meta_title TEXT, meta_description TEXT,
            status TEXT DEFAULT 'draft', updated_by INT, updated_at TEXT)");
        $this->db->exec("CREATE TABLE mia_executions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, action_id INT NOT NULL, draft_id INT, website_id INT NOT NULL, connector TEXT, target_ref TEXT,
            page_url TEXT, before_json TEXT, after_json TEXT, status TEXT DEFAULT 'applied', verify_note TEXT, applied_by INT, applied_at TEXT, rolled_back_by INT, rolled_back_at TEXT, error TEXT)");
        $this->db->exec("INSERT INTO mia_websites (id, site_id, base_url, host, status) VALUES (1, 1, 'https://mowology.ca', 'mowology.ca', 'verified')");
        $this->putPage('about', self::OLD_T, self::OLD_D);
        $this->live = ['title' => self::OLD_T, 'meta_desc' => self::OLD_D];
    }

    private function putPage(string $slug, ?string $t, ?string $d, string $status = 'published'): int
    {
        $this->db->prepare("INSERT INTO cms_pages (slug, title, meta_title, meta_description, status) VALUES (?, 'x', ?, ?, ?)")->execute([$slug, $t, $d, $status]);
        return (int)$this->db->lastInsertId();
    }

    private function actions(): MiaActionService { return new MiaActionService($this->db, 1); }

    private function publisher(?WebsiteConnector $c = null, ?callable $reader = null): MiaPublishService
    {
        $c = $c ?: new CmsConnector($this->db, 'mowology.ca', function (int $id) {});
        return new MiaPublishService($this->db, 1, $this->actions(), $c, $reader ?: function (string $url) { return $this->live; });
    }

    /** A proposal with its draft, optionally approved. @return int action id */
    private function proposal(string $pageUrl = self::PAGE, bool $approve = true, ?string $curT = self::OLD_T, ?string $curD = self::OLD_D): int
    {
        $r = $this->actions()->propose(['type' => 'seo_meta_update', 'side_effect' => 'none', 'opportunity_key' => 'page:' . sha1($pageUrl . microtime()), 'title' => 'Rewrite the title and description',
            'why' => 'The title is short and the description is generic.', 'risk' => 'A new title can move rankings either way.', 'evidence' => [['page' => $pageUrl]],
            'evidence_basis' => ['k' => $pageUrl . microtime()]], $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $aid = (int)$r['id'];
        $this->db->prepare("INSERT INTO mia_content_drafts (site_id, website_id, action_id, kind, page_url, current_title, current_desc, title, meta_desc, status, created_at)
                            VALUES (1, 1, ?, 'seo_meta', ?, ?, ?, ?, ?, 'draft', '2026-10-26 08:00:00')")->execute([$aid, $pageUrl, $curT, $curD, self::NEW_T, self::NEW_D]);
        $did = (int)$this->db->lastInsertId();
        $a = $this->actions()->attachDraft($aid, 'mia_content_drafts', $did, null, $this->now);
        $this->assertTrue($a['ok'], json_encode($a));
        if ($approve) {
            $d = $this->actions()->decide($aid, 7, 'approve', null, null, MiaRecommendationWriter::draftText(self::NEW_T, self::NEW_D), $this->now);
            $this->assertTrue($d['ok'], json_encode($d));
        }
        return $aid;
    }

    private function pageRow(string $slug = 'about'): array
    {
        $s = $this->db->prepare("SELECT meta_title, meta_description FROM cms_pages WHERE slug = ?");
        $s->execute([$slug]);
        return $s->fetch();
    }

    private function actionStatus(int $aid): string { return (string)$this->db->query("SELECT status FROM mia_actions WHERE id = $aid")->fetchColumn(); }

    // ---- the happy path ---------------------------------------------------------------------------------

    public function testApplyChangesThePageRecordsBeforeAndAfterAndSeesItLive(): void
    {
        $aid = $this->proposal();
        $reads = 0;
        $pub = $this->publisher(null, function () use (&$reads) {
            $reads++;
            return $reads === 1 ? $this->live : ['title' => self::NEW_T, 'meta_desc' => self::NEW_D];   // second read: the change shows
        });
        $r = $pub->apply($aid, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('verified', $r['status']);
        $this->assertSame(['meta_title' => self::NEW_T, 'meta_description' => self::NEW_D], $this->pageRow());
        $x = $this->db->query("SELECT * FROM mia_executions")->fetch();
        $this->assertSame('verified', $x['status']);
        $this->assertSame(['meta_title' => self::OLD_T, 'meta_description' => self::OLD_D], json_decode($x['before_json'], true));
        $this->assertSame('done', $this->actionStatus($aid));
        $this->assertSame('applied', $this->db->query("SELECT status FROM mia_content_drafts")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_action_events WHERE action_id = $aid AND event = 'executed'")->fetchColumn());
    }

    public function testAChangeTheLivePageDoesNotShowIsReportedNotHidden(): void
    {
        $aid = $this->proposal();
        $r = $this->publisher()->apply($aid, 7, $this->now);   // the live page keeps showing the old wording
        $this->assertTrue($r['ok']);
        $this->assertSame('unverified', $r['status']);
        $this->assertStringContainsString('still shows the old wording', $r['note']);
    }

    // ---- what must stop it -------------------------------------------------------------------------------

    public function testNothingIsAppliedUntilTheOwnerApproved(): void
    {
        $aid = $this->proposal(self::PAGE, false);
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_approved', $r['code']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_executions")->fetchColumn());
    }

    public function testAnEditAfterApprovalVoidsItAndNothingChanges(): void
    {
        $aid = $this->proposal();
        $this->db->exec("UPDATE mia_content_drafts SET title = 'Cheap lawns now' WHERE action_id = $aid");
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('draft_changed', $r['code']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
        $this->assertSame('proposed', $this->actionStatus($aid), 'reopened for a new decision');
    }

    public function testAPageBuiltFromACodeFileIsLeftForTheOwner(): void
    {
        $aid = $this->proposal('https://mowology.ca/services/strata-landscaping-maintenance');
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('manual', $r['code']);
        $this->assertSame('approved', $this->actionStatus($aid));
    }

    public function testATemplatePageIsLeftForTheOwner(): void
    {
        $this->putPage('services/kits', 'Lawn Care {{NEIGHBOURHOOD}} | {{BRAND}}', 'Care in {{CITY}}');
        $aid = $this->proposal('https://mowology.ca/services/kits', true, 'Lawn Care Kitsilano | Mowology', 'Care in Vancouver');
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertSame('manual', $r['code']);
    }

    public function testWhenTheLivePageIsNotWhatTheEditorHoldsNothingIsSaved(): void
    {
        $this->live = ['title' => 'A different title from a code file', 'meta_desc' => 'Something else'];
        $aid = $this->proposal();
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_live_source', $r['code']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_executions")->fetchColumn());
    }

    public function testAPageEditedSinceMiaReadItIsNotOverwritten(): void
    {
        $aid = $this->proposal();
        $this->db->exec("UPDATE cms_pages SET meta_title = 'Edited by the owner' WHERE slug = 'about'");
        $r = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('page_changed', $r['code']);
        $this->assertSame('Edited by the owner', $this->pageRow()['meta_title']);
    }

    public function testAnEditBetweenCheckAndWriteLosesTheRaceSafely(): void
    {
        $aid = $this->proposal();
        $db = $this->db;
        $racy = new class($db, 'mowology.ca') extends CmsConnector {
            private $pdo;
            public function __construct(PDO $db, string $h) { parent::__construct($db, $h, function (int $id) {}); $this->pdo = $db; }
            public function applySeoMeta(string $ref, array $expected, array $new, int $userId): array
            {
                $this->pdo->exec("UPDATE cms_pages SET meta_description = 'Changed a moment ago' WHERE id = " . (int)$ref);
                return parent::applySeoMeta($ref, $expected, $new, $userId);
            }
        };
        $r = $this->publisher($racy)->apply($aid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('conflict', $r['code']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title'], 'the new title was not written');
        $this->assertSame('approved', $this->actionStatus($aid));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_executions")->fetchColumn(), 'nothing half-recorded');
    }

    public function testAnUnreadableLivePageStopsTheChange(): void
    {
        $aid = $this->proposal();
        $r = $this->publisher(null, function () { return null; })->apply($aid, 7, $this->now);
        $this->assertSame('live_unreadable', $r['code']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
    }

    public function testItCannotBeAppliedTwice(): void
    {
        $aid = $this->proposal();
        $this->assertTrue($this->publisher()->apply($aid, 7, $this->now)['ok']);
        $again = $this->publisher()->apply($aid, 7, $this->now);
        $this->assertFalse($again['ok']);
        $this->assertSame('not_approved', $again['code']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_executions")->fetchColumn());
    }

    // ---- undo ----------------------------------------------------------------------------------------

    public function testRollbackPutsBackExactlyWhatWasThere(): void
    {
        $aid = $this->proposal();
        $pub = $this->publisher();
        $pub->apply($aid, 7, $this->now);
        $xid = (int)$this->db->query("SELECT id FROM mia_executions")->fetchColumn();
        $r = $pub->rollback($xid, 7, $this->now);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(['meta_title' => self::OLD_T, 'meta_description' => self::OLD_D], $this->pageRow());
        $this->assertSame('rolled_back', $this->db->query("SELECT status FROM mia_executions")->fetchColumn());
        $this->assertSame('rolled_back', $this->actionStatus($aid));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_action_events WHERE action_id = $aid AND event = 'rolled_back'")->fetchColumn());
    }

    public function testRollbackRefusesIfThePageWasEditedAfterMiasChange(): void
    {
        $aid = $this->proposal();
        $pub = $this->publisher();
        $pub->apply($aid, 7, $this->now);
        $this->db->exec("UPDATE cms_pages SET meta_title = 'Owner rewrote it again' WHERE slug = 'about'");
        $xid = (int)$this->db->query("SELECT id FROM mia_executions")->fetchColumn();
        $r = $pub->rollback($xid, 7, $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('conflict', $r['code']);
        $this->assertSame('Owner rewrote it again', $this->pageRow()['meta_title']);
        $this->assertSame('done', $this->actionStatus($aid));
    }

    public function testRollbackCannotBeRepeated(): void
    {
        $aid = $this->proposal();
        $pub = $this->publisher();
        $pub->apply($aid, 7, $this->now);
        $xid = (int)$this->db->query("SELECT id FROM mia_executions")->fetchColumn();
        $this->assertTrue($pub->rollback($xid, 7, $this->now)['ok']);
        $this->assertSame('not_undoable', $pub->rollback($xid, 7, $this->now)['code']);
    }

    // ---- the connector on its own ------------------------------------------------------------------------

    public function testSlugsAndHostsAreMatchedStrictly(): void
    {
        $this->assertSame('home', CmsConnector::slugFor('https://mowology.ca/'));
        $this->assertSame('services/strata', CmsConnector::slugFor('https://mowology.ca/services/strata/'));
        $this->assertSame('about', CmsConnector::slugFor('https://mowology.ca/about.php'));
        $this->assertNull(CmsConnector::slugFor('https://mowology.ca/a b'));
        $c = new CmsConnector($this->db, 'www.mowology.ca');
        $this->assertTrue($c->locate('https://mowology.ca/about')['ok']);
        $this->assertTrue($c->locate('https://www.mowology.ca/about')['ok']);
        $this->assertFalse($c->locate('https://evil.example/about')['ok'], 'another host is never matched');
        $this->putPage('old', 'T', 'D', 'draft');
        $this->assertFalse($c->locate('https://mowology.ca/old')['ok'], 'unpublished pages are left alone');
    }

    public function testTemplatePlaceholdersCannotBeWritten(): void
    {
        $c = new CmsConnector($this->db, 'mowology.ca');
        $r = $c->applySeoMeta('1', ['meta_title' => self::OLD_T, 'meta_description' => self::OLD_D], ['meta_title' => 'X {{BRAND}}', 'meta_description' => 'ok'], 7);
        $this->assertFalse($r['ok']);
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
    }

    public function testPreviewSaysWhatWouldChangeAndWhyNotWhenItCannot(): void
    {
        $aid = $this->proposal();
        $p = $this->publisher()->preview($aid);
        $this->assertTrue($p['ok']);
        $this->assertTrue($p['can_apply']);
        $this->assertSame(self::OLD_T, $p['before']['title']);
        $this->assertSame(self::NEW_T, $p['after']['title']);
        $manual = $this->proposal('https://mowology.ca/services/x');
        $q = $this->publisher()->preview($manual);
        $this->assertFalse($q['can_apply']);
        $this->assertNotEmpty($q['why_not']);
    }

    // ---- through the API handler ---------------------------------------------------------------------------

    private function api(): MiaApiHandler
    {
        $h = new MiaApiHandler($this->db, 7, null, null, 1);
        $reads = 0;
        $h->setPublisherFactory(function (array $draft) use (&$reads) {
            return $this->publisher(null, function () use (&$reads) {
                $reads++;
                return $reads === 1 ? $this->live : ['title' => self::NEW_T, 'meta_desc' => self::NEW_D];
            });
        });
        return $h;
    }

    public function testTheApprovedDetailOffersApplyAndTheAppliedOneOffersUndo(): void
    {
        $aid = $this->proposal();
        $h = $this->api();
        $d = $h->handle('action_detail', 'GET', ['id' => $aid], [], $this->now);
        $this->assertSame(200, $d[0]);
        $this->assertTrue($d[1]['item']['apply']['can_apply']);

        $a = $h->handle('action_apply', 'POST', [], ['id' => $aid], $this->now);
        $this->assertSame(200, $a[0], json_encode($a[1]));
        $this->assertSame('verified', $a[1]['status']);
        $this->assertSame('done', $a[1]['item']['status']);
        $xid = (int)$a[1]['item']['executions'][0]['id'];

        $u = $h->handle('action_rollback', 'POST', [], ['execution_id' => $xid], $this->now);
        $this->assertSame(200, $u[0], json_encode($u[1]));
        $this->assertSame(self::OLD_T, $this->pageRow()['meta_title']);
        $list = $h->handle('actions', 'GET', ['status' => 'done'], [], $this->now);
        $this->assertSame('rolled_back', $list[1]['items'][0]['status'], 'an undone change stays in the Applied list');
    }

    public function testApplyRefusesWhenNotApprovedOrUnknown(): void
    {
        $aid = $this->proposal(self::PAGE, false);
        $h = $this->api();
        $this->assertSame(409, $h->handle('action_apply', 'POST', [], ['id' => $aid], $this->now)[0]);
        $this->assertSame(404, $h->handle('action_apply', 'POST', [], ['id' => 9999], $this->now)[0]);
        $this->assertSame(404, $h->handle('action_rollback', 'POST', [], ['execution_id' => 9999], $this->now)[0]);
        $this->assertSame(405, $h->handle('action_apply', 'GET', [], [], $this->now)[0]);
    }

    public function testWithoutMigration1403NothingBreaksAndApplyIsRefusedPlainly(): void
    {
        $aid = $this->proposal();
        $this->db->exec("DROP TABLE mia_executions");
        $h = $this->api();
        $d = $h->handle('action_detail', 'GET', ['id' => $aid], [], $this->now);
        $this->assertSame(200, $d[0]);
        $this->assertFalse($d[1]['item']['apply']['can_apply']);
        $this->assertStringContainsString('1403', $d[1]['item']['apply']['why_not']);
        $this->assertSame(503, $h->handle('action_apply', 'POST', [], ['id' => $aid], $this->now)[0]);
    }
}
