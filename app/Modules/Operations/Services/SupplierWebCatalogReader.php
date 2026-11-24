<?php
/**
 * SupplierWebCatalogReader — a supplier's PUBLIC web shop read as a price list (Tim, 2026-10-20:
 * "supplier, scrape public website" — brecksbulbs.ca). SHARED code (Ittai): nothing here knows a supplier.
 *
 * Only Shopify shops, and only their public product feed: GET <base>/products.json?limit=250&page=N. That is
 * the catalogue Shopify publishes for every store; Breck's robots.txt (read 2026-10-20) disallows /cart,
 * /checkout, /account, /search and sort/filter URLs but not this path. We read it at most weekly, 2 s apart,
 * name ourselves in the User-Agent, and stop at once on a 429 or 403. No HTML is scraped, no login, no cart.
 *
 * Output is the SAME cells a spreadsheet gives (row 1 = headers) so SupplierListParser::parse and
 * SupplierPriceListService::importCells read it unchanged. Stock: a Shopify variant says only available
 * true/false, never a count, so true -> NULL ("stock not listed") and false -> 0 ("sold out", which is how
 * an out-of-season line looks); SupplierSearchService already ranks sold-out offers last.
 *
 * The format's spec_json carries a "web" section:
 *   base         https://www.example.com          (https, a public host name)
 *   supplier_id  supplier_accounts.id this list belongs to
 *   skip_types   product types that are not plants/materials ("Gifts", "Décor", ...), compared lower case, decoded
 *   skip_title   regex applied to the title of products with NO type or a skipped type
 */
declare(strict_types=1);

class SupplierWebCatalogReader
{
    public const UA = 'MowologyCRM/1.0 (supplier price list; office@mowology.ca)';
    public const PAGE_SIZE = 250;
    public const MAX_PAGES = 8;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const PAUSE_US = 2000000;
    public const PATH = '/products.json';
    public const RULE = 'Order online: shipping extra, ships in season';
    public const HEADERS = ['Code', 'Plant', 'Size', 'Category', 'Price', 'Available', 'Comment'];

    /** Not plants or materials, for a landscaper (checked against Breck's real types 2026-10-20). */
    public const DEFAULT_SKIP_TYPES = ['gifts', 'holiday gifts', 'décor', 'decor', 'food', 'garden essentials', 'backyard birding',
                                       'solar and wind', 'tools and supplies', 'gift cards', 'gift card'];
    public const DEFAULT_SKIP_TITLE = '\\b(toy|toys|dishtowels?|towels?|twirlers?|accent light|ornaments?|gloves?|gauntlet|potpourri|tools?|cabin|bird|birds|birdbath|feeder|decor|candles?|diffuser|wreath|sign|apron|mug|garland|gift|statues?|lanterns?|pillows?|blankets?|stand|baskets?|lighted|light up|led|solar|caddie|clock|netting|stakes?|supports?|pot toes|tins?|tea|truffles?|cheese|knives|knife|handbook|silhouette|lamp|cover|crate|cups|pins|jelly|sweets|chocolates?|sampler|barn|spa|santa|cardinal)\\b';

    // ---- pure -------------------------------------------------------------------------------

