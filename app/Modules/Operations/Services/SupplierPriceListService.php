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
require_once __DIR__ . '/CatalogService.php';

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
            $known = false;
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

    /** Already imported this exact link from this exact email? */
    private function seen(int $supplierId, string $url, string $messageKey): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND source_url = ? AND (source_message_key = ? OR ? = '') LIMIT 1");
        $s->execute([$this->siteId, $supplierId, mb_substr($url, 0, 500), mb_substr($messageKey, 0, 191), $messageKey]);
        return (bool)$s->fetchColumn();
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
        $cells = SheetReader::read($bytes, (string)($meta['file_name'] ?? 'list.xlsx'));
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
        $s = $this->db->prepare("SELECT i.*, p.canonical_name, p.common_names FROM supplier_price_items i
                                 LEFT JOIN catalog_products p ON p.id = i.catalog_product_id
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
        $s = $this->db->prepare("SELECT id, valid_until FROM supplier_price_lists WHERE site_id = ? AND supplier_account_id = ? AND kind = 'specials'
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
                JOIN (SELECT supplier_account_id, MAX(list_date) AS d FROM supplier_price_lists WHERE site_id = ? AND kind = 'availability' GROUP BY supplier_account_id) x
                  ON x.supplier_account_id = l.supplier_account_id AND x.d = l.list_date
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
