<?php
/**
 * ExpenseLookupService — read-only lookups the receipt review form needs on BOTH
 * clients: vendor autocomplete, job search, category lists, and amount/date
 * duplicate detection.
 *
 * Before this service existed each lookup lived inline in a session-only web handler
 * (vendors.php?action=search, expenses.php?action=search_jobs / check_duplicates,
 * vendors.php?action=categories), so the native iOS app — which can only reach JWT
 * endpoints — had no vendor picker, no job picker, a hard-coded category list, and no
 * duplicate warning. The web handlers now delegate here and the JWT endpoint
 * (Api/expense-lookup.php) calls the same methods, so the two review forms can't drift.
 *
 * Global-namespace (no production autoloader): require_once the file and
 * `new ExpenseLookupService($db)` — matches every other service in this module.
 */
class ExpenseLookupService
{
    public const MIN_QUERY_LENGTH   = 2;
    public const DUPLICATE_DAY_SPAN = 3;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Pure helpers (unit-tested) ─────────────────────────────────────

    /** Trim + reject queries too short to be worth a LIKE scan. Returns null when unusable. */
    public static function normalizeQuery(?string $q): ?string
    {
        $q = trim((string)$q);
        return strlen($q) >= self::MIN_QUERY_LENGTH ? $q : null;
    }

    /** Whether a duplicate check has enough signal to run at all. */
    public static function canCheckDuplicates(?float $total, ?string $date): bool
    {
        return $total !== null && $total > 0 && !empty($date);
    }

    /**
     * The canonical category / payment-method lists. Static so both the session
     * (vendors.php?action=categories) and JWT clients read one source of truth.
     */
    public static function categories(): array
    {
        return [
            'accounting_categories' => [
                'Materials', 'Fuel', 'Tools/Equipment', 'Repairs/Maintenance',
                'Disposal/Dump', 'Subcontractors', 'Marketing', 'Office/Admin',
                'Overhead', 'Licenses/Permits', 'Meals', 'Vehicle', 'Other',
            ],
            'gbp_categories' => [
                'Garden center/nursery', 'Hardware store', 'Building materials',
                'Equipment rental', 'Gas station', 'Waste disposal/landfill',
                'Restaurant/food', 'Office supply', 'Auto parts',
                'Wholesale store', 'Other',
            ],
            'payment_methods' => [
                'cash', 'credit_card', 'debit', 'company_card', 'etransfer', 'cheque', 'owner_paid',
            ],
        ];
    }

    // ── Lookups ────────────────────────────────────────────────────────

