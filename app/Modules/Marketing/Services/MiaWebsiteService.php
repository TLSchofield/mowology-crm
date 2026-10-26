<?php
/**
 * MiaWebsiteService — connect a website to Mia and prove the owner controls it (Phase 1.6).
 *
 * Why verification: the crawler fetches pages on the owner's behalf. Without proof of control, anyone could
 * point Mia at someone else's site. Nothing may crawl a website until isVerified() is true; the crawler
 * (step 1.7) calls requireVerified() first.
 *
 * Three ways to prove it (the owner picks one):
 *   meta  <meta name="ittai-mia-verification" content="TOKEN"> on the home page
 *   file  https://host/.well-known/ittai-mia.txt containing the token
 *   dns   a TXT record "ittai-mia=TOKEN" on the host
 * All page fetches go through SafeHttpFetcher (no private addresses, pinned IPs, capped size/time).
 *
 * The connected address is only an origin (scheme://host[:port]). IP-address hosts are refused: a verified
 * site must be a real domain name. Redirects are followed only while they stay on the same host
 * (www. optional); a site that redirects elsewhere cannot vouch for itself.
 *
 * Every query binds site_id. Methods return result arrays and do not throw (the constructor refuses site 0).
 * Global namespace, no autoloader: require_once it. PHP 7.4-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../Services/Net/SafeHttpFetcher.php';

final class MiaWebsiteService
{
    public const METHODS = ['meta', 'file', 'dns'];
    public const FILE_PATH = '/.well-known/ittai-mia.txt';
    public const META_NAME = 'ittai-mia-verification';
    public const DNS_PREFIX = 'ittai-mia=';
    /** Capabilities by connector. Phase 1 reads only: nothing here can change a site. */
    public const CAPABILITIES = [
        'none' => 'canReadPages',
    ];
    /** A verified site is re-proved this often before a crawl (the token could have been removed). */
    public const REVERIFY_DAYS = 90;

    /** @var PDO */
    private $db;
    /** @var int */
    private $siteId;
    /** @var SafeHttpFetcher */
    private $fetcher;
    /** @var callable fn(string $host): string[]  TXT strings for the host */
    private $dnsTxt;
    /** @var callable fn(): string */
    private $tokenGen;

    public function __construct(PDO $db, int $siteId, ?SafeHttpFetcher $fetcher = null, ?callable $dnsTxt = null, ?callable $tokenGen = null)
    {
        if ($siteId <= 0) { throw new InvalidArgumentException('MiaWebsiteService needs a site'); }
        $this->db      = $db;
        $this->siteId  = $siteId;
        $this->fetcher = $fetcher ?: new SafeHttpFetcher(['allowed_types' => ['text/html', 'application/xhtml+xml', 'text/plain'], 'max_bytes' => 512 * 1024, 'timeout' => 10]);
        $this->dnsTxt  = $dnsTxt ?: [self::class, 'defaultDnsTxt'];
        $this->tokenGen = $tokenGen ?: function (): string { return bin2hex(random_bytes(16)); };
    }

    // ---- pure helpers -------------------------------------------------------------------------

    /**
     * What the owner typed -> an origin, or null. "Mowology.ca/services" -> "https://mowology.ca".
     * Refuses non-http(s), credentials, IP-address hosts, single-label names and odd characters.
     * @return array{base_url:string,host:string,scheme:string}|null
     */
    public static function normalizeOrigin(string $input): ?array
    {
        $in = trim($input);
        if ($in === '' || strlen($in) > 255 || preg_match('/[\x00-\x20\x7f]/', $in)) { return null; }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $in)) { $in = 'https://' . $in; }
        $p = parse_url($in);
        if ($p === false || empty($p['host']) || isset($p['user']) || isset($p['pass'])) { return null; }
        $scheme = strtolower($p['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') { return null; }
        $host = strtolower(rtrim($p['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) { return null; }
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) && !preg_match('/^(?:[a-z0-9-]+\.)+xn--[a-z0-9-]+$/', $host)) { return null; }
        $port = isset($p['port']) ? (int)$p['port'] : null;
        $default = $scheme === 'https' ? 443 : 80;
        $base = $scheme . '://' . $host . ($port !== null && $port !== $default ? ':' . $port : '');
        return ['base_url' => $base, 'host' => $host, 'scheme' => $scheme];
    }

    /**
     * True when $finalUrl is on EXACTLY this host. "www." is NOT folded away: an apex domain and its www
     * name can have different DNS and owners, so a redirect from one to the other cannot vouch for it.
     * (A site that redirects to www should be connected as its www address.)
     */
    public static function sameSite(string $host, string $finalUrl): bool
    {
        $h = parse_url($finalUrl, PHP_URL_HOST);
        if (!is_string($h) || $h === '') { return false; }
        return strtolower(rtrim($h, '.')) === strtolower(rtrim($host, '.'));
    }

    /**
     * Does this page carry the verification meta tag, with exactly this token, as a real <meta> element in
     * the document <head>? Parsed as HTML, so text inside comments, scripts, styles or the body, and look-alike
     * attributes such as data-name / data-content, do not count.
     */
    public static function htmlHasToken(string $html, string $token): bool
    {
        if ($token === '' || $html === '') { return false; }
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            // The encoding hint stops libxml guessing; LIBXML_NONET blocks any network fetch while parsing.
            $ok = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (!$ok) { return false; }
            $xp = new DOMXPath($doc);
            foreach ($xp->query('//head/meta') as $meta) {
                /** @var DOMElement $meta */
                if (strtolower(trim($meta->getAttribute('name'))) === self::META_NAME
                    && hash_equals($token, trim($meta->getAttribute('content')))) {
                    return true;
                }
            }
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    public static function defaultDnsTxt(string $host): array
    {
        $out = [];
        $recs = @dns_get_record($host, DNS_TXT);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (isset($r['txt'])) { $out[] = (string)$r['txt']; }
            }
        }
        return $out;
    }

    // ---- connect ------------------------------------------------------------------------------

    /**
     * Register a website. Returns the token and instructions; nothing is fetched except a DNS lookup.
     * Connecting an address that is already connected returns the existing record.
     * @return array{ok:bool,code:?string,error:?string,id:?int,existing:bool,token:?string,instructions:?array}
     */
    public function connect(string $address, int $userId, string $mode = 'existing'): array
    {
        if ($userId <= 0) { return $this->fail('no_user', 'Only a signed-in person can connect a website'); }
        $o = self::normalizeOrigin($address);
        if (!$o) { return $this->fail('bad_url', 'Enter your website address, for example mowology.ca'); }
        $mode = $mode === 'managed' ? 'managed' : 'existing';

        $check = $this->fetcher->checkUrl($o['base_url'] . '/');
        if (!$check['ok']) {
            return $this->fail($check['code'] === 'dns_failed' ? 'dns_failed' : 'not_allowed',
                               $check['code'] === 'dns_failed' ? 'That address could not be found' : 'That address cannot be used');
        }
        try {
            $s = $this->db->prepare("SELECT id, verify_token, status FROM mia_websites WHERE site_id = ? AND host = ?");
            $s->execute([$this->siteId, $o['host']]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['status'] === 'disconnected') {
                    $this->db->prepare("UPDATE mia_websites SET status = 'pending', verified_at = NULL, verified_via = NULL, base_url = ? WHERE id = ? AND site_id = ?")
                        ->execute([$o['base_url'], $row['id'], $this->siteId]);
                }
                return ['ok' => true, 'code' => null, 'error' => null, 'id' => (int)$row['id'], 'existing' => true,
                        'token' => $row['verify_token'], 'instructions' => self::instructions($o['host'], (string)$row['verify_token'])];
            }
            $token = (string)call_user_func($this->tokenGen);
            if (!preg_match('/^[a-f0-9]{32}$/', $token)) { return $this->fail('db', 'Could not create a verification token'); }
            $this->db->prepare("INSERT INTO mia_websites (site_id, base_url, host, mode, connector, capabilities, status, verify_token, created_by)
                                VALUES (?, ?, ?, ?, 'none', ?, 'pending', ?, ?)")
                ->execute([$this->siteId, $o['base_url'], $o['host'], $mode, self::CAPABILITIES['none'], $token, $userId]);
            return ['ok' => true, 'code' => null, 'error' => null, 'id' => (int)$this->db->lastInsertId(), 'existing' => false,
                    'token' => $token, 'instructions' => self::instructions($o['host'], $token)];
        } catch (Throwable $e) {
            error_log('MiaWebsiteService connect: ' . $e->getMessage());
            return $this->fail('db', 'Could not save the website');
        }
    }

    /** The three ways to prove control, with the exact text to copy. */
    public static function instructions(string $host, string $token): array
    {
        return [
            'meta' => ['label' => 'Add a tag to your home page', 'snippet' => '<meta name="' . self::META_NAME . '" content="' . $token . '">'],
            'file' => ['label' => 'Upload a small file', 'path' => self::FILE_PATH, 'contents' => $token],
            'dns'  => ['label' => 'Add a DNS TXT record', 'host' => $host, 'value' => self::DNS_PREFIX . $token],
        ];
    }

    // ---- verify -------------------------------------------------------------------------------

    /**
     * Check the proof. $method: meta | file | dns | any (tries each).
     * @return array{ok:bool,code:?string,error:?string,id:?int,existing:bool,method:?string}
     */
    public function verify(int $websiteId, string $method = 'any', ?DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $w = $this->get($websiteId);
        if (!$w)                               { return $this->fail('not_found', 'No such website'); }
        if ($w['status'] === 'disconnected')   { return $this->fail('disconnected', 'This website is disconnected'); }
        $methods = $method === 'any' ? self::METHODS : (in_array($method, self::METHODS, true) ? [$method] : []);
        if (!$methods)                         { return $this->fail('bad_method', 'Unknown verification method'); }

        $via = null; $last = 'Not found';
        foreach ($methods as $m) {
            $r = $m === 'meta' ? $this->checkMeta($w) : ($m === 'file' ? $this->checkFile($w) : $this->checkDns($w));
            if ($r === true) { $via = $m; break; }
            $last = $r;
        }
        try {
            if ($via !== null) {
                // Not for a site disconnected while the fetch was running.
                $u = $this->db->prepare("UPDATE mia_websites SET status = 'verified', verified_via = ?, verified_at = ?, last_verify_attempt_at = ?, last_verify_error = NULL
                                          WHERE id = ? AND site_id = ? AND status <> 'disconnected'");
                $u->execute([$via, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $websiteId, $this->siteId]);
                if ($u->rowCount() !== 1) { return $this->fail('disconnected', 'This website is disconnected'); }
                return ['ok' => true, 'code' => null, 'error' => null, 'id' => $websiteId, 'existing' => false, 'method' => $via];
            }
            // A failed re-check of an already-verified site withdraws the verification.
            // verified_at/verified_via come BEFORE status: MySQL evaluates SET left to right, so they still see the old status.
            $this->db->prepare("UPDATE mia_websites SET verified_at = CASE WHEN status = 'verified' THEN NULL ELSE verified_at END,
                                       verified_via = CASE WHEN status = 'verified' THEN NULL ELSE verified_via END,
                                       status = CASE WHEN status = 'verified' THEN 'pending' ELSE status END,
                                       last_verify_attempt_at = ?, last_verify_error = ? WHERE id = ? AND site_id = ? AND status <> 'disconnected'")
                ->execute([$now->format('Y-m-d H:i:s'), mb_substr($last, 0, 200), $websiteId, $this->siteId]);
        } catch (Throwable $e) {
            error_log('MiaWebsiteService verify: ' . $e->getMessage());
            return $this->fail('db', 'Could not save the result');
        }
        return $this->fail('not_verified', $last);
    }

    /** @return true|string  true when proved, else a short reason */
    private function checkMeta(array $w)
    {
        $r = $this->fetcher->fetch($w['base_url'] . '/');
        if (!$r['ok'])                                   { return 'Could not load your home page (' . ($r['code'] ?: 'error') . ')'; }
        if (!self::sameSite($w['host'], $r['url']))      { return 'Your home page redirects to a different address (' . (parse_url($r['url'], PHP_URL_HOST) ?: 'unknown') . '). Connect that address instead.'; }
        if ($r['status'] !== 200)                        { return 'Your home page answered with status ' . $r['status']; }
        return self::htmlHasToken($r['body'], (string)$w['verify_token']) ? true : 'The tag was not found on your home page';
    }

    private function checkFile(array $w)
    {
        $r = $this->fetcher->fetch($w['base_url'] . self::FILE_PATH);
        if (!$r['ok'])                                   { return 'Could not load the verification file (' . ($r['code'] ?: 'error') . ')'; }
        if (!self::sameSite($w['host'], $r['url']))      { return 'The file address redirects to a different address. Connect that address instead.'; }
        if ($r['status'] !== 200)                        { return 'The verification file answered with status ' . $r['status']; }
        return hash_equals((string)$w['verify_token'], trim($r['body'])) ? true : 'The file does not contain the token';
    }

    private function checkDns(array $w)
    {
        $want = self::DNS_PREFIX . $w['verify_token'];
        try {
            foreach ((array)call_user_func($this->dnsTxt, $w['host']) as $txt) {
                if (hash_equals($want, trim((string)$txt, " \t\"'"))) { return true; }
            }
        } catch (Throwable $e) {
            return 'DNS lookup failed';
        }
        return 'The DNS record was not found';
    }

    // ---- gate and read ------------------------------------------------------------------------

    /**
     * Take the right to run a verification NOW. Atomic: only one of several parallel requests gets true, so a burst of
     * clicks cannot start a burst of DNS/HTTP checks. Records the attempt time as the claim.
     */
    public function claimVerifyAttempt(int $websiteId, DateTimeImmutable $now, int $cooldownSeconds): bool
    {
        try {
            $u = $this->db->prepare("UPDATE mia_websites SET last_verify_attempt_at = ? WHERE id = ? AND site_id = ? AND status <> 'disconnected'
                                      AND (last_verify_attempt_at IS NULL OR last_verify_attempt_at <= ?)");
            $u->execute([$now->format('Y-m-d H:i:s'), $websiteId, $this->siteId, $now->modify('-' . max(0, $cooldownSeconds) . ' seconds')->format('Y-m-d H:i:s')]);
            return $u->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Verified, and proved recently enough. */
    public function isVerified(int $websiteId, ?DateTimeImmutable $now = null): bool
    {
        $w = $this->get($websiteId);
        if (!$w || $w['status'] !== 'verified' || empty($w['verified_at'])) { return false; }
        $now = $now ?: new DateTimeImmutable('now');
        try {
            return new DateTimeImmutable($w['verified_at']) > $now->modify('-' . self::REVERIFY_DAYS . ' days');
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The crawler calls this first. @return array|null the website row when it may be crawled, else null */
    public function requireVerified(int $websiteId, ?DateTimeImmutable $now = null): ?array
    {
        return $this->isVerified($websiteId, $now) ? $this->get($websiteId) : null;
    }

    public function disconnect(int $websiteId): bool
    {
        try {
            $u = $this->db->prepare("UPDATE mia_websites SET status = 'disconnected', verified_at = NULL, verified_via = NULL WHERE id = ? AND site_id = ?");
            $u->execute([$websiteId, $this->siteId]);
            return $u->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function get(int $websiteId): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM mia_websites WHERE id = ? AND site_id = ?");
            $s->execute([$websiteId, $this->siteId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function listAll(): array
    {
        try {
            $s = $this->db->prepare("SELECT id, base_url, host, mode, connector, status, verified_via, verified_at, last_verify_error, crawl_cap
                                       FROM mia_websites WHERE site_id = ? AND status <> 'disconnected' ORDER BY id");
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function fail(string $code, string $error): array
    {
        return ['ok' => false, 'code' => $code, 'error' => $error, 'id' => null, 'existing' => false, 'token' => null, 'instructions' => null, 'method' => null];
    }
}
