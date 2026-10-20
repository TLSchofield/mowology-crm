<?php
/**
 * CatalogService — the shared product catalogue (owner, 2026-10-12). Migration 1331.
 *
 * SHARED tables (no site_id, no prices): catalog_families, catalog_categories, catalog_category_map,
 * catalog_products, catalog_attribute_defs/values, catalog_size_equivalents, catalog_aliases.
 * PRIVATE table (site_id): catalog_match_queue — this company's fuzzy matches waiting for a person.
 *
 * resolve() links one supplier line to a catalogue product:
 *   1. a confirmed alias (any tenant's confirmation)       → auto, confidence 1
 *   2. the family matcher's exact tiers in order            → auto (exact 1, mpn .95, alt .9)
 *      — a tier that finds two products is ambiguous and only suggests
 *   3. fuzzy within the group (genus / brand)               → SUGGESTED into the review queue, no link
 *   4. nothing alike                                        → a new catalogue product (seeds the catalogue)
 * A person's decision (confirm / merge / separate) is learned as an alias, so the next list from any
 * supplier, for any tenant, matches by itself.
 *
 * SQL is portable (MySQL 5.7 and SQLite for tests): no INSERT IGNORE / ON DUPLICATE KEY.
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogMatchers.php';
require_once __DIR__ . '/SupplierListParser.php';

class CatalogService
{
    public const SUGGEST_AT = 0.75;

    private PDO $db;
    private int $siteId;
    private array $families = [];
    private array $sizeCache = [];
    private array $catCache = [];
    private array $resolveCache = [];

    public function __construct(PDO $db, int $siteId = 1)
    {
        $this->db = $db;
        $this->siteId = $siteId;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM catalog_products LIMIT 1");
            $this->db->query("SELECT 1 FROM catalog_families LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---- families ------------------------------------------------------------------------

    public function family(string $slug): array
    {
        if (!isset($this->families[$slug])) {
            $s = $this->db->prepare("SELECT * FROM catalog_families WHERE slug = ?");
            $s->execute([$slug]);
            $this->families[$slug] = $s->fetch(PDO::FETCH_ASSOC) ?: ['slug' => $slug, 'name' => $slug, 'matcher' => 'part_number', 'default_use_kind' => null];
        }
        return $this->families[$slug];
    }

    public function matcher(string $family): CatalogMatcher
    {
        return CatalogMatchers::for((string)$this->family($family)['matcher']);
    }

    /** All families, with 'used' = this company works with it (tenant_catalog_families). */
    public function families(): array
    {
        $rows = $this->db->query("SELECT * FROM catalog_families ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        $s = $this->db->prepare("SELECT family FROM tenant_catalog_families WHERE site_id = ?");
        $s->execute([$this->siteId]);
        $used = array_flip($s->fetchAll(PDO::FETCH_COLUMN));
        foreach ($rows as &$r) $r['used'] = !$used || isset($used[$r['slug']]);
        return $rows;
    }

    // ---- sizes and categories --------------------------------------------------------------

    /** Supplier size → canonical code (equivalence table first, then the family matcher's rule). */
    public function size(string $family, ?string $raw): array
    {
        $raw = trim((string)$raw);
        if ($raw === '') return ['code' => null, 'height' => null];
        $ck = $family . '|' . $raw;
        if (isset($this->sizeCache[$ck])) return $this->sizeCache[$ck];
        $byRule = $this->matcher($family)->size($raw);
        $code = $this->equivalent($family, strtolower(trim((string)preg_replace('/\s+/', ' ', $raw))))
             ?? $this->equivalent($family, (string)$byRule['code'])
             ?? $byRule['code'];
        return $this->sizeCache[$ck] = ['code' => $code, 'height' => $byRule['height']];
    }

    private function equivalent(string $family, string $rawKey): ?string
    {
        if ($rawKey === '') return null;
        $s = $this->db->prepare("SELECT canonical FROM catalog_size_equivalents WHERE family = ? AND raw_key = ?");
        $s->execute([$family, $rawKey]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Notes for canonical sizes ("No.1 pot, about 1 US gal"). */
    public function sizeNotes(string $family): array
    {
        $s = $this->db->prepare("SELECT canonical, note FROM catalog_size_equivalents WHERE family = ? AND raw_key = canonical");
        $s->execute([$family]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Supplier category text → catalogue category id. Learned and shared: a mapped text is reused by
     * every tenant; an unmapped one is matched to a category of the same name, or becomes a new
     * category under the family (and the mapping is remembered).
     */
    public function categoryFor(string $family, ?string $text): ?int
    {
        $key = SupplierListParser::fold((string)$text);
        if ($key === '') return null;
        $ck = $family . '|' . $key;
        if (array_key_exists($ck, $this->catCache)) return $this->catCache[$ck];
        $s = $this->db->prepare("SELECT category_id FROM catalog_category_map WHERE family = ? AND text_key = ?");
        $s->execute([$family, $key]);
        $id = $s->fetchColumn();
        if ($id !== false) return $this->catCache[$ck] = (int)$id;

        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', $key), '-');
        $s = $this->db->prepare("SELECT id FROM catalog_categories WHERE family = ? AND (slug = ? OR LOWER(name) = ?)");
        $s->execute([$family, $slug, strtolower(trim((string)$text))]);
        $id = $s->fetchColumn();
        $how = 'name';
        if ($id === false) {
            $root = $this->db->prepare("SELECT id FROM catalog_categories WHERE family = ? AND parent_id IS NULL");
            $root->execute([$family]);
            $parent = $root->fetchColumn();
            $this->db->prepare("INSERT INTO catalog_categories (parent_id, family, name, slug) VALUES (?, ?, ?, ?)")
                     ->execute([$parent === false ? null : (int)$parent, $family, mb_substr(trim((string)$text), 0, 120), substr($slug, 0, 120)]);
            $id = (int)$this->db->lastInsertId();
            $how = 'new';
        }
        $this->db->prepare("INSERT INTO catalog_category_map (family, text_key, category_id, how) VALUES (?, ?, ?, ?)")
                 ->execute([$family, $key, (int)$id, $how]);
        return $this->catCache[$ck] = (int)$id;
    }

    // ---- matching --------------------------------------------------------------------------

    /**
     * Link one supplier line to the catalogue.
     * $suggest = false skips the fuzzy tier: a machine model number that matches no exact tier is a
     * different model (HRX217 is not HRC216), so it becomes its own product instead of a review item
     * (EquipmentCatalogService, 2026-10-13).
     * @return array{product_id: ?int, confidence: ?float, how: string, name_key: string, suggested_id: ?int}
     */
    public function resolve(string $family, array $item, ?int $categoryId = null, bool $suggest = true): array
    {
        $m = $this->matcher($family);
        $p = $m->parse($item);
        $ck = $family . '|' . $p['key'];
        if (isset($this->resolveCache[$ck])) {
            $r = $this->resolveCache[$ck];
            // Made earlier in this run from the same key: from now on that is an exact match.
            if ($r['how'] === 'new') $r = $this->resolveCache[$ck] = ['how' => 'exact', 'confidence' => 1.0] + $r;
            if ($r['product_id']) $this->addAttrs((int)$r['product_id'], $p['attrs'], 'supplier list');
            return $r;
        }
        $out = ['product_id' => null, 'confidence' => null, 'how' => 'suggested', 'name_key' => $p['key'], 'suggested_id' => null];

        $s = $this->db->prepare("SELECT product_id FROM catalog_aliases WHERE family = ? AND alias_key = ?");
        $s->execute([$family, $p['key']]);
        $id = $s->fetchColumn();
        if ($id !== false) {
            $out = ['product_id' => (int)$id, 'confidence' => 1.0, 'how' => 'alias'] + $out;
        } else {
            foreach ($p['lookups'] as $t) {
                if (!in_array($t['col'], ['match_key', 'alt_key', 'upc', 'mfr_part_no'], true) || $t['value'] === '') continue;
                $s = $this->db->prepare("SELECT id, canonical_name, brand, mfr_part_no, upc, match_key FROM catalog_products WHERE family = ? AND {$t['col']} = ? ORDER BY id LIMIT 5");
                $s->execute([$family, $t['value']]);
                $ids = [];
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if ($m->accepts($p, $m->fromCatalog($c), $t['how'])) $ids[] = (int)$c['id'];
                }
                if (count($ids) === 1) { $out = ['product_id' => (int)$ids[0], 'confidence' => $t['confidence'], 'how' => $t['how']] + $out; break; }
                if (count($ids) > 1) { $this->enqueue($family, $p['key'], (string)$item['name'], (int)$ids[0], $t['confidence']); $out['suggested_id'] = (int)$ids[0]; return $this->resolveCache[$ck] = $out; }
            }
        }
        if ($out['product_id'] === null) {
            [$bestId, $score] = $suggest ? $this->closest($family, $m, $p) : [null, 0.0];
            if ($bestId !== null && $score >= self::SUGGEST_AT) {
                $this->enqueue($family, $p['key'], (string)$item['name'], $bestId, $score);
                $out['suggested_id'] = $bestId;
                $out['confidence'] = $score;
                return $this->resolveCache[$ck] = $out;
            }
            $out = ['product_id' => $this->create($family, $p, $categoryId), 'confidence' => 1.0, 'how' => 'new'] + $out;
        }
        $this->addAttrs((int)$out['product_id'], $p['attrs'], 'supplier list');
        return $this->resolveCache[$ck] = $out;
    }

    /** Best fuzzy candidate in the same group (genus / brand): [id, score]. */
    private function closest(string $family, CatalogMatcher $m, array $p): array
    {
        if ($p['group_key'] === '') return [null, 0.0];
        $s = $this->db->prepare("SELECT id, canonical_name, brand, mfr_part_no, upc, match_key FROM catalog_products WHERE family = ? AND group_key = ? LIMIT 500");
        $s->execute([$family, $p['group_key']]);
        $best = [null, 0.0];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $sc = $m->similarity($p, $m->fromCatalog($c));
            if ($sc > $best[1]) $best = [(int)$c['id'], $sc];
        }
        return $best;
    }

    private function create(string $family, array $p, ?int $categoryId): int
    {
        $this->db->prepare("INSERT INTO catalog_products (family, category_id, canonical_name, brand, mfr_part_no, upc, match_key, alt_key, group_key, common_names)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                 ->execute([$family, $categoryId, mb_substr($p['display'] !== '' ? $p['display'] : $p['key'], 0, 255), $p['brand'], $p['mfr_part_no'], $p['upc'],
                            mb_substr($p['key'], 0, 255), $p['alt_key'], mb_substr($p['group_key'], 0, 120),
                            $p['aliases'] ? mb_substr(implode('|', $p['aliases']), 0, 255) : null]);
        return (int)$this->db->lastInsertId();
    }

    /** Facts the list gives (zone). Never overwrites a value already there. */
    private function addAttrs(int $productId, array $attrs, string $source): void
    {
        if (!$attrs) return;
        $have = $this->db->prepare("SELECT attr_key FROM catalog_attribute_values WHERE product_id = ?");
        $have->execute([$productId]);
        $existing = array_flip($have->fetchAll(PDO::FETCH_COLUMN));
        $ins = $this->db->prepare("INSERT INTO catalog_attribute_values (product_id, attr_key, value, source) VALUES (?, ?, ?, ?)");
        foreach ($attrs as $k => $v) {
            if (isset($existing[$k]) || (string)$v === '') continue;
            $ins->execute([$productId, $k, mb_substr((string)$v, 0, 255), $source]);
        }
    }

    public function attributes(int $productId): array
    {
        $s = $this->db->prepare("SELECT attr_key, value FROM catalog_attribute_values WHERE product_id = ?");
        $s->execute([$productId]);
        return $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function product(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM catalog_products WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ---- review queue (private) and what it teaches (shared) --------------------------------

    private function enqueue(string $family, string $key, string $raw, ?int $suggested, float $score): void
    {
        $s = $this->db->prepare("SELECT id, status FROM catalog_match_queue WHERE site_id = ? AND family = ? AND name_key = ?");
        $s->execute([$this->siteId, $family, $key]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ($row['status'] === 'open') {
                $this->db->prepare("UPDATE catalog_match_queue SET suggested_product_id = ?, score = ? WHERE id = ?")->execute([$suggested, round($score, 3), $row['id']]);
            }
            return;
        }
        $this->db->prepare("INSERT INTO catalog_match_queue (site_id, family, name_key, raw_name, suggested_product_id, score) VALUES (?, ?, ?, ?, ?, ?)")
                 ->execute([$this->siteId, $family, mb_substr($key, 0, 255), mb_substr($raw, 0, 255), $suggested, round($score, 3)]);
    }

    public function queue(string $status = 'open', int $limit = 100): array
    {
        $s = $this->db->prepare("SELECT q.*, p.canonical_name AS suggested_name,
                                        (SELECT COUNT(*) FROM supplier_price_items i WHERE i.site_id = q.site_id AND i.name_key = q.name_key) AS item_count
                                 FROM catalog_match_queue q LEFT JOIN catalog_products p ON p.id = q.suggested_product_id
                                 WHERE q.site_id = ? AND q.status = ? ORDER BY q.score DESC, q.id LIMIT " . max(1, min(500, $limit)));
        $s->execute([$this->siteId, $status]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * A person's decision on a queued name:
     *   confirm  — it IS the suggested product (alias learned)
     *   merge    — it is product $targetId (alias learned)
     *   separate — it is a different product (a new catalogue entry)
     * @return int the product the name now links to
     */
    public function decide(int $queueId, string $action, ?int $userId = null, ?int $targetId = null): int
    {
        $s = $this->db->prepare("SELECT * FROM catalog_match_queue WHERE id = ? AND site_id = ?");
        $s->execute([$queueId, $this->siteId]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q || $q['status'] !== 'open') throw new RuntimeException('not an open review item');
        $family = (string)$q['family'];
        if ($action === 'confirm' || $action === 'merge') {
            $pid = $action === 'merge' ? (int)$targetId : (int)$q['suggested_product_id'];
            if ($pid <= 0 || !$this->product($pid)) throw new RuntimeException('no such catalogue product');
            $this->learnAlias($family, (string)$q['name_key'], $pid, $action === 'merge' ? 'merged' : 'confirmed');
            $how = 'confirmed';
            $status = $action === 'merge' ? 'merged' : 'confirmed';
        } elseif ($action === 'separate') {
            $p = $this->matcher($family)->parse(['name' => $q['raw_name']]);
            $exists = $this->db->prepare("SELECT id FROM catalog_products WHERE family = ? AND match_key = ?");
            $exists->execute([$family, $p['key']]);
            $pid = (int)($exists->fetchColumn() ?: 0) ?: $this->create($family, $p, null);
            $this->addAttrs($pid, $p['attrs'], 'supplier list');
            $how = 'new';
            $status = 'separate';
        } else {
            throw new RuntimeException('unknown action');
        }
        $this->relink((string)$q['name_key'], $pid, $how);
        $this->db->prepare("UPDATE catalog_match_queue SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?")
                 ->execute([$status, $userId, date('Y-m-d H:i:s'), $queueId]);
        return $pid;
    }

    /** Remember that this spelling is that product — shared with every tenant. */
    public function learnAlias(string $family, string $key, int $productId, string $how = 'confirmed'): void
    {
        $s = $this->db->prepare("SELECT id FROM catalog_aliases WHERE family = ? AND alias_key = ?");
        $s->execute([$family, $key]);
        $id = $s->fetchColumn();
        if ($id !== false) {
            $this->db->prepare("UPDATE catalog_aliases SET product_id = ?, how = ?, times_confirmed = times_confirmed + 1 WHERE id = ?")->execute([$productId, $how, $id]);
        } else {
            $this->db->prepare("INSERT INTO catalog_aliases (family, alias_key, product_id, how) VALUES (?, ?, ?, ?)")->execute([$family, mb_substr($key, 0, 255), $productId, $how]);
        }
        unset($this->resolveCache[$family . '|' . $key]);
    }

    /** This company's lines with that name now point at $productId. */
    private function relink(string $nameKey, int $productId, string $how): void
    {
        $this->db->prepare("UPDATE supplier_price_items SET catalog_product_id = ?, match_how = ?, match_confidence = 1 WHERE site_id = ? AND name_key = ?")
                 ->execute([$productId, $how, $this->siteId, $nameKey]);
    }

    /**
     * Two catalogue entries are the same product: $fromId folds into $intoId. Its keys become
     * aliases, its facts fill gaps, and every line pointing at it (any tenant — the catalogue is
     * shared, prices are not touched) points at $intoId.
     */
    public function mergeProducts(int $fromId, int $intoId): void
    {
        if ($fromId === $intoId) return;
        $from = $this->product($fromId);
        $into = $this->product($intoId);
        if (!$from || !$into || $from['family'] !== $into['family']) throw new RuntimeException('cannot merge these products');
        $this->learnAlias((string)$from['family'], (string)$from['match_key'], $intoId, 'merged');
        $this->db->prepare("UPDATE catalog_aliases SET product_id = ? WHERE product_id = ?")->execute([$intoId, $fromId]);
        $this->addAttrs($intoId, $this->attributes($fromId), 'merged');
        $this->db->prepare("DELETE FROM catalog_attribute_values WHERE product_id = ?")->execute([$fromId]);
        $this->db->prepare("UPDATE supplier_price_items SET catalog_product_id = ? WHERE catalog_product_id = ?")->execute([$intoId, $fromId]);
        $this->db->prepare("UPDATE catalog_match_queue SET suggested_product_id = ? WHERE suggested_product_id = ?")->execute([$intoId, $fromId]);
        // Receipt lines (migration 1378) follow the merge too, when that column is there.
        try {
            $this->db->prepare("UPDATE expense_line_items SET catalog_product_id = ? WHERE catalog_product_id = ?")->execute([$intoId, $fromId]);
            $this->db->prepare("UPDATE expense_line_items SET catalog_suggested_id = ? WHERE catalog_suggested_id = ?")->execute([$intoId, $fromId]);
        } catch (PDOException $e) { /* before 1378 */ }
        $this->db->prepare("DELETE FROM catalog_products WHERE id = ?")->execute([$fromId]);
    }
}
