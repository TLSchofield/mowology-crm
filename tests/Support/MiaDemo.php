<?php
/**
 * MiaDemo: the Phase 1 demonstration (Part 20 of the brief), run with the REAL classes on SQLite and a SYNTHETIC
 * Mowology: a pretend website served from memory, a Search Console fixture and a scripted "model". No network, no
 * production data, and nothing in it can write to a website: every request it makes is recorded and is a GET.
 *
 *   1  Mia examines the website              connect + prove ownership + weekly audit (crawl + checks)
 *   2  She finds a meaningful opportunity    Search Console + findings -> scored opportunities
 *   3  She explains it                       plain-English reason, estimate, risk, the numbers
 *   4  She creates a proposed improvement    checked wording for the title and description
 *   5  It appears in the action queue        what the owner's Growth tab and Charlie's brief show
 *   6  The owner reviews it                  approves one (bound to the wording shown), sets one aside with a reason
 *   7  Mia records the decision              audit trail + decision memory
 *   8  She tracks its status                 approved stays approved, set-aside stays set aside
 *   9  She uses the decision next time       the next week's run does not repeat it, and shows what got fixed
 *
 * Used by tests/Unit/Marketing/MiaDemoWorkflowTest.php (assertions) and tests/tools/mia_demo.php (narrated output).
 */
declare(strict_types=1);

final class MiaDemo
{
    public const HOST = 'mowology.ca';
    public const TOKEN = 'aabbccddeeff00112233445566778899';
    public const USER_ID = 7;

