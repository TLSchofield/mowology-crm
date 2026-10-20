<?php
/**
 * ReceiptCatalogService — receipt lines matched to the SHARED product catalogue by themselves (owner,
 * 2026-10-19: "shouldnt we be able to match products with our catalogue and have an option to add to
 * it?" … "as much as possible this must be automatched"). Migration 1378.
 *
 * match(expenseId) — for each product line of a Materials / Tools / Repairs receipt, in order:
 *   1. a learned link (catalog_aliases, shared): this vendor's SKU, then this vendor's wording  → linked
 *   2. an exact key: a valid UPC/barcode, or the catalogue matcher's exact name key            → linked
 *   3. the same words as one catalogue product, and only one                                  → linked
 *   4. a clearly most-alike product (word overlap ≥ SUGGEST_AT, ahead of the next)             → suggested
 *   5. nothing alike                                                                           → "Add to catalogue"
 * Lines a person decided (confirmed / added / none) are never changed by it.
 * confirm() / add() teach the vendor's SKU and wording as aliases, so the next receipt links by itself.
 * Learned links are shared (a vendor's SKU means the same to every company: VendorIdentity name key);
 * prices stay on this tenant's lines. The catalogue only grows on a person's "Add to catalogue" —
 * never from receipt OCR by itself (it would fill every tenant's catalogue with "EG056V22" SPMW A").
 *
 * Runs after every receipt save (ExpenseGateHooks::catalog) and over past receipts (sweep()).
 * Tenant-neutral: categories and families are data. Global-namespace service: require_once the file.
 */
require_once dirname(__DIR__, 2) . '/Operations/Services/CatalogService.php';
require_once dirname(__DIR__, 2) . '/Knowledge/Services/VendorIdentity.php';
require_once dirname(__DIR__, 2) . '/Operations/Services/MarketPriceService.php';

class ReceiptCatalogService
{
    public const SUGGEST_AT = 0.6;
    /** catalog_aliases rows for "not this product" (alias_key 'rcpt-no:…', product_id = the product refused). */
    public const NOT_FAMILY = '_not';
    public const MARGIN = 0.1;
    /** Receipt categories whose lines are products, and the families each searches (first = default for Add). */
    public const CATEGORY_FAMILIES = [
        'Materials'           => ['hardscape', 'plants', 'irrigation', 'chemicals', 'tools', 'other'],
        'Tools/Equipment'     => ['tools', 'irrigation', 'other'],
        'Repairs/Maintenance' => ['tools', 'plumbing', 'electrical', 'irrigation', 'other'],
    ];
    private const NOT_A_PRODUCT = '/^\W*(ehf|eco ?fee|env\w* ?fee|deposit|core charge|gst|pst|hst|tax|sub ?total|subotal|total|amount|value|savings?|discount|coupon|bag fee|delivery|fuel surcharge|labou?r|purchase|change|cash|visa|debit|mastercard)\b|\b(disposal|shop|service) fee\b/i';
    /** Words that only say a kind of thing ("Annuals 6 Pack", "Perennial × 18", "Herbs/Veggies") — a category, not a product. */
    private const GENERIC = ['annual', 'perennial', 'plant', 'herb', 'veggie', 'veggy', 'veg', 'bulb', 'shrub', 'tree', 'flower', 'inch', 'pack',
                             'pot', 'fibre', 'fiber', 'landscaper', 'discount', 'misc', 'item', 'part', 'product', 'material', 'supply', 'rock', 'crusher'];
    private const STOP = ['the', 'and', 'of', 'with', 'for', 'a', 'an', 'pk', 'pack', 'ea', 'each', 'in', 'x', 'lb', 'lbs', 'kg', 'l', 'ml', 'pc', 'pcs', 'per', 'w'];
    private const FAMILY_WORDS = [
        'irrigation' => '/\b(hose|timer|sprinkler|drip|valve|nozzle|faucet|irrigat|emitter|rain ?bird|orbit|hunter|spigot)/i',
        'chemicals'  => '/\b(fertili[sz]|moss|herbicide|weed|killer|insect|pesticide|fungicide|lime|grass seed|seed)/i',
        'tools'      => '/\b(rake|saw|shovel|spade|pruner|shear|lopper|glove|glasses|trimmer|blower|mower|drill|driver|hammer|knife|tarp|strap|wheelbarrow|broom|hoe|fork)/i',
    ];

