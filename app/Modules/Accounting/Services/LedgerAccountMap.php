<?php
/**
 * LedgerAccountMap — which account each invoice line and receipt posts to.
 *
 * Before (2026-10-05): every invoice posted to 4900 Other Services and every receipt
 * to 6900 Miscellaneous. Now, from two maps the owner controls (migration 1130; Penny
 * asks him to fill gaps):
 *   receipts: expense_category_accounts (category → account), else 6900;
 *   invoices: on a contract → 4050 Contract Income; else each line's own job service
 *             type (visit → plan), else the invoice's job, through
 *             service_revenue_accounts; else 4900. A mixed invoice is split across
 *             accounts in proportion to its lines.
 * Shared by LedgerSyncService (posting), the repost runner and Penny (BankDeskService).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class LedgerAccountMap
{
    public const DEFAULT_REVENUE = '4900';
    public const DEFAULT_EXPENSE = '6900';
    public const CONTRACT_INCOME = '4050';

    private PDO $db;
    private ?array $catCodes = null;
    private ?array $svcCodes = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'service_revenue_accounts'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** accounting_category(lower) => chart code. */
    public function categoryCodes(): array
    {
        if ($this->catCodes === null) {
            $this->catCodes = [];
            try {
                foreach ($this->db->query("SELECT m.category, c.code FROM expense_category_accounts m JOIN chart_of_accounts c ON c.id = m.account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $this->catCodes[strtolower(trim($r['category']))] = $r['code'];
                }
            } catch (Throwable $e) { /* before migration 1130 */ }
        }
        return $this->catCodes;
    }

    /** service type (lower slug or label) => chart code. */
    public function serviceCodes(): array
    {
        if ($this->svcCodes === null) {
            $this->svcCodes = [];
            try {
                foreach ($this->db->query("SELECT m.service_type, c.code FROM service_revenue_accounts m JOIN chart_of_accounts c ON c.id = m.account_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $this->svcCodes[self::serviceKey($r['service_type'])] = $r['code'];
                }
            } catch (Throwable $e) { /* before migration 1130 */ }
        }
        return $this->svcCodes;
    }

    public function expenseCode(?string $category, ?string $assetTag = null, ?string $vendor = null): string
    {
        $code = $this->categoryCodes()[strtolower(trim((string)$category))] ?? self::DEFAULT_EXPENSE;
        return self::refineExpenseCode($code, $category, $assetTag, $vendor);
    }

    /**
     * Revenue split for one invoice: [[code, amount], …] summing to $net.
     * @param array $inv id, contract_id, plan_id
     */
    public function invoiceSplits(array $inv, float $net): array
    {
        if (!empty($inv['contract_id'])) {
            return [[self::CONTRACT_INCOME, round($net, 2)]];
        }
        $planType = null;
        if (!empty($inv['plan_id'])) {
            $s = $this->db->prepare("SELECT service_type FROM job_plans WHERE id = ?");
            $s->execute([(int)$inv['plan_id']]);
            $planType = $s->fetchColumn() ?: null;
        }
        $s = $this->db->prepare("
            SELECT li.line_total, jp.service_type
            FROM invoice_line_items li
            LEFT JOIN job_visits jv ON jv.id = li.visit_id
            LEFT JOIN job_plans jp ON jp.id = jv.plan_id
            WHERE li.invoice_id = ?
        ");
        $s->execute([(int)$inv['id']]);
        $lines = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $lines[] = ['amount' => (float)$l['line_total'], 'code' => self::codeFor($l['service_type'] ?: $planType, $this->serviceCodes())];
        }
        if (!$lines) {
            $lines[] = ['amount' => 1, 'code' => self::codeFor($planType, $this->serviceCodes())];
        }
        return self::split($lines, $net);
    }

    /** Service types that still post to 4900 (no account yet) — what Penny asks about. */
    public function unmappedServiceTypes(): array
    {
        $codes = $this->serviceCodes();
        $rows = $this->db->query("
            SELECT jp.service_type, COUNT(DISTINCT i.id) AS invoices, COALESCE(SUM(i.subtotal), 0) AS amount
            FROM invoices i JOIN job_plans jp ON jp.id = i.plan_id
            WHERE i.contract_id IS NULL AND COALESCE(i.total, 0) > 0 AND jp.service_type IS NOT NULL AND jp.service_type <> ''
            GROUP BY jp.service_type
        ")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (!isset($codes[self::serviceKey($r['service_type'])])) {
                $out[] = ['service_type' => $r['service_type'], 'invoices' => (int)$r['invoices'], 'amount' => round((float)$r['amount'], 2)];
            }
        }
        usort($out, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $out;
    }

    /** Owner's answer: this service type's income goes to this account. */
    public function setServiceAccount(string $serviceType, int $accountId, int $userId): void
    {
        $this->db->prepare("
            INSERT INTO service_revenue_accounts (service_type, account_id, set_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), set_by = VALUES(set_by)
        ")->execute([self::serviceKey($serviceType), $accountId, $userId]);
        $this->svcCodes = null;
    }

    /** Owner's answer: receipts in this category go to this account. */
    public function setCategoryAccount(string $category, int $accountId, int $userId): void
    {
        $this->db->prepare("
            INSERT INTO expense_category_accounts (category, account_id, set_by) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), set_by = VALUES(set_by)
        ")->execute([strtolower(trim($category)), $accountId, $userId]);
        $this->catCodes = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public const VEHICLE_MAINTENANCE = '6120';
    /** Vendors that only ever fix vehicles. */
    public const AUTO_SHOP = '/\b(AUTO|AUTOMOTIVE|TIRES?|LUBE|MECHANIC(AL)?|COLLISION|AUTO ?BODY|MUFFLER|TRANSMISSION|OIL CHANGE|JIFFY|MIDAS|KAL TIRE|OK TIRE|CARSTAR|DEALERSHIP|DODGE|RAM TRUCK)\b/i';

    /**
     * "Repairs/Maintenance" covers the truck and the equipment. It's the truck — 6120
     * Vehicle Maintenance — when the receipt is tagged truck or the vendor is an auto shop.
     */
    public static function refineExpenseCode(string $code, ?string $category, ?string $assetTag, ?string $vendor): string
    {
        if (strtolower(trim((string)$category)) !== 'repairs/maintenance') return $code;
        if ($assetTag === 'truck' || preg_match(self::AUTO_SHOP, (string)$vendor)) return self::VEHICLE_MAINTENANCE;
        return $code;
    }

    /** "Lawn Care", "lawn_care", " LAWN-CARE " → "lawn care" (slugs and labels meet). */
    public static function serviceKey(?string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', strtolower((string)$s))));
    }

    public static function codeFor(?string $serviceType, array $serviceCodes): string
    {
        return $serviceCodes[self::serviceKey($serviceType)] ?? self::DEFAULT_REVENUE;
    }

    /**
     * Spread $net over accounts in proportion to the lines; amounts sum exactly to $net
     * (the rounding remainder lands on the largest account).
     * @param array $lines [['amount' => float, 'code' => string], …]
     * @return array [[code, amount], …]
     */
    public static function split(array $lines, float $net): array
    {
        $net = round($net, 2);
        $by = [];
        $total = 0.0;
        foreach ($lines as $l) {
            $amt = max(0.0, (float)$l['amount']);
            $by[$l['code']] = ($by[$l['code']] ?? 0) + $amt;
            $total += $amt;
        }
        if ($total <= 0 || count($by) === 1) {
            return [[(string)(array_key_first($by) ?? self::DEFAULT_REVENUE), $net]];
        }
        arsort($by);
        $out = [];
        $sum = 0.0;
        foreach ($by as $code => $amt) {
            $part = round($net * $amt / $total, 2);
            $out[] = [(string)$code, $part];
            $sum += $part;
        }
        $out[0][1] = round($out[0][1] + ($net - $sum), 2);
        return $out;
    }
}