    /** Vendor autocomplete: active vendors whose name or aliases contain $q. */
    public function searchVendors(?string $q): array
    {
        $q = self::normalizeQuery($q);
        if ($q === null) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT id, name, aliases, default_accounting_category, default_gbp_category
            FROM vendors
            WHERE is_active = 1
              AND (name LIKE ? OR aliases LIKE ?)
            ORDER BY name
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Job-plan search (plan number, job title, service, address, building name, client,
     * property manager) for attaching an expense to a job. Returns property_id and
     * contact_id alongside the plan so the client can persist all three payer/site
     * identifiers the same way a GPS job suggestion does.
     *
     * $onDate (the receipt date): jobs with a visit scheduled that day come first and carry
     * scheduled_on_date = 1; with no query, only that day's jobs are returned — so the
     * picker can list "on the schedule that day" before anything is typed.
     */
    public function searchJobs(?string $q, ?string $onDate = null): array
    {
        $q = self::normalizeQuery($q);
        $onDate = ($onDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate)) ? $onDate : null;
        if ($q === null && $onDate === null) {
            return [];
        }
        $params = [];
        $scheduled = '0';
        if ($onDate !== null) {
            $scheduled = "EXISTS (SELECT 1 FROM job_visits jv JOIN calendar_stops cs ON cs.id = jv.stop_id
                                  WHERE jv.plan_id = jp.id AND cs.stop_date = ? AND jv.status <> 'cancelled')";
            $params[] = $onDate;
        }
        $where = '';
        if ($q !== null) {
            $like = '%' . $q . '%';
            $where = "WHERE (
                  jp.plan_number LIKE ?
                  OR jp.title LIKE ?
                  OR jp.service_type LIKE ?
                  OR p.address LIKE ?
                  OR p.property_name LIKE ?
                  OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
                  OR EXISTS (SELECT 1 FROM company_properties cp JOIN companies co ON co.id = cp.company_id
                             WHERE cp.property_id = p.id AND co.company_name LIKE ?)
              )";
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }
        $sql = "
            SELECT * FROM (
                SELECT
                    jp.id,
                    jp.plan_number,
                    jp.title,
                    jp.service_type,
                    jp.status,
                    jp.property_id,
                    p.site_contact_id AS contact_id,
                    p.address,
                    p.property_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
                    {$scheduled} AS scheduled_on_date
                FROM job_plans jp
                LEFT JOIN properties p ON p.id = jp.property_id
                LEFT JOIN contacts c ON c.id = p.site_contact_id
                {$where}
            ) j
            " . ($q === null ? 'WHERE j.scheduled_on_date = 1' : '') . "
            ORDER BY j.scheduled_on_date DESC, j.status = 'active' DESC, j.id DESC
            LIMIT 15
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** CRM product search for linking a receipt line item (name or SKU). */
    public function searchProducts(?string $q): array
    {
        $q = self::normalizeQuery($q);
        if ($q === null) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT id, name, sku, track_inventory, current_stock
            FROM products
            WHERE COALESCE(active, 1) = 1 AND COALESCE(is_archived, 0) = 0
              AND (name LIKE ? OR sku LIKE ?)
            ORDER BY name
            LIMIT 20
        ");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Likely-duplicate expenses: same total (to the cent) within ±3 days, and — when
     * vendor info is supplied — the same vendor_id OR a vendor_name_raw match.
     *
     * @return array  Each row: id, expense_date, total, status, vendor_name_raw,
     *                vendor_name, receipt_media_id, receipt_path (proxied URL or null).
     */
    public function findDuplicates(?string $vendorName, ?int $vendorId, ?float $total, ?string $date, ?int $excludeId = null): array
    {
        if (!self::canCheckDuplicates($total, $date)) {
            return [];
        }

        $where  = [
            'ABS(e.total - ?) < 0.01',
            'e.expense_date BETWEEN DATE_SUB(?, INTERVAL ' . self::DUPLICATE_DAY_SPAN . ' DAY) AND DATE_ADD(?, INTERVAL ' . self::DUPLICATE_DAY_SPAN . ' DAY)',
        ];
        $params = [$total, $date, $date];

        if ($excludeId) {
            $where[]  = 'e.id != ?';
            $params[] = $excludeId;
        }

        $vendorClause = [];
        if ($vendorId) {
            $vendorClause[] = 'e.vendor_id = ?';
            $params[]       = $vendorId;
        }
        if ($vendorName !== null && strlen(trim($vendorName)) >= self::MIN_QUERY_LENGTH) {
            $vendorClause[] = 'e.vendor_name_raw LIKE ?';
            $params[]       = '%' . trim($vendorName) . '%';
        }
        if ($vendorClause) {
            $where[] = '(' . implode(' OR ', $vendorClause) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.status,
                   e.vendor_name_raw, e.receipt_media_id,
                   v.name AS vendor_name
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY e.expense_date DESC
            LIMIT 5
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['receipt_path'] = !empty($row['receipt_media_id'])
                ? '/crm/api/serve-receipt.php?id=' . (int)$row['receipt_media_id']
                : null;
        }
        unset($row);

        return $rows;
    }

    // ── The receipt item picker ("Add Item") ───────────────────────────
    //
    // Chevron #355 (2026-10-13): the picker offered Crusher Dust, Road Base, Diesel and Traffic
    // Cones for a gas-station coffee — Chevron has no vendor_products, so any typing fell through
    // to the whole CRM product list. Now: this vendor's usual items first (what was bought there
    // before, from past receipt lines and SKU memory, ranked by how often and how lately), then
    // this vendor's catalogue, and the rest of the CRM products only on a typed search.

    /** Header words a receipt reader once saved as "items". */
    private const ITEM_JUNK = ['AMOUNT', 'PRODUCT', 'QTY', 'PRICE', 'ITEM', 'ITEMS', 'DESCRIPTION', 'TOTAL'];

    /** A past item's display name: printed prices and "(x2)" removed ("CRF 0.84" → "CRF"). Pure. */
    public static function usualItemName(string $name): string
    {
        if (!function_exists('layoutItemName')) require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        $n = layoutItemName($name);
        $n = preg_replace('/\(\s*x\s*\d+\s*\)/i', '', $n);
        return trim((string)preg_replace('/\s{2,}/', ' ', (string)$n));
    }

    /** Not an item at all: tax / total labels, header words, no word of 3+ letters. Pure. */
    public static function isJunkItemName(string $name): bool
    {
        if (!function_exists('isTaxOrSummaryLabel')) require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        $n = trim($name);
        return $n === '' || !preg_match('/[A-Za-z]{3,}/', $n) || isTaxOrSummaryLabel($n)
            || in_array(strtoupper($n), self::ITEM_JUNK, true);
    }

    /**
     * Rank a vendor's past items. Rows: {name, n, confirmed, last (Y-m-d), last_total, last_qty}.
     * Grouped by display name (case-insensitive); score = times bought + times on an approved
     * receipt + 3 when bought in the last 30 days (1.5 within 90). Ties: most recent first. Pure.
     * @return list<array{name: string, price: ?float, times: int, last: ?string}>
     */
    public static function rankUsualItems(array $rows, string $today, ?string $q = null, int $limit = 12): array
    {
        $groups = [];
        foreach ($rows as $r) {
            $name = self::usualItemName((string)($r['name'] ?? ''));
            if (self::isJunkItemName($name)) continue;
            $k = strtolower($name);
            $g = $groups[$k] ?? ['name' => $name, 'best' => -1, 'n' => 0, 'confirmed' => 0, 'last' => null, 'price' => null];
            $n = (int)($r['n'] ?? 0);
            $c = (int)($r['confirmed'] ?? 0);
            $rank = $c * 1000 + $n;                                   // the spelling people confirmed most
            if ($rank > $g['best']) { $g['best'] = $rank; $g['name'] = $name; }
            $g['n'] += $n;
            $g['confirmed'] += $c;
            $last = $r['last'] ?? null;
            if ($last && ($g['last'] === null || $last > $g['last'])) {
                $g['last'] = $last;
                $qty = (float)($r['last_qty'] ?? 1) ?: 1.0;
                $g['price'] = isset($r['last_total']) && is_numeric($r['last_total']) && (float)$r['last_total'] > 0
                    ? round((float)$r['last_total'] / $qty, 2) : $g['price'];
            }
            $groups[$k] = $g;
        }
        $q = $q !== null ? strtolower(trim($q)) : '';
        $t = strtotime($today) ?: time();
        $out = [];
        foreach ($groups as $k => $g) {
            if ($q !== '' && strpos($k, $q) === false) continue;
            $days = $g['last'] ? ($t - (strtotime($g['last']) ?: $t)) / 86400 : 9999;
            $score = $g['n'] + $g['confirmed'] + ($days <= 30 ? 3 : ($days <= 90 ? 1.5 : 0));
            $out[] = ['name' => $g['name'], 'price' => $g['price'], 'times' => $g['n'], 'last' => $g['last'], '_s' => $score];
        }
        usort($out, fn($a, $b) => [$b['_s'], (string)$b['last']] <=> [$a['_s'], (string)$a['last']]);
        return array_map(function ($i) { unset($i['_s']); return $i; }, array_slice($out, 0, $limit));
    }

    /**
     * The "Add Item" picker for a receipt: {usual, catalogue, other}.
     *   usual     — this vendor's past receipt lines + SKU memory, ranked (rankUsualItems)
     *   catalogue — this vendor's vendor_products (active)
     *   other     — CRM products, ONLY on a typed search (2+ characters)
     * Web: expenses.php ?action=item_suggestions; iPhone: expense-lookup type=vendor_items.
     */
    public function vendorItemSuggestions(int $vendorId, ?string $q, int $limit = 12): array
    {
        $q = trim((string)$q);
        $usual = [];
        $catalogue = [];
        if ($vendorId > 0) {
            $rows = [];
            try {
                $s = $this->db->prepare("
                    SELECT eli.name, COUNT(*) AS n,
                           SUM(e.status IN ('approved', 'forwarded')) AS confirmed,
                           MAX(e.expense_date) AS last,
                           SUBSTRING_INDEX(GROUP_CONCAT(eli.line_total ORDER BY e.expense_date DESC, eli.id DESC), ',', 1) AS last_total,
                           SUBSTRING_INDEX(GROUP_CONCAT(eli.quantity ORDER BY e.expense_date DESC, eli.id DESC), ',', 1) AS last_qty
                    FROM expense_line_items eli
                    JOIN expenses e ON e.id = eli.expense_id
                    WHERE e.vendor_id = ? AND e.status NOT IN ('rejected', 'cancelled')
                    GROUP BY eli.name
                    ORDER BY MAX(e.expense_date) DESC
                    LIMIT 300
                ");
                $s->execute([$vendorId]);
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* no history */ }
            try {
                $s = $this->db->prepare("
                    SELECT item_name AS name, times_seen AS n, COALESCE(times_confirmed, 0) AS confirmed,
                           DATE(last_seen_at) AS last, NULL AS last_total, 1 AS last_qty
                    FROM vendor_product_skus
                    WHERE vendor_id = ? AND item_name IS NOT NULL AND item_name <> ''
                    LIMIT 200
                ");
                $s->execute([$vendorId]);
                $rows = array_merge($rows, $s->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e) { /* before migration 1115 */ }
            $usual = self::rankUsualItems($rows, date('Y-m-d'), $q, $limit);

            try {
                $sql = "SELECT id, name, category, unit, price_per_unit FROM vendor_products WHERE vendor_id = ? AND is_active = 1";
                $params = [$vendorId];
                if ($q !== '') { $sql .= " AND (name LIKE ? OR ocr_aliases LIKE ?)"; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; }
                $s = $this->db->prepare($sql . " ORDER BY category, name LIMIT 40");
                $s->execute($params);
                $catalogue = $s->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* no catalogue */ }
        }
        // The rest of the CRM's products: typed searches only — never offered unasked.
        $other = self::normalizeQuery($q) !== null ? $this->searchProducts($q) : [];
        return ['usual' => $usual, 'catalogue' => $catalogue, 'other' => $other];
    }
}