    /**
     * "https://www.brecksbulbs.ca/anything" -> "https://www.brecksbulbs.ca". https only, a real host name
     * (no IP, no localhost, no port, no credentials).
     * @throws InvalidArgumentException
     */
    public static function safeBase(string $url): string
    {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) $url = 'https://' . $url;
        $p = parse_url($url);
        $host = strtolower((string)($p['host'] ?? ''));
        if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https') throw new InvalidArgumentException('the web address must be https');
        if (isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int)$p['port'] !== 443)) throw new InvalidArgumentException('no port or login in the web address');
        if ($host === '' || strpos($host, '.') === false || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9.-]+$/', $host) || substr($host, -6) === '.local') {
            throw new InvalidArgumentException('that is not a public web site name');
        }
        return 'https://' . $host;
    }

    /** Skip a product? PURE. */
    public static function skip(array $product, array $web): bool
    {
        $type = mb_strtolower(trim(html_entity_decode((string)($product['product_type'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $skipTypes = array_map('mb_strtolower', (array)($web['skip_types'] ?? self::DEFAULT_SKIP_TYPES));
        $title = html_entity_decode((string)($product['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rx = (string)($web['skip_title'] ?? self::DEFAULT_SKIP_TITLE);
        $titleHit = $rx !== '' && @preg_match('/' . str_replace('/', '\/', $rx) . '/iu', $title) === 1;
        if ($type === '') return $titleHit;                     // no type: only the title can say it is not a plant
        return in_array($type, $skipTypes, true);
    }

    /**
     * Shopify products -> spreadsheet cells (row 1 headers). One row per variant.
     * @param array $products decoded "products" array
     * @return array<int,array<string,mixed>> [row => [col => value]]
     */
    public static function cells(array $products, array $web = []): array
    {
        $cells = [1 => []];
        foreach (self::HEADERS as $i => $h) $cells[1][chr(65 + $i)] = $h;
        $cells[1]['I'] = self::RULE;      // shown beside this supplier's offers (an order rule): the price is NOT delivered
        $r = 2;
        foreach ($products as $p) {
            if (!is_array($p) || self::skip($p, $web)) continue;
            $title = trim(html_entity_decode((string)($p['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '') continue;
            $type = trim(html_entity_decode((string)($p['product_type'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach ((array)($p['variants'] ?? []) as $v) {
                if (!is_array($v) || !isset($v['price']) || !is_numeric($v['price'])) continue;
                [$size, $ships] = self::sizeOf((string)($v['title'] ?? ''));
                $code = trim((string)($v['sku'] ?? '')) ?: trim((string)($p['handle'] ?? ''));
                $cells[$r++] = [
                    'A' => $code, 'B' => $title, 'C' => $size, 'D' => $type,
                    'E' => round((float)$v['price'], 2),
                    'F' => !empty($v['available']) ? null : 0,
                    'G' => $ships,
                ];
            }
        }
        return $cells;
    }

    /**
     * "10 Bulbs | Ships in Fall" -> ["10 Bulbs", "Ships in Fall"]; "Default Title" -> ["", ""]. PURE.
     * @return array{0: string, 1: string}
     */
    public static function sizeOf(string $variantTitle): array
    {
        $t = trim(html_entity_decode($variantTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t === '' || strcasecmp($t, 'Default Title') === 0) return ['', ''];
        $ships = '';
        if (preg_match('/\s*\|\s*(ships[^|]*)$/i', $t, $m)) {
            $ships = trim($m[1]);
            $t = trim(substr($t, 0, -strlen($m[0])));
        }
        return [$t, $ships];
    }

    /** The spec_json for a web format. */
    public static function spec(string $base, int $supplierId, array $skipTypes = self::DEFAULT_SKIP_TYPES, string $skipTitle = self::DEFAULT_SKIP_TITLE): array
    {
        return [
            'header_anchor' => 'Code',
            'columns' => ['product_code' => ['Code'], 'name' => ['Plant'], 'size' => ['Size'], 'category' => ['Category'],
                          'price' => ['Price'], 'available' => ['Available'], 'comment' => ['Comment']],
            'rules_cells' => ['I1'],
            'web' => ['type' => 'shopify', 'base' => $base, 'supplier_id' => $supplierId, 'skip_types' => array_values($skipTypes), 'skip_title' => $skipTitle],
        ];
    }

    // ---- network (injectable) ---------------------------------------------------------------------

    /**
     * Read every page of the public feed.
     * @param callable $http  fn(string $url): array{code: int, body: string}
     * @param callable $sleep fn(int $microseconds): void
     * @return array{products: array, pages: int}
     * @throws RuntimeException  on 403/429 (we stop), a non-JSON answer (not a Shopify shop), or an oversized feed
     */
    public static function fetchAll(string $base, callable $http, callable $sleep): array
    {
        $base = self::safeBase($base);
        $all = [];
        $bytes = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if ($page > 1) $sleep(self::PAUSE_US);
            $res = $http($base . self::PATH . '?limit=' . self::PAGE_SIZE . '&page=' . $page);
            $code = (int)($res['code'] ?? 0);
            if ($code === 429 || $code === 403) throw new RuntimeException("the shop answered HTTP {$code} — stopped, will try again next week");
            if ($code !== 200) throw new RuntimeException("the shop answered HTTP {$code}");
            $body = (string)($res['body'] ?? '');
            $bytes += strlen($body);
            if ($bytes > self::MAX_BYTES) throw new RuntimeException('the product feed is larger than 10 MB');
            $json = json_decode($body, true);
            if (!is_array($json) || !array_key_exists('products', $json)) throw new RuntimeException('this site does not publish a Shopify product feed (/products.json)');
            $batch = (array)$json['products'];
            if (!$batch) return ['products' => $all, 'pages' => $page - 1];
            foreach ($batch as $p) $all[] = $p;
            if (count($batch) < self::PAGE_SIZE) return ['products' => $all, 'pages' => $page];
        }
        return ['products' => $all, 'pages' => self::MAX_PAGES];
    }

    /**
     * @return array{code: int, body: string}
     * Follows up to 2 redirects (example.com -> www.example.com) but never to another host.
     */
    public static function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_TIMEOUT => 30,
                                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => ['Accept: application/json'],
                                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        $norm = static fn(string $u): string => (string)preg_replace('/^www\./', '', strtolower((string)parse_url($u, PHP_URL_HOST)));
        if ($norm($final) !== $norm($url)) return ['code' => 0, 'body' => ''];       // redirected to a different site: refuse
        return ['code' => $code, 'body' => is_string($body) ? $body : ''];
    }
}
