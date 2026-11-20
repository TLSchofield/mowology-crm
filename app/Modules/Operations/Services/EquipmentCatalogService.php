<?php
/**
 * EquipmentCatalogService — a machine's MODEL in the shared catalogue (migration 1350, Tim 2026-10-13).
 *
 * SHARED (every tenant, no prices, no serials) — hangs off the catalogue product (family 'tools'):
 *   the model itself              catalog_products, matched by brand + model number with the part-number
 *                                 matcher (CatalogService::resolve(…, suggest: false) — HRX217 is never
 *                                 "nearly" HRC216; a model number that matches nothing is a new model)
 *   specs                         catalog_attribute_values (equipment_class, power_source, model_name, …)
 *   parts                         catalog_attribute_values part_* (blade, air filter, spark plug…); each part
 *                                 number is ALSO its own catalogue product (brand + mfr_part_no), so a
 *                                 dealer's parts price list imported through SupplierPriceListService links
 *                                 to it by the mpn tier and this company's price shows beside the part
 *   manual intervals              catalog_service_intervals — what the maker's manual says, with its words
 *                                 and page (recorded when Tim confirms a line Otto read; never invented)
 * PRIVATE (this company): equipment rows (serial, cost, receipt, truck, hours, log) and
 *   equipment_service_intervals — including model-level rows (catalog_product_id) = "every unit of this
 *   model", which Tim adopts from the shared intervals with one tap.
 *
 * SQL is portable (MySQL 5.7 and SQLite for tests). No namespace: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogService.php';

class EquipmentCatalogService
{
    public const FAMILY = 'tools';
    public const PART_KEYS = [
        'part_blade' => 'Blade', 'part_air_filter' => 'Air filter', 'part_spark_plug' => 'Spark plug', 'part_oil' => 'Engine oil',
        'part_belt' => 'Belt', 'part_line' => 'Trimmer line / head', 'part_chain' => 'Chain / bar', 'part_fuel_filter' => 'Fuel filter',
    ];
    /** Category under the tools family, per class. */
    public const CATEGORY = [
        'mower' => 'Mowers', 'trimmer' => 'Trimmers', 'blower' => 'Blowers', 'hedge_trimmer' => 'Hedge trimmers', 'saw' => 'Saws',
        'aerator' => 'Aerators', 'battery_pack' => 'Batteries', 'truck' => 'Trucks', 'trailer' => 'Trailers', 'other' => 'Other equipment',
    ];

    private PDO $db;
    private int $siteId;
    private CatalogService $cat;
    private array $colCache = [];

    public function __construct(PDO $db, int $siteId = 1)
    {
        $this->db = $db;
        $this->siteId = $siteId;
        $this->cat = new CatalogService($db, $siteId);
    }

    /** Catalogue tables exist and equipment carries the model link (migrations 1331 + 1350). */
    public function ready(): bool
    {
        return $this->cat->ready() && $this->hasCol('equipment', 'catalog_product_id');
    }

    public function hasCol(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!array_key_exists($k, $this->colCache)) {
            try { $this->db->query("SELECT {$col} FROM {$table} LIMIT 0"); $this->colCache[$k] = true; }
            catch (Throwable $e) { $this->colCache[$k] = false; }
        }
        return $this->colCache[$k];
    }

    // ── The model ───────────────────────────────────────────────────────────

    /**
     * The catalogue product for a make + model, made on first sight. Writes only facts about the model
     * (never the serial, price or anything about this unit). Null when there is no model number.
     */
    public function linkModel(?string $make, ?string $model, ?string $class = null, ?string $power = null, ?string $modelName = null): ?int
    {
        $model = trim((string)$model);
        if ($model === '' || !$this->cat->ready()) return null;
        $make = trim((string)$make);
        $class = $class !== null && isset(self::CATEGORY[$class]) ? $class : null;
        $categoryId = $class ? $this->cat->categoryFor(self::FAMILY, self::CATEGORY[$class]) : null;
        $r = $this->cat->resolve(self::FAMILY, ['name' => trim($make . ' ' . $model), 'brand' => $make !== '' ? $make : null, 'mfr_part_no' => $model],
                                 $categoryId, false);
        $id = $r['product_id'] ?? null;
        if (!$id) return null;
        $facts = ['equipment_class' => $class, 'power_source' => $power, 'model_name' => $modelName];
        foreach ($facts as $k => $v) if ($v !== null && trim((string)$v) !== '') $this->setAttr((int)$id, $k, (string)$v, 'plate', false);
        return (int)$id;
    }

    /** The existing catalogue product for a model, WITHOUT creating one (the plate preview). */
    public function findModel(?string $make, ?string $model): ?array
    {
        $mpn = PartNumberMatcher::mpn($model);
        if ($mpn === null || !$this->cat->ready()) return null;
        $s = $this->db->prepare("SELECT * FROM catalog_products WHERE family = ? AND mfr_part_no = ? ORDER BY id LIMIT 5");
        $s->execute([self::FAMILY, $mpn]);
        $brand = PlantNameNormalizer::fold((string)$make);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $b = PlantNameNormalizer::fold((string)($p['brand'] ?? ''));
            if ($brand === '' || $b === '' || $b === $brand) return $p;
        }
        return null;
    }

    public function product(int $id): ?array
    {
        return $this->cat->product($id);
    }

    public function attributes(int $productId): array
    {
        return $this->cat->attributes($productId);
    }

    /** Units of this model in THIS company (private count). */
    public function unitsOfModel(int $productId): int
    {
        if (!$this->hasCol('equipment', 'catalog_product_id')) return 0;
        $s = $this->db->prepare("SELECT COUNT(*) FROM equipment WHERE catalog_product_id = ? AND status = 'active'");
        $s->execute([$productId]);
        return (int)$s->fetchColumn();
    }

    /** Shared attribute write. $overwrite = false keeps a fact already there (the plate never overrides a person). */
    public function setAttr(int $productId, string $key, string $value, string $source, bool $overwrite = true): void
    {
        $value = mb_substr(trim($value), 0, 255);
        $s = $this->db->prepare("SELECT value FROM catalog_attribute_values WHERE product_id = ? AND attr_key = ?");
        $s->execute([$productId, $key]);
        $have = $s->fetchColumn();
        if ($have !== false) {
            if (!$overwrite || (string)$have === $value) return;
            if ($value === '') {
                $this->db->prepare("DELETE FROM catalog_attribute_values WHERE product_id = ? AND attr_key = ?")->execute([$productId, $key]);
                return;
            }
            $this->db->prepare("UPDATE catalog_attribute_values SET value = ?, source = ? WHERE product_id = ? AND attr_key = ?")
                     ->execute([$value, $source, $productId, $key]);
            return;
        }
        if ($value === '') return;
        $this->db->prepare("INSERT INTO catalog_attribute_values (product_id, attr_key, value, source) VALUES (?, ?, ?, ?)")
                 ->execute([$productId, $key, $value, $source]);
    }

    // ── Parts (shared) ──────────────────────────────────────────────────────

    /**
     * Set one part for a model: the attribute holds the part number as printed; when it looks like a
     * part number (has a digit) it also becomes a catalogue product of its own (brand + mpn), which is
     * what a dealer's parts list matches on.
     */
    public function setPart(int $productId, string $key, string $value): array
    {
        if (!isset(self::PART_KEYS[$key])) return ['ok' => false, 'message' => 'Unknown part.'];
        $model = $this->product($productId);
        if (!$model || $model['family'] !== self::FAMILY) return ['ok' => false, 'message' => 'That model is not in the catalogue.'];
        $value = trim($value);
        $this->setAttr($productId, $key, $value, 'owner');
        $partId = null;
        if ($value !== '' && preg_match('/\d/', $value)) $partId = $this->partProduct((string)($model['brand'] ?? ''), $value, self::PART_KEYS[$key]);
        return ['ok' => true, 'message' => $value === '' ? self::PART_KEYS[$key] . ' removed.' : self::PART_KEYS[$key] . ' saved for every ' . $model['canonical_name'] . '.',
                'part_product_id' => $partId];
    }

    /** The catalogue product for a part number (made on first sight, never fuzzy). */
    public function partProduct(string $brand, string $partNo, string $label): ?int
    {
        $brand = trim($brand);
        $r = $this->cat->resolve(self::FAMILY, ['name' => trim($brand . ' ' . $label . ' ' . $partNo), 'brand' => $brand !== '' ? $brand : null,
                                                'mfr_part_no' => $partNo], $this->cat->categoryFor(self::FAMILY, 'Parts'), false);
        return $r['product_id'] ?? null;
    }

    /**
     * Parts of a model: [{key, label, value, part_product_id, price, price_date, supplier}] — every part key,
     * blank when unknown. The price is THIS company's latest supplier price for the part (private), if a
     * dealer's list has been imported; nothing shared carries a price.
     */
    public function parts(int $productId): array
    {
        $attrs = $this->attributes($productId);
        $model = $this->product($productId);
        $out = [];
        foreach (self::PART_KEYS as $k => $label) {
            $v = (string)($attrs[$k] ?? '');
            $row = ['key' => $k, 'label' => $label, 'value' => $v, 'part_product_id' => null, 'price' => null, 'price_date' => null, 'supplier' => null];
            if ($v !== '' && ($mpn = PartNumberMatcher::mpn($v)) !== null) {
                $s = $this->db->prepare("SELECT id, brand FROM catalog_products WHERE family = ? AND mfr_part_no = ? ORDER BY id");
                $s->execute([self::FAMILY, $mpn]);
                $brand = PlantNameNormalizer::fold((string)($model['brand'] ?? ''));
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $b = PlantNameNormalizer::fold((string)($p['brand'] ?? ''));
                    if ($brand === '' || $b === '' || $b === $brand) { $row['part_product_id'] = (int)$p['id']; break; }
                }
                if ($row['part_product_id']) $row = $this->latestPrice($row['part_product_id']) + $row;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** This company's latest list price for a catalogue product (PRIVATE tables, this site only). */
    private function latestPrice(int $productId): array
    {
        try {
            $s = $this->db->prepare("SELECT i.price, i.special_price, l.list_date, a.name AS supplier
                                     FROM supplier_price_items i JOIN supplier_price_lists l ON l.id = i.list_id
                                     LEFT JOIN supplier_accounts a ON a.id = l.supplier_account_id
                                     WHERE i.site_id = ? AND i.catalog_product_id = ? AND l.needs_review = 0 ORDER BY l.list_date DESC, i.id DESC LIMIT 1");
            $s->execute([$this->siteId, $productId]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        if (!$r) return [];
        $p = $r['special_price'] !== null ? (float)$r['special_price'] : ($r['price'] !== null ? (float)$r['price'] : null);
        return ['price' => $p, 'price_date' => (string)$r['list_date'], 'supplier' => $r['supplier']];
    }

    // ── Manual intervals (shared) ───────────────────────────────────────────

    public function sharedIntervals(int $productId): array
    {
        if (!$this->sharedReady()) return [];
        $s = $this->db->prepare("SELECT * FROM catalog_service_intervals WHERE product_id = ? ORDER BY task");
        $s->execute([$productId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sharedReady(): bool
    {
        try { $this->db->query("SELECT 1 FROM catalog_service_intervals LIMIT 1"); return true; } catch (Throwable $e) { return false; }
    }

    /**
     * A manual line a person confirmed → the model's shared interval (the manual's own numbers, words and
     * page). A second confirmation of the same task counts up; it never rewrites the first reading.
     */
    public function recordManualInterval(int $productId, string $task, ?float $hours, ?int $days, ?string $page, ?string $quote): void
    {
        if (!$this->sharedReady()) return;
        $task = mb_substr(trim($task), 0, 60);
        if ($task === '' || (!$hours && !$days)) return;
        $key = mb_strtolower($task);
        $s = $this->db->prepare("SELECT id FROM catalog_service_intervals WHERE product_id = ? AND task_key = ?");
        $s->execute([$productId, $key]);
        $id = $s->fetchColumn();
        if ($id !== false) {
            $this->db->prepare("UPDATE catalog_service_intervals SET times_confirmed = times_confirmed + 1 WHERE id = ?")->execute([(int)$id]);
            return;
        }
        $this->db->prepare("INSERT INTO catalog_service_intervals (product_id, task, task_key, every_hours, every_days, page, quote, source)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 'manual')")
                 ->execute([$productId, $task, $key, $hours ?: null, $days ?: null, $page !== null && $page !== '' ? mb_substr($page, 0, 20) : null,
                            $quote !== null && $quote !== '' ? mb_substr($quote, 0, 300) : null]);
    }

    /**
     * "Use the manual's intervals": copy the model's shared intervals into this company's own, at model
     * level (every unit of the model). Tasks this company already has for the model are left alone.
     * @return int how many were added
     */
    public function adoptShared(int $productId): int
    {
        if (!$this->hasCol('equipment_service_intervals', 'catalog_product_id')) return 0;
        $have = $this->db->prepare("SELECT LOWER(task) FROM equipment_service_intervals WHERE catalog_product_id = ?");
        $have->execute([$productId]);
        $taken = array_flip($have->fetchAll(PDO::FETCH_COLUMN));
        $ins = $this->db->prepare("INSERT INTO equipment_service_intervals (equipment_class, equipment_id, catalog_product_id, task, every_hours, every_days) VALUES (NULL, NULL, ?, ?, ?, ?)");
        $n = 0;
        foreach ($this->sharedIntervals($productId) as $iv) {
            if (isset($taken[mb_strtolower((string)$iv['task'])])) continue;
            $ins->execute([$productId, $iv['task'], $iv['every_hours'], $iv['every_days']]);
            $n++;
        }
        return $n;
    }

    /** Shared intervals this company has not taken for the model yet. */
    public function notAdopted(int $productId): array
    {
        $shared = $this->sharedIntervals($productId);
        if (!$shared || !$this->hasCol('equipment_service_intervals', 'catalog_product_id')) return $shared;
        $have = $this->db->prepare("SELECT LOWER(task) FROM equipment_service_intervals WHERE catalog_product_id = ?");
        $have->execute([$productId]);
        $taken = array_flip($have->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter($shared, fn($iv) => !isset($taken[mb_strtolower((string)$iv['task'])])));
    }
}
