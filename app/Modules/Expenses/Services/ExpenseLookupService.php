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
                'cash', 'credit_card', 'debit', 'company_card', 'etransfer', 'cheque',
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
     * Job-plan search for attaching an expense to a job. Returns property_id and
     * contact_id alongside the plan so the client can persist all three payer/site
     * identifiers the same way a GPS job suggestion does.
     */
    public function searchJobs(?string $q): array
    {
        $q = self::normalizeQuery($q);
        if ($q === null) {
            return [];
        }
        $like = '%' . $q . '%';
        $stmt = $this->db->prepare("
            SELECT
                jp.id,
                jp.plan_number,
                jp.service_type,
                jp.status,
                jp.property_id,
                p.site_contact_id AS contact_id,
                p.address,
                CONCAT(c.first_name, ' ', c.last_name) AS contact_name
            FROM job_plans jp
            LEFT JOIN properties p ON p.id = jp.property_id
            LEFT JOIN contacts c ON c.id = p.site_contact_id
            WHERE (
                  jp.plan_number LIKE ?
                  OR jp.service_type LIKE ?
                  OR p.address LIKE ?
                  OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
              )
            ORDER BY jp.status = 'active' DESC, jp.id DESC
            LIMIT 15
        ");
        $stmt->execute([$like, $like, $like, $like]);
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
}