    /** @var PDO */
    public $db;
    /** @var string[] "METHOD url" of every request made to the pretend website */
    public $requests = [];
    /** @var array<string,array> url => response of the pretend website */
    public $site = [];
    /** @var array<int,array> scripted model replies */
    public $modelReplies = [];
    /** @var array<int,string> step name => what happened (for the narrated tool) */
    public $log = [];

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::schema($this->db);
        $this->db->exec("INSERT INTO users VALUES (" . self::USER_ID . ")");
        $this->db->exec("INSERT INTO business_settings VALUES (1, 'Mowology', '(778) 846-9273', 'office@mowology.ca')");
        $this->buildSite();
        $this->seedSearchConsole();
    }

    // ---- fixtures -----------------------------------------------------------------------------------------

    public static function schema(PDO $db): void
    {
        foreach ([
            "CREATE TABLE users (id INTEGER PRIMARY KEY)",
            "CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)",
            "CREATE TABLE business_settings (id INTEGER PRIMARY KEY, company_name TEXT, phone TEXT, email TEXT)",
            "CREATE TABLE mia_websites (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, base_url TEXT, host TEXT, mode TEXT DEFAULT 'existing', connector TEXT DEFAULT 'none', capabilities TEXT, status TEXT DEFAULT 'pending', verify_token TEXT, verified_via TEXT, verified_at TEXT, last_verify_attempt_at TEXT, last_verify_error TEXT, crawl_cap INT DEFAULT 200, robots_hash TEXT, created_by INT, created_at TEXT, UNIQUE(site_id, host))",
            "CREATE TABLE mia_site_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, url_hash TEXT, url TEXT, status_code INT, content_hash TEXT, title TEXT, meta_desc TEXT, h1 TEXT, canonical TEXT, robots_meta TEXT, word_count INT, schema_types TEXT, h1_count INT, images_total INT, images_no_alt INT, has_viewport INT, lang TEXT, links_out INT, etag TEXT, last_modified TEXT, first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, last_changed_at TEXT, last_crawl_at TEXT, UNIQUE(site_id, website_id, url_hash))",
            "CREATE TABLE mia_site_links (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, from_hash TEXT NOT NULL, to_hash TEXT NOT NULL, UNIQUE(site_id, website_id, from_hash, to_hash))",
            "CREATE TABLE mia_audits (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, kind TEXT, status TEXT, pages INT DEFAULT 0, findings INT DEFAULT 0, started_at TEXT, finished_at TEXT, summary TEXT)",
            "CREATE TABLE mia_audit_findings (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT, audit_id INT, page_id INT, rule_key TEXT, severity TEXT, evidence TEXT, fingerprint TEXT, first_seen_audit_id INT, resolved_at TEXT, created_at TEXT)",
            "CREATE TABLE mia_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, opportunity_key TEXT, type TEXT, side_effect TEXT, status TEXT NOT NULL DEFAULT 'proposed', title TEXT, why TEXT, expected_benefit TEXT, risk TEXT, evidence TEXT, score REAL, score_inputs TEXT, evidence_hash TEXT, draft_ref_table TEXT, draft_ref_id INT, draft_hash TEXT, preview_url TEXT, autonomy_level INT DEFAULT 1, proposed_by TEXT DEFAULT 'mia', proposed_at TEXT, decided_by INT, decided_at TEXT, decision_note TEXT, executed_at TEXT, result TEXT, created_at TEXT)",
            "CREATE TABLE mia_action_events (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, action_id INT, event TEXT, actor TEXT, detail TEXT, created_at TEXT)",
            "CREATE TABLE mia_decisions (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, subject_key TEXT, action_type TEXT, evidence_hash TEXT, decision TEXT, reason_code TEXT, reason_text TEXT, decided_by INT, decided_at TEXT, suppress_until TEXT)",
            "CREATE TABLE mia_memory (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, kind TEXT, mem_key TEXT, mem_value TEXT, source TEXT DEFAULT 'owner', updated_by INT, updated_at TEXT, UNIQUE(site_id, kind, mem_key))",
            "CREATE TABLE mia_llm_usage (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, task TEXT, model TEXT, input_tokens INT DEFAULT 0, output_tokens INT DEFAULT 0, cost_usd REAL DEFAULT 0, ok INT DEFAULT 1, error TEXT, created_at TEXT)",
            "CREATE TABLE mia_content_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, website_id INT NOT NULL, action_id INT, kind TEXT, page_url TEXT, current_title TEXT, current_desc TEXT, title TEXT, meta_desc TEXT, rationale TEXT, input_hash TEXT, status TEXT DEFAULT 'draft', version INT DEFAULT 1, created_at TEXT)",
            "CREATE TABLE gsc_properties (id INTEGER PRIMARY KEY AUTOINCREMENT, site_url TEXT)",
            "CREATE TABLE gsc_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INT, snapshot_date TEXT)",
            "CREATE TABLE gsc_query_page_stats (id INTEGER PRIMARY KEY AUTOINCREMENT, snapshot_id INT, query TEXT, page TEXT, clicks INT, impressions INT, ctr REAL, position REAL)",
        ] as $sql) { $db->exec($sql); }
    }

    private function page(string $title, ?string $desc, string $body, bool $viewport = true, string $head = ''): array
    {
        return ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=utf-8', 'etag' => '"' . md5($title . $body . $desc) . '"'],
                'body' => '<!doctype html><html lang="en"><head><meta charset="utf-8">' . ($viewport ? '<meta name="viewport" content="width=device-width, initial-scale=1">' : '')
                    . '<title>' . $title . '</title>' . ($desc !== null ? '<meta name="description" content="' . $desc . '">' : '') . $head . '</head><body>' . $body . '</body></html>'];
    }

    /** A small synthetic landscaping site with a few realistic problems. */
    private function buildSite(): void
    {
        $o = 'https://' . self::HOST;
        $words = str_repeat('Reliable grounds care for Vancouver properties and strata corporations. ', 12);
        $this->site = [
            $o . '/robots.txt' => ['status' => 200, 'headers' => ['content-type' => 'text/plain'], 'body' => "User-agent: *\nDisallow: /crm/\nSitemap: $o/sitemap.xml\n"],
            $o . '/sitemap.xml' => ['status' => 200, 'headers' => ['content-type' => 'application/xml'], 'body' => '<urlset><url><loc>' . $o . '/</loc></url><url><loc>' . $o . '/services/strata-landscaping</loc></url><url><loc>' . $o . '/about</loc></url><url><loc>' . $o . '/contact</loc></url></urlset>'],
            $o . '/' => $this->page('Mowology | Landscaping and Grounds Maintenance in Vancouver', 'Landscaping and grounds maintenance for homes and strata properties in Vancouver, Burnaby and Richmond. Ask for a quote today.',
                '<h1>Landscaping and grounds maintenance</h1><p>' . $words . '</p><a href="/services/strata-landscaping">Strata</a> <a href="/about">About</a> <a href="/contact">Contact</a> <a href="/old-promo">Spring promotion</a>',
                true, '<link rel="canonical" href="' . $o . '/"><script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","name":"Mowology"}</script>'),
            $o . '/services/strata-landscaping' => $this->page('Strata', null,
                '<h1>Strata landscaping</h1><p>' . $words . '</p><a href="/">Home</a> <a href="/contact">Contact</a>', true, '<link rel="canonical" href="' . $o . '/services/strata-landscaping">'),
            $o . '/about' => $this->page('About Mowology: a Vancouver landscaping team', 'Meet the Mowology team: local, family run and focused on tidy, dependable grounds care across Metro Vancouver.',
                '<p>' . $words . '</p><a href="/">Home</a>', true, '<link rel="canonical" href="' . $o . '/about">'),
            $o . '/contact' => $this->page('Contact Mowology for a landscaping quote in Vancouver', 'Call or write to Mowology for a landscaping or strata grounds maintenance quote in Vancouver, Burnaby or Richmond.',
                '<h1>Contact</h1><p>' . $words . '</p><a href="/">Home</a>', true, '<link rel="canonical" href="' . $o . '/contact">'),
        ];
    }

    private function seedSearchConsole(): void
    {
        $this->db->exec("INSERT INTO gsc_properties (site_url) VALUES ('sc-domain:" . self::HOST . "')");
        $rows = function (array $r) { return $r; };
        // two non-overlapping periods: the strata page is shown a lot but rarely clicked
        foreach ([['2026-09-18', [['strata landscaping vancouver', 12, 500], ['strata gardening', 4, 180]]], ['2026-10-22', [['strata landscaping vancouver', 6, 560], ['strata gardening', 2, 190], ['landscape maintenance burnaby', 0, 240]]]] as $snap) {
            $this->db->exec("INSERT INTO gsc_snapshots (property_id, snapshot_date) VALUES (1, '{$snap[0]}')");
            $sid = (int)$this->db->lastInsertId();
            foreach ($snap[1] as $q) {
                $page = $q[0] === 'landscape maintenance burnaby' ? 'https://' . self::HOST . '/' : 'https://' . self::HOST . '/services/strata-landscaping';
                $pos = $q[0] === 'landscape maintenance burnaby' ? 34.0 : 4.0;
                $this->db->prepare("INSERT INTO gsc_query_page_stats (snapshot_id, query, page, clicks, impressions, ctr, position) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$sid, $q[0], $page, $q[1], $q[2], $q[2] ? $q[1] / $q[2] : 0, $pos]);
            }
        }
    }

    // ---- plumbing ------------------------------------------------------------------------------------------

    public function fetcher(): SafeHttpFetcher
    {
        return new SafeHttpFetcher(['allowed_types' => ['text/html', 'application/xhtml+xml']],
            function (string $host) { return $host === self::HOST ? ['93.184.216.34'] : []; },
            function (string $url, string $h, int $p, string $ip, array $opts) {
                $this->requests[] = 'GET ' . $url;                                      // the transport only ever issues GET
                $r = $this->site[$url] ?? ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => 'not found'];
                if (isset($opts['request_headers']['if-none-match']) && ($r['headers']['etag'] ?? null) === $opts['request_headers']['if-none-match']) { return ['status' => 304, 'headers' => [], 'body' => '']; }
                $ct = strtolower(trim(explode(';', $r['headers']['content-type'] ?? 'text/html')[0]));
                if (is_array($opts['allowed_types'] ?? null) && !in_array($ct, $opts['allowed_types'], true)) { return ['code' => 'bad_content_type', 'error' => 'x']; }
                return $r;
            });
    }

    public function websiteService(int $site = 1): MiaWebsiteService
    {
        return new MiaWebsiteService($this->db, $site, $this->fetcher(), function () { return []; }, function () { return self::TOKEN; });
    }

    public function api(): MiaApiHandler
    {
        return new MiaApiHandler($this->db, self::USER_ID, $this->websiteService(), new MiaActionService($this->db, 1));
    }

    public function jobs(): MiaScheduledJobs
    {
        $demo = $this;
        return new MiaScheduledJobs($this->db, [
            'crawl' => function (int $s, int $w, int $max) use ($demo) {
                $c = new SiteCrawler($demo->db, $s, $demo->websiteService($s), $demo->fetcher(), function (float $x) {}, function (): float { static $t = 0.0; return $t += 0.01; },
                    function () { return new DateTimeImmutable('2026-10-26 05:30:00'); });
                return $c->crawl($w, ['max_seconds' => $max]);
            },
            'draft' => function (int $s, int $w, DateTimeImmutable $now) use ($demo) {
                $llm = new MiaLlmGateway($demo->db, $s, function (array $body) use ($demo) {
                    $r = array_shift($demo->modelReplies) ?? ['__http' => 500];
                    if (isset($r['__http'])) { return ['code' => $r['__http'], 'body' => '{}']; }
                    return ['code' => 200, 'body' => json_encode(['content' => [['type' => 'text', 'text' => json_encode($r)]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 160]])];
                });
                return (new MiaRecommendationWriter($demo->db, $s, $llm, new MiaActionService($demo->db, $s)))->prepareOpen($w, 3, $now);
            },
        ]);
    }

    /** What the scripted model returns for the strata page: wording that passes every check. */
    public function scriptModel(): void
    {
        $this->modelReplies = [[
            'title' => 'Strata Landscaping and Grounds Care in Vancouver',
            'meta_description' => 'Regular landscaping and grounds care for strata corporations in Vancouver. Reliable crews and a clear written quote on request.',
            'explanation' => 'Many people see this page but few click it. A clearer title and description that say what you do for strata properties may help more of them choose it.',
        ]];
    }

    // ---- the demonstration ---------------------------------------------------------------------------------

    /** Runs the nine steps. Returns what the owner and Charlie would see at each. */
    public function run(): array
    {
        $out = [];
        $mon1 = new DateTimeImmutable('2026-10-26 05:30:00');
        $api = $this->api();

        // 1. Mia examines the company's website
        list($h, $b) = $api->handle('connect_website', 'POST', [], ['address' => self::HOST], $mon1);
        $out['connect'] = $b;
        $this->db->prepare("UPDATE mia_websites SET status = 'verified', verified_via = 'meta', verified_at = ? WHERE id = ?")->execute([$mon1->format('Y-m-d H:i:s'), $b['id']]);
        $out['audit'] = $this->jobs()->auditWeekly($mon1);
        list(, $out['health']) = $api->handle('health', 'GET', [], [], $mon1);

        // 2-5. She finds an opportunity, explains it, drafts an improvement, and it lands in the queue
        $this->scriptModel();
        $out['search'] = $this->jobs()->searchWeekly($mon1->modify('+25 minutes'));
        list(, $out['queue']) = $api->handle('actions', 'GET', ['status' => 'proposed'], [], $mon1);
        $out['brief'] = MiaQueueBrief::items($this->db, 1, $mon1->modify('+1 hour 30 minutes'));
        $ctr = null; $meta = null;
        foreach ($out['queue']['items'] as $it) {
            if (strpos($it['title'], 'Rewrite the title') === 0) { $ctr = $it; }
            if ($it['title'] === 'Add a description to 1 page') { $meta = $it; }
        }
        $out['ctrItem'] = $ctr; $out['metaItem'] = $meta;

        // 6. The owner reviews it: approves the wording he saw, sets the other aside with a reason
        if ($ctr) {
            list(, $detail) = $api->handle('action_detail', 'GET', ['id' => $ctr['id']], [], $mon1->modify('+2 hours'));
            list($h1, $b1) = $api->handle('action_decide', 'POST', [], ['id' => $ctr['id'], 'decision' => 'approve', 'seen_hash' => $detail['item']['seen_hash']], $mon1->modify('+2 hours'));
            $out['approve'] = [$h1, $b1];
        }
        if ($meta) {
            list($h2, $b2) = $api->handle('action_decide', 'POST', [], ['id' => $meta['id'], 'decision' => 'reject', 'reason_code' => 'not_our_voice', 'reason_text' => 'We write those ourselves'], $mon1->modify('+2 hours'));
            $out['reject'] = [$h2, $b2];
        }

        // 7-8. Mia records the decision and tracks its status
        $out['events'] = [];
        foreach ([$ctr, $meta] as $it) { if ($it) { list(, $d) = $api->handle('action_detail', 'GET', ['id' => $it['id']], [], $mon1->modify('+3 hours')); $out['events'][$it['id']] = $d['item']; } }
        $out['decisions'] = (new MiaMemoryService($this->db, 1))->recentDecisions();

        // 9. She uses the decision next time: the owner fixes the About page's heading, the next Monday run does not repeat anything decided
        $this->site['https://' . self::HOST . '/about'] = $this->page('About Mowology: a Vancouver landscaping team', 'Meet the Mowology team: local, family run and focused on tidy, dependable grounds care across Metro Vancouver.',
            '<h1>About us</h1><p>' . str_repeat('Reliable grounds care for Vancouver properties and strata corporations. ', 12) . '</p><a href="/">Home</a>', true,
            '<link rel="canonical" href="https://' . self::HOST . '/about">');
        $mon2 = new DateTimeImmutable('2026-11-02 05:30:00');
        $this->requests = [];
        $out['audit2'] = $this->jobs()->auditWeekly($mon2);
        $out['audit2requests'] = $this->requests;
        $this->scriptModel();
        $out['search2'] = $this->jobs()->searchWeekly($mon2->modify('+25 minutes'));
        list(, $out['queue2']) = $api->handle('actions', 'GET', ['status' => 'proposed'], [], $mon2);
        list(, $out['results']) = $api->handle('actions', 'GET', ['status' => 'all'], [], $mon2);
        return $out;
    }
}