    private PDO $db;
    private CatalogService $catalog;
    /** @var array<string, list<array{id: int, name: string, family: string, tokens: string[]}>> */
    private array $products = [];

    public function __construct(PDO $db, ?CatalogService $catalog = null)
    {
        $this->db = $db;
        $this->catalog = $catalog ?? new CatalogService($db);
    }

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT catalog_product_id, catalog_match FROM expense_line_items LIMIT 0');
            return $this->catalog->ready();
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Pure helpers ───────────────────────────────────────────────────────────

    /**
     * A receipt line's wording → [name, code]: the "(2 @ $14.99)" count and a leading quantity ("1 M",
     * "2m", "1 yd") are not the product; a leading store code ("EVV-33225 EverGro…") is kept as the code.
     */
    public static function clean(string $name): array
    {
        $n = trim(preg_replace('/\(\s*\d+(\.\d+)?\s*[a-z]*\.?\s*@\s*\$?\s*[\d.,]+\s*\)/i', '', $name));
        $n = preg_replace('/^\d+(\.\d+)?\s*(m3|m|yds?|yards?|cy|bags?|pcs?|ea)\b\.?\s*/i', '', $n);
        $code = null;
        if (preg_match('/^([A-Z]{2,5}-?\d{3,})\s+(?=[A-Za-z])/', $n, $m)) { $code = $m[1]; $n = substr($n, strlen($m[0])); }
        return [trim(preg_replace('/\s+/', ' ', $n)), $code];
    }

