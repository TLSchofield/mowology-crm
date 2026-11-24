<?php
/**
 * SiteAuditRules — deterministic technical-SEO checks over the crawl inventory (Phase 1.8).
 *
 * No AI here. Every finding is a plain rule over rows the crawler stored (mia_site_pages, mia_site_links), so the
 * same inventory always gives the same findings, and each can be explained with the numbers behind it. AI is used
 * later (step 1.10) only to turn findings into plain-English explanations and drafts.
 *
 * evaluate() is pure. run() stores the findings in mia_audit_findings and keeps them tidy across runs:
 *   - a finding is identified by a fingerprint (rule + address + a coarse class), so a title that goes from 72 to
 *     75 characters is the same finding, not a new one;
 *   - a finding seen again keeps its first_seen_audit_id; one that is no longer true is marked resolved_at.
 *
 * Checks that need data the crawler does not keep (sitemap contents, page speed, accessibility scores) are not
 * faked here. They arrive with their own collectors.
 *
 * Every query binds site_id. Methods return result arrays and do not throw (the constructor refuses site 0).
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/RobotsParser.php';
require_once __DIR__ . '/../../../../Services/Net/SafeHttpFetcher.php';

final class SiteAuditRules
{
    public const TITLE_MIN = 20;
    public const TITLE_MAX = 65;
    public const META_MIN = 70;
    public const META_MAX = 160;
    public const THIN_WORDS = 150;
    public const STALE_DAYS = 365;
    /** Pages not seen by a crawl for this long are treated as gone and ignored. */
    public const IGNORE_UNSEEN_DAYS = 90;
    public const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];

    /**
     * The catalogue: what each rule means for a business owner. Used by the UI and by the explanation step.
     * 'why' is the reason it matters, in plain words, without promising a result.
     */
    public const RULES = [
        'not_https'            => ['high',   'The site is served over http, not https',                 'Browsers warn visitors about sites without https, and search engines prefer secure pages.'],
        'noindex_home'         => ['high',   'The home page tells search engines to ignore it',         'A noindex tag on the home page usually keeps the whole site out of search results.'],
        'broken_page'          => ['high',   'A page that cannot be found',                             'Visitors and search engines land on an error instead of your content.'],
        'server_error'         => ['high',   'A page that fails with a server error',                   'The page is not available when people or search engines ask for it.'],
        'canonical_offsite'    => ['high',   'A page points search engines to a different website',     'The page asks Google to treat another site as the original, so this page may not appear.'],
        'canonical_to_broken'  => ['high',   'A page points search engines to a broken page',           'The preferred address it names does not work.'],
        'viewport_missing'     => ['high',   'A page is not set up for phones',                         'Without a viewport tag the page can look tiny on a phone, and most visitors are on phones.'],
        'title_missing'        => ['high',   'A page has no title',                                     'The title is what people see first in search results.'],
        'meta_missing'         => ['medium', 'A page has no description',                               'Without one, Google writes its own snippet, which may not sell the page.'],
        'h1_missing'           => ['medium', 'A page has no main heading',                              'A clear main heading tells visitors and search engines what the page is about.'],
        'title_duplicate'      => ['medium', 'Several pages share the same title',                      'Identical titles make pages hard to tell apart in search results.'],
        'meta_duplicate'       => ['medium', 'Several pages share the same description',                'Identical descriptions give people no reason to choose one page over another.'],
        'duplicate_content'    => ['medium', 'Several addresses show identical content',                'Search engines have to guess which copy to show, and the pages compete with each other.'],
        'schema_missing_home'  => ['medium', 'The home page has no business details for search engines','Structured business data helps Google show your name, area and services accurately.'],
        'images_missing_alt'   => ['medium', 'Images without descriptions',                             'Alt text helps search engines and screen readers understand pictures.'],
        'title_short'          => ['low',    'A page title is very short',                              'A short title wastes space where you could say what the page offers.'],
        'title_long'           => ['low',    'A page title is too long',                                'Long titles are cut off in search results.'],
        'meta_short'           => ['low',    'A page description is very short',                        'A fuller description gives people more reason to click.'],
        'meta_long'            => ['low',    'A page description is too long',                          'Long descriptions are cut off in search results.'],
        'h1_multiple'          => ['low',    'A page has more than one main heading',                   'One clear main heading is easier for visitors and search engines to follow.'],
        'canonical_missing'    => ['low',    'A page does not name its preferred address',              'Without it, copies of the page (with or without www, tracking tags) can be treated as separate pages.'],
        'thin_content'         => ['low',    'A page has very little text',                             'Pages with only a few lines rarely answer what people searched for.'],
        'orphan_page'          => ['low',    'A page that no other page links to',                      'Visitors and search engines may never find it.'],
        'link_to_redirect'     => ['low',    'Internal links go through a redirect',                    'Linking straight to the final address is faster and cleaner.'],
        'lang_missing'         => ['low',    'A page does not declare its language',                    'The language tag helps search engines and screen readers.'],
        'canonical_elsewhere'  => ['info',   'A page names a different address as its original',        'This can be intentional. Check that it is what you want.'],
        'site_stale'           => ['info',   'Nothing on the site has changed for a long time',          'Fresh, accurate pages tend to serve visitors better.'],
    ];

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;

    public function __construct(PDO $db, int $siteId)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('SiteAuditRules needs a site'); }
        $this->db     = $db;
        $this->siteId = $siteId;
    }

    // ---- pure -----------------------------------------------------------------------------------

    /**
     * @param array<int,array> $pages  rows of mia_site_pages (id, url_hash, url, status_code, content_hash, title, meta_desc, h1, canonical,
     *                                 robots_meta, word_count, schema_types, h1_count, images_total, images_no_alt, has_viewport, lang, last_changed_at)
     * @param array<int,array{0:string,1:string}> $edges  [from_hash, to_hash]
     * @param array{base_url:string,now?:DateTimeImmutable} $ctx
     * @return array<int,array{rule_key:string,severity:string,url:string,page_id:?int,evidence:string,fingerprint:string}>
     */
    public static function evaluate(array $pages, array $edges, array $ctx): array
    {
        $base   = rtrim((string)$ctx['base_url'], '/');
        $host   = strtolower((string)parse_url($base, PHP_URL_HOST));
        $now    = $ctx['now'] ?? new DateTimeImmutable('now');
        $out    = [];
        $add = function (string $rule, string $url, ?int $pageId, string $evidence, string $class = '') use (&$out) {
            $out[] = ['rule_key' => $rule, 'severity' => self::RULES[$rule][0], 'url' => $url, 'page_id' => $pageId,
                      'evidence' => mb_substr($evidence, 0, 500), 'fingerprint' => sha1($rule . '|' . $url . '|' . $class)];
        };

        $byHash = [];
        foreach ($pages as $p) { $byHash[$p['url_hash']] = $p; }

        $inbound = [];                       // to_hash => [from_hash, ...] (internal links between inventory pages)
        foreach ($edges as $e) {
            if ($e[0] !== $e[1] && isset($byHash[$e[0]])) { $inbound[$e[1]][$e[0]] = true; }
        }
        $linkedFrom = function (string $hash, int $max = 3) use ($inbound, $byHash): array {
            $urls = [];
            foreach (array_keys($inbound[$hash] ?? []) as $f) { if (isset($byHash[$f])) { $urls[] = $byHash[$f]['url']; } }
            sort($urls);
            return [count($urls), array_slice($urls, 0, $max)];
        };
        $path = function (string $url): string { return (string)(parse_url($url, PHP_URL_PATH) ?: '/'); };
        $isNoindex = function (array $p): bool { return RobotsParser::metaNoindex($p['robots_meta'] ?? null); };
        // The home page whichever way its address was stored: "https://x.ca" or "https://x.ca/".
        $isHome = function (array $p) use ($host): bool {
            $pp = parse_url((string)$p['url']);
            return $pp !== false && strtolower((string)($pp['host'] ?? '')) === $host && in_array($pp['path'] ?? '', ['', '/'], true) && !isset($pp['query']);
        };
        $stripWww = function (string $h): string { return preg_replace('/^www\./', '', strtolower($h)) ?? $h; };

        if (stripos($base, 'http://') === 0) {
            $add('not_https', $base . '/', null, 'The site address starts with http://');
        }

        // indexable, healthy pages are the ones the page-level rules apply to
        $live = [];
        foreach ($pages as $p) {
            if ((int)$p['status_code'] === 200 && !$isNoindex($p)) { $live[] = $p; }
        }

        foreach ($live as $p) {
            $u = $p['url']; $id = isset($p['id']) ? (int)$p['id'] : null;
            // title
            $title = $p['title'];
            if ($title === null || trim((string)$title) === '') {
                $add('title_missing', $u, $id, 'No <title> found');
            } else {
                $n = mb_strlen($title, 'UTF-8');
                if ($n < self::TITLE_MIN) { $add('title_short', $u, $id, "Title is $n characters (aim for " . self::TITLE_MIN . '-60): ' . $title, 'short'); }
                if ($n > self::TITLE_MAX) { $add('title_long', $u, $id, "Title is $n characters (search results cut off around 60): " . $title, 'long'); }
            }
            // description
            $meta = $p['meta_desc'];
            if ($meta === null || trim((string)$meta) === '') {
                $add('meta_missing', $u, $id, 'No meta description found');
            } else {
                $n = mb_strlen($meta, 'UTF-8');
                if ($n < self::META_MIN) { $add('meta_short', $u, $id, "Description is $n characters (aim for " . self::META_MIN . '-155)', 'short'); }
                if ($n > self::META_MAX) { $add('meta_long', $u, $id, "Description is $n characters (search results cut off around 155)", 'long'); }
            }
            // headings
            $h1c = isset($p['h1_count']) && $p['h1_count'] !== null ? (int)$p['h1_count'] : (($p['h1'] ?? null) !== null ? 1 : 0);
            if ($h1c === 0) { $add('h1_missing', $u, $id, 'No <h1> main heading found'); }
            if ($h1c > 1)   { $add('h1_multiple', $u, $id, "$h1c main headings found (one is expected)"); }
            // canonical
            $can = $p['canonical'];
            if ($can === null || trim((string)$can) === '') {
                $add('canonical_missing', $u, $id, 'No canonical link found');
            } else {
                $abs = self::resolve($u, trim((string)$can));
                $ch = strtolower((string)parse_url($abs, PHP_URL_HOST));
                if ($ch !== '' && $stripWww($ch) !== $stripWww($host)) {
                    $add('canonical_offsite', $u, $id, "Canonical points to $can", 'offsite');
                } else {
                    $norm = function (string $x): string { return rtrim((string)preg_replace('/[?#].*$/', '', $x), '/'); };
                    $same = function (string $a, string $b) use ($norm, $stripWww): bool {
                        $strip = function (string $x) use ($stripWww): string {
                            $pp = parse_url($x);
                            return strtolower($stripWww((string)($pp['host'] ?? ''))) . ($pp['path'] ?? '');
                        };
                        return rtrim($strip($norm($a)), '/') === rtrim($strip($norm($b)), '/');
                    };
                    if (!$same($abs, $u)) {
                        $clean = (string)preg_replace('/[?#].*$/', '', $abs);
                        $target = $byHash[sha1($clean)] ?? $byHash[sha1(rtrim($clean, '/'))] ?? $byHash[sha1(rtrim($clean, '/') . '/')] ?? null;
                        if ($target !== null && (int)$target['status_code'] !== 200) {
                            $add('canonical_to_broken', $u, $id, "Canonical points to $can, which returns " . (int)$target['status_code'], 'broken');
                        } else {
                            $add('canonical_elsewhere', $u, $id, "Canonical points to $can", 'elsewhere');
                        }
                    }
                }
            }
            // thin content
            if ($p['word_count'] !== null && (int)$p['word_count'] < self::THIN_WORDS) {
                $add('thin_content', $u, $id, (int)$p['word_count'] . ' words of text (under ' . self::THIN_WORDS . ')');
            }
            // images
            if (isset($p['images_no_alt']) && (int)$p['images_no_alt'] > 0) {
                $tot = max(1, (int)$p['images_total']); $no = (int)$p['images_no_alt'];
                $add('images_missing_alt', $u, $id, "$no of $tot images have no alt text", $no * 2 >= $tot ? 'most' : 'some');
            }
            // phones and language
            if (isset($p['has_viewport']) && $p['has_viewport'] !== null && (int)$p['has_viewport'] === 0) {
                $add('viewport_missing', $u, $id, 'No mobile viewport tag found');
            }
            if (($p['h1_count'] ?? null) !== null && ($p['lang'] ?? null) === null) {          // h1_count is NULL on rows the crawler stored before these checks existed
                $add('lang_missing', $u, $id, 'No lang attribute on <html>');
            }
            // business data on the home page only
            if ($isHome($p)) {
                $types = array_filter(explode(',', (string)($p['schema_types'] ?? '')));
                $hasBiz = false;
                foreach ($types as $t) { if (preg_match('/(Business|Organization|Corporation|Store)$/', $t)) { $hasBiz = true; } }
                if (!$hasBiz) {
                    $add('schema_missing_home', $u, $id, $types ? 'Structured data found (' . implode(', ', $types) . ') but no business or organization type' : 'No structured data found');
                }
            }
            // orphan (not the home page)
            if (!$isHome($p) && empty($inbound[$p['url_hash']])) {
                $add('orphan_page', $u, $id, 'No other crawled page links here');
            }
        }

        // home page told to stay out of search
        foreach ($pages as $p) {
            if ($isHome($p) && (int)$p['status_code'] === 200 && $isNoindex($p)) {
                $add('noindex_home', $p['url'], isset($p['id']) ? (int)$p['id'] : null, 'Robots meta: ' . $p['robots_meta']);
            }
        }

        // duplicates across the live pages
        $group = function (string $field) use ($live): array {
            $g = [];
            foreach ($live as $p) {
                $v = $p[$field];
                if ($v === null || trim((string)$v) === '') { continue; }
                $g[mb_strtolower(trim((string)$v))][] = $p;
            }
            return array_filter($g, function ($x) { return count($x) > 1; });
        };
        foreach ($group('title') as $key => $members) {
            foreach ($members as $p) {
                $add('title_duplicate', $p['url'], isset($p['id']) ? (int)$p['id'] : null, 'Same title as ' . (count($members) - 1) . ' other page(s): ' . $p['title'], sha1($key));
            }
        }
        foreach ($group('meta_desc') as $key => $members) {
            foreach ($members as $p) {
                $add('meta_duplicate', $p['url'], isset($p['id']) ? (int)$p['id'] : null, 'Same description as ' . (count($members) - 1) . ' other page(s)', sha1($key));
            }
        }
        foreach ($group('content_hash') as $key => $members) {
            foreach ($members as $p) {
                $add('duplicate_content', $p['url'], isset($p['id']) ? (int)$p['id'] : null, 'Identical content on ' . count($members) . ' addresses', (string)$key);
            }
        }

        // broken, failing and redirected addresses
        foreach ($pages as $p) {
            $code = (int)$p['status_code'];
            $id = isset($p['id']) ? (int)$p['id'] : null;
            list($n, $sample) = $linkedFrom($p['url_hash']);
            if ($code === 404 || $code === 410) {
                $add('broken_page', $p['url'], $id, "Returns $code. " . ($n ? "Linked from $n page(s), for example " . implode(', ', $sample) : 'Listed in the sitemap or seen on an earlier visit'));
            } elseif ($code >= 500) {
                $add('server_error', $p['url'], $id, "Returns $code. " . ($n ? "Linked from $n page(s)" : 'Not linked from crawled pages'));
            } elseif ($code === 301 || $code === 302 || $code === 307 || $code === 308) {
                if ($n > 0) { $add('link_to_redirect', $p['url'], $id, "$n page(s) link to this address, which redirects, for example " . implode(', ', $sample)); }
            }
        }

        // freshness (whole site)
        $latest = null;
        foreach ($live as $p) {
            if (!empty($p['last_changed_at']) && ($latest === null || $p['last_changed_at'] > $latest)) { $latest = $p['last_changed_at']; }
        }
        if ($latest !== null) {
            try {
                $days = (int)floor(($now->getTimestamp() - (new DateTimeImmutable($latest))->getTimestamp()) / 86400);
                if ($days > self::STALE_DAYS) { $add('site_stale', $base . '/', null, "The newest change we saw was $days days ago"); }
            } catch (Throwable $e) { /* unparseable date: no finding */ }
        }
        return $out;
    }

    /** A canonical href resolved against the page it was found on (handles ?query, #fragment, //host, /path, relative). */
    private static function resolve(string $pageUrl, string $href): string
    {
        if ($href === '') { return $pageUrl; }
        if ($href[0] === '#') { return $pageUrl; }
        if ($href[0] === '?') { return (string)preg_replace('/[?#].*$/', '', $pageUrl) . $href; }
        return SafeHttpFetcher::resolveLocation($pageUrl, $href) ?? $href;
    }

    // ---- persist ----------------------------------------------------------------------------------

    /**
     * Evaluate the stored inventory of one website and sync mia_audit_findings.
     * @return array{ok:bool,code:?string,error:?string,audit_id:?int,total:int,new:int,still_open:int,resolved:int,by_severity:array}
     */
    public function run(int $websiteId, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $res = ['ok' => false, 'code' => null, 'error' => null, 'audit_id' => null, 'total' => 0, 'new' => 0, 'still_open' => 0, 'resolved' => 0,
                'by_severity' => ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0]];
        $when = $now->format('Y-m-d H:i:s');
        $own = false;
        $began = false;
        $sp = '';
        try {
            $s = $this->db->prepare("SELECT id, base_url, status FROM mia_websites WHERE id = ? AND site_id = ?");
            $s->execute([$websiteId, $this->siteId]);
            $w = $s->fetch(PDO::FETCH_ASSOC);
            if (!$w || $w['status'] === 'disconnected') { $res['code'] = 'not_found'; $res['error'] = 'No such website'; return $res; }

            $cut = $now->modify('-' . self::IGNORE_UNSEEN_DAYS . ' days')->format('Y-m-d H:i:s');
            $q = $this->db->prepare("SELECT id, url_hash, url, status_code, content_hash, title, meta_desc, h1, canonical, robots_meta, word_count, schema_types,
                                            h1_count, images_total, images_no_alt, has_viewport, lang, last_changed_at
                                       FROM mia_site_pages WHERE site_id = ? AND website_id = ? AND last_seen_at >= ?");
            $q->execute([$this->siteId, $websiteId, $cut]);
            $pages = $q->fetchAll(PDO::FETCH_ASSOC);
            $e = $this->db->prepare("SELECT from_hash, to_hash FROM mia_site_links WHERE site_id = ? AND website_id = ?");
            $e->execute([$this->siteId, $websiteId]);
            $edges = array_map(function ($r) { return [$r['from_hash'], $r['to_hash']]; }, $e->fetchAll(PDO::FETCH_ASSOC));

            $findings = self::evaluate($pages, $edges, ['base_url' => $w['base_url'], 'now' => $now]);
            // one row per fingerprint
            $current = [];
            foreach ($findings as $f) { $current[$f['fingerprint']] = $f; }

            $own = !$this->db->inTransaction();
            $sp  = 'mia_audit_' . substr(md5((string)mt_rand()), 0, 8);
            if ($own) { $this->db->beginTransaction(); } else { $this->db->exec("SAVEPOINT {$sp}"); }
            $began = true;

            $this->db->prepare("INSERT INTO mia_audits (site_id, website_id, kind, status, pages, findings, started_at) VALUES (?, ?, 'rules', 'running', ?, 0, ?)")
                ->execute([$this->siteId, $websiteId, count($pages), $when]);
            $auditId = (int)$this->db->lastInsertId();
            $res['audit_id'] = $auditId;

            // Only findings from THIS rule set are compared and resolved: another collector's open findings
            // (page speed, accessibility, ...) were not evaluated here and must be left alone.
            $o = $this->db->prepare("SELECT id, fingerprint, rule_key FROM mia_audit_findings WHERE site_id = ? AND website_id = ? AND resolved_at IS NULL");
            $o->execute([$this->siteId, $websiteId]);
            $open = [];
            foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset(self::RULES[$r['rule_key']])) { $open[$r['fingerprint']] = (int)$r['id']; }
            }

            $upd = $this->db->prepare("UPDATE mia_audit_findings SET audit_id = ?, page_id = ?, severity = ?, evidence = ? WHERE id = ? AND site_id = ?");
            $ins = $this->db->prepare("INSERT INTO mia_audit_findings (site_id, website_id, audit_id, page_id, rule_key, severity, evidence, fingerprint, first_seen_audit_id)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($current as $fp => $f) {
                if (isset($open[$fp])) {
                    $upd->execute([$auditId, $f['page_id'], $f['severity'], $f['evidence'], $open[$fp], $this->siteId]);
                    $res['still_open']++;
                    unset($open[$fp]);
                } else {
                    $ins->execute([$this->siteId, $websiteId, $auditId, $f['page_id'], $f['rule_key'], $f['severity'], $f['evidence'], $fp, $auditId]);
                    $res['new']++;
                }
                $res['by_severity'][$f['severity']]++;
            }
            $done = $this->db->prepare("UPDATE mia_audit_findings SET resolved_at = ? WHERE id = ? AND site_id = ? AND resolved_at IS NULL");
            foreach ($open as $fp => $id) { $done->execute([$when, $id, $this->siteId]); $res['resolved']++; }

            $res['total'] = count($current);
            $summary = sprintf('%d pages checked: %d findings (%d high, %d medium, %d low, %d info). %d new, %d resolved.',
                count($pages), $res['total'], $res['by_severity']['high'], $res['by_severity']['medium'], $res['by_severity']['low'],
                $res['by_severity']['info'], $res['new'], $res['resolved']);
            $this->db->prepare("UPDATE mia_audits SET status = 'done', findings = ?, finished_at = ?, summary = ? WHERE id = ? AND site_id = ?")
                ->execute([$res['total'], $when, $summary, $auditId, $this->siteId]);
            if ($own) { $this->db->commit(); } else { $this->db->exec("RELEASE SAVEPOINT {$sp}"); }
            $res['ok'] = true;
            return $res;
        } catch (Throwable $e) {
            if ($began) {
                try {
                    if ($own) { if ($this->db->inTransaction()) { $this->db->rollBack(); } } else { $this->db->exec("ROLLBACK TO SAVEPOINT {$sp}"); }
                } catch (Throwable $ignore) { /* original error matters */ }
            }
            error_log('SiteAuditRules: ' . $e->getMessage());
            $res['ok'] = false;
            $res['code'] = 'error';
            $res['error'] = 'The checks could not be completed';
            return $res;
        }
    }

    /** Open findings, worst first. @return array<int,array> */
    public function openFindings(int $websiteId, ?string $severity = null, int $limit = 100): array
    {
        try {
            $sql = "SELECT f.id, f.rule_key, f.severity, f.evidence, f.page_id, f.first_seen_audit_id, f.audit_id, p.url
                      FROM mia_audit_findings f LEFT JOIN mia_site_pages p ON p.id = f.page_id AND p.site_id = f.site_id
                     WHERE f.site_id = ? AND f.website_id = ? AND f.resolved_at IS NULL" . ($severity !== null ? " AND f.severity = ?" : '') . "
                     ORDER BY CASE f.severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END, f.rule_key, f.id
                     LIMIT " . max(1, min(500, $limit));
            $s = $this->db->prepare($sql);
            $s->execute($severity !== null ? [$this->siteId, $websiteId, $severity] : [$this->siteId, $websiteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
