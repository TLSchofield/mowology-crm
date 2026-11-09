<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** SiteAuditRules: every rule fires on bad data and stays quiet on good data; findings keep a stable identity across runs. */
class SiteAuditRulesTest extends TestCase
{
    private const BASE = 'https://mowology.ca';
    private PDO $db;
    private int $websiteId = 1;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, status TEXT DEFAULT 'verified')");
        $db->exec("CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, url_hash TEXT, url TEXT,
            status_code INT, content_hash TEXT, title TEXT, meta_desc TEXT, h1 TEXT, canonical TEXT, robots_meta TEXT, word_count INT, schema_types TEXT,
            h1_count INT, images_total INT, images_no_alt INT, has_viewport INT, lang TEXT, last_seen_at TEXT, last_changed_at TEXT)");
        $db->exec("CREATE TABLE mia_site_links (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, from_hash TEXT, to_hash TEXT)");
        $db->exec("CREATE TABLE mia_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, kind TEXT, status TEXT,
            pages INT DEFAULT 0, findings INT DEFAULT 0, started_at TEXT, finished_at TEXT, summary TEXT)");
        $db->exec("CREATE TABLE mia_audit_findings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, audit_id INT, page_id INT,
            rule_key TEXT, severity TEXT, evidence TEXT, fingerprint TEXT, first_seen_audit_id INT, resolved_at TEXT, created_at TEXT)");
        $db->exec("INSERT INTO mia_websites (site_id, base_url, host) VALUES (1, '" . self::BASE . "', 'mowology.ca')");
        $this->db = $db;
    }

    /** A page row that breaks no rule. */
    private function good(string $path, array $over = []): array
    {
        $url = self::BASE . $path;
        return array_merge([
            'id' => abs(crc32($path)), 'url_hash' => sha1($url), 'url' => $url, 'status_code' => 200, 'content_hash' => sha1('c' . $path),
            'title' => 'Strata Landscaping Maintenance in Vancouver | Mowology' . ($path === '/' ? '' : " $path"),
            'meta_desc' => 'Reliable landscaping and grounds maintenance for Vancouver, Burnaby and Richmond strata corporations. Request a quote today. ' . $path,
            'h1' => 'Heading', 'canonical' => $url, 'robots_meta' => null, 'word_count' => 400,
            'schema_types' => 'LocalBusiness', 'h1_count' => 1, 'images_total' => 3, 'images_no_alt' => 0, 'has_viewport' => 1, 'lang' => 'en',
            'last_changed_at' => '2026-10-01 00:00:00',
        ], $over);
    }

    /** @return array<string,array> rule_key => findings */
    private function rules(array $pages, array $edges = [], array $ctx = []): array
    {
        $by = [];
        foreach (SiteAuditRules::evaluate($pages, $edges, array_merge(['base_url' => self::BASE, 'now' => new DateTimeImmutable('2026-10-25')], $ctx)) as $f) {
            $by[$f['rule_key']][] = $f;
        }
        return $by;
    }

    private function linked(array $pages): array
    {
        $home = sha1(self::BASE . '/');
        $edges = [];
        foreach ($pages as $p) { if ($p['url_hash'] !== $home) { $edges[] = [$home, $p['url_hash']]; } }
        return $edges;
    }

    public function testACleanSiteHasNoFindings(): void
    {
        $pages = [$this->good('/'), $this->good('/services'), $this->good('/about')];
        $this->assertSame([], $this->rules($pages, $this->linked($pages)));
    }

    public function testEveryRuleInTheCatalogueHasASeverityAndText(): void
    {
        foreach (SiteAuditRules::RULES as $key => $r) {
            $this->assertArrayHasKey($r[0], SiteAuditRules::SEVERITY_RANK, $key);
            $this->assertNotSame('', $r[1], $key);
            $this->assertNotSame('', $r[2], $key);
            $this->assertStringNotContainsStringIgnoringCase('guarantee', $r[2], $key);
        }
    }

    /** @dataProvider singlePageCases */
    public function testPageRulesFire(array $over, string $rule, string $needle = ''): void
    {
        $pages = [$this->good('/'), $this->good('/x', $over)];
        $r = $this->rules($pages, $this->linked($pages));
        $this->assertArrayHasKey($rule, $r, $rule);
        $this->assertSame(self::BASE . '/x', $r[$rule][0]['url']);
        $this->assertSame(SiteAuditRules::RULES[$rule][0], $r[$rule][0]['severity']);
        if ($needle !== '') { $this->assertStringContainsString($needle, $r[$rule][0]['evidence']); }
    }

    public function singlePageCases(): array
    {
        return [
            'title missing'      => [['title' => null], 'title_missing'],
            'title blank'        => [['title' => '   '], 'title_missing'],
            'title short'        => [['title' => 'Home'], 'title_short', '4 characters'],
            'title long'         => [['title' => str_repeat('word ', 16)], 'title_long', '80 characters'],
            'meta missing'       => [['meta_desc' => null], 'meta_missing'],
            'meta short'         => [['meta_desc' => 'Too short.'], 'meta_short'],
            'meta long'          => [['meta_desc' => str_repeat('a', 200)], 'meta_long', '200 characters'],
            'h1 missing'         => [['h1' => null, 'h1_count' => 0], 'h1_missing'],
            'h1 multiple'        => [['h1_count' => 3], 'h1_multiple', '3 main headings'],
            'canonical missing'  => [['canonical' => null], 'canonical_missing'],
            'canonical offsite'  => [['canonical' => 'https://competitor.example/x'], 'canonical_offsite', 'competitor.example'],
            'canonical elsewhere'=> [['canonical' => self::BASE . '/y'], 'canonical_elsewhere'],
            'thin content'       => [['word_count' => 40], 'thin_content', '40 words'],
            'images most'        => [['images_total' => 4, 'images_no_alt' => 3], 'images_missing_alt', '3 of 4'],
            'viewport missing'   => [['has_viewport' => 0], 'viewport_missing'],
            'lang missing'       => [['lang' => null], 'lang_missing'],
        ];
    }

    public function testNoindexPagesAreExemptFromPageLevelRules(): void
    {
        $pages = [$this->good('/'), $this->good('/private', ['robots_meta' => 'noindex,follow', 'title' => null, 'meta_desc' => null, 'word_count' => 3])];
        $this->assertSame([], $this->rules($pages, $this->linked($pages)));
    }

    public function testNoindexOnTheHomePageIsHigh(): void
    {
        $pages = [$this->good('/', ['robots_meta' => 'noindex'])];
        $r = $this->rules($pages);
        $this->assertArrayHasKey('noindex_home', $r);
        $this->assertSame('high', $r['noindex_home'][0]['severity']);
    }

    public function testHomeSchemaRule(): void
    {
        $this->assertArrayNotHasKey('schema_missing_home', $this->rules([$this->good('/', ['schema_types' => 'LandscapingBusiness,WebSite'])]));
        $this->assertArrayNotHasKey('schema_missing_home', $this->rules([$this->good('/', ['schema_types' => 'Organization'])]));
        $r = $this->rules([$this->good('/', ['schema_types' => 'WebSite,BreadcrumbList'])]);
        $this->assertStringContainsString('no business or organization type', $r['schema_missing_home'][0]['evidence']);
        $r = $this->rules([$this->good('/', ['schema_types' => null])]);
        $this->assertStringContainsString('No structured data', $r['schema_missing_home'][0]['evidence']);
        $pages = [$this->good('/'), $this->good('/inner', ['schema_types' => null])];
        $this->assertArrayNotHasKey('schema_missing_home', $this->rules($pages, $this->linked($pages)), 'inner pages are not required to have it');
    }

    public function testImagesWithSomeMissingAltAreAGentlerClass(): void
    {
        $a = $this->rules([$this->good('/', ['images_total' => 10, 'images_no_alt' => 1])])['images_missing_alt'][0]['fingerprint'];
        $b = $this->rules([$this->good('/', ['images_total' => 10, 'images_no_alt' => 9])])['images_missing_alt'][0]['fingerprint'];
        $this->assertNotSame($a, $b);
        $this->assertArrayNotHasKey('images_missing_alt', $this->rules([$this->good('/', ['images_total' => 5, 'images_no_alt' => 0])]));
    }

    public function testUnextractedFieldsAreNotFlagged(): void
    {
        // A 304'd or older row may have NULLs for the newer columns: no viewport/lang/alt findings from missing data.
        $p = $this->good('/', ['has_viewport' => null, 'lang' => null, 'h1_count' => null, 'images_total' => null, 'images_no_alt' => null, 'word_count' => null]);
        $r = $this->rules([$p]);
        foreach (['viewport_missing', 'lang_missing', 'images_missing_alt', 'thin_content'] as $k) { $this->assertArrayNotHasKey($k, $r, $k); }
    }

    public function testNotHttps(): void
    {
        $r = $this->rules([$this->good('/')], [], ['base_url' => 'http://mowology.ca']);
        $this->assertArrayHasKey('not_https', $r);
        $this->assertArrayNotHasKey('not_https', $this->rules([$this->good('/')]));
    }

    // ---- duplicates ----

    public function testDuplicateTitlesDescriptionsAndContent(): void
    {
        $same = ['title' => 'Same Title For Two Pages Here', 'meta_desc' => str_repeat('d', 100), 'content_hash' => sha1('same')];
        $pages = [$this->good('/'), $this->good('/a', $same), $this->good('/b', $same), $this->good('/c')];
        $r = $this->rules($pages, $this->linked($pages));
        foreach (['title_duplicate', 'meta_duplicate', 'duplicate_content'] as $k) {
            $this->assertCount(2, $r[$k], $k);
            $this->assertEqualsCanonicalizing([self::BASE . '/a', self::BASE . '/b'], array_column($r[$k], 'url'), $k);
        }
        $this->assertStringContainsString('1 other page', $r['title_duplicate'][0]['evidence']);
    }

    public function testDuplicatesAreCaseInsensitiveAndIgnoreBrokenAndNoindexPages(): void
    {
        $pages = [$this->good('/'), $this->good('/a', ['title' => 'Some Title Of Decent Length']),
                  $this->good('/b', ['title' => 'SOME TITLE OF DECENT LENGTH']),
                  $this->good('/c', ['title' => 'Some Title Of Decent Length', 'status_code' => 404]),
                  $this->good('/d', ['title' => 'Some Title Of Decent Length', 'robots_meta' => 'noindex'])];
        $r = $this->rules($pages, $this->linked($pages));
        $this->assertCount(2, $r['title_duplicate']);
    }

    // ---- links ----

    public function testBrokenPageReportsWhoLinksToIt(): void
    {
        $broken = $this->good('/gone', ['status_code' => 404, 'title' => null]);
        $pages = [$this->good('/'), $this->good('/services'), $broken];
        $edges = $this->linked([$this->good('/services')]);
        $edges[] = [sha1(self::BASE . '/'), $broken['url_hash']];
        $edges[] = [sha1(self::BASE . '/services'), $broken['url_hash']];
        $r = $this->rules($pages, $edges);
        $this->assertSame(['broken_page'], array_keys(array_diff_key($r, ['orphan_page' => 1])));
        $this->assertSame('high', $r['broken_page'][0]['severity']);
        $this->assertStringContainsString('Linked from 2 page(s)', $r['broken_page'][0]['evidence']);
        $this->assertStringContainsString(self::BASE . '/services', $r['broken_page'][0]['evidence']);
    }

    public function testBrokenPageNotLinkedFromAnywhereSaysSo(): void
    {
        $r = $this->rules([$this->good('/'), $this->good('/gone', ['status_code' => 410])]);
        $this->assertStringContainsString('sitemap or seen on an earlier visit', $r['broken_page'][0]['evidence']);
    }

    public function testServerErrorsAndLinksToRedirects(): void
    {
        $err = $this->good('/boom', ['status_code' => 503]);
        $red = $this->good('/old', ['status_code' => 301]);
        $pages = [$this->good('/'), $err, $red];
        $edges = [[sha1(self::BASE . '/'), $err['url_hash']], [sha1(self::BASE . '/'), $red['url_hash']]];
        $r = $this->rules($pages, $edges);
        $this->assertSame('high', $r['server_error'][0]['severity']);
        $this->assertSame(self::BASE . '/old', $r['link_to_redirect'][0]['url']);
        $unlinked = $this->rules([$this->good('/'), $this->good('/old', ['status_code' => 301])]);
        $this->assertArrayNotHasKey('link_to_redirect', $unlinked, 'an unlinked redirect is harmless');
    }

    public function testOrphanPagesExcludeTheHomePageAndSelfLinks(): void
    {
        $a = $this->good('/a');
        $pages = [$this->good('/'), $a, $this->good('/b')];
        $edges = [[sha1(self::BASE . '/'), $a['url_hash']], [$a['url_hash'], $a['url_hash']]];
        $r = $this->rules($pages, $edges);
        $this->assertSame([self::BASE . '/b'], array_column($r['orphan_page'], 'url'));
    }

    public function testLinksFromUncrawledPagesDoNotCount(): void
    {
        $pages = [$this->good('/'), $this->good('/a')];
        $r = $this->rules($pages, [[sha1('https://mowology.ca/not-in-inventory'), sha1(self::BASE . '/a')]]);
        $this->assertArrayHasKey('orphan_page', $r);
    }

    public function testCanonicalPointingAtABrokenPage(): void
    {
        $gone = $this->good('/gone', ['status_code' => 404]);
        $pages = [$this->good('/'), $this->good('/x', ['canonical' => self::BASE . '/gone']), $gone];
        $r = $this->rules($pages, $this->linked($pages));
        $this->assertSame('high', $r['canonical_to_broken'][0]['severity']);
        $this->assertStringContainsString('404', $r['canonical_to_broken'][0]['evidence']);
        $this->assertArrayNotHasKey('canonical_elsewhere', $r);
    }

    public function testCanonicalTrailingSlashAndRelativeFormsMatchTheirOwnPage(): void
    {
        $pages = [$this->good('/'), $this->good('/a/', ['canonical' => self::BASE . '/a']), $this->good('/b', ['canonical' => '/b']), $this->good('/c', ['canonical' => self::BASE . '/c?utm=1'])];
        $r = $this->rules($pages, $this->linked($pages));
        $this->assertArrayNotHasKey('canonical_elsewhere', $r);
        $this->assertArrayNotHasKey('canonical_offsite', $r);
    }

    public function testSiteStale(): void
    {
        $old = '2024-01-01 00:00:00';
        $r = $this->rules([$this->good('/', ['last_changed_at' => $old]), $this->good('/a', ['last_changed_at' => $old])], [[sha1(self::BASE . '/'), sha1(self::BASE . '/a')]]);
        $this->assertArrayHasKey('site_stale', $r);
        $this->assertSame('info', $r['site_stale'][0]['severity']);
        $this->assertArrayNotHasKey('site_stale', $this->rules([$this->good('/', ['last_changed_at' => $old]), $this->good('/a')], [[sha1(self::BASE . '/'), sha1(self::BASE . '/a')]]));
    }

    public function testFingerprintsAreStableAcrossSmallChangesAndUniquePerFinding(): void
    {
        $a = $this->rules([$this->good('/', ['title' => str_repeat('x', 70)])])['title_long'][0]['fingerprint'];
        $b = $this->rules([$this->good('/', ['title' => str_repeat('y', 90)])])['title_long'][0]['fingerprint'];
        $this->assertSame($a, $b, 'a longer title is the same finding');
        $pages = [$this->good('/'), $this->good('/a', ['title' => null, 'meta_desc' => null])];
        $all = SiteAuditRules::evaluate($pages, $this->linked($pages), ['base_url' => self::BASE, 'now' => new DateTimeImmutable('2026-10-25')]);
        $fps = array_column($all, 'fingerprint');
        $this->assertSame(count($fps), count(array_unique($fps)));
    }

    public function testEvidenceIsCappedAndHostileTitlesStayPlainData(): void
    {
        $r = $this->rules([$this->good('/', ['title' => '<script>alert(1)</script>' . str_repeat('t', 3000)])]);
        $this->assertLessThanOrEqual(500, mb_strlen($r['title_long'][0]['evidence']));
    }

    // ---- persistence and lifecycle ----

    private function insertPage(array $p): void
    {
        $this->db->prepare("INSERT INTO mia_site_pages (site_id, website_id, url_hash, url, status_code, content_hash, title, meta_desc, h1, canonical, robots_meta, word_count,
            schema_types, h1_count, images_total, images_no_alt, has_viewport, lang, last_seen_at, last_changed_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$this->websiteId, $p['url_hash'], $p['url'], $p['status_code'], $p['content_hash'], $p['title'], $p['meta_desc'], $p['h1'], $p['canonical'],
                       $p['robots_meta'], $p['word_count'], $p['schema_types'], $p['h1_count'], $p['images_total'], $p['images_no_alt'], $p['has_viewport'], $p['lang'],
                       '2026-10-24 00:00:00', $p['last_changed_at']]);
    }

    private function svc(int $site = 1): SiteAuditRules
    {
        return new SiteAuditRules($this->db, $site);
    }

    private function now(string $d = '2026-10-25 10:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($d);
    }

    public function testRunStoresFindingsThenResolvesFixedOnesAndKeepsFirstSeen(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/a', ['title' => null]));
        $this->db->exec("INSERT INTO mia_site_links (site_id, website_id, from_hash, to_hash) VALUES (1, 1, '" . sha1(self::BASE . '/') . "', '" . sha1(self::BASE . '/a') . "')");

        $r1 = $this->svc()->run($this->websiteId, $this->now());
        $this->assertTrue($r1['ok'], (string)$r1['error']);
        $this->assertSame(1, $r1['total']);
        $this->assertSame(1, $r1['new']);
        $this->assertSame(1, $r1['by_severity']['high']);
        $row = $this->db->query("SELECT * FROM mia_audit_findings")->fetch();
        $this->assertSame('title_missing', $row['rule_key']);
        $this->assertSame($r1['audit_id'], (int)$row['first_seen_audit_id']);
        $this->assertNull($row['resolved_at']);
        $this->assertNotNull($row['page_id']);

        $r2 = $this->svc()->run($this->websiteId, $this->now('2026-10-26 10:00:00'));
        $this->assertSame(0, $r2['new']);
        $this->assertSame(1, $r2['still_open']);
        $row = $this->db->query("SELECT * FROM mia_audit_findings")->fetch();
        $this->assertSame($r1['audit_id'], (int)$row['first_seen_audit_id'], 'first seen stays');
        $this->assertSame($r2['audit_id'], (int)$row['audit_id'], 'last seen moves');
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_audit_findings")->fetchColumn(), 'no duplicate row');

        $this->db->exec("UPDATE mia_site_pages SET title = 'A Perfectly Good Page Title Here' WHERE url LIKE '%/a'");
        $r3 = $this->svc()->run($this->websiteId, $this->now('2026-10-27 10:00:00'));
        $this->assertSame(1, $r3['resolved']);
        $this->assertSame(0, $r3['total']);
        $row = $this->db->query("SELECT * FROM mia_audit_findings")->fetch();
        $this->assertSame('2026-10-27 10:00:00', $row['resolved_at']);

        $this->db->exec("UPDATE mia_site_pages SET title = NULL WHERE url LIKE '%/a'");
        $r4 = $this->svc()->run($this->websiteId, $this->now('2026-10-28 10:00:00'));
        $this->assertSame(1, $r4['new'], 'a regression after a fix is a new finding');
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM mia_audit_findings")->fetchColumn());
    }

    public function testRunWritesAnAuditRowWithSummary(): void
    {
        $this->insertPage($this->good('/'));
        $r = $this->svc()->run($this->websiteId, $this->now());
        $a = $this->db->query("SELECT * FROM mia_audits WHERE id = " . $r['audit_id'])->fetch();
        $this->assertSame('rules', $a['kind']);
        $this->assertSame('done', $a['status']);
        $this->assertSame(1, (int)$a['pages']);
        $this->assertStringContainsString('1 pages checked', $a['summary']);
    }

    public function testPagesNotSeenForNinetyDaysAreIgnored(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/old', ['title' => null]));
        $this->db->exec("UPDATE mia_site_pages SET last_seen_at = '2026-01-01 00:00:00' WHERE url LIKE '%/old'");
        $this->assertSame(0, $this->svc()->run($this->websiteId, $this->now())['total']);
    }

    public function testOtherSitesCannotRunOrSeeThisWebsite(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/a', ['title' => null]));
        $this->assertSame('not_found', $this->svc(2)->run($this->websiteId, $this->now())['code']);
        $this->svc(1)->run($this->websiteId, $this->now());
        $this->assertSame([], $this->svc(2)->openFindings($this->websiteId));
        $this->assertNotEmpty($this->svc(1)->openFindings($this->websiteId));
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_audit_findings WHERE site_id <> 1")->fetchColumn());
    }

    public function testDisconnectedOrMissingWebsite(): void
    {
        $this->assertSame('not_found', $this->svc()->run(999, $this->now())['code']);
        $this->db->exec("UPDATE mia_websites SET status = 'disconnected'");
        $this->assertSame('not_found', $this->svc()->run($this->websiteId, $this->now())['code']);
    }

    public function testOpenFindingsAreWorstFirstAndFilterable(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/a', ['meta_desc' => 'short', 'title' => null, 'word_count' => 20]));
        $this->db->exec("INSERT INTO mia_site_links (site_id, website_id, from_hash, to_hash) VALUES (1, 1, '" . sha1(self::BASE . '/') . "', '" . sha1(self::BASE . '/a') . "')");
        $this->svc()->run($this->websiteId, $this->now());
        $all = $this->svc()->openFindings($this->websiteId);
        $ranks = array_map(function ($f) { return SiteAuditRules::SEVERITY_RANK[$f['severity']]; }, $all);
        $sorted = $ranks; sort($sorted);
        $this->assertSame($sorted, $ranks);
        $this->assertSame('high', $all[0]['severity']);
        $this->assertSame(self::BASE . '/a', $all[0]['url']);
        $high = $this->svc()->openFindings($this->websiteId, 'high');
        $this->assertSame(['high'], array_unique(array_column($high, 'severity')));
    }

    public function testDatabaseFailureReturnsAnErrorAndLeavesNothingHalfWritten(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/a', ['title' => null]));
        $this->db->exec("DROP TABLE mia_audit_findings");
        $r = $this->svc()->run($this->websiteId, $this->now());
        $this->assertFalse($r['ok']);
        $this->assertSame('error', $r['code']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_audits")->fetchColumn(), 'the audit row rolled back too');
    }

    public function testSiteZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteAuditRules($this->db, 0);
    }

    // ---- Codex round 1 regressions ----

    public function testRobotsMetaValuesWithColonsAreNotNoindex(): void
    {
        $r = $this->rules([$this->good('/', ['robots_meta' => 'index,follow,max-snippet:none,max-image-preview:large'])]);
        $this->assertArrayNotHasKey('noindex_home', $r);
        $this->assertSame([], $r);
        foreach (['noindex', 'NOINDEX, nofollow', 'none', 'noindex nofollow'] as $v) {
            $this->assertArrayHasKey('noindex_home', $this->rules([$this->good('/', ['robots_meta' => $v])]), $v);
        }
    }

    public function testHomePageIsRecognisedWithOrWithoutTrailingSlash(): void
    {
        $noSlash = $this->good('/', ['url' => self::BASE, 'url_hash' => sha1(self::BASE), 'canonical' => self::BASE, 'schema_types' => null]);
        $r = $this->rules([$noSlash]);
        $this->assertArrayHasKey('schema_missing_home', $r);
        $this->assertArrayNotHasKey('orphan_page', $r);
        $r = $this->rules([array_merge($noSlash, ['robots_meta' => 'noindex'])], [], ['base_url' => self::BASE . '/']);
        $this->assertArrayHasKey('noindex_home', $r);
    }

    /** @dataProvider healthyCanonicals */
    public function testHealthyCanonicalFormsAreQuiet(string $path, string $canonical): void
    {
        $pages = [$this->good('/'), $this->good($path, ['canonical' => $canonical])];
        $r = $this->rules($pages, $this->linked($pages));
        $this->assertArrayNotHasKey('canonical_elsewhere', $r, $canonical);
        $this->assertArrayNotHasKey('canonical_offsite', $r, $canonical);
    }

    public function healthyCanonicals(): array
    {
        return [
            'query only'        => ['/products', '?color=blue'],
            'fragment only'     => ['/products', '#details'],
            'protocol relative' => ['/about', '//mowology.ca/about'],
            'relative sibling'  => ['/a/b', 'b'],
            'absolute with q'   => ['/c', self::BASE . '/c?utm=1'],
            'www of same site'  => ['/d', 'https://www.mowology.ca/d'],
        ];
    }

    public function testWwwCanonicalIsNotOffsiteButADifferentSiteIs(): void
    {
        $r = $this->rules([$this->good('/', ['canonical' => 'https://www.mowology.ca/'])]);
        $this->assertArrayNotHasKey('canonical_offsite', $r);
        $r = $this->rules([$this->good('/', ['canonical' => 'https://mowology.ca.evil.example/'])]);
        $this->assertArrayHasKey('canonical_offsite', $r);
    }

    public function testTitleLengthCountsCharactersNotBytes(): void
    {
        $r = $this->rules([$this->good('/', ['title' => str_repeat('é', 40) . ' ok'])]);
        $this->assertArrayNotHasKey('title_long', $r);
    }

    public function testLangMissingDoesNotDependOnViewportData(): void
    {
        $r = $this->rules([$this->good('/', ['lang' => null, 'has_viewport' => null, 'h1_count' => 1])]);
        $this->assertArrayHasKey('lang_missing', $r);
        $r = $this->rules([$this->good('/', ['lang' => null, 'has_viewport' => null, 'h1_count' => null])]);
        $this->assertArrayNotHasKey('lang_missing', $r, 'old rows without extracted fields are not judged');
    }

    public function testOtherCollectorsFindingsAreNotResolvedByThisRuleSet(): void
    {
        $this->insertPage($this->good('/'));
        $this->db->exec("INSERT INTO mia_audit_findings (site_id, website_id, audit_id, rule_key, severity, evidence, fingerprint, first_seen_audit_id)
                         VALUES (1, 1, 1, 'pagespeed_mobile', 'medium', 'LCP 5s', 'ps-1', 1)");
        $r = $this->svc()->run($this->websiteId, $this->now());
        $this->assertSame(0, $r['resolved']);
        $this->assertNull($this->db->query("SELECT resolved_at FROM mia_audit_findings WHERE fingerprint = 'ps-1'")->fetchColumn());
    }

    public function testFailedRunInsideCallersTransactionRollsBackToSavepoint(): void
    {
        $this->insertPage($this->good('/'));
        $this->insertPage($this->good('/a', ['title' => null]));
        $this->db->exec("CREATE TRIGGER boom BEFORE INSERT ON mia_audit_findings BEGIN SELECT RAISE(ABORT, 'nope'); END");
        $this->db->beginTransaction();
        $this->db->exec("INSERT INTO mia_websites (site_id, base_url, host) VALUES (1, 'https://callers.example', 'callers.example')");
        $r = $this->svc()->run($this->websiteId, $this->now());
        $this->assertFalse($r['ok']);
        $this->db->commit();
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_audits")->fetchColumn(), 'no half-written audit');
        $this->assertSame(2, (int)$this->db->query("SELECT COUNT(*) FROM mia_websites")->fetchColumn(), 'the caller\'s own work survives');
    }
}