    /** Words that say what it is: folded, stop words and bare numbers out, simple endings off. PURE. */
    public static function tokens(string $s): array
    {
        $out = [];
        // A size is part of what it is (a 20" strap is not a 30" one): "20\"" → 20in, "2 cu ft" → 2cuft, "4*8" → 4x8.
        $s = preg_replace_callback('/(\d+(?:\.\d+)?)\s*(\"|”|\'\'|in\b|inch(?:es)?\b|\'|ft\b|feet\b|cu\.?\s*ft\b|cu\.?\s*yd\b|yds?\b|cm\b|mm\b|m\b|l\b|litres?\b|liters?\b|ml\b|kg\b|g\b|lbs?\b|oz\b|gal\b|v\b|ah\b)/i',
            static function ($m) {
                $u = strtolower(preg_replace('/[\s.]+/', '', $m[2]));
                $u = ['"' => 'in', '”' => 'in', "''" => 'in', 'inch' => 'in', 'inches' => 'in', "'" => 'ft', 'feet' => 'ft', 'litre' => 'l', 'litres' => 'l',
                      'liter' => 'l', 'liters' => 'l', 'lbs' => 'lb', 'yds' => 'yd'][$u] ?? $u;
                return ' ' . str_replace('.', 'p', $m[1]) . $u . ' ';
            }, $s);
        $s = preg_replace('/(\d+)\s*[x*×]\s*(\d+)/i', ' $1x$2 ', $s);
        foreach (explode(' ', PlantNameNormalizer::fold($s)) as $w) {
            $w = trim($w, '.');
            if ($w === '' || in_array($w, self::STOP, true) || preg_match('/^\d+(\.\d+)?$/', $w)) continue;
            if (strlen($w) > 4 && substr($w, -2) === 'ed') $w = substr($w, 0, -2);
            elseif (strlen($w) > 4 && substr($w, -3) === 'ies') $w = substr($w, 0, -3) . 'y';
            elseif (strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') $w = substr($w, 0, -1);
            $out[$w] = true;
        }
        return array_keys($out);
    }

    /**
     * 0..1 word overlap (Dice) on the words; sizes ("20in", "4x8", "19mm"): a different size halves it,
     * a size on one side only caps it below "the same" (a suggestion, never a link by itself). PURE.
     */
    public static function score(array $a, array $b): float
    {
        $isSize = static fn($w) => (bool)preg_match('/^\d/', $w);
        $wa = array_values(array_filter($a, fn($w) => !$isSize($w)));
        $wb = array_values(array_filter($b, fn($w) => !$isSize($w)));
        if (!$wa || !$wb) return 0.0;
        $sc = 2 * count(array_intersect($wa, $wb)) / (count($wa) + count($wb));
        $sa = array_filter($a, $isSize);
        $sb = array_filter($b, $isSize);
        if ($sa && $sb && !array_intersect($sa, $sb)) $sc /= 2;
        elseif ((bool)$sa !== (bool)$sb) $sc = min($sc, 0.95);    // one side gives no size: ask, never link by itself
        return round($sc, 3);
    }

    /** A UPC-A / EAN-13 / GTIN-14 printed as the line's code, else null (store SKUs are not barcodes). PURE. */
    public static function barcode(?string $sku): ?string
    {
        $u = PartNumberMatcher::upc($sku);
        return $u !== null && strlen($u) >= 12 ? $u : null;
    }

    /** Is this line a product at all (not a fee, a tax, a deposit)? PURE. */
    public static function isProduct(string $name): bool
    {
        $n = trim($name);
        if ($n === '' || preg_match(self::NOT_A_PRODUCT, $n)) return false;
        $t = self::tokens(self::clean($n)[0]);
        $words = array_filter($t, static fn($w) => !preg_match('/^\d/', $w));   // a size alone says nothing
        return $words !== [] && array_diff($words, self::GENERIC) !== [];
    }

    /** The family "Add to catalogue" starts with: what the words say, else the category's first. PURE. */
    public static function guessFamily(string $category, string $name, string $vendor = ''): string
    {
        $fams = self::CATEGORY_FAMILIES[$category] ?? ['other'];
        if (class_exists('MarketPriceService') && MarketPriceService::material($name) !== null) return 'hardscape';
        foreach (self::FAMILY_WORDS as $fam => $re) if (in_array($fam, $fams, true) && preg_match($re, $name)) return $fam;
        if (in_array('plants', $fams, true) && preg_match('/nurser|garden cent|greenhouse/i', $vendor)) return 'plants';
        return $fams[0] === 'hardscape' ? 'other' : $fams[0];
    }

    /** The shared alias keys for a line at a vendor: its SKU first, then its words. PURE. */
    public static function aliasKeys(?string $vendorKey, ?string $sku, string $cleanName): array
    {
        if ($vendorKey === null || $vendorKey === '') return [];
        $keys = [];
        $s = PartNumberMatcher::mpn($sku);
        if ($s !== null && strlen($s) >= 4) $keys['sku'] = 'rcpt:' . $vendorKey . ':sku:' . $s;
        $w = self::tokens($cleanName);
        if ($w) $keys['n'] = 'rcpt:' . $vendorKey . ':n:' . mb_substr(implode(' ', $w), 0, 200);
        return $keys;
    }

    /**
     * Pick from scored candidates: one clear winner. [product_id, score, how] — how 'exact' when the
     * words are the same as one product's and no other's, 'suggested' when it leads by MARGIN, else none. PURE.
     */
    public static function pick(array $scored): array
    {
        usort($scored, static fn($x, $y) => $y['score'] <=> $x['score']);
        $top = $scored[0] ?? null;
        if (!$top || $top['score'] < self::SUGGEST_AT) return [null, 0.0, null];
        $next = $scored[1]['score'] ?? 0.0;
        if ($top['score'] >= 0.999 && $next < 0.999) return [$top['id'], 1.0, 'exact'];
        if ($top['score'] - $next >= self::MARGIN) return [$top['id'], $top['score'], 'suggested'];
        return [null, 0.0, null];
    }

    // ── Matching ──────────────────────────────────────────────────────────────

    /** Match every undecided product line of one receipt. @return array{linked: int, suggested: int, open: int} */
    public function match(int $expenseId): array
    {
        $r = ['linked' => 0, 'suggested' => 0, 'open' => 0];
        if (!$this->ready()) return $r;
        $e = $this->expense($expenseId);
        $fams = $e ? (self::CATEGORY_FAMILIES[(string)$e['accounting_category']] ?? null) : null;
        if (!$e || !$fams) return $r;
        $vk = VendorIdentity::nameKey($e['vendor']);
        $lines = $this->db->prepare("SELECT id, name, sku_raw FROM expense_line_items WHERE expense_id = ?
                                     AND (catalog_match IS NULL OR catalog_match IN ('alias', 'exact', 'suggested', 'open', 'skip'))");
        $lines->execute([$expenseId]);
        $set = $this->db->prepare("UPDATE expense_line_items SET catalog_product_id = ?, catalog_match = ?, catalog_suggested_id = ?, catalog_score = ? WHERE id = ?");
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if (!self::isProduct((string)$l['name'])) { $set->execute([null, 'skip', null, null, (int)$l['id']]); continue; }
            [$id, $score, $how] = $this->find((string)$l['name'], $l['sku_raw'] ?? null, $vk, $fams);
            if ($how === 'suggested') { $set->execute([null, 'suggested', $id, $score, (int)$l['id']]); $r['suggested']++; }
            elseif ($id !== null)     { $set->execute([$id, $how, null, $score, (int)$l['id']]); $r['linked']++; }
            else                      { $set->execute([null, 'open', null, null, (int)$l['id']]); $r['open']++; }
        }
        return $r;
    }

    /** [product_id, score, how] for one line. */
    public function find(string $name, ?string $sku, ?string $vendorKey, array $families): array
    {
        [$clean] = self::clean($name);
        // 1. Learned (shared): this vendor's SKU, then this vendor's words.
        $keys = self::aliasKeys($vendorKey, $sku, $clean);
        if ($keys) {
            $in = implode(',', array_fill(0, count($keys), '?'));
            $s = $this->db->prepare("SELECT alias_key, product_id FROM catalog_aliases WHERE alias_key IN ($in) AND family <> ? ORDER BY id");
            $s->execute(array_merge(array_values($keys), [self::NOT_FAMILY]));
            $hit = $s->fetchAll(PDO::FETCH_KEY_PAIR);   // one row per key (teach() keeps it so); the latest wins if not
            foreach ($keys as $k) if (isset($hit[$k]) && $this->exists((int)$hit[$k])) return [(int)$hit[$k], 1.0, 'alias'];
        }
        $not = $this->rejected($keys['n'] ?? null);
        // 2. A barcode, or the family matcher's exact name key.
        $upc = self::barcode($sku);
        foreach ($families as $fam) {
            try { $p = $this->catalog->matcher($fam)->parse(['name' => $clean, 'upc' => $upc]); } catch (Throwable $e) { continue; }
            foreach ($p['lookups'] as $t) {
                if (!in_array($t['col'], ['match_key', 'upc'], true) || $t['value'] === '') continue;
                $s = $this->db->prepare("SELECT id FROM catalog_products WHERE family = ? AND {$t['col']} = ? LIMIT 2");
                $s->execute([$fam, $t['value']]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (count($ids) === 1 && !in_array((int)$ids[0], $not, true)) return [(int)$ids[0], 1.0, 'exact'];
            }
        }
        // 3–4. The same words as one product (linked), or a clear most-alike (suggested).
        $want = self::tokens($clean);
        if (!$want) return [null, 0.0, null];
        $scored = [];
        foreach ($families as $fam) {
            foreach ($this->productsIn($fam) as $c) {
                if (in_array($c['id'], $not, true)) continue;                    // a person said "not this" for this wording
                $sc = self::score($want, $c['tokens']);
                if ($sc >= self::SUGGEST_AT - self::MARGIN) $scored[] = ['id' => $c['id'], 'score' => $sc];
            }
        }
        return self::pick($scored);
    }

    // ── A person's decisions (each taught as a shared alias) ───────────────────

    /** "Yes, that's it" or "it's this one": link the line and teach the vendor's SKU and wording. */
    public function confirm(int $lineId, int $productId, ?int $by = null, string $why = 'confirmed'): array
    {
        $l = $this->line($lineId);
        $p = $this->catalog->product($productId);
        if (!$l || !$p) return ['ok' => false, 'message' => 'That line or product is gone.'];
        $was = $this->currentName($l);
        $this->db->prepare("UPDATE expense_line_items SET catalog_product_id = ?, catalog_match = 'confirmed', catalog_suggested_id = NULL, catalog_score = 1 WHERE id = ?")
                 ->execute([$productId, $lineId]);
        $this->teach($l, $productId, (string)$p['family']);
        // Penny's lessons (PennyLessons): what she had for this line vs what the person said it is.
        if ($was !== (string)$p['canonical_name']) $this->lesson($l, $was, (string)$p['canonical_name'], $by, $why);
        $this->rematchVendor($l, $lineId, $productId);
        return ['ok' => true, 'product' => ['id' => $productId, 'name' => (string)$p['canonical_name'], 'family' => (string)$p['family']]];
    }

    /** "Not this": no suggestion on this line until it changes. */
    public function dismiss(int $lineId, ?int $by = null): array
    {
        $l = $this->line($lineId);
        if ($l && ($was = $this->currentName($l)) !== null) $this->lesson($l, $was, 'not this product', $by, 'dismissed');
        if ($l && ($pid = $this->currentId($l)) !== null) {
            [$clean] = self::clean((string)$l['name']);
            $k = self::aliasKeys(VendorIdentity::nameKey($l['vendor']), null, $clean)['n'] ?? null;
            if ($k !== null) {
                $nk = 'rcpt-no:' . substr($k, 5) . ':' . $pid;
                $s = $this->db->prepare("SELECT 1 FROM catalog_aliases WHERE family = ? AND alias_key = ?");
                $s->execute([self::NOT_FAMILY, $nk]);
                if (!$s->fetchColumn()) $this->db->prepare("INSERT INTO catalog_aliases (family, alias_key, product_id, how) VALUES (?, ?, ?, 'rejected')")
                                                ->execute([self::NOT_FAMILY, mb_substr($nk, 0, 255), $pid]);
            }
        }
        $this->db->prepare("UPDATE expense_line_items SET catalog_product_id = NULL, catalog_match = 'none', catalog_suggested_id = NULL, catalog_score = NULL WHERE id = ?")
                 ->execute([$lineId]);
        return ['ok' => true];
    }

    /** "Add to catalogue": the product a person names (an exact match is reused, never duplicated). */
    public function add(int $lineId, string $family, string $name, ?string $brand = null, ?int $by = null): array
    {
        $l = $this->line($lineId);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (!$l || $name === '') return ['ok' => false, 'message' => 'Give the product a name.'];
        $fam = $this->catalog->family($family);
        if (empty($fam['id'])) return ['ok' => false, 'message' => 'Pick what kind of product it is.'];   // family() falls back for unknown slugs
        $res = $this->catalog->resolve($family, ['name' => mb_substr($name, 0, 255), 'brand' => $brand, 'upc' => self::barcode($l['sku_raw'] ?? null)], null, false);
        if (empty($res['product_id'])) return ['ok' => false, 'message' => "That couldn't be added."];
        unset($this->products[$family]);   // a new product: read the family again
        $r = $this->confirm($lineId, (int)$res['product_id'], $by, 'added to catalogue');
        if (!$r['ok']) return $r;
        $this->db->prepare("UPDATE expense_line_items SET catalog_match = 'added' WHERE id = ?")->execute([$lineId]);
        return $r + ['new' => ($res['how'] ?? '') === 'new'];
    }

    /** Catalogue products whose name has these words (the "it's another one" picker). */
    public function search(string $q, ?string $family = null, int $limit = 12): array
    {
        $want = self::tokens($q);
        if (!$want) return [];
        $fams = $family ? [$family] : array_column($this->catalog->families(), 'slug');
        $scored = [];
        foreach ($fams as $fam) {
            foreach ($this->productsIn((string)$fam) as $c) {
                $hit = count(array_intersect($want, $c['tokens']));
                if ($hit) $scored[] = ['id' => $c['id'], 'name' => $c['name'], 'family' => $c['family'], 'score' => $hit / count($want) + self::score($want, $c['tokens']) / 10];
            }
        }
        usort($scored, static fn($x, $y) => $y['score'] <=> $x['score']);
        return array_slice($scored, 0, $limit);
    }

    /** What the receipt window shows for each line. @return array<int, array> keyed by line id */
    public function forExpense(int $expenseId): array
    {
        if (!$this->ready()) return [];
        $e = $this->expense($expenseId);
        $s = $this->db->prepare("SELECT l.id, l.name, l.catalog_match, l.catalog_score, l.catalog_product_id, l.catalog_suggested_id,
                                        p.canonical_name AS product, p.family, q.canonical_name AS suggested
                                 FROM expense_line_items l
                                 LEFT JOIN catalog_products p ON p.id = l.catalog_product_id
                                 LEFT JOIN catalog_products q ON q.id = l.catalog_suggested_id
                                 WHERE l.expense_id = ?");
        $s->execute([$expenseId]);
        $cat = (string)($e['accounting_category'] ?? '');
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eligible = isset(self::CATEGORY_FAMILIES[$cat]) && self::isProduct((string)$r['name']);
            [$clean] = self::clean((string)$r['name']);
            $out[(int)$r['id']] = [
                'eligible' => $eligible, 'match' => $r['catalog_match'], 'score' => $r['catalog_score'] !== null ? (float)$r['catalog_score'] : null,
                'product' => $r['catalog_product_id'] ? ['id' => (int)$r['catalog_product_id'], 'name' => (string)$r['product'], 'family' => (string)$r['family']] : null,
                'suggested' => $r['catalog_suggested_id'] ? ['id' => (int)$r['catalog_suggested_id'], 'name' => (string)$r['suggested']] : null,
                'add' => ['name' => $clean, 'family' => self::guessFamily($cat, (string)$r['name'], (string)($e['vendor'] ?? ''))],
            ];
        }
        return $out;
    }

    /** Past receipts: match every undecided line of eligible receipts since $since. */
    public function sweep(string $since = '2026-01-01', int $limit = 500): array
    {
        $tot = ['receipts' => 0, 'linked' => 0, 'suggested' => 0, 'open' => 0];
        if (!$this->ready()) return $tot;
        $cats = array_keys(self::CATEGORY_FAMILIES);
        $in = implode(',', array_fill(0, count($cats), '?'));
        $s = $this->db->prepare("SELECT DISTINCT e.id FROM expenses e JOIN expense_line_items l ON l.expense_id = e.id
                                 WHERE e.expense_date >= ? AND e.status <> 'rejected' AND e.accounting_category IN ($in)
                                   AND l.catalog_match IS NULL ORDER BY e.id LIMIT " . (int)$limit);   // never looked at
        $s->execute(array_merge([$since], $cats));
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $r = $this->match((int)$id);
            $tot['receipts']++;
            foreach (['linked', 'suggested', 'open'] as $k) $tot[$k] += $r[$k];
        }
        return $tot;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** The product Penny had on the line (linked or suggested), by id. */
    private function currentId(array $line): ?int
    {
        $s = $this->db->prepare("SELECT COALESCE(catalog_product_id, catalog_suggested_id) FROM expense_line_items WHERE id = ?");
        $s->execute([(int)$line['id']]);
        $v = $s->fetchColumn();
        return $v ? (int)$v : null;
    }

    /** Products a person refused for this wording at this vendor. */
    private function rejected(?string $nameKey): array
    {
        if ($nameKey === null) return [];
        $s = $this->db->prepare("SELECT product_id FROM catalog_aliases WHERE family = ? AND alias_key LIKE ?");
        $s->execute([self::NOT_FAMILY, 'rcpt-no:' . substr($nameKey, 5) . ':%']);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The product Penny had on the line (linked or suggested), by name. */
    private function currentName(array $line): ?string
    {
        $s = $this->db->prepare("SELECT COALESCE(p.canonical_name, q.canonical_name) FROM expense_line_items l
                                 LEFT JOIN catalog_products p ON p.id = l.catalog_product_id LEFT JOIN catalog_products q ON q.id = l.catalog_suggested_id WHERE l.id = ?");
        $s->execute([(int)$line['id']]);
        $v = $s->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    private function lesson(array $line, ?string $said, string $correct, ?int $by, string $why): void
    {
        try {
            if (!class_exists('PennyLessons')) require_once dirname(__DIR__, 2) . '/Accounting/Services/PennyLessons.php';
            PennyLessons::expense($this->db, (int)$line['expense_id'], 'catalog_product', $said, $correct, 'receipt_catalog', $by,
                                  mb_substr('Line "' . $line['name'] . '": ' . $why, 0, 255));
        } catch (Throwable $e) {
            error_log('[receipt catalogue] lesson: ' . $e->getMessage());
        }
    }

    private function teach(array $line, int $productId, string $family): void
    {
        [$clean] = self::clean((string)$line['name']);
        foreach (self::aliasKeys(VendorIdentity::nameKey($line['vendor']), $line['sku_raw'] ?? null, $clean) as $key) {
            // A correction to another family moves the key: never two products for one spelling.
            $this->db->prepare("DELETE FROM catalog_aliases WHERE alias_key = ? AND family <> ? AND family <> ?")->execute([$key, $family, self::NOT_FAMILY]);
            $this->catalog->learnAlias($family, $key, $productId);
        }
    }

    /** One tap links the same SKU / wording on this vendor's other receipts too — only those lines, directly. */
    private function rematchVendor(array $line, int $exceptLineId, int $productId): void
    {
        if (empty($line['vendor_id'])) return;
        $vk = VendorIdentity::nameKey($line['vendor']);
        [$clean] = self::clean((string)$line['name']);
        $taught = self::aliasKeys($vk, $line['sku_raw'] ?? null, $clean);
        if (!$taught) return;
        $s = $this->db->prepare("SELECT l.id, l.name, l.sku_raw FROM expense_line_items l JOIN expenses e ON e.id = l.expense_id
                                 WHERE l.id <> ? AND e.vendor_id = ? AND e.status <> 'rejected'
                                   AND (l.catalog_match IS NULL OR l.catalog_match IN ('suggested', 'open', 'alias', 'exact')) LIMIT 2000");
        $s->execute([$exceptLineId, (int)$line['vendor_id']]);
        $set = $this->db->prepare("UPDATE expense_line_items SET catalog_product_id = ?, catalog_match = 'alias', catalog_suggested_id = NULL, catalog_score = 1 WHERE id = ?");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $k = self::aliasKeys($vk, $o['sku_raw'] ?? null, self::clean((string)$o['name'])[0]);
            if (array_intersect_assoc($k, $taught)) $set->execute([$productId, (int)$o['id']]);
        }
    }

    private function expense(int $id): ?array
    {
        $s = $this->db->prepare("SELECT e.id, e.accounting_category, e.vendor_id, COALESCE(v.name, e.vendor_name_raw, '') AS vendor
                                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function line(int $id): ?array
    {
        $s = $this->db->prepare("SELECT l.id, l.expense_id, l.name, l.sku_raw, e.vendor_id, COALESCE(v.name, e.vendor_name_raw, '') AS vendor
                                 FROM expense_line_items l JOIN expenses e ON e.id = l.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id WHERE l.id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function exists(int $productId): bool
    {
        $s = $this->db->prepare("SELECT 1 FROM catalog_products WHERE id = ?");
        $s->execute([$productId]);
        return (bool)$s->fetchColumn();
    }

    /** A family's products with their words (name + common names), read once per request. */
    private function productsIn(string $family): array
    {
        if (!isset($this->products[$family])) {
            $s = $this->db->prepare("SELECT id, canonical_name, common_names FROM catalog_products WHERE family = ? LIMIT 5000");
            $s->execute([$family]);
            $this->products[$family] = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $this->products[$family][] = ['id' => (int)$r['id'], 'name' => (string)$r['canonical_name'], 'family' => $family,
                    'tokens' => self::tokens($r['canonical_name'] . ' ' . str_replace('|', ' ', (string)$r['common_names']))];
            }
        }
        return $this->products[$family];
    }
}
