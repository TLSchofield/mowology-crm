<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** SiteCrawler against a fake site: robots, sitemap, rate limit, 304, change tracking, limits. No network. */
class SiteCrawlerTest extends TestCase
{
    private PDO $db;
    private const TOKEN = 'aabbccddeeff00112233445566778899';
    private const ORIGIN = 'https://mowology.ca';
    /** @var array<string,array> url => response */
    private array $site = [];
    /** @var array<int,array{url:string,headers:array}> */
    private array $requests = [];
    /** @var float[] */
    private array $sleeps = [];
    private float $clockNow = 1000.0;
    private int $websiteId = 0;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT,
            mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none', capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT,
            verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT,
            created_by INT, created_at TEXT, UNIQUE(site_id, host))");
        $db->exec("CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, url_hash TEXT,
            url TEXT, status_code INT, content_hash TEXT, title TEXT, meta_desc TEXT, h1 TEXT, canonical TEXT, robots_meta TEXT, word_count INT,
            schema_types TEXT, h1_count INT, images_total INT, images_no_alt INT, has_viewport INT, lang TEXT, links_out INT, etag TEXT, last_modified TEXT, first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, last_changed_at TEXT,
            last_crawl_at TEXT, UNIQUE(site_id, website_id, url_hash))");
        $db->exec("CREATE TABLE mia_site_links (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL,
            from_hash TEXT NOT NULL, to_hash TEXT NOT NULL, UNIQUE(site_id, website_id, from_hash, to_hash))");
        $db->exec("CREATE TABLE mia_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, kind TEXT, status TEXT,
            pages INT DEFAULT 0, findings INT DEFAULT 0, started_at TEXT, finished_at TEXT, summary TEXT)");
        $this->db = $db;
        $this->site = [];
        $this->requests = [];
        $this->sleeps = [];
        $this->clockNow = 1000.0;
        $db->exec("INSERT INTO mia_websites (site_id, base_url, host, status, verify_token, verified_via, verified_at, crawl_cap)
                   VALUES (1, '" . self::ORIGIN . "', 'mowology.ca', 'verified', '" . self::TOKEN . "', 'dns', '" . date('Y-m-d H:i:s') . "', 200)");
        $this->websiteId = (int)$db->lastInsertId();
    }

    private function html(string $title, string $body = '', string $head = ''): array
    {
        return ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8', 'etag' => '"' . md5($title . $body) . '"'],
                'body' => "<!doctype html><html><head><title>$title</title>$head</head><body><h1>$title</h1>$body</body></html>"];
    }

    private function crawler(int $site = 1): SiteCrawler
    {
        $fetcher = new SafeHttpFetcher(
            ['allowed_types' => ['text/html', 'application/xhtml+xml']],
            function (string $host) { return ['mowology.ca' => ['93.184.216.34'], 'www.mowology.ca' => ['93.184.216.34'], 'other.example' => ['8.8.8.8']][$host] ?? []; },
            function (string $url, string $host, int $port, string $ip, array $opts) {
                $this->requests[] = ['url' => $url, 'headers' => $opts['request_headers'] ?? [], 'at' => $this->clockNow];
                $r = $this->site[$url] ?? ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => 'nope'];
                $cond = $opts['request_headers'] ?? [];
                if (isset($cond['if-none-match']) && ($r['headers']['etag'] ?? null) === $cond['if-none-match'] && $r['status'] === 200) {
                    return ['status' => 304, 'headers' => [], 'body' => ''];
                }
                $ct = strtolower(trim(explode(';', $r['headers']['content-type'] ?? 'text/html')[0]));
                if (is_array($opts['allowed_types']) && !in_array($ct, $opts['allowed_types'], true)) {
                    return ['code' => 'bad_content_type', 'error' => 'x'];
                }
                return $r;
            }
        );
        $websites = new MiaWebsiteService($this->db, $site, $fetcher, function () { return []; });
        return new SiteCrawler($this->db, $site, $websites, $fetcher,
            function (float $s) { $this->sleeps[] = $s; $this->clockNow += $s; },
            function (): float { $this->clockNow += 0.05; return $this->clockNow; },
            function (): DateTimeImmutable { return new DateTimeImmutable('2026-10-25 10:00:00'); });
    }

    private function basicSite(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /admin\n"];
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/services">Services</a> <a href="/about#team">About</a> <a href="/admin/login">Admin</a>
            <a href="https://other.example/x">Away</a> <a href="mailto:a@b.ca">m</a> <a href="/logo.png">img</a> <a href="/search?q=1">q</a> <a href="/missing">gone</a>');
        $this->site[self::ORIGIN . '/services'] = $this->html('Services', '<p>Lawn care for strata.</p><a href="/">Home</a>');
        $this->site[self::ORIGIN . '/about'] = $this->html('About', '<p>About us.</p>');
    }

    // ---- gate ----

    public function testRefusesAnUnverifiedWebsiteAndFetchesNothing(): void
    {
        $this->basicSite();
        $this->db->exec("UPDATE mia_websites SET status='pending', verified_at=NULL");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertFalse($r['ok']);
        $this->assertSame('not_verified', $r['code']);
        $this->assertSame([], $this->requests);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_audits")->fetchColumn());
    }

    public function testAnotherSiteCannotCrawlThisWebsite(): void
    {
        $this->basicSite();
        $this->assertSame('not_verified', $this->crawler(2)->crawl($this->websiteId)['code']);
        $this->assertSame([], $this->requests);
    }

    // ---- discovery, robots, limits ----

    public function testCrawlsInternalLinksOnlyAndRespectsRobots(): void
    {
        $this->basicSite();
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertTrue($r['ok'], (string)$r['error']);
        $urls = array_column($this->requests, 'url');
        $this->assertContains(self::ORIGIN . '/services', $urls);
        $this->assertContains(self::ORIGIN . '/about', $urls);
        foreach ($urls as $u) {
            $this->assertStringNotContainsString('/admin', $u, 'robots.txt disallows /admin');
            $this->assertStringNotContainsString('other.example', $u);
            $this->assertStringNotContainsString('logo.png', $u);
            $this->assertStringNotContainsString('?', $u, 'query-string URLs are skipped');
        }
        $this->assertSame(1, $r['blocked_by_robots']);
        $this->assertSame(1, $r['broken'], '/missing is a 404');
        $this->assertSame(4, (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages")->fetchColumn());
        $this->assertSame('404', (string)$this->db->query("SELECT status_code FROM mia_site_pages WHERE url LIKE '%/missing'")->fetchColumn());
        $a = $this->db->query("SELECT * FROM mia_audits")->fetch();
        $this->assertSame('done', $a['status']);
        $this->assertNotNull($a['finished_at']);
        $this->assertNotNull($this->db->query("SELECT robots_hash FROM mia_websites")->fetchColumn());
    }

    public function testExtractedFieldsAreStored(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/services'] = $this->html('Services - Mowology', '<p>Lawn care for strata.</p>',
            '<meta name="description" content="Strata lawn care."><link rel="canonical" href="https://mowology.ca/services"><meta name="robots" content="index,follow">
             <script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"LocalBusiness"},{"@type":["Service","Thing"]}]}</script>');
        $this->crawler()->crawl($this->websiteId);
        $p = $this->db->query("SELECT * FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services'")->fetch();
        $this->assertSame('Services - Mowology', $p['title']);
        $this->assertSame('Strata lawn care.', $p['meta_desc']);
        $this->assertSame('Services - Mowology', $p['h1']);
        $this->assertSame('https://mowology.ca/services', $p['canonical']);
        $this->assertSame('index,follow', $p['robots_meta']);
        $this->assertSame('LocalBusiness,Service,Thing', $p['schema_types']);
        $this->assertGreaterThan(3, (int)$p['word_count']);
    }

    public function testSitemapUrlsAreSeededAndForeignOnesIgnored(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/robots.txt']['body'] .= "Sitemap: " . self::ORIGIN . "/sitemap.xml\n";
        $this->site[self::ORIGIN . '/sitemap.xml'] = ['status' => 200, 'headers' => ['content-type' => 'application/xml'],
            'body' => '<urlset><url><loc>' . self::ORIGIN . '/hidden-page</loc></url><url><loc>https://other.example/x</loc></url></urlset>'];
        $this->site[self::ORIGIN . '/hidden-page'] = $this->html('Hidden');
        $this->crawler()->crawl($this->websiteId);
        $urls = array_column($this->requests, 'url');
        $this->assertContains(self::ORIGIN . '/hidden-page', $urls);
        $this->assertNotContains('https://other.example/x', $urls);
    }

    public function testRobotsBlockingEverythingMeansNoPageRequests(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /\n"];
        $this->site[self::ORIGIN . '/'] = $this->html('Home');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['pages']);
        $this->assertSame([self::ORIGIN . '/robots.txt'], array_column($this->requests, 'url'));
        $this->assertStringContainsString('robots.txt', (string)$r['summary']);
    }

    public function testUnreadableRobotsMeansNothingIsCrawled(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 503, 'headers' => ['content-type' => 'text/plain'], 'body' => ''];
        $this->site[self::ORIGIN . '/'] = $this->html('Home');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(0, $r['pages']);
        $this->assertNotContains(self::ORIGIN . '/', array_column($this->requests, 'url'));
    }

    public function testMissingRobotsMeansCrawlAway(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/a">a</a>');
        $this->site[self::ORIGIN . '/a'] = $this->html('A');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(2, $r['new']);
    }

    public function testPageLimitStopsTheCrawlAsPartial(): void
    {
        $links = '';
        for ($i = 1; $i <= 10; $i++) { $links .= "<a href=\"/p$i\">p$i</a>"; $this->site[self::ORIGIN . "/p$i"] = $this->html("P$i"); }
        $this->site[self::ORIGIN . '/'] = $this->html('Home', $links);
        $this->db->exec("UPDATE mia_websites SET crawl_cap = 4");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(4, $r['pages']);
        $this->assertSame('partial', $r['status']);
        $this->assertStringContainsString('page limit', (string)$r['summary']);
    }

    public function testTimeLimitStopsTheCrawl(): void
    {
        $links = '';
        for ($i = 1; $i <= 30; $i++) { $links .= "<a href=\"/p$i\">p$i</a>"; $this->site[self::ORIGIN . "/p$i"] = $this->html("P$i"); }
        $this->site[self::ORIGIN . '/'] = $this->html('Home', $links);
        $r = $this->crawler()->crawl($this->websiteId, ['max_seconds' => 10]);
        $this->assertSame('partial', $r['status']);
        $this->assertLessThan(15, $r['pages']);
        $this->assertStringContainsString('time limit', (string)$r['summary']);
    }

    // ---- politeness ----

    public function testEveryRequestIsSpacedByAtLeastOneSecond(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $this->assertGreaterThan(3, count($this->requests));
        for ($i = 1; $i < count($this->requests); $i++) {
            $this->assertGreaterThanOrEqual(1.0, $this->requests[$i]['at'] - $this->requests[$i - 1]['at'], 'gap before request ' . $i);
        }
    }

    public function testCrawlDelayIsHonouredButCapped(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/robots.txt']['body'] .= "Crawl-delay: 5\n";
        $this->crawler()->crawl($this->websiteId);
        for ($i = 1; $i < count($this->requests); $i++) {
            $this->assertGreaterThanOrEqual(5.0, $this->requests[$i]['at'] - $this->requests[$i - 1]['at']);
        }
        $this->setUp();
        $this->basicSite();
        $this->site[self::ORIGIN . '/robots.txt']['body'] .= "Crawl-delay: 9999\n";
        $this->crawler()->crawl($this->websiteId);
        $this->assertLessThanOrEqual(10.2, max(array_map(function ($i) { return $this->requests[$i]['at'] - $this->requests[$i - 1]['at']; }, range(1, count($this->requests) - 1))));
    }

    public function testRepeatedServerErrorsStopTheCrawlAndSlowItDown(): void
    {
        $links = '';
        for ($i = 1; $i <= 8; $i++) { $links .= "<a href=\"/p$i\">p$i</a>"; $this->site[self::ORIGIN . "/p$i"] = ['status' => 503, 'headers' => ['content-type' => 'text/html'], 'body' => '']; }
        $this->site[self::ORIGIN . '/'] = $this->html('Home', $links);
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame('partial', $r['status']);
        $this->assertSame(3, $r['errors']);
        $this->assertStringContainsString('slow down', (string)$r['summary']);
        $this->assertGreaterThanOrEqual(3.9, $this->requests[count($this->requests) - 1]['at'] - $this->requests[count($this->requests) - 2]['at'], 'delay doubled after 503s');
    }

    // ---- change tracking and conditional GET ----

    public function testSecondCrawlSendsConditionalHeadersAndSkipsUnchangedPages(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $first = (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages WHERE status_code = 200")->fetchColumn();
        $this->requests = [];
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(0, $r['changed']);
        $this->assertSame(0, $r['new']);
        $this->assertSame($first, $r['unchanged'], 'every 200 page came back 304');
        $withHeader = array_filter($this->requests, function ($q) { return isset($q['headers']['if-none-match']); });
        $this->assertCount($first, $withHeader);
    }

    public function testChangedPageIsDetectedAndStamped(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $before = $this->db->query("SELECT last_changed_at, content_hash FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services'")->fetch();
        $this->site[self::ORIGIN . '/services'] = $this->html('Services', '<p>Now with snow removal.</p><a href="/">Home</a>');
        $this->crawler()->crawl($this->websiteId);
        $r = $this->db->query("SELECT * FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services'")->fetch();
        $this->assertNotSame($before['content_hash'], $r['content_hash']);
        $this->assertSame('2026-10-25 10:00:00', $r['last_changed_at']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services'")->fetchColumn(), 'updated in place');
    }

    public function testChangedFlagCountedWhenServerIgnoresConditionalHeaders(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        foreach ($this->site as $u => &$resp) { unset($resp['headers']['etag']); }
        unset($resp);
        $this->db->exec("UPDATE mia_site_pages SET etag = NULL, last_modified = NULL");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(0, $r['changed'], 'same content, same hash, even without 304');
        $this->assertGreaterThan(0, $r['unchanged']);
    }

    public function testKnownPagesKeepBeingDiscoveredAfter304s(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $this->requests = [];
        $this->crawler()->crawl($this->websiteId);
        $urls = array_column($this->requests, 'url');
        $this->assertContains(self::ORIGIN . '/services', $urls, 'inventory seeds the queue');
    }

    // ---- redirects, hostile pages ----

    public function testRedirectedPageIsRecordedAsRedirectAndFinalPageStored(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/old">old</a>');
        $this->site[self::ORIGIN . '/old'] = ['status' => 301, 'headers' => ['location' => '/new'], 'body' => ''];
        $this->site[self::ORIGIN . '/new'] = $this->html('New');
        $this->crawler()->crawl($this->websiteId);
        $this->assertSame('301', (string)$this->db->query("SELECT status_code FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/old'")->fetchColumn());
        $this->assertSame('New', $this->db->query("SELECT title FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/new'")->fetchColumn());
    }

    public function testRedirectToAnotherHostIsNotFollowedIntoStorage(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/jump">j</a>');
        $this->site[self::ORIGIN . '/jump'] = ['status' => 302, 'headers' => ['location' => 'https://other.example/landing'], 'body' => ''];
        $this->site['https://other.example/landing'] = $this->html('Elsewhere');
        $this->crawler()->crawl($this->websiteId);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages WHERE url LIKE '%other.example%'")->fetchColumn());
    }

    public function testHostilePageContentIsStoredAsPlainShortText(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/'] = $this->html('Home ' . str_repeat('x', 1000) . "\x00\x07", '<a href="/ok">ok</a><script>document.write("<a href=\'/evil\'>")</script>',
            '<meta name="description" content="' . str_repeat('d', 2000) . '">');
        $this->site[self::ORIGIN . '/ok'] = $this->html('Ok');
        $this->crawler()->crawl($this->websiteId);
        $p = $this->db->query("SELECT title, meta_desc FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/'")->fetch();
        $this->assertLessThanOrEqual(300, mb_strlen($p['title']));
        $this->assertLessThanOrEqual(500, mb_strlen($p['meta_desc']));
        $this->assertStringNotContainsString("\x00", $p['title']);
        $this->assertNotContains(self::ORIGIN . '/evil', array_column($this->requests, 'url'), 'scripts are never run or mined for links');
    }

    public function testMetaNofollowStopsLinkDiscovery(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/a">a</a>', '<meta name="robots" content="noindex,nofollow">');
        $this->site[self::ORIGIN . '/a'] = $this->html('A');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(1, $r['new']);
    }

    public function testNonHtmlAnswersAreSkippedNotErrors(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/download">d</a>');
        $this->site[self::ORIGIN . '/download'] = ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF'];
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(1, $r['skipped']);
        $this->assertSame(0, $r['errors']);
    }

    // ---- tenant isolation ----

    public function testRowsAreScopedToTheSite(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages WHERE site_id <> 1")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM mia_audits WHERE site_id <> 1")->fetchColumn());
    }

    // ---- pure helpers ----

    /** @dataProvider urls */
    public function testNormalizeUrl(string $href, ?string $expect): void
    {
        $this->assertSame($expect, SiteCrawler::normalizeUrl($href, 'https://mowology.ca/a/b/', 'https://mowology.ca'), $href);
    }

    public function urls(): array
    {
        return [
            ['/x', 'https://mowology.ca/x'], ['c', 'https://mowology.ca/a/b/c'], ['../d', 'https://mowology.ca/a/d'], ['./e', 'https://mowology.ca/a/b/e'],
            ['/x#frag', 'https://mowology.ca/x'], ['https://mowology.ca/y', 'https://mowology.ca/y'], ['http://mowology.ca/z', 'https://mowology.ca/z'],
            ['//mowology.ca/w', 'https://mowology.ca/w'], ['/', 'https://mowology.ca/'],
            ['https://www.mowology.ca/y', null], ['https://other.example/y', null], ['/x?a=1', null], ['/a.pdf', null], ['/img/a.JPG', null],
            ['mailto:a@b.ca', null], ['javascript:void(0)', null], ['tel:7788469273', null], ['#top', null], ['', null],
            ['/../../etc/passwd', null], ['/a b', null], ['https://u:p@mowology.ca/x', null], ['/' . str_repeat('a', 600), null],
        ];
    }

    public function testParseSitemapIndexAndUrlset(): void
    {
        $idx = SiteCrawler::parseSitemap('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.ca/s1.xml</loc></sitemap></sitemapindex>');
        $this->assertSame(['https://a.ca/s1.xml'], $idx['sitemaps']);
        $this->assertSame([], $idx['urls']);
        $set = SiteCrawler::parseSitemap('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc> https://a.ca/x </loc></url></urlset>');
        $this->assertSame(['https://a.ca/x'], $set['urls']);
    }

    public function testParseSitemapRefusesEntityTricksAndGarbage(): void
    {
        $this->assertSame(['urls' => [], 'sitemaps' => []], SiteCrawler::parseSitemap('<!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/passwd">]><urlset><url><loc>&a;</loc></url></urlset>'));
        $this->assertSame(['urls' => [], 'sitemaps' => []], SiteCrawler::parseSitemap('not xml at all'));
        $this->assertSame(['urls' => [], 'sitemaps' => []], SiteCrawler::parseSitemap(''));
    }

    public function testRemoveDotSegments(): void
    {
        $this->assertSame('/a/c', SiteCrawler::removeDotSegments('/a/b/../c'));
        $this->assertSame('/a/', SiteCrawler::removeDotSegments('/a/b/..'));
        $this->assertNull(SiteCrawler::removeDotSegments('/../x'));
        $this->assertSame('/', SiteCrawler::removeDotSegments('/'));
    }

    public function testSiteZeroIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $f = new SafeHttpFetcher();
        new SiteCrawler($this->db, 0, new MiaWebsiteService($this->db, 1, $f), $f);
    }

    // ---- Codex round 1 regressions ----

    public function testOffSiteRedirectIsNeverRequestedAndIsRecordedAsARedirect(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/jump">j</a>');
        $this->site[self::ORIGIN . '/jump'] = ['status' => 302, 'headers' => ['location' => 'https://other.example/collect'], 'body' => ''];
        $this->site['https://other.example/collect'] = $this->html('Elsewhere');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertNotContains('https://other.example/collect', array_column($this->requests, 'url'));
        $this->assertSame('301', (string)$this->db->query("SELECT status_code FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/jump'")->fetchColumn());
        $this->assertGreaterThanOrEqual(1, $r['skipped']);
    }

    public function testEncodedLinkCannotSlipPastRobotsOrDuplicateAPage(): void
    {
        $this->basicSite();
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/%61dmin/login">sneaky</a> <a href="/%73ervices">enc</a> <a href="/services">plain</a>');
        $this->crawler()->crawl($this->websiteId);
        $urls = array_column($this->requests, 'url');
        $this->assertNotContains(self::ORIGIN . '/admin/login', $urls);
        $this->assertSame(1, count(array_keys($urls, self::ORIGIN . '/services')), 'encoded and plain forms are one page');
        $this->assertNotContains(self::ORIGIN . '/a/%2e%2e/admin', $urls);
    }

    public function testEncodedDotSegmentsResolve(): void
    {
        $this->assertSame('https://mowology.ca/admin', SiteCrawler::normalizeUrl('/a/%2e%2e/admin', 'https://mowology.ca/', 'https://mowology.ca'));
        $this->assertSame('https://mowology.ca/~u', SiteCrawler::normalizeUrl('/%7Eu', 'https://mowology.ca/', 'https://mowology.ca'));
    }

    public function testConditionalValidatorsAreNotForwardedAcrossRedirects(): void
    {
        $this->basicSite();
        $this->crawler()->crawl($this->websiteId);
        $this->site[self::ORIGIN . '/services'] = ['status' => 301, 'headers' => ['location' => '/services-new'], 'body' => ''];
        $this->site[self::ORIGIN . '/services-new'] = $this->html('Services New');
        $this->requests = [];
        $this->crawler()->crawl($this->websiteId);
        $new = array_values(array_filter($this->requests, function ($q) { return $q['url'] === self::ORIGIN . '/services-new'; }));
        $this->assertNotEmpty($new);
        $this->assertSame([], $new[0]['headers']);
        $this->assertSame('Services New', $this->db->query("SELECT title FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services-new'")->fetchColumn());
        $this->assertSame('301', (string)$this->db->query("SELECT status_code FROM mia_site_pages WHERE url = '" . self::ORIGIN . "/services'")->fetchColumn());
    }

    public function testDisallowRootWithAllowedSubtreeStillCrawlsItFromTheSitemap(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /\nAllow: /public/\nSitemap: " . self::ORIGIN . "/sitemap.xml\n"];
        $this->site[self::ORIGIN . '/sitemap.xml'] = ['status' => 200, 'headers' => ['content-type' => 'application/xml'],
            'body' => '<urlset><url><loc>' . self::ORIGIN . '/public/a</loc></url><url><loc>' . self::ORIGIN . '/private/b</loc></url></urlset>'];
        $this->site[self::ORIGIN . '/public/a'] = $this->html('Public A');
        $this->site[self::ORIGIN . '/private/b'] = $this->html('Private B');
        $this->crawler()->crawl($this->websiteId);
        $urls = array_column($this->requests, 'url');
        $this->assertContains(self::ORIGIN . '/public/a', $urls);
        $this->assertNotContains(self::ORIGIN . '/private/b', $urls);
    }

    public function testFetchTimeoutNeverExceedsTheRemainingBudget(): void
    {
        $this->basicSite();
        $timeouts = [];
        $fetcher = new SafeHttpFetcher([], function () { return ['93.184.216.34']; }, function ($url, $h, $p, $ip, $opts) use (&$timeouts) {
            $timeouts[] = $opts['timeout'];
            return $this->site[$url] ?? ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => ''];
        });
        $websites = new MiaWebsiteService($this->db, 1, $fetcher, function () { return []; });
        $c = new SiteCrawler($this->db, 1, $websites, $fetcher, function (float $s) { $this->clockNow += $s; },
            function (): float { $this->clockNow += 0.05; return $this->clockNow; }, function () { return new DateTimeImmutable('2026-10-25 10:00:00'); });
        $c->crawl($this->websiteId, ['max_seconds' => 8]);
        $this->assertNotEmpty($timeouts);
        $this->assertLessThanOrEqual(8.0, max($timeouts), 'no request may be allowed longer than the whole run');
        $this->assertGreaterThan($timeouts[count($timeouts) - 1], $timeouts[0], 'later requests get less time');
    }

    public function testRetryAfterTooLongStopsTheCrawl(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/a">a</a><a href="/b">b</a>');
        $this->site[self::ORIGIN . '/a'] = ['status' => 429, 'headers' => ['content-type' => 'text/html', 'retry-after' => '120'], 'body' => ''];
        $this->site[self::ORIGIN . '/b'] = $this->html('B');
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame('partial', $r['status']);
        $this->assertStringContainsString('come back later', (string)$r['summary']);
        $this->assertNotContains(self::ORIGIN . '/b', array_column($this->requests, 'url'));
    }

    public function testShortRetryAfterRaisesTheDelay(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/a">a</a><a href="/b">b</a>');
        $this->site[self::ORIGIN . '/a'] = ['status' => 429, 'headers' => ['content-type' => 'text/html', 'retry-after' => '12'], 'body' => ''];
        $this->site[self::ORIGIN . '/b'] = $this->html('B');
        $this->crawler()->crawl($this->websiteId);
        $n = count($this->requests);
        $this->assertGreaterThanOrEqual(12.0, $this->requests[$n - 1]['at'] - $this->requests[$n - 2]['at']);
    }

    public function testSitemapThatSaysSlowDownStopsSitemapFetching(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'],
            'body' => "Sitemap: " . self::ORIGIN . "/s1.xml\nSitemap: " . self::ORIGIN . "/s2.xml\n"];
        $this->site[self::ORIGIN . '/s1.xml'] = ['status' => 429, 'headers' => ['content-type' => 'application/xml', 'retry-after' => '5'], 'body' => ''];
        $this->site[self::ORIGIN . '/s2.xml'] = ['status' => 200, 'headers' => ['content-type' => 'application/xml'], 'body' => '<urlset><url><loc>' . self::ORIGIN . '/x</loc></url></urlset>'];
        $this->site[self::ORIGIN . '/'] = $this->html('Home');
        $this->crawler()->crawl($this->websiteId);
        $urls = array_column($this->requests, 'url');
        $this->assertNotContains(self::ORIGIN . '/s2.xml', $urls);
    }

    public function testQueueAdmissionIsBounded(): void
    {
        // 30 pages each linking to 40 new pages; cap 5 -> at most cap*4 = 20 URLs may ever be admitted.
        $links = '';
        for ($i = 0; $i < 40; $i++) { $links .= '<a href="/n' . $i . '">n</a>'; $this->site[self::ORIGIN . "/n$i"] = $this->html("N$i", $links); }
        $this->site[self::ORIGIN . '/'] = $this->html('Home', $links);
        $this->db->exec("UPDATE mia_websites SET crawl_cap = 5");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertSame(5, $r['pages']);
        $this->assertLessThanOrEqual(5, (int)$this->db->query("SELECT COUNT(*) FROM mia_site_pages")->fetchColumn());
    }

    public function testDatabaseFailureDuringTheGateReturnsAnErrorInsteadOfThrowing(): void
    {
        $this->basicSite();
        $this->db->exec("DROP TABLE mia_websites");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertFalse($r['ok']);
        $this->assertContains($r['code'], ['not_verified', 'error']);
    }

    public function testRedirectTargetAlsoInTheSitemapIsProcessedOnce(): void
    {
        $this->site[self::ORIGIN . '/robots.txt'] = ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "Sitemap: " . self::ORIGIN . "/sitemap.xml\n"];
        $this->site[self::ORIGIN . '/sitemap.xml'] = ['status' => 200, 'headers' => ['content-type' => 'application/xml'],
            'body' => '<urlset><url><loc>' . self::ORIGIN . '/old</loc></url><url><loc>' . self::ORIGIN . '/new</loc></url></urlset>'];
        $this->site[self::ORIGIN . '/old'] = ['status' => 301, 'headers' => ['location' => '/new'], 'body' => ''];
        $this->site[self::ORIGIN . '/new'] = $this->html('New');
        $this->site[self::ORIGIN . '/'] = $this->html('Home');
        $this->crawler()->crawl($this->websiteId);
        $newFetches = array_filter(array_column($this->requests, 'url'), function ($u) { return $u === self::ORIGIN . '/new'; });
        $this->assertCount(1, $newFetches, '/old redirects to /new, which is also queued: fetched once');
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $this->site[self::ORIGIN . '/'] = $this->html('Home', '<a href="/a">a</a><a href="/b">b</a>');
        $this->site[self::ORIGIN . '/a'] = ['status' => 503, 'headers' => ['content-type' => 'text/html', 'retry-after' => 'Sun, 25 Oct 2026 10:00:20 GMT'], 'body' => ''];
        $this->site[self::ORIGIN . '/b'] = $this->html('B');
        $this->crawler()->crawl($this->websiteId);                    // fake "now" is 2026-10-25 10:00:00, so 20 s
        $n = count($this->requests);
        $this->assertGreaterThanOrEqual(20.0, $this->requests[$n - 1]['at'] - $this->requests[$n - 2]['at']);
    }

    public function testChangeFarDownALongPageChangesTheHash(): void
    {
        $filler = str_repeat('lorem ipsum dolor sit amet ', 12000);          // ~320 000 characters of text
        $this->site[self::ORIGIN . '/'] = $this->html('Home', "<p>$filler</p><p>Price: 100</p>");
        $this->crawler()->crawl($this->websiteId);
        $h1 = $this->db->query("SELECT content_hash FROM mia_site_pages")->fetchColumn();
        $this->site[self::ORIGIN . '/'] = $this->html('Home', "<p>$filler</p><p>Price: 120</p>");
        $r = $this->crawler()->crawl($this->websiteId);
        $this->assertNotSame($h1, $this->db->query("SELECT content_hash FROM mia_site_pages")->fetchColumn());
        $this->assertSame(1, $r['changed']);
    }
}
