<?php
/** Purchase history for a shared catalogue product. Global namespace; MySQL 5.7 compatible. */
declare(strict_types=1);

class ProductPriceHistoryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Effective price for one purchased package. PURE. */
    public static function effectivePrice(?float $unitPrice, ?float $lineTotal, ?float $quantity): ?float
    {
        if ($unitPrice !== null && $unitPrice > 0) return $unitPrice;
        if ($lineTotal !== null && $lineTotal > 0 && $quantity !== null && $quantity > 0) return $lineTotal / $quantity;
        return null;
    }

    /** Pick newest and cheapest from newest-first normalized rows. PURE. */
    public static function pick(array $rows, bool $usePerUnit): ?array
    {
        if (!$rows) return null;
        $last = $rows[0];
        $cheapest = null;
        foreach ($rows as $row) {
            $value = $usePerUnit && $row['per_unit_value'] !== null ? (float)$row['per_unit_value'] : (float)$row['unit_price'];
            if ($cheapest === null || $value < $cheapest['_compare']) $cheapest = $row + ['_compare' => $value];
        }
        unset($cheapest['_compare']);
        $shape = static function (array $r): array {
            return ['vendor' => $r['vendor'], 'date' => $r['date'], 'price' => $r['unit_price'],
                    'per_unit_label' => $r['per_unit_label'], 'per_unit_value' => $r['per_unit_value']];
        };
        return ['last' => $shape($last), 'cheapest' => $shape($cheapest), 'count' => count($rows)];
    }

    public function history(int $productId, int $limit = 200): array
    {
        $product = $this->product($productId);
        if (!$product) return ['product' => null, 'history' => []];
        return ['product' => $product, 'history' => $this->rows($productId, max(1, min(500, $limit)), 0, $product)];
    }

    public function byVendor(int $productId): array
    {
        $product = $this->product($productId);
        if (!$product) return [];
        $groups = [];
        foreach ($this->rows($productId, null, 0, $product) as $row) $groups[$row['vendor']][] = $row;
        $out = [];
        foreach ($groups as $vendor => $rows) {
            $last = $rows[0];
            $cheap = self::pick($rows, $last['per_unit_value'] !== null)['cheapest'];
            $out[] = ['vendor' => $vendor, 'last_date' => $last['date'], 'last_price' => $last['unit_price'], 'last_per_unit' => $last['per_unit_value'],
                      'cheapest_price' => $cheap['price'], 'cheapest_per_unit' => $cheap['per_unit_value'], 'per_unit_label' => $cheap['per_unit_label'], 'count' => count($rows)];
        }
        usort($out, static function ($a, $b) {
            $av = $a['cheapest_per_unit'] !== null ? $a['cheapest_per_unit'] : $a['cheapest_price'];
            $bv = $b['cheapest_per_unit'] !== null ? $b['cheapest_per_unit'] : $b['cheapest_price'];
            return $av <=> $bv;
        });
        return $out;
    }

    public function hint(int $productId, int $excludeLineId = 0): ?array
    {
        $product = $this->product($productId);
        if (!$product) return null;
        $rows = $this->rows($productId, null, $excludeLineId, $product);
        return self::pick($rows, $product['unit_size'] !== null);
    }

    private function product(int $productId): ?array
    {
        try {
            $s = $this->db->prepare('SELECT canonical_name AS name, unit_size, unit_size_unit FROM catalog_products WHERE id = ?');
            $s->execute([$productId]);
        } catch (Throwable $e) {   // before migration 1423 the size columns do not exist: no size, prices still show
            $s = $this->db->prepare('SELECT canonical_name AS name, NULL AS unit_size, NULL AS unit_size_unit FROM catalog_products WHERE id = ?');
            $s->execute([$productId]);
        }
        $p = $s->fetch(PDO::FETCH_ASSOC);
        if (!$p) return null;
        $p['unit_size'] = $p['unit_size'] !== null ? (float)$p['unit_size'] : null;
        return $p;
    }

    private function rows(int $productId, ?int $limit, int $excludeLineId, array $product): array
    {
        if (!class_exists('ReceiptCatalogService')) require_once __DIR__ . '/ReceiptCatalogService.php';
        $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, $limit);
        $s = $this->db->prepare("SELECT l.id AS line_id, l.expense_id, e.expense_date AS date, COALESCE(v.name, e.vendor_name_raw, '') AS vendor,
                                        l.quantity AS qty, l.unit_price, l.line_total
                                 FROM expense_line_items l JOIN expenses e ON e.id = l.expense_id LEFT JOIN vendors v ON v.id = e.vendor_id
                                 WHERE l.catalog_product_id = ? AND l.id <> ? AND e.status NOT IN ('rejected', 'cancelled', 'deleted', 'duplicate', 'personal')
                                   AND COALESCE(e.accounting_category, '') <> 'Personal'
                                 ORDER BY e.expense_date DESC, l.id DESC" . $limitSql);
        $s->execute([$productId, $excludeLineId]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $price = self::effectivePrice($r['unit_price'] !== null ? (float)$r['unit_price'] : null, $r['line_total'] !== null ? (float)$r['line_total'] : null,
                                          $r['qty'] !== null ? (float)$r['qty'] : null);
            if ($price === null) continue;
            $per = ReceiptCatalogService::perUnit($price, $product['unit_size'], $product['unit_size_unit']);
            $out[] = ['line_id' => (int)$r['line_id'], 'expense_id' => (int)$r['expense_id'], 'date' => $r['date'], 'vendor' => (string)$r['vendor'],
                      'qty' => $r['qty'] !== null ? (float)$r['qty'] : null, 'unit_price' => round($price, 4),
                      'per_unit_value' => $per['value'] ?? null, 'per_unit_label' => $per['label'] ?? null];
        }
        return $out;
    }
}
