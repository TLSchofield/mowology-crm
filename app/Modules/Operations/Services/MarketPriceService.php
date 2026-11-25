<?php
/**
 * MarketPriceService — what others charge customers in the area, read from their public prices
 * (owner, 2026-10-19: "local market research is important, my garden bag publish their prices").
 *
 * SHARED regional layer (Ittai): public facts any tenant in the region may see — never a tenant's
 * private trade prices (those are supplier_price_lists, per site). Tables: migration 1375.
 *
 * Reader 'shopify': GET <url>/products.json?limit=250&page=N (robots.txt of a Shopify store allows public
 * product data; we identify ourselves). Only bulk-material lines are kept: a variant naming a yard size
 * ("1 Yard Bag", "1/2 Yard", "1/4 Yard Bag") of soil, mulch, compost, gravel, sand or rock.
 * A read is kept when the price is new or changed since the last read (price history, up/down);
 * an unchanged price is not stored again. refreshDue() runs at most once a week per source.
 *
 * Global-namespace service: require_once the file.
 */
require_once __DIR__ . '/HttpFetch.php';

class MarketPriceService
{
    public const WEEK_SEC = 7 * 86400;
    public const MAX_PAGES = 4;
    private const MATERIAL = [
        'mulch'   => ['mulch', 'bark', 'wood chip', 'woodchip'],
        'compost' => ['compost', 'manure'],
        'soil'    => ['soil', 'blend', 'top dress', 'topdress', 'amender', 'veggie', 'garden mix', 'turf'],
        'sand'    => ['sand'],
        'rock'    => ['rock', 'pebble', 'cobble'],
        'gravel'  => ['gravel', 'crush', 'crusher', 'road base', 'navvy', 'limestone', 'birdseye', 'aggregate', 'dust'],
    ];

    private PDO $db;
    /** @var callable(string): ?string */
    private $fetch;

