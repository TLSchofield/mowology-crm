<?php
/**
 * WebsiteWatchService — Mia's weekly read of Google Search Console (28 days vs the 28 before).
 *
 * Credentials, in order:
 *   1. The CRM's existing Search Console connection (gsc_properties, OAuth, tokens encrypted
 *      with APP_ENCRYPTION_KEY — the same scheme /crm/gsc/sync-cron.php uses). This is the
 *      one already syncing sc-domain:mowology.ca.
 *   2. A service account added as a user on the Search Console property:
 *      GSC_SERVICE_ACCOUNT_JSON (absolute path to the key file, in public/app_config/, gitignored)
 *      — falls back to GOOGLE_VISION_CREDENTIALS, the CRM's existing service-account key, if
 *      that account has been added to Search Console.
 * Neither → the report is saved as 'not_connected' with the steps, never an error page.
 *
 * Read-only against Google (webmasters.readonly). Stores one row per week in mia_web_reports.
 * Rules live in WebsiteWatchRules (pure, tested). Suggest-only: nothing here edits a page.
 */
require_once __DIR__ . '/WebsiteWatchRules.php';

class WebsiteWatchService
{
    private const API = 'https://www.googleapis.com/webmasters/v3/sites/';
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'mia_web_reports'")->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Latest stored report (decoded), or null. */
    public function latest(): ?array
    {
        try {
            $r = $this->db->query("SELECT * FROM mia_web_reports ORDER BY week_start DESC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) return null;
        $r['report'] = json_decode((string)$r['report_json'], true) ?: [];
        $r['period'] = json_decode((string)$r['period_json'], true) ?: [];
        unset($r['report_json'], $r['period_json']);
        return $r;
    }

    /**
     * Build and save this week's report. Never throws.
     * @return array{status: string, week_start: string, headline?: string, error?: string}
     */
    public function run(DateTimeImmutable $today): array
    {
        $week = $today->modify('monday this week')->format('Y-m-d');
        $periods = WebsiteWatchRules::periods($today);
        try {
            [$token, $site, $source] = $this->credentials();
            if ($token === null) {
                $this->save($week, 'not_connected', null, $periods, [], 'No Search Console connection: connect it at /crm/gsc/connect.php or add a service account (see docs/crm/mia-channels.md).');
                return ['status' => 'not_connected', 'week_start' => $week];
            }
            $in = [
                'cur_queries'  => WebsiteWatchRules::byKey($this->query($token, $site, ['query'], $periods['cur'])),
                'prev_queries' => WebsiteWatchRules::byKey($this->query($token, $site, ['query'], $periods['prev'])),
                'cur_pages'    => WebsiteWatchRules::byKey($this->query($token, $site, ['page'], $periods['cur'])),
                'prev_pages'   => WebsiteWatchRules::byKey($this->query($token, $site, ['page'], $periods['prev'])),
                'query_page'   => $this->queryTopPage($this->query($token, $site, ['query', 'page'], $periods['cur'], 2000)),
                'service_pages' => $this->servicePages(),
                'site_pages'   => $this->sitePages(),
                'seasons'      => $this->seasons(),
            ];
            $report = WebsiteWatchRules::analyze($in, $today);
            $cms = $this->cmsIds();
            foreach ($report['suggestions'] as &$sg) {
                $sg['cms_url'] = WebsiteWatchRules::cmsLink((string)$sg['path'], $cms);
            }
            unset($sg);
            $report['site'] = $site;
            $this->save($week, 'ok', $source, $periods, $report, null);
            return ['status' => 'ok', 'week_start' => $week, 'headline' => $report['headline']];
        } catch (Throwable $e) {
            error_log('Mia website watch: ' . $e->getMessage());
            $this->save($week, 'error', null, $periods, [], mb_substr($e->getMessage(), 0, 250));
            return ['status' => 'error', 'week_start' => $week, 'error' => $e->getMessage()];
        }
    }

    // ── Credentials ─────────────────────────────────────────────────────────

    /** @return array{0: ?string, 1: string, 2: string} [access token|null, site url, source] */
    private function credentials(): array
    {
        $prop = null;
        try {
            $prop = $this->db->query("SELECT id, site_url, access_token_encrypted, refresh_token_encrypted, expires_at FROM gsc_properties ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { /* no table */ }
        $site = $prop ? self::apiSite((string)$prop['site_url']) : $this->defaultSite();

        if ($prop) {
            $tok = $this->oauthToken($prop);
            if ($tok !== null) return [$tok, $site, 'oauth'];
        }
        $tok = $this->serviceAccountToken();
        return [$tok, $site, $tok !== null ? 'service_account' : ''];
    }

    /** "mowology.ca" → "sc-domain:mowology.ca"; an sc-domain: or URL-prefix property is used as is. */
    public static function apiSite(string $siteUrl): string
    {
        $s = trim($siteUrl);
        if (strpos($s, 'sc-domain:') === 0 || preg_match('#^https?://#', $s)) return $s;
        return 'sc-domain:' . rtrim($s, '/');
    }

    private function defaultSite(): string
    {
        try {
            $w = (string)$this->db->query("SELECT company_website FROM business_settings WHERE id = 1")->fetchColumn();
            $host = parse_url(preg_match('#^https?://#', $w) ? $w : 'https://' . $w, PHP_URL_HOST);
            if ($host) return 'sc-domain:' . preg_replace('/^www\./', '', $host);
        } catch (Throwable $e) {}
        return 'sc-domain:mowology.ca';
    }

    private function oauthToken(array $prop): ?string
    {
        $exp = strtotime((string)($prop['expires_at'] ?? ''));
        if ($exp && $exp > time() + 120) {
            $t = self::decrypt((string)$prop['access_token_encrypted']);
            if ($t !== '') return $t;
        }
        $refresh = self::decrypt((string)$prop['refresh_token_encrypted']);
        if ($refresh === '' || !defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) return null;
        $res = $this->post('https://oauth2.googleapis.com/token', http_build_query([
            'client_id' => GOOGLE_CLIENT_ID, 'client_secret' => GOOGLE_CLIENT_SECRET,
            'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if (empty($res['access_token'])) return null;
        try {
            $this->db->prepare("UPDATE gsc_properties SET access_token_encrypted = ?, expires_at = ? WHERE id = ?")
                ->execute([self::encrypt((string)$res['access_token']), date('Y-m-d H:i:s', time() + (int)($res['expires_in'] ?? 3600)), (int)$prop['id']]);
        } catch (Throwable $e) {}
        return (string)$res['access_token'];
    }

    private function serviceAccountToken(): ?string
    {
        $path = '';
        foreach (['GSC_SERVICE_ACCOUNT_JSON', 'GOOGLE_VISION_CREDENTIALS'] as $c) {
            if (defined($c) && is_string(constant($c)) && constant($c) !== '' && is_file(constant($c))) { $path = constant($c); break; }
        }
        if ($path === '') return null;
        $cred = json_decode((string)file_get_contents($path), true);
        if (!is_array($cred) || empty($cred['client_email']) || empty($cred['private_key'])) return null;
        $now = time();
        $aud = $cred['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
                  . $b64(json_encode(['iss' => $cred['client_email'], 'scope' => self::SCOPE, 'aud' => $aud, 'iat' => $now, 'exp' => $now + 3600]));
        $key = openssl_pkey_get_private($cred['private_key']);
        if ($key === false || !openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256)) return null;
        $res = $this->post($aud, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $b64($sig)]),
            ['Content-Type: application/x-www-form-urlencoded']);
        return !empty($res['access_token']) ? (string)$res['access_token'] : null;
    }

    /** Same scheme as /crm/gsc/sync-cron.php (APP_ENCRYPTION_KEY, AES-256-CBC, IV prefix). */
    private static function decrypt(string $enc): string
    {
        if ($enc === '' || !defined('APP_ENCRYPTION_KEY')) return $enc;
        $data = base64_decode($enc, true);
        if ($data === false || strlen($data) <= 16) return '';
        $out = openssl_decrypt(substr($data, 16), 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, substr($data, 0, 16));
        return $out === false ? '' : $out;
    }

    private static function encrypt(string $plain): string
    {
        if ($plain === '' || !defined('APP_ENCRYPTION_KEY')) return $plain;
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv));
    }

    // ── Search Console + site data ──────────────────────────────────────────

    private function query(string $token, string $site, array $dims, array $range, int $limit = 1000): array
    {
        $res = $this->post(self::API . rawurlencode($site) . '/searchAnalytics/query', json_encode([
            'startDate' => $range[0], 'endDate' => $range[1], 'dimensions' => $dims,
            'rowLimit' => $limit, 'searchType' => 'web', 'dataState' => 'final',
        ]), ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
        if (isset($res['error'])) {
            throw new RuntimeException('Search Console: ' . (is_array($res['error']) ? ($res['error']['message'] ?? 'error') : (string)$res['error']));
        }
        return $res['rows'] ?? [];
    }

    /** query => the page with the most impressions for it. */
    private function queryTopPage(array $rows): array
    {
        $best = [];
        foreach ($rows as $r) {
            $q = (string)($r['keys'][0] ?? '');
            $p = (string)($r['keys'][1] ?? '');
            $i = (float)($r['impressions'] ?? 0);
            if ($q === '' || $p === '') continue;
            if (!isset($best[$q]) || $i > $best[$q][1]) $best[$q] = [$p, $i];
        }
        return array_map(fn($b) => $b[0], $best);
    }

    /** Service pages: the static /services/*.php files plus CMS service pages. */
    private function servicePages(): array
    {
        $out = [];
        $dir = defined('PUBLIC_ROOT') ? PUBLIC_ROOT . '/services' : '';
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob($dir . '/*.php') ?: [] as $f) {
                $slug = basename($f, '.php');
                if ($slug === 'index') continue;
                $out['/services/' . $slug] = ['path' => '/services/' . $slug, 'title' => ucwords(str_replace('-', ' ', $slug))];
            }
        }
        try {
            foreach ($this->db->query("SELECT slug, title FROM cms_pages WHERE status = 'published' AND page_type IN ('services', 'service_landing')")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $path = '/' . ltrim((string)$p['slug'], '/');
                $out[$path] = ['path' => $path, 'title' => (string)$p['title']];
            }
        } catch (Throwable $e) {}
        return array_values($out);
    }

    /** Every page with its last edit (CMS updated_at; static files by modification time). */
    private function sitePages(): array
    {
        $out = [];
        $dir = defined('PUBLIC_ROOT') ? PUBLIC_ROOT . '/services' : '';
        if ($dir !== '' && is_dir($dir)) {
            foreach (glob($dir . '/*.php') ?: [] as $f) {
                $slug = basename($f, '.php');
                $out[] = ['path' => '/services/' . $slug, 'title' => ucwords(str_replace('-', ' ', $slug)), 'updated_at' => date('Y-m-d', (int)filemtime($f))];
            }
        }
        try {
            foreach ($this->db->query("SELECT slug, title, updated_at FROM cms_pages WHERE status = 'published'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = ['path' => '/' . ltrim((string)$p['slug'], '/'), 'title' => (string)$p['title'], 'updated_at' => (string)$p['updated_at']];
            }
        } catch (Throwable $e) {}
        return $out;
    }

    private function seasons(): array
    {
        try {
            return $this->db->query("SELECT season_key, label, start_month, start_day, end_month, end_day, services_json, is_active FROM seo_seasons")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function cmsIds(): array
    {
        try {
            $out = [];
            foreach ($this->db->query("SELECT id, slug FROM cms_pages")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[trim((string)$p['slug'], '/')] = (int)$p['id'];
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function save(string $week, string $status, ?string $source, array $periods, array $report, ?string $error): void
    {
        try {
            $this->db->prepare("
                INSERT INTO mia_web_reports (week_start, status, source, period_json, report_json, error)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), source = VALUES(source), period_json = VALUES(period_json),
                    report_json = VALUES(report_json), error = VALUES(error)
            ")->execute([$week, $status, $source, json_encode($periods), json_encode($report), $error]);
        } catch (Throwable $e) {
            error_log('Mia website watch save: ' . $e->getMessage());
        }
    }

    private function post(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('HTTP error: ' . $err);
        return json_decode((string)$raw, true) ?: [];
    }
}
