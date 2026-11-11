<?php
/**
 * SupplierPriceListService — this company's supplier price lists (owner, 2026-10-12). Migration 1331.
 *
 * PRIVATE data (site_id): supplier_price_lists + supplier_price_items — trade prices, specials,
 * availability. Every tenant imports its own copy from its own mailbox; lists never cross tenants.
 * SHARED pieces it uses: supplier_list_formats (how a list reads), SupplierListParser, and
 * CatalogService (each line linked to the shared catalogue — no price goes there).
 *
 *   import()          bytes + format → one list, idempotent per (site, supplier, kind, list_date);
 *                     each line keeps the supplier's name/code/size/category exactly as given, gets
 *                     the previous list's price (new / up / down / same) and a catalogue link
 *   importFromEmail() the links in a supplier's email (format link_hosts, https only) → fetch → import
 *   diff()            new, gone, price up, price down vs the previous list of the same kind
 *   compare()         one catalogue product + size: every supplier's latest price side by side
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once __DIR__ . '/SupplierListParser.php';
require_once __DIR__ . '/SupplierFormatInferrer.php';
require_once __DIR__ . '/SupplierWebCatalogReader.php';
require_once __DIR__ . '/SupplierDirectoryService.php';
require_once __DIR__ . '/CatalogService.php';
require_once dirname(__DIR__, 3) . '/Services/Spreadsheet/PdfTableReader.php';   // supplier PDF price tables (2026-10-19)

class SupplierPriceListService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const TIMEOUT = 20;
    public const KINDS = ['availability', 'specials'];

    private PDO $db;
    private int $siteId;
    private CatalogService $catalog;

    public function __construct(PDO $db, int $siteId = 1, ?CatalogService $catalog = null)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->catalog = $catalog ?? new CatalogService($db, $siteId);
    }

    public function catalog(): CatalogService
    {
        return $this->catalog;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM supplier_price_items LIMIT 1");
            $this->db->query("SELECT 1 FROM supplier_list_formats LIMIT 1");
            return $this->catalog->ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---- formats (shared) ------------------------------------------------------------------

    /** The active list format for mail from this domain (or a subdomain of it), or null. */
    public function formatForDomain(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') return null;
        foreach ($this->db->query("SELECT * FROM supplier_list_formats WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            foreach (self::splitList((string)$f['sender_domains']) as $d) {
                if ($domain === $d || substr($domain, -strlen('.' . $d)) === '.' . $d) return $f;
            }
        }
        return null;
    }

    public static function splitList(string $s): array
    {
        return array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode('|', $s)), fn($x) => $x !== ''));
    }

    // ---- links and download (pure checks + one fetch) ---------------------------------------

    /** https, on one of the format's hosts exactly (or a subdomain), no credentials, no odd port. PURE. */
    public static function urlAllowed(string $url, array $hosts): bool
    {
        $p = parse_url($url);
        if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int)$p['port'] !== 443)) return false;
        $host = strtolower((string)($p['host'] ?? ''));
        if ($host === '') return false;
        foreach ($hosts as $h) {
            $h = strtolower(trim((string)$h));
            if ($h !== '' && ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h)) return true;
        }
        return false;
    }

    /**
     * The list files an email links to: allowed host, a readable file type, not skipped (booking
     * forms), and named like one of the format's kinds. PURE.
     * @return array<int, array{url: string, file_name: string}>
     */
    public static function listLinks(string $body, array $format): array
    {
        $spec = SupplierListParser::spec((string)$format['spec_json']);
        $hosts = self::splitList((string)($format['link_hosts'] ?? ''));
        $types = (array)($spec['file_types'] ?? SheetReader::TYPES);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('#https?://[^\s"\'<>()\[\]]+#i', $body, $m);
        $out = [];
        foreach (array_unique($m[0]) as $url) {
            $url = rtrim($url, '.,;:');
            if (!self::urlAllowed($url, $hosts)) continue;
            $name = rawurldecode(basename((string)parse_url($url, PHP_URL_PATH)));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $types, true)) continue;
            if (!empty($spec['skip_files']) && preg_match('/' . str_replace('/', '\/', (string)$spec['skip_files']) . '/i', $name)) continue;
            $known = !empty($format['auto_inferred']);   // a format read automatically has no file-name kinds: any sheet link counts
            foreach ((array)($spec['kinds'] ?? []) as $k) {
                if (!empty($k['file']) && preg_match('/' . str_replace('/', '\/', (string)$k['file']) . '/i', $name)) $known = true;
            }
            if (!$known) continue;
            $out[] = ['url' => $url, 'file_name' => $name];
        }
        return $out;
    }

    /** Download one list: allow-listed https only, no redirects, 5 MB cap, 20 s. @throws RuntimeException */
    public static function fetch(string $url, array $hosts): string
    {
        if (!self::urlAllowed($url, $hosts)) throw new RuntimeException('link not on an allowed host');
        if (!function_exists('curl_init')) throw new RuntimeException('curl not available');
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (price list import)',
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$buf) {
                $buf .= $chunk;
                return strlen($buf) > self::MAX_BYTES ? 0 : strlen($chunk);   // 0 aborts the transfer
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (strlen($buf) > self::MAX_BYTES) throw new RuntimeException('list larger than 5 MB');
        if ($ok === false) throw new RuntimeException('download failed: ' . $err);
        if ($code !== 200) throw new RuntimeException('download returned HTTP ' . $code);
        return $buf;
    }

    /**
     * Every list linked in one supplier email → imported. Never throws: a failure is logged and
     * counted, so the mail poll carries on. $fetcher(url, hosts) is for tests.
     * @return array{imported: int, duplicate: int, failed: int, lists: int[]}
     */
    public function importFromEmail(array $supplier, array $format, string $body, array $meta, ?callable $fetcher = null): array
    {
        $out = ['imported' => 0, 'duplicate' => 0, 'failed' => 0, 'lists' => []];
        try {
            $links = self::listLinks($body, $format);
        } catch (Throwable $e) {
            error_log('[price lists] format ' . ($format['code'] ?? '?') . ': ' . $e->getMessage());
            $out['failed']++;
            return $out;
        }
        $hosts = self::splitList((string)($format['link_hosts'] ?? ''));
        foreach ($links as $l) {
            try {
                if ($this->seen((int)$supplier['id'], $l['url'], (string)($meta['message_key'] ?? ''))) { $out['duplicate']++; continue; }
                $bytes = $fetcher ? $fetcher($l['url'], $hosts) : self::fetch($l['url'], $hosts);
                $r = $this->import($bytes, [
                    'supplier_account_id' => (int)$supplier['id'], 'format' => $format, 'file_name' => $l['file_name'],
                    'source_url' => $l['url'], 'message_key' => $meta['message_key'] ?? null, 'body' => $body,
                    'received' => $meta['received_at'] ?? '',
                ]);
                $out[$r['status'] === 'imported' ? 'imported' : 'duplicate']++;
                if ($r['list_id']) $out['lists'][] = (int)$r['list_id'];
            } catch (Throwable $e) {
                $out['failed']++;
                error_log('[price lists] ' . ($supplier['name'] ?? 'supplier') . ' ' . $l['file_name'] . ': ' . $e->getMessage());
            }
        }
        return $out;
    }

    /** The attached files a format reads (by extension). PURE. */
    public static function listAttachment(string $fileName, array $format): bool
    {
        if (!in_array((string)($format['arrives'] ?? 'link'), ['attachment', 'both'], true)) return false;
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $spec = json_decode((string)($format['spec_json'] ?? ''), true);
        $types = (array)($spec['attachment_types'] ?? ['xlsx', 'xls', 'csv', 'pdf']);
        return in_array($ext, $types, true);
    }

    /**
     * One attached list → imported (Specimen Trees' monthly .xls, SW Garden's contractor PDF). Never throws.
     * @return array{status: string, list_id: ?int}
     */
    public function importAttachment(array $supplier, array $format, string $fileName, string $bytes, array $meta): array
    {
        try {
            if (strlen($bytes) > self::MAX_BYTES * 4) return ['status' => 'too_big', 'list_id' => null];
            $source = 'attachment:' . $fileName;
            if ($this->seen((int)$supplier['id'], $source, (string)($meta['message_key'] ?? ''))) return ['status' => 'duplicate', 'list_id' => null];
            $r = $this->import($bytes, ['supplier_account_id' => (int)$supplier['id'], 'format' => $format, 'file_name' => $fileName,
                                        'source_url' => $source, 'message_key' => $meta['message_key'] ?? null, 'body' => $meta['body'] ?? '',
                                        'received' => $meta['received_at'] ?? '']);
            return ['status' => $r['status'], 'list_id' => $r['list_id']];
        } catch (Throwable $e) {
            error_log('[price lists] ' . ($supplier['name'] ?? 'supplier') . ' ' . $fileName . ': ' . $e->getMessage());
            return ['status' => 'failed', 'list_id' => null];
        }
    }

    /** Already imported this exact link from this exact email? */
    private function seen(int $supplierId, string $url, string $messageKey): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND source_url = ? AND (source_message_key = ? OR ? = '') LIMIT 1");
        $s->execute([$this->siteId, $supplierId, mb_substr($url, 0, 500), mb_substr($messageKey, 0, 191), $messageKey]);
        return (bool)$s->fetchColumn();
    }

    // ---- a supplier's public web shop (SupplierWebCatalogReader) ----------------------------------

    /**
     * Add a Shopify web shop as a supplier: the directory entry (once per web domain), its web format (held for a
     * "looks right"), and the first read. Returns what happened.
     * @param callable|null $resolve fn(string $host): string  host -> IP (tests); default gethostbyname
     * @return array{supplier_id: int, format_id: int, import: array}
     * @throws InvalidArgumentException|RuntimeException
     */
    public function addWebSupplier(string $name, string $url, ?callable $http = null, ?callable $sleep = null, ?callable $resolve = null): array
    {
        $base = SupplierWebCatalogReader::safeBase($url);
        $host = (string)parse_url($base, PHP_URL_HOST);
        $ip = ($resolve ?? 'gethostbyname')($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException('that name does not resolve to a public web server');
        $name = trim($name) !== '' ? trim($name) : $host;

        $dir = new SupplierDirectoryService($this->db);
        $s = $this->db->prepare("SELECT id FROM supplier_accounts WHERE domain = ? OR domain = ? ORDER BY id LIMIT 1");
        $s->execute([$host, preg_replace('/^www\./', '', $host)]);
        $sid = (int)($s->fetchColumn() ?: 0);
        if (!$sid) $sid = $dir->upsert(['name' => $name, 'website' => $base, 'status' => 'open', 'list_kind' => 'web', 'sells' => 'online shop']);
        if (!$sid) throw new RuntimeException('could not add the supplier');

        $bare = (string)preg_replace('/^www\./', '', $host);   // www and bare are one shop
        $code = 'web-' . substr((string)preg_replace('/[^a-z0-9]+/', '-', $bare), 0, 50);
        $q = $this->db->prepare("SELECT * FROM supplier_list_formats WHERE code = ?");
        $q->execute([$code]);
        $format = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$format) {
            $spec = SupplierWebCatalogReader::spec($base, $sid);
            $this->db->prepare("INSERT INTO supplier_list_formats (code, name, family, sender_domains, link_hosts, arrives, spec_json, is_active, needs_review, auto_inferred)
                                VALUES (?, ?, 'other', NULL, ?, 'web', ?, 1, 1, 0)")
                     ->execute([$code, mb_substr($name . ' (web shop)', 0, 160), $bare, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $q->execute([$code]);
            $format = $q->fetch(PDO::FETCH_ASSOC);
        }
        $supplier = ['id' => $sid, 'name' => $name];
        return ['supplier_id' => $sid, 'format_id' => (int)$format['id'], 'import' => $this->importWeb($supplier, $format, $http, $sleep)];
    }

    /**
     * Read one web format now -> a new list dated today (the existing diff shows what moved since last time).
     * @return array{status: string, list_id: ?int, rows: int, products: int, pages: int}
     * @throws RuntimeException when the shop cannot be read
     */
    public function importWeb(array $supplier, array $format, ?callable $http = null, ?callable $sleep = null): array
    {
        $spec = SupplierListParser::spec((string)$format['spec_json']);
        $web = (array)($spec['web'] ?? []);
        if (($web['type'] ?? '') !== 'shopify' || empty($web['base'])) throw new RuntimeException('not a web shop format');
        $base = SupplierWebCatalogReader::safeBase((string)$web['base']);
        $hosts = self::splitList((string)($format['link_hosts'] ?? ''));
        if ($hosts && !self::urlAllowed($base . '/', $hosts)) throw new RuntimeException('the shop address is not the format\'s host');
        $feed = SupplierWebCatalogReader::fetchAll($base, $http ?? [SupplierWebCatalogReader::class, 'curl'], $sleep ?? static function (int $us): void { usleep($us); });
        $cells = SupplierWebCatalogReader::cells($feed['products'], $web);
        $r = $this->importCells($cells, ['supplier_account_id' => (int)$supplier['id'], 'format' => $format, 'file_name' => 'web catalogue',
                                         'source_url' => $base . SupplierWebCatalogReader::PATH, 'received' => date('Y-m-d')]);
        return ['status' => $r['status'], 'list_id' => $r['list_id'], 'rows' => $r['rows'], 'products' => count($feed['products']), 'pages' => $feed['pages']];
    }

    /**
     * Weekly: every active web format whose newest list is 7+ days old. A shop that cannot be read is recorded
     * as a skip with the reason, never silently. Called by the generate_visits cron.
     * @return array{shops: int, imported: int, failed: int}
     */
    public function refreshWebDue(?callable $http = null, ?callable $sleep = null, ?int $now = null): array
    {
        $out = ['shops' => 0, 'imported' => 0, 'failed' => 0];
        $now = $now ?? time();
        try {
            $formats = $this->db->query("SELECT * FROM supplier_list_formats WHERE is_active = 1 AND arrives = 'web'")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $out;
        }
        foreach ($formats as $f) {
            $web = (array)((json_decode((string)$f['spec_json'], true) ?: [])['web'] ?? []);
            $sid = (int)($web['supplier_id'] ?? 0);
            if (!$sid) continue;
            $s = $this->db->prepare("SELECT MAX(list_date) FROM supplier_price_lists WHERE site_id = ? AND format_id = ?");
            $s->execute([$this->siteId, (int)$f['id']]);
            $last = (string)$s->fetchColumn();
            if ($last !== '' && strtotime($last) > $now - 6 * 86400) continue;
            // A shop that refused us today (429/403 or an error) is not asked again until tomorrow.
            $tried = $this->db->prepare("SELECT 1 FROM supplier_list_skips WHERE site_id = ? AND supplier_account_id = ? AND message_key = ?");
            $tried->execute([$this->siteId, $sid, 'web:' . date('Y-m-d', $now)]);
            if ($tried->fetchColumn()) continue;
            $out['shops']++;
            try {
                $r = $this->importWeb(['id' => $sid, 'name' => $f['name']], $f, $http, $sleep);
                if ($r['status'] === 'imported') $out['imported']++;
            } catch (Throwable $e) {
                $out['failed']++;
                error_log('[web catalogue] ' . $f['name'] . ': ' . $e->getMessage());
                $this->recordSkip($sid, (string)($f['link_hosts'] ?? ''), 'Web catalogue', 'products.json', mb_substr($e->getMessage(), 0, 240), 'web:' . date('Y-m-d', $now));
            }
        }
        return $out;
    }

    // ---- formats read automatically (migration 1390) --------------------------------------------

    /** Has migration 1390 run? The automatic path stays off until it has. */
    public function reviewReady(): bool
    {
        try {
            $this->db->query("SELECT needs_review, auto_inferred FROM supplier_list_formats LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Read one file and work out how it reads. Never throws.
     * @return array the SupplierFormatInferrer result (ok=false with a reason when it is not a price list)
     */
    public function inferFromFile(string $fileName, string $bytes): array
    {
        try {
            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES * 4) return ['ok' => false, 'reason' => 'the file is empty or too big'] + self::emptyInference();
            if (strncmp($bytes, '%PDF-', 5) === 0 || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf') return $this->inferFromPdf($fileName, $bytes);
            return SupplierFormatInferrer::infer(SheetReader::read($bytes, $fileName), $fileName);
        } catch (Throwable $e) {
            return ['ok' => false, 'reason' => 'could not open the file (' . $e->getMessage() . ')'] + self::emptyInference();
        }
    }

    /**
     * A PDF price table: a product line ends in N numbers (PdfTableReader). Try N = 1..4 and keep the LARGEST N
     * that still reads at least 70% as many rows as N = 1 (a 1-number read matches every line, so it is the ceiling).
     * The numbers are called Price, List Price, then Value 3/4: a guess a person confirms on the check banner.
     */
    private function inferFromPdf(string $fileName, string $bytes): array
    {
        $names = ['Price', 'List Price', 'Value 3', 'Value 4'];
        $best = null;
        $ceiling = 0;
        for ($n = 1; $n <= 4; $n++) {
            $pdf = ['numbers' => $n, 'columns' => array_slice($names, 0, $n)];
            $inf = SupplierFormatInferrer::infer(PdfTableReader::cells($bytes, $pdf), $fileName);
            if ($n === 1) $ceiling = (int)$inf['rows'];
            if (!$inf['ok'] || $ceiling === 0 || $inf['rows'] < 0.7 * $ceiling) continue;
            $inf['spec']['pdf'] = $pdf;
            $best = $inf;
        }
        return $best ?? ['ok' => false, 'reason' => 'the PDF has no table of lines ending in prices'] + self::emptyInference();
    }

    private static function emptyInference(): array
    {
        return ['spec' => null, 'header_row' => null, 'mapped' => [], 'unmapped' => [], 'confidence' => 0.0, 'rows' => 0, 'sample' => []];
    }

    /** plants when the names read as botanical ("Acer palmatum 'X'") or a zone column exists, else other. PURE. */
    public static function guessFamily(array $inference): string
    {
        if (isset($inference['mapped']['zone'])) return 'plants';
        $bot = 0;
        $n = 0;
        foreach ((array)($inference['sample'] ?? []) as $r) {
            $n++;
            if (preg_match('/^[A-Z][a-z]+ (x |[a-z]{3,})/', (string)($r['name'] ?? ''))) $bot++;
        }
        return $n && $bot / $n >= 0.6 ? 'plants' : 'other';
    }

    /**
     * Make the format for an unknown supplier's first list, held for a person to confirm (needs_review).
     * One format per sender domain: a second call for the same domain returns the one that exists.
     * @param array $inference a SupplierFormatInferrer result with ok = true
     */
    public function createAutoFormat(array $supplier, string $domain, array $inference, array $hosts = []): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || empty($inference['ok'])) return null;
        if ($f = $this->formatForDomain($domain)) return $f;
        $code = 'auto-' . substr(preg_replace('/[^a-z0-9]+/', '-', $domain), 0, 40);
        $spec = (array)$inference['spec'];
        $has = $this->db->prepare("SELECT 1 FROM supplier_list_formats WHERE code = ?");
        $has->execute([$code]);
        if (!$has->fetchColumn()) {
            $this->db->prepare("INSERT INTO supplier_list_formats (code, name, family, sender_domains, link_hosts, arrives, spec_json,
                                    is_active, needs_review, auto_inferred, inferred_confidence) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, ?)")
                     ->execute([$code, mb_substr(((string)($supplier['name'] ?? $domain)) . ' (read automatically)', 0, 160), self::guessFamily($inference), $domain,
                                implode('|', $hosts ?: [$domain]), 'both',
                                json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (float)($inference['confidence'] ?? 0)]);
        }
        return $this->formatForDomain($domain);
    }

    /** The supplier changed their sheet: store the new reading and ask for another check. Auto formats only. */
    public function respecAutoFormat(int $formatId, array $inference): ?array
    {
        if (empty($inference['ok'])) return null;
        $u = $this->db->prepare("UPDATE supplier_list_formats SET spec_json = ?, needs_review = 1, inferred_confidence = ? WHERE id = ? AND auto_inferred = 1");
        $u->execute([json_encode((array)$inference['spec'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (float)($inference['confidence'] ?? 0), $formatId]);
        if (!$u->rowCount()) return null;
        $s = $this->db->prepare("SELECT * FROM supplier_list_formats WHERE id = ?");
        $s->execute([$formatId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Remember a list that arrived but could not be read, so the page can say so. Never throws. */
    public function recordSkip(int $supplierId, string $sender, string $subject, string $fileName, string $reason, string $messageKey): void
    {
        try {
            $has = $this->db->prepare("SELECT 1 FROM supplier_list_skips WHERE site_id = ? AND message_key = ? AND file_name = ?");
            $has->execute([$this->siteId, mb_substr($messageKey, 0, 191), mb_substr($fileName, 0, 255)]);
            if ($has->fetchColumn()) return;
            $this->db->prepare("INSERT INTO supplier_list_skips (site_id, supplier_account_id, sender, subject, file_name, reason, message_key)
                                VALUES (?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$this->siteId, $supplierId ?: null, mb_substr($sender, 0, 190), mb_substr($subject, 0, 255), mb_substr($fileName, 0, 255),
                                mb_substr($reason, 0, 255), mb_substr($messageKey, 0, 191)]);
        } catch (Throwable $e) {
            error_log('[price lists] recordSkip: ' . $e->getMessage());
        }
    }

    /** @return array<int,array> lists we could not read, newest first (not dismissed) */
    public function openSkips(int $limit = 20): array
    {
        try {
            $s = $this->db->prepare("SELECT k.*, a.name AS supplier_name FROM supplier_list_skips k LEFT JOIN supplier_accounts a ON a.id = k.supplier_account_id
                                     WHERE k.site_id = ? AND k.dismissed_at IS NULL ORDER BY k.id DESC LIMIT " . (int)$limit);
            $s->execute([$this->siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function dismissSkip(int $id): void
    {
        $this->db->prepare("UPDATE supplier_list_skips SET dismissed_at = ? WHERE id = ? AND site_id = ?")->execute([date('Y-m-d H:i:s'), $id, $this->siteId]);
    }

    /**
     * Formats read automatically and not yet confirmed, each with this site's latest list and 5 sample lines,
     * for the "check this reading" banner.
     * @return array<int,array>
     */
    public function reviewQueue(): array
    {
        if (!$this->reviewReady()) return [];
        $out = [];
        foreach ($this->db->query("SELECT * FROM supplier_list_formats WHERE needs_review = 1 AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $s = $this->db->prepare("SELECT l.*, a.name AS supplier_name FROM supplier_price_lists l LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                                     WHERE l.site_id = ? AND l.format_id = ? ORDER BY l.list_date DESC, l.id DESC LIMIT 1");
            $s->execute([$this->siteId, (int)$f['id']]);
            $l = $s->fetch(PDO::FETCH_ASSOC);
            if (!$l) continue;
            $spec = json_decode((string)$f['spec_json'], true) ?: [];
            $cols = [];
            foreach ((array)($spec['columns'] ?? []) as $field => $syns) $cols[$field] = (string)((array)$syns)[0];
            $out[] = ['format' => $f, 'list' => $l, 'columns' => $cols, 'sample' => $this->items((int)$l['id'], [], 5)];
        }
        return $out;
    }

    /** "Looks right": the format stops needing a check, and its lists join quote pricing. */
    public function confirmFormat(int $formatId): void
    {
        $this->db->prepare("UPDATE supplier_list_formats SET needs_review = 0 WHERE id = ?")->execute([$formatId]);
        $this->db->prepare("UPDATE supplier_price_lists SET needs_review = 0 WHERE site_id = ? AND format_id = ?")->execute([$this->siteId, $formatId]);
    }

    /**
     * "Fix columns": point fields at different header texts. $fieldToHeader = [field => exact header text or ''],
     * '' removes the field. Only known fields; name stays required. Re-read the lists afterwards from the page.
     * @throws InvalidArgumentException
     */
    public function updateFormatColumns(int $formatId, array $fieldToHeader): void
    {
        $s = $this->db->prepare("SELECT spec_json FROM supplier_list_formats WHERE id = ? AND auto_inferred = 1");
        $s->execute([$formatId]);
        $json = $s->fetchColumn();
        if ($json === false) throw new InvalidArgumentException('format not found');
        $spec = json_decode((string)$json, true) ?: [];
        foreach ($fieldToHeader as $field => $header) {
            if (!in_array($field, SupplierListParser::FIELDS, true)) continue;
            $header = trim((string)$header);
            if ($header === '') unset($spec['columns'][$field]);
            else $spec['columns'][$field] = [$header];
        }
        if (empty($spec['columns']['name'])) throw new InvalidArgumentException('a name column is required');
        $this->db->prepare("UPDATE supplier_list_formats SET spec_json = ? WHERE id = ?")
                 ->execute([json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $formatId]);
    }

    // ---- import ----------------------------------------------------------------------------

    /**
     * @param array $meta supplier_account_id, format (supplier_list_formats row), file_name,
     *                    source_url?, message_key?, body? (email text), received?
     * @return array{status: string, list_id: ?int, rows: int, kind: string, list_date: string}
     */
    public function import(string $bytes, array $meta): array
    {
        $format = (array)$meta['format'];
        $spec = SupplierListParser::spec((string)$format['spec_json']);
        $fileName = (string)($meta['file_name'] ?? 'list.xlsx');
        $cells = (strncmp($bytes, '%PDF-', 5) === 0 || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'pdf')
            ? PdfTableReader::cells($bytes, (array)($spec['pdf'] ?? []))      // a PDF price table (format's "pdf" section)
            : SheetReader::read($bytes, $fileName);
        return $this->importCells($cells, $meta);
    }

    /**
     * The same import from cells already read (a web catalogue, SupplierWebCatalogReader, has no file).
     * @param array $meta as import()
     */
    public function importCells(array $cells, array $meta): array
    {
        $format = (array)$meta['format'];
        $spec = SupplierListParser::spec((string)$format['spec_json']);
        $p = SupplierListParser::parse($cells, $spec, ['file_name' => (string)($meta['file_name'] ?? ''),
                                                       'body' => (string)($meta['body'] ?? ''), 'received' => (string)($meta['received'] ?? '')]);
        $supplierId = (int)$meta['supplier_account_id'];
        $base = ['rows' => count($p['rows']), 'kind' => $p['kind'], 'list_date' => $p['list_date']];
        if (!$p['rows']) return ['status' => 'empty', 'list_id' => null] + $base;

        $s = $this->db->prepare("SELECT id FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND kind = ? AND list_date = ?");
        $s->execute([$this->siteId, $supplierId, $p['kind'], $p['list_date']]);
        if (($id = $s->fetchColumn()) !== false) return ['status' => 'duplicate', 'list_id' => (int)$id] + $base;

        $family = (string)($format['family'] ?? 'other');
        $useKind = $this->catalog->family($family)['default_use_kind'] ?? null;
        $prev = $this->previousPrices($supplierId, $p['kind'], $p['list_date']);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("INSERT INTO supplier_price_lists (site_id, supplier_account_id, format_id, kind, list_date, valid_until, title, file_name,
                                    source_url, source_message_key, rules_json, row_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$this->siteId, $supplierId, $format['id'] ?? null, $p['kind'], $p['list_date'], $p['valid_until'],
                                mb_substr($p['title'], 0, 160) ?: null, isset($meta['file_name']) ? mb_substr((string)$meta['file_name'], 0, 255) : null,
                                isset($meta['source_url']) ? mb_substr((string)$meta['source_url'], 0, 500) : null,
                                isset($meta['message_key']) ? mb_substr((string)$meta['message_key'], 0, 191) : null,
                                json_encode($p['rules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($p['rows'])]);
            $listId = (int)$this->db->lastInsertId();
            if (!empty($format['needs_review'])) {
                $this->db->prepare("UPDATE supplier_price_lists SET needs_review = 1 WHERE id = ?")->execute([$listId]);   // out of quote pricing until confirmed
            }
            $ins = $this->db->prepare("INSERT INTO supplier_price_items (site_id, list_id, product_code, name, size, zone, category, brand, mfr_part_no, upc,
                                           price, list_price, special_price, available, comment, use_kind, prev_price, price_change, catalog_product_id,
                                           catalog_category_id, size_code, height, name_key, match_confidence, match_how)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($p['rows'] as $r) {
                $catId = $this->catalog->categoryFor($family, $r['category']);
                $m = $this->catalog->resolve($family, $r, $catId);
                $size = $this->catalog->size($family, $r['size']);
                $k = self::lineKey($r);
                $was = $prev[$k] ?? null;
                $now = $r['price'];
                $change = $prev ? ($was === null ? 'new' : ($now === null ? null : ($now > $was ? 'up' : ($now < $was ? 'down' : 'same')))) : null;
                $ins->execute([$this->siteId, $listId, self::cut($r['product_code'], 60), mb_substr($r['name'], 0, 255), self::cut($r['size'], 80),
                               self::cut($r['zone'], 20), self::cut($r['category'], 120), self::cut($r['brand'], 120), self::cut($r['mfr_part_no'], 80),
                               self::cut($r['upc'], 20), $r['price'], $r['list_price'], $r['special_price'], $r['available'], self::cut($r['comment'], 255),
                               $useKind, $was, $change, $m['product_id'], $catId, $size['code'], $size['height'], mb_substr($m['name_key'], 0, 255),
                               $m['confidence'], $m['how']]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return ['status' => 'imported', 'list_id' => $listId] + $base;
    }

    private static function cut($v, int $n): ?string
    {
        return $v === null ? null : mb_substr((string)$v, 0, $n);
    }

    /** A line's identity across weeks: the supplier's code (else its name) + its size, as given. PURE. */
    public static function lineKey(array $r): string
    {
        $id = trim((string)($r['product_code'] ?? ''));
        if ($id === '') $id = 'n:' . PlantNameNormalizer::fold((string)($r['name'] ?? ''));
        return $id . '|' . PlantNameNormalizer::fold((string)($r['size'] ?? ''));
    }

    /** lineKey => price on the latest earlier list of the same kind (empty when this is the first). */
    private function previousPrices(int $supplierId, string $kind, string $listDate): array
    {
        $s = $this->db->prepare("SELECT id FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND kind = ? AND list_date < ? ORDER BY list_date DESC LIMIT 1");
        $s->execute([$this->siteId, $supplierId, $kind, $listDate]);
        $prevId = $s->fetchColumn();
        if ($prevId === false) return [];
        $s = $this->db->prepare("SELECT product_code, name, size, price FROM supplier_price_items WHERE list_id = ?");
        $s->execute([(int)$prevId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[self::lineKey($r)] = $r['price'] === null ? null : (float)$r['price'];
        return $out;
    }

    // ---- reading -----------------------------------------------------------------------------

    public function list(int $id): ?array
    {
        $s = $this->db->prepare("SELECT l.*, a.name AS supplier_name FROM supplier_price_lists l LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                                 WHERE l.id = ? AND l.site_id = ?");
        $s->execute([$id, $this->siteId]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $r['rules'] = json_decode((string)$r['rules_json'], true) ?: [];
        return $r;
    }

    /** The newest list of each supplier + kind (MySQL 5.7: no window functions). */
    public function latestLists(?int $supplierId = null): array
    {
        $sql = "SELECT l.*, a.name AS supplier_name FROM supplier_price_lists l
                JOIN (SELECT supplier_account_id, kind, MAX(list_date) AS d FROM supplier_price_lists WHERE site_id = ? GROUP BY supplier_account_id, kind) x
                  ON x.supplier_account_id = l.supplier_account_id AND x.kind = l.kind AND x.d = l.list_date
                LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                WHERE l.site_id = ?" . ($supplierId ? " AND l.supplier_account_id = ?" : '') . " ORDER BY a.name, l.kind";
        $s = $this->db->prepare($sql);
        $s->execute($supplierId ? [$this->siteId, $this->siteId, $supplierId] : [$this->siteId, $this->siteId]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['rules'] = json_decode((string)$r['rules_json'], true) ?: [];
        return $rows;
    }

    /**
     * Lines of one list, filtered. Specials from the supplier's current specials list are joined
     * by the supplier's code + size. Filters: q (name or code), category, size, changes (1 = only
     * new/up/down).
     */
    public function items(int $listId, array $f = [], int $limit = 2000): array
    {
        $list = $this->list($listId);
        if (!$list) return [];
        $where = ['i.list_id = ?', 'i.site_id = ?'];
        $args = [$listId, $this->siteId];
        if (($q = trim((string)($f['q'] ?? ''))) !== '') {
            $where[] = '(i.name LIKE ? OR i.product_code LIKE ? OR p.common_names LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            array_push($args, $like, $like, $like);
        }
        if (($c = trim((string)($f['category'] ?? ''))) !== '') { $where[] = 'i.category = ?'; $args[] = $c; }
        if (($z = trim((string)($f['size'] ?? ''))) !== '') { $where[] = 'i.size_code = ?'; $args[] = $z; }
        if (!empty($f['changes'])) $where[] = "i.price_change IN ('new','up','down')";
        $s = $this->db->prepare("SELECT i.*, p.canonical_name, p.common_names, t.photo_file, t.photo_credit FROM supplier_price_items i
                                 LEFT JOIN catalog_products p ON p.id = i.catalog_product_id
                                 LEFT JOIN catalog_taxa t ON t.species_key = p.species_key AND t.status = 'found'
                                 WHERE " . implode(' AND ', $where) . " ORDER BY i.name, i.size_code LIMIT " . max(1, min(5000, $limit)));
        $s->execute($args);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($list['kind'] === 'availability') {
            $specials = $this->currentSpecials((int)$list['supplier_account_id']);
            foreach ($rows as &$r) {
                $sp = $specials['items'][self::lineKey($r)] ?? null;
                $r['special'] = $sp;
                $r['special_until'] = $sp ? $specials['valid_until'] : null;
            }
        }
        return $rows;
    }

    /** The supplier's specials list still in force today: lineKey => special price. */
    public function currentSpecials(int $supplierId, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $s = $this->db->prepare("SELECT id, valid_until FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND kind = 'specials' AND needs_review = 0
                                 AND (valid_until IS NULL OR valid_until >= ?) ORDER BY list_date DESC LIMIT 1");
        $s->execute([$this->siteId, $supplierId, $today]);
        $l = $s->fetch(PDO::FETCH_ASSOC);
        if (!$l) return ['list_id' => null, 'valid_until' => null, 'items' => []];
        $s = $this->db->prepare("SELECT product_code, name, size, special_price, comment FROM supplier_price_items WHERE list_id = ? AND special_price IS NOT NULL");
        $s->execute([(int)$l['id']]);
        $items = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[self::lineKey($r)] = ['price' => (float)$r['special_price'], 'comment' => $r['comment']];
        return ['list_id' => (int)$l['id'], 'valid_until' => $l['valid_until'], 'items' => $items];
    }

    /** Distinct categories and sizes in a list (filters). */
    public function facets(int $listId): array
    {
        $s = $this->db->prepare("SELECT DISTINCT category FROM supplier_price_items WHERE list_id = ? AND site_id = ? AND category IS NOT NULL ORDER BY category");
        $s->execute([$listId, $this->siteId]);
        $cats = $s->fetchAll(PDO::FETCH_COLUMN);
        $s = $this->db->prepare("SELECT size_code, MIN(size) AS label, COUNT(*) AS n FROM supplier_price_items WHERE list_id = ? AND site_id = ? AND size_code IS NOT NULL
                                 GROUP BY size_code ORDER BY size_code");
        $s->execute([$listId, $this->siteId]);
        return ['categories' => $cats, 'sizes' => $s->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Changes since the previous list of the same kind.
     * @return array{new: int, up: int, down: int, same: int, gone: array<int, array>, first: bool}
     */
    public function diff(int $listId): array
    {
        $l = $this->list($listId);
        $out = ['new' => 0, 'up' => 0, 'down' => 0, 'same' => 0, 'gone' => [], 'first' => true];
        if (!$l) return $out;
        $s = $this->db->prepare("SELECT price_change, COUNT(*) FROM supplier_price_items WHERE list_id = ? AND price_change IS NOT NULL GROUP BY price_change");
        $s->execute([$listId]);
        foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) { if (isset($out[$k])) { $out[$k] = (int)$n; $out['first'] = false; } }
        $s = $this->db->prepare("SELECT id FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND kind = ? AND list_date < ? ORDER BY list_date DESC LIMIT 1");
        $s->execute([$this->siteId, $l['supplier_account_id'], $l['kind'], $l['list_date']]);
        $prevId = $s->fetchColumn();
        if ($prevId === false) return $out;
        $out['first'] = false;
        $s = $this->db->prepare("SELECT product_code, name, size FROM supplier_price_items WHERE list_id = ?");
        $s->execute([$listId]);
        $now = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $now[self::lineKey($r)] = true;
        $s = $this->db->prepare("SELECT product_code, name, size, price FROM supplier_price_items WHERE list_id = ? ORDER BY name");
        $s->execute([(int)$prevId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) if (!isset($now[self::lineKey($r)])) $out['gone'][] = $r;
        return $out;
    }

    /**
     * One catalogue product (+ size): each supplier's line on its newest availability list, side
     * by side — this company's prices only.
     */
    public function compare(int $productId, ?string $sizeCode = null): array
    {
        $sql = "SELECT a.name AS supplier_name, l.supplier_account_id, l.list_date, i.name, i.size, i.size_code, i.height, i.price,
                       i.available, i.product_code, i.price_change, i.prev_price
                FROM supplier_price_items i
                JOIN supplier_price_lists l ON l.id = i.list_id
                JOIN (SELECT supplier_account_id, MAX(list_date) AS d FROM supplier_price_lists WHERE site_id = ? AND kind = 'availability' AND needs_review = 0 GROUP BY supplier_account_id) x
                  ON x.supplier_account_id = l.supplier_account_id AND x.d = l.list_date AND l.needs_review = 0
                LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                WHERE i.site_id = ? AND l.kind = 'availability' AND i.catalog_product_id = ?" . ($sizeCode !== null && $sizeCode !== '' ? " AND i.size_code = ?" : '') . "
                ORDER BY i.size_code, i.height, i.price";
        $args = [$this->siteId, $this->siteId, $productId];
        if ($sizeCode !== null && $sizeCode !== '') $args[] = $sizeCode;
        $s = $this->db->prepare($sql);
        $s->execute($args);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