    public function __construct(PDO $db, ?callable $fetch = null)
    {
        $this->db = $db;
        $this->fetch = $fetch ?? static function (string $url): ?string {
            return HttpFetch::get($url, 15, ['User-Agent: Mowology-CRM/1.0 (market research; office@mowology.ca)', 'Accept: application/json']);
        };
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM market_prices LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** "1 Yard Bag" → 1.0, "1/2 Yard Bag" → 0.5, "1/4 Yard" → 0.25, "Default Title" → null. PURE. */
    public static function yards(string $variant): ?float
    {
        $v = strtolower($variant);
        if (!preg_match('/\byards?\b|\byd\b/', $v)) return null;
        if (preg_match('/(\d+)\s*\/\s*(\d+)/', $v, $m) && (int)$m[2] > 0) return round((int)$m[1] / (int)$m[2], 2);
        if (preg_match('/(\d+(?:\.\d+)?)/', $v, $m)) return (float)$m[1];
        return null;
    }

    /** The bulk material a product is, from its title and type; null for tools, kits, empty bags. PURE. */
    public static function material(string $title, string $type = ''): ?string
    {
        $t = ' ' . strtolower($title . ' ' . $type) . ' ';
        if (preg_match('/\bempty\b|\bkit\b|variety/', $t)) return null;
        foreach (self::MATERIAL as $key => $words) {
            foreach ($words as $w) if (strpos($t, $w) !== false) return $key;
        }
        return null;
    }

    /** Shopify products.json → bulk lines [product, variant, product_type, material, yards, price]. PURE. */
    public static function parseShopify(string $json): array
    {
        $d = json_decode($json, true);
        $out = [];
        foreach ((array)($d['products'] ?? []) as $p) {
            $title = trim((string)($p['title'] ?? ''));
            $type = trim((string)($p['product_type'] ?? ''));
            $mat = self::material($title, $type);
            if ($title === '' || $mat === null) continue;
            foreach ((array)($p['variants'] ?? []) as $v) {
                $vt = trim((string)($v['title'] ?? ''));
                $yd = self::yards($vt);
                if ($yd === null || !is_numeric($v['price'] ?? null)) continue;
                $out[] = ['product' => mb_substr($title, 0, 160), 'variant' => mb_substr($vt, 0, 120), 'product_type' => $type !== '' ? mb_substr($type, 0, 60) : null,
                          'material' => $mat, 'yards' => $yd, 'price' => round((float)$v['price'], 2)];
            }
        }
        return $out;
    }

    /** Read every active source due for a read (or all, $force). @return array{sources: int, stored: int, errors: int} */
    public function refreshDue(bool $force = false, ?int $now = null): array
    {
        $now = $now ?? time();
        $r = ['sources' => 0, 'stored' => 0, 'errors' => 0];
        if (!$this->ready()) return $r;
        foreach ($this->db->query("SELECT * FROM market_price_sources WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC) as $src) {
            $last = $src['last_fetched_at'] ? strtotime((string)$src['last_fetched_at']) : 0;
            if (!$force && $last && $now - $last < self::WEEK_SEC) continue;
            $r['sources']++;
            try {
                $rows = $this->read($src);
                $r['stored'] += $this->store((int)$src['id'], $rows, date('Y-m-d', $now));
                $this->db->prepare("UPDATE market_price_sources SET last_fetched_at = ?, last_error = NULL WHERE id = ?")->execute([date('Y-m-d H:i:s', $now), (int)$src['id']]);
            } catch (Throwable $e) {
                $r['errors']++;
                $this->db->prepare("UPDATE market_price_sources SET last_fetched_at = ?, last_error = ? WHERE id = ?")
                         ->execute([date('Y-m-d H:i:s', $now), mb_substr($e->getMessage(), 0, 255), (int)$src['id']]);
                error_log('[market prices] ' . $src['name'] . ': ' . $e->getMessage());
            }
        }
        return $r;
    }

    private function read(array $src): array
    {
        if (($src['reader'] ?? '') !== 'shopify') throw new RuntimeException('no reader for ' . $src['reader']);
        $base = rtrim((string)$src['url'], '/');
        if (!preg_match('#^https://[a-z0-9.-]+$#i', $base)) throw new RuntimeException('source url must be https://host');
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = ($this->fetch)($base . '/products.json?limit=250&page=' . $page);
            if ($body === null) throw new RuntimeException('no answer from ' . $base);
            $d = json_decode($body, true);
            if (!is_array($d) || !isset($d['products'])) throw new RuntimeException('not a Shopify product list');
            $rows = array_merge($rows, self::parseShopify($body));
            if (count((array)$d['products']) < 250) break;
        }
        return $rows;
    }

    /** Keep a price when it is new or changed since the last read. @return int rows stored */
    public function store(int $sourceId, array $rows, string $day): int
    {
        $last = $this->db->prepare("SELECT price FROM market_prices WHERE source_id = ? AND product = ? AND variant = ? AND seen_on < ? ORDER BY seen_on DESC LIMIT 1");
        $ins = $this->db->prepare("INSERT INTO market_prices (source_id, product, variant, product_type, material, yards, price, seen_on, prev_price, price_change)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $n = 0;
        foreach ($rows as $r) {
            $last->execute([$sourceId, $r['product'], $r['variant'], $day]);
            $was = $last->fetchColumn();
            $was = $was === false ? null : (float)$was;
            if ($was !== null && abs($was - $r['price']) < 0.005) continue;                    // unchanged: nothing new to keep
            $change = $was === null ? 'new' : ($r['price'] > $was ? 'up' : 'down');
            try {
                $ins->execute([$sourceId, $r['product'], $r['variant'], $r['product_type'], $r['material'], $r['yards'], $r['price'], $day, $was, $change]);
                $n++;
            } catch (PDOException $e) { /* already read today */ }
        }
        return $n;
    }

    /**
     * The market's current price for a bulk material, per bag size: the latest read of each line.
     * $words: what Sam searched ("mushroom manure"); matched against the product name first, else the material.
     * @return array<int, array{source: string, what: ?string, product: string, sizes: array, seen_on: string}>
     */
    public function current(array $words, ?string $material = null): array
    {
        if (!$this->ready()) return [];
        $rows = $this->db->query("SELECT p.*, s.name AS source, s.what FROM market_prices p JOIN market_price_sources s ON s.id = p.source_id
                                  WHERE s.is_active = 1 AND p.id IN (SELECT MAX(id) FROM market_prices GROUP BY source_id, product, variant)")->fetchAll(PDO::FETCH_ASSOC);
        return self::pick($rows, $words, $material);
    }

    /** Rows → products matching the words (all of them), else of the material; sizes 1 → ¼ yd. PURE. */
    public static function pick(array $rows, array $words, ?string $material = null): array
    {
        $byName = [];
        $byMat = [];
        foreach ($rows as $r) {
            $hay = strtolower((string)$r['product']);
            $all = $words !== [];
            foreach ($words as $w) if (strpos($hay, strtolower($w)) === false) { $all = false; break; }
            $key = $r['source'] . '|' . $r['product'];
            $line = ['source' => (string)$r['source'], 'what' => $r['what'] ?? null, 'product' => (string)$r['product'], 'sizes' => [], 'seen_on' => (string)$r['seen_on']];
            if ($all) { $byName[$key] = $byName[$key] ?? $line; $byName[$key]['sizes'][(string)(float)$r['yards']] = (float)$r['price']; }
            elseif ($material !== null && $r['material'] === $material) { $byMat[$key] = $byMat[$key] ?? $line; $byMat[$key]['sizes'][(string)(float)$r['yards']] = (float)$r['price']; }
        }
        $out = array_values($byName ?: $byMat);
        foreach ($out as &$o) krsort($o['sizes'], SORT_NUMERIC);
        unset($o);
        usort($out, static fn($a, $b) => (reset($a['sizes']) ?: 0) <=> (reset($b['sizes']) ?: 0));
        return array_slice($out, 0, 4);
    }
}
