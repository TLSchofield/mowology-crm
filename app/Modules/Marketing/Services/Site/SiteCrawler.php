<?php
/**
 * SiteCrawler — polite, bounded, change-tracking crawl of ONE verified website (Phase 1.7).
 *
 * What it guarantees:
 *   - only a website the owner has proved they control (MiaWebsiteService::requireVerified);
 *   - every request goes through SafeHttpFetcher (no private addresses, pinned IPs, size/time caps);
 *   - robots.txt is obeyed (our agent group, else "*"; longest rule wins; Crawl-delay honoured up to 10 s;
 *     a 5xx/unreachable robots.txt means "do not crawl this run");
 *   - at most one request per DELAY_MIN seconds, doubled after a 429/503, and the crawl stops after 3
 *     bad answers in a row;
 *   - hard limits: pages (website.crawl_cap, never above HARD_MAX_PAGES), run time (max_seconds), response
 *     size, redirects; only the exact verified host is followed; URLs with query strings are skipped
 *     (crawl-trap protection);
 *   - unchanged pages are not re-processed: known pages are asked with If-None-Match / If-Modified-Since
 *     and a 304 costs one tiny request; changed pages are detected by a content hash.
 *
 * Page text is stored only as short fields (title, description, h1, canonical, counts), never the body, and
 * is treated as untrusted data everywhere downstream. Nothing here writes to the website.
 *
 * Every query binds site_id. crawl() returns a result array and does not throw (the constructor refuses
 * site 0). Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../Services/Net/SafeHttpFetcher.php';
require_once __DIR__ . '/../MiaWebsiteService.php';
require_once __DIR__ . '/RobotsParser.php';

final class SiteCrawler
{
    public const DELAY_MIN = 1.0;
    public const DELAY_MAX = 10.0;         // longest Crawl-delay we honour
    public const BACKOFF_MAX = 30.0;
    public const HARD_MAX_PAGES = 500;
    public const MAX_SECONDS = 240;
    public const MAX_SITEMAP_FILES = 10;
    public const MAX_SITEMAP_URLS = 5000;
    public const MAX_LINKS_PER_PAGE = 500;
    public const PAGE_BYTES = 1048576;
    public const SITEMAP_BYTES = 5242880;
    public const STOP_AFTER_BAD = 3;
    public const SKIP_EXT = 'jpg|jpeg|png|gif|webp|svg|ico|bmp|avif|pdf|zip|gz|rar|7z|mp3|mp4|mov|avi|wmv|webm|doc|docx|xls|xlsx|ppt|pptx|csv|xml|json|txt|css|js|woff|woff2|ttf|eot|rss|atom';

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var MiaWebsiteService */
    private $websites;
    /** @var SafeHttpFetcher */
    private $fetcher;
    /** @var callable fn(float $seconds): void */
    private $sleep;
    /** @var callable fn(): float  monotonic-ish seconds */
    private $clock;
    /** @var callable fn(): DateTimeImmutable */
    private $now;

    public function __construct(PDO $db, int $siteId, MiaWebsiteService $websites, ?SafeHttpFetcher $fetcher = null,
                                ?callable $sleep = null, ?callable $clock = null, ?callable $now = null)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('SiteCrawler needs a site'); }
        $this->db       = $db;
        $this->siteId   = $siteId;
        $this->websites = $websites;
        $this->fetcher  = $fetcher ?: new SafeHttpFetcher(['allowed_types' => ['text/html', 'application/xhtml+xml'], 'max_bytes' => self::PAGE_BYTES, 'timeout' => 15]);
        $this->sleep    = $sleep ?: function (float $s) { usleep((int)($s * 1000000)); };
        $this->clock    = $clock ?: function (): float { return microtime(true); };
        $this->now      = $now ?: function (): DateTimeImmutable { return new DateTimeImmutable('now'); };
    }

    // ---- pure helpers (unit-tested directly) --------------------------------------------------

    /**
     * An href found on $pageUrl -> a crawlable URL on this site, or null.
     * Same exact host only, origin's scheme and port forced, fragment dropped, dot-segments resolved,
     * no query string, no file extensions that are not pages, max 500 characters.
     */
    public static function normalizeUrl(string $href, string $pageUrl, string $origin): ?string
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#' || strlen($href) > 800 || preg_match('/[\x00-\x1f\x7f\s]/', $href)) { return null; }
        if (preg_match('#^(?:mailto|tel|javascript|data|sms|geo|whatsapp):#i', $href)) { return null; }
        $abs = SafeHttpFetcher::resolveLocation($pageUrl, $href);
        if ($abs === null) { return null; }
        $p = parse_url($abs);
        $o = parse_url($origin);
        if ($p === false || $o === false || empty($p['host']) || empty($p['scheme'])) { return null; }
        if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) { return null; }
        if (isset($p['user']) || isset($p['pass'])) { return null; }
        if (strtolower(rtrim($p['host'], '.')) !== strtolower($o['host'])) { return null; }
        if (isset($p['query'])) { return null; }
        $path = RobotsParser::normalizePercent($p['path'] ?? '/');
        $path = self::removeDotSegments($path === '' ? '/' : $path);
        if ($path === null || preg_match('#\.(?:' . self::SKIP_EXT . ')$#i', $path)) { return null; }
        $url = rtrim($origin, '/') . $path;
        return strlen($url) > 500 ? null : $url;
    }

    /** RFC 3986 dot-segment removal. Null if the path climbs above the root. */
    public static function removeDotSegments(string $path): ?string
    {
        $out = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $seg) {
            if ($seg === '.' ) { if ($i === $last) { $out[] = ''; } continue; }
            if ($seg === '..') {
                if (!$out || (count($out) === 1 && $out[0] === '')) { return null; }
                array_pop($out);
                if ($i === $last) { $out[] = ''; }
                continue;
            }
            $out[] = $seg;
        }
        $r = implode('/', $out);
        return $r === '' ? '/' : ($r[0] === '/' ? $r : '/' . $r);
    }

    /**
     * Pull the audit fields and outgoing links out of a page.
     * @return array{title:?string,meta_desc:?string,h1:?string,canonical:?string,robots_meta:?string,word_count:int,
     *               schema_types:?string,content_hash:string,links:string[],nofollow:bool,noindex:bool}
     */
    public static function extract(string $html, string $pageUrl, string $origin, ?string $charset = null): array
    {
        if ($charset !== null && !preg_match('/^utf-?8$/i', $charset) && in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $conv = @mb_convert_encoding($html, 'UTF-8', $charset);
            if (is_string($conv)) { $html = $conv; }
        }
        $clean = function (?string $s, int $max): ?string {
            if ($s === null) { return null; }
            $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s) ?? '';
            $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
            return $s === '' ? null : mb_substr($s, 0, $max);
        };
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                return self::emptyExtract();
            }
            $xp = new DOMXPath($doc);
            $first = function (string $q) use ($xp): ?string {
                $n = $xp->query($q);
                return $n && $n->length ? (string)$n->item(0)->textContent : null;
            };
            $meta = function (string $name) use ($xp): ?string {
                foreach ($xp->query('//head/meta') as $m) {
                    /** @var DOMElement $m */
                    if (strtolower(trim($m->getAttribute('name'))) === $name) { return $m->getAttribute('content'); }
                }
                return null;
            };
            $title     = $clean($first('//head/title'), 300);
            $desc      = $clean($meta('description'), 500);
            $h1        = $clean($first('//h1'), 300);
            $robotsRaw = strtolower((string)$meta('robots'));
            $robots    = $clean($robotsRaw, 100);

            $canonical = null;
            foreach ($xp->query('//head/link') as $l) {
                /** @var DOMElement $l */
                if (in_array('canonical', preg_split('/\s+/', strtolower(trim($l->getAttribute('rel')))) ?: [], true)) {
                    $canonical = $clean($l->getAttribute('href'), 1000);
                    break;
                }
            }

            // JSON-LD types (data only; never executed)
            $types = [];
            foreach ($xp->query('//script[@type="application/ld+json"]') as $sc) {
                $j = json_decode((string)$sc->textContent, true);
                if (is_array($j)) { self::collectTypes($j, $types, 0); }
            }
            $types = array_slice(array_values(array_unique($types)), 0, 20);

            // visible text (for word count and the change hash)
            foreach (['script', 'style', 'noscript', 'template'] as $tag) {
                foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) { $n->parentNode->removeChild($n); }
            }
            $bodyNode = $doc->getElementsByTagName('body')->item(0);
            $text = $bodyNode ? preg_replace('/\s+/u', ' ', (string)$bodyNode->textContent) : '';
            $text = trim((string)$text);           // the whole visible text: a change anywhere must change the hash
            $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

            $nofollow = (bool)preg_match('/\bnofollow\b|\bnone\b/', $robotsRaw);
            $noindex  = (bool)preg_match('/\bnoindex\b|\bnone\b/', $robotsRaw);

            $links = [];
            if (!$nofollow) {
                foreach ($xp->query('//a[@href]') as $a) {
                    /** @var DOMElement $a */
                    if (preg_match('/\bnofollow\b/i', $a->getAttribute('rel'))) { continue; }
                    $u = self::normalizeUrl($a->getAttribute('href'), $pageUrl, $origin);
                    if ($u !== null) { $links[$u] = true; }
                    if (count($links) >= self::MAX_LINKS_PER_PAGE) { break; }
                }
            }
            return [
                'title' => $title, 'meta_desc' => $desc, 'h1' => $h1, 'canonical' => $canonical, 'robots_meta' => $robots,
                'word_count' => $words, 'schema_types' => $types ? implode(',', $types) : null,
                'content_hash' => sha1(implode("\x1f", [$title, $desc, $h1, $canonical, $robots, implode(',', $types), $text])),
                'links' => array_keys($links), 'nofollow' => $nofollow, 'noindex' => $noindex,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private static function emptyExtract(): array
    {
        return ['title' => null, 'meta_desc' => null, 'h1' => null, 'canonical' => null, 'robots_meta' => null, 'word_count' => 0,
                'schema_types' => null, 'content_hash' => sha1(''), 'links' => [], 'nofollow' => false, 'noindex' => false];
    }

    private static function collectTypes($node, array &$types, int $depth): void
    {
        if ($depth > 4 || !is_array($node)) { return; }
        foreach ($node as $k => $v) {
            if ($k === '@type') {
                foreach ((array)$v as $t) { if (is_string($t) && preg_match('/^[A-Za-z][A-Za-z0-9_:.\/-]{0,60}$/', $t)) { $types[] = $t; } }
            } elseif (is_array($v)) {
                self::collectTypes($v, $types, $depth + 1);
            }
        }
    }

    /**
     * URLs from a sitemap or sitemap index. XML is parsed with no network and no entity substitution.
     * @return array{urls:string[],sitemaps:string[]}
     */
    public static function parseSitemap(string $xml): array
    {
        $out = ['urls' => [], 'sitemaps' => []];
        if ($xml === '' || stripos($xml, '<!ENTITY') !== false) { return $out; }      // no entity tricks at all
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) { return $out; }
            $root = $doc->documentElement;
            if (!$root) { return $out; }
            $isIndex = strtolower($root->localName) === 'sitemapindex';
            foreach ($doc->getElementsByTagName('loc') as $loc) {
                $u = trim((string)$loc->textContent);
                if ($u === '' || strlen($u) > 800) { continue; }
                if ($isIndex) { $out['sitemaps'][] = $u; } else { $out['urls'][] = $u; }
                if (count($out['urls']) >= self::MAX_SITEMAP_URLS) { break; }
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    // ---- the crawl ----------------------------------------------------------------------------

    /**
     * @param array $opts  max_seconds (int, default 240)
     * @return array{ok:bool,code:?string,error:?string,audit_id:?int,status:?string,pages:int,changed:int,unchanged:int,
     *               new:int,broken:int,blocked_by_robots:int,skipped:int,errors:int,summary:?string}
     */
    public function crawl(int $websiteId, array $opts = []): array
    {
        $res = $this->blank();
        $lock = null;
        $auditId = null;
        $status = 'failed';
        try {
            $w = $this->websites->requireVerified($websiteId, ($this->now)());
            if (!$w) { return $this->fail($res, 'not_verified', 'This website has not been verified (or the proof has expired)'); }

            $lock = 'mia_crawl_' . md5($this->siteId . '|' . $websiteId);
            if (!$this->acquire($lock)) { $lock = null; return $this->fail($res, 'busy', 'A crawl of this website is already running'); }

            $this->db->prepare("INSERT INTO mia_audits (site_id, website_id, kind, status, pages, findings, started_at) VALUES (?, ?, 'crawl', 'running', 0, 0, ?)")
                ->execute([$this->siteId, $websiteId, ($this->now)()->format('Y-m-d H:i:s')]);
            $auditId = (int)$this->db->lastInsertId();
            $res['audit_id'] = $auditId;

            $res = $this->run($w, $res, $opts);
            $status = $res['status'] ?? 'done';
        } catch (Throwable $e) {
            error_log('SiteCrawler: ' . $e->getMessage());
            $res['code'] = 'error';
            $res['error'] = 'The crawl stopped unexpectedly';
            $status = 'failed';
            if ($auditId === null) { return $res; }
        } finally {
            if ($lock !== null) { $this->release($lock); }
        }
        $res['status'] = $status;
        $res['summary'] = $res['summary'] ?? ($res['note'] ? $res['note'] . '. ' : '') . $this->summarise($res);
        if ($auditId !== null) {
            try {
                $this->db->prepare("UPDATE mia_audits SET status = ?, pages = ?, finished_at = ?, summary = ? WHERE id = ? AND site_id = ?")
                    ->execute([$status, $res['pages'], ($this->now)()->format('Y-m-d H:i:s'), mb_substr((string)$res['summary'], 0, 400), $auditId, $this->siteId]);
            } catch (Throwable $e) {
                error_log('SiteCrawler audit close: ' . $e->getMessage());
            }
        }
        $res['ok'] = $status !== 'failed';
        return $res;
    }

    private function run(array $w, array $res, array $opts): array
    {
        $origin = (string)$w['base_url'];
        $host   = (string)$w['host'];
        $cap    = max(1, min(self::HARD_MAX_PAGES, (int)($w['crawl_cap'] ?: 200)));
        $budget = max(5, min(self::MAX_SECONDS, (int)($opts['max_seconds'] ?? self::MAX_SECONDS)));
        $t0     = ($this->clock)();
        $delay  = self::DELAY_MIN;
        $lastRequestAt = null;

        $remaining = function () use ($t0, $budget): float { return $budget - (($this->clock)() - $t0); };
        // Wait out the politeness delay, but never past the end of the run. false = no time left.
        $throttle = function () use (&$lastRequestAt, &$delay, $remaining): bool {
            if ($lastRequestAt !== null) {
                $wait = $delay - (($this->clock)() - $lastRequestAt);
                if ($wait > 0) {
                    if ($wait >= $remaining()) { return false; }
                    ($this->sleep)($wait);
                }
            }
            $lastRequestAt = ($this->clock)();
            return $remaining() > 0;
        };
        $limits = function (array $extra = []) use ($remaining, $host): array {
            return array_merge(['same_host' => $host, 'timeout' => max(0.3, min(15.0, $remaining()))], $extra);
        };
        $retryAfter = function (array $headers): ?float {
            $v = trim((string)($headers['retry-after'] ?? ''));
            if ($v === '') { return null; }
            if (ctype_digit($v)) { return (float)$v; }
            $ts = strtotime($v);                                   // HTTP-date form
            return $ts === false ? null : max(0.0, (float)($ts - ($this->now)()->getTimestamp()));
        };

        // robots.txt first
        $throttle();
        $r = $this->fetcher->fetch($origin . '/robots.txt', $limits(['allowed_types' => null, 'max_bytes' => RobotsParser::MAX_BYTES]));
        if ($r['ok'] && !MiaWebsiteService::sameSite($host, $r['url'])) {
            $robots = RobotsParser::disallowAll();                       // robots.txt moved to another host: do not guess
        } elseif ($r['ok']) {
            $robots = RobotsParser::forStatus((int)$r['status'], (string)$r['body']);
            $this->touch('mia_websites', (int)$w['id'], 'robots_hash', sha1((string)$r['body']));
        } else {
            $robots = RobotsParser::disallowAll();                       // cannot read it: crawl nothing this run
        }
        if ($robots->blocksEverything()) {
            $res['status'] = 'done';
            $res['summary'] = $r['ok'] ? 'robots.txt does not allow crawling this site' : 'robots.txt could not be read, so nothing was crawled';
            return $res;
        }
        if ($robots->crawlDelay() !== null) { $delay = max(self::DELAY_MIN, min(self::DELAY_MAX, $robots->crawlDelay())); }

        // known inventory (so link discovery continues past pages answered with 304)
        $known = [];
        $kq = $this->db->prepare("SELECT url_hash, url, etag, last_modified, content_hash, status_code FROM mia_site_pages WHERE site_id = ? AND website_id = ?");
        $kq->execute([$this->siteId, (int)$w['id']]);
        foreach ($kq->fetchAll(PDO::FETCH_ASSOC) as $k) { $known[$k['url_hash']] = $k; }

        $queue = [$origin . '/'];
        $seen  = [];
        // Admission is bounded: a page can list 500 links, so without this a 500-page crawl could queue
        // hundreds of thousands of addresses it will never fetch.
        $maxAdmit = $cap * 4;
        $enqueue = function (string $u) use (&$queue, &$seen, $maxAdmit) {
            $h = sha1($u);
            if (!isset($seen[$h]) && count($seen) < $maxAdmit) { $seen[$h] = true; $queue[] = $u; }
        };
        $seen[sha1($origin . '/')] = true;

        // sitemaps
        $maps = [];
        foreach ($robots->sitemaps() as $sm) {
            $n = MiaWebsiteService::normalizeOrigin($sm);
            if ($n && $n['host'] === $host) { $maps[] = $sm; }
        }
        if (!$maps) { $maps[] = $origin . '/sitemap.xml'; }
        $mapsDone = 0;
        while ($maps && $mapsDone < self::MAX_SITEMAP_FILES && (($this->clock)() - $t0) < $budget) {
            $mapUrl = array_shift($maps);
            $mapsDone++;
            if (!$throttle()) { break; }
            $m = $this->fetcher->fetch($mapUrl, $limits(['allowed_types' => null, 'max_bytes' => self::SITEMAP_BYTES]));
            if ($m['ok'] && ($m['status'] === 429 || $m['status'] === 503)) {            // asked to slow down: stop asking for sitemaps
                $delay = min(self::BACKOFF_MAX, max($delay * 2, $retryAfter($m['headers']) ?? 0));
                break;
            }
            if (!$m['ok'] || $m['status'] !== 200 || !MiaWebsiteService::sameSite($host, $m['url'])) { continue; }
            $parsed = self::parseSitemap((string)$m['body']);
            foreach ($parsed['sitemaps'] as $sub) {
                $n = MiaWebsiteService::normalizeOrigin($sub);
                if ($n && $n['host'] === $host && count($maps) + $mapsDone < self::MAX_SITEMAP_FILES * 2) { $maps[] = $sub; }
            }
            foreach ($parsed['urls'] as $u) {
                $nu = self::normalizeUrl($u, $origin . '/', $origin);
                if ($nu !== null) { $enqueue($nu); }
            }
        }
        foreach ($known as $k) {
            $nu = self::normalizeUrl((string)$k['url'], $origin . '/', $origin);
            if ($nu !== null) { $enqueue($nu); }
        }

        // pages
        $bad = 0;
        $done = [];                      // addresses already processed this run (a redirect target may also be queued)
        $res['status'] = 'done';
        while ($queue) {
            if ($res['pages'] >= $cap) { $res['status'] = 'partial'; $res['note'] = 'Stopped at the page limit (' . $cap . ')'; break; }
            if ((($this->clock)() - $t0) >= $budget) { $res['status'] = 'partial'; $res['note'] = 'Stopped at the time limit'; break; }
            $url = array_shift($queue);
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '/');
            if (!$robots->isAllowed($path)) { $res['blocked_by_robots']++; continue; }

            $hash = sha1($url);
            if (isset($done[$hash])) { continue; }
            $done[$hash] = true;
            $kn = $known[$hash] ?? null;
            $headers = [];
            if ($kn && (int)$kn['status_code'] === 200 && $kn['content_hash']) {
                if (!empty($kn['etag'])) { $headers['if-none-match'] = $kn['etag']; }
                if (!empty($kn['last_modified'])) { $headers['if-modified-since'] = $kn['last_modified']; }
            }
            if (!$throttle()) { $res['status'] = 'partial'; $res['note'] = 'Stopped at the time limit'; break; }
            $f = $this->fetcher->fetch($url, $limits(['headers' => $headers]));
            $res['pages']++;
            $now = ($this->now)()->format('Y-m-d H:i:s');

            if (!$f['ok']) {
                if ($f['code'] === 'bad_content_type') { $res['skipped']++; continue; }       // an image, a PDF: not a page
                if ($f['code'] === 'off_site') {                                               // redirects away from the verified host
                    $this->savePage((int)$w['id'], $url, 301, null, $kn, $now, false);
                    $res['skipped']++;
                    continue;
                }
                $res['errors']++;
                if (in_array($f['code'], ['timeout', 'transport', 'too_many_redirects'], true)) { $bad++; } else { $bad = 0; }
                if ($bad >= self::STOP_AFTER_BAD) { $res['status'] = 'partial'; $res['note'] = 'Stopped after repeated errors from the site'; break; }
                continue;
            }
            $status = (int)$f['status'];
            if ($status === 429 || $status === 503 || $status >= 500) {
                $res['errors']++;
                $bad++;
                $ra = $retryAfter($f['headers']);
                if ($ra !== null && $ra > 60) { $res['status'] = 'partial'; $res['note'] = 'The site asked us to come back later'; break; }
                $delay = min(self::BACKOFF_MAX, max($delay * 2, $ra ?? 0));
                if ($bad >= self::STOP_AFTER_BAD) { $res['status'] = 'partial'; $res['note'] = 'The site asked us to slow down, so the crawl was stopped'; break; }
                continue;
            }
            $bad = 0;

            if ($status === 304 && $kn) {
                $this->db->prepare("UPDATE mia_site_pages SET last_seen_at = ?, last_crawl_at = ? WHERE site_id = ? AND website_id = ? AND url_hash = ?")
                    ->execute([$now, $now, $this->siteId, (int)$w['id'], $hash]);
                $res['unchanged']++;
                continue;
            }

            // redirected: remember the old address as a redirect, process the final page under its own address
            $finalUrl = $url;
            if ($f['url'] !== $url) {
                $this->savePage((int)$w['id'], $url, 301, null, $kn, $now, false);
                $fu = self::normalizeUrl($f['url'], $origin . '/', $origin);
                if ($fu === null || !MiaWebsiteService::sameSite($host, $f['url'])) { $res['skipped']++; continue; }
                $finalUrl = $fu;
                $seen[sha1($fu)] = true;
                if (isset($done[sha1($fu)])) { $res['skipped']++; continue; }       // already handled earlier in this run
                $done[sha1($fu)] = true;
                $kn = $known[sha1($fu)] ?? null;
            }

            if ($status >= 400) {
                $this->savePage((int)$w['id'], $finalUrl, $status, null, $kn, $now, false);
                $res['broken']++;
                continue;
            }
            if ($status < 200 || $status >= 300) { $res['skipped']++; continue; }

            $charset = null;
            if (isset($f['headers']['content-type']) && preg_match('/charset=([\w\-]+)/i', $f['headers']['content-type'], $cm)) { $charset = $cm[1]; }
            $x = self::extract((string)$f['body'], $finalUrl, $origin, $charset);
            $x['etag'] = isset($f['headers']['etag']) ? mb_substr($f['headers']['etag'], 0, 190) : null;
            $x['last_modified'] = isset($f['headers']['last-modified']) ? mb_substr($f['headers']['last-modified'], 0, 60) : null;
            $kind = $this->savePage((int)$w['id'], $finalUrl, $status, $x, $kn, $now, true);
            $res[$kind]++;
            foreach ($x['links'] as $l) { $enqueue($l); }
        }
        return $res;
    }

    /** Insert or update one page row. @return string 'new' | 'changed' | 'unchanged' */
    private function savePage(int $websiteId, string $url, int $status, ?array $x, ?array $known, string $now, bool $isPage): string
    {
        $hash = sha1($url);
        $c = function ($v, int $n) { return $v === null ? null : mb_substr((string)$v, 0, $n); };
        $contentHash = $x['content_hash'] ?? null;
        $kind = !$known ? 'new' : (($isPage && $known['content_hash'] !== $contentHash) ? 'changed' : 'unchanged');
        if (!$known) {
            $this->db->prepare("INSERT INTO mia_site_pages (site_id, website_id, url_hash, url, status_code, content_hash, title, meta_desc, h1, canonical,
                                robots_meta, word_count, schema_types, etag, last_modified, first_seen_at, last_seen_at, last_changed_at, last_crawl_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$this->siteId, $websiteId, $hash, mb_substr($url, 0, 1000), $status, $contentHash,
                           $c($x['title'] ?? null, 300), $c($x['meta_desc'] ?? null, 500), $c($x['h1'] ?? null, 300), $c($x['canonical'] ?? null, 1000),
                           $c($x['robots_meta'] ?? null, 100), $x['word_count'] ?? null, $c($x['schema_types'] ?? null, 255),
                           $x['etag'] ?? null, $x['last_modified'] ?? null, $now, $now, $now, $now]);
            return 'new';
        }
        $this->db->prepare("UPDATE mia_site_pages SET status_code = ?, content_hash = ?, title = ?, meta_desc = ?, h1 = ?, canonical = ?, robots_meta = ?,
                            word_count = ?, schema_types = ?, etag = ?, last_modified = ?, last_seen_at = ?, last_crawl_at = ?,
                            last_changed_at = CASE WHEN ? = 1 THEN ? ELSE last_changed_at END
                            WHERE site_id = ? AND website_id = ? AND url_hash = ?")
            ->execute([$status, $contentHash, $c($x['title'] ?? null, 300), $c($x['meta_desc'] ?? null, 500), $c($x['h1'] ?? null, 300),
                       $c($x['canonical'] ?? null, 1000), $c($x['robots_meta'] ?? null, 100), $x['word_count'] ?? null,
                       $c($x['schema_types'] ?? null, 255), $x['etag'] ?? null, $x['last_modified'] ?? null, $now, $now,
                       $kind === 'changed' ? 1 : 0, $now, $this->siteId, $websiteId, $hash]);
        return $kind;
    }

    // ---- internals ----------------------------------------------------------------------------

    private function touch(string $table, int $id, string $column, string $value): void
    {
        if ($table !== 'mia_websites' || $column !== 'robots_hash') { return; }
        try {
            $this->db->prepare("UPDATE mia_websites SET robots_hash = ? WHERE id = ? AND site_id = ?")->execute([$value, $id, $this->siteId]);
        } catch (Throwable $e) { /* informational only */ }
    }

    private function acquire(string $name): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return true; }
        try {
            $s = $this->db->prepare("SELECT GET_LOCK(?, 0)");
            $s->execute([$name]);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function release(string $name): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { return; }
        try { $this->db->prepare("SELECT RELEASE_LOCK(?)")->execute([$name]); } catch (Throwable $e) { /* released on disconnect */ }
    }

    private function summarise(array $r): string
    {
        return sprintf('%d pages fetched: %d new, %d changed, %d unchanged, %d broken, %d blocked by robots.txt, %d errors',
            $r['pages'], $r['new'], $r['changed'], $r['unchanged'], $r['broken'], $r['blocked_by_robots'], $r['errors']);
    }

    private function blank(): array
    {
        return ['ok' => false, 'code' => null, 'error' => null, 'audit_id' => null, 'status' => null, 'pages' => 0, 'changed' => 0,
                'unchanged' => 0, 'new' => 0, 'broken' => 0, 'blocked_by_robots' => 0, 'skipped' => 0, 'errors' => 0, 'summary' => null, 'note' => null];
    }

    private function fail(array $res, string $code, string $error): array
    {
        $res['code'] = $code;
        $res['error'] = $error;
        return $res;
    }
}
