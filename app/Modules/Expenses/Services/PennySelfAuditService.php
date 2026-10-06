<?php
/**
 * PennySelfAuditService — Penny re-checks a sample of her own past calls each week.
 *
 * Bookkeeping-agent guardrail #6: "the agent re-checks a random sample of its own past
 * entries and reports its error rate, so you know when to trust it more or less."
 * Code only — no AI cost. For a random sample of decisions the owner approved:
 *   receipts   — does the receipt still say what was approved (category, For tag, job,
 *                total)? A later correction means the approved call was wrong;
 *   bank lines — is the line still on the account approved on her card?
 * The result is kept in ops_settings ('penny_self_audit', JSON) and shown on her card.
 * It runs when the card loads and the last audit is older than EVERY_DAYS.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennySelfAuditService
{
    public const EVERY_DAYS = 7;
    public const SAMPLE = 20;
    private const KEY = 'penny_self_audit';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The last audit, or null. */
    public function last(): ?array
    {
        try {
            $v = $this->db->query("SELECT setting_value FROM ops_settings WHERE setting_key = '" . self::KEY . "'")->fetchColumn();
            $r = $v ? json_decode((string)$v, true) : null;
            return is_array($r) ? $r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Run if the last audit is stale; return the latest. Never throws. */
    public function dueThenLast(): ?array
    {
        $last = $this->last();
        if ($last && strtotime((string)$last['at']) > strtotime('-' . self::EVERY_DAYS . ' days')) return $last;
        try {
            return $this->run();
        } catch (Throwable $e) {
            error_log('Penny self-audit failed: ' . $e->getMessage());
            return $last;
        }
    }

    public function run(): array
    {
        $checked = 0; $wrong = [];
        // Receipts: what was approved vs what the receipt says now.
        $rows = $this->db->query("
            SELECT s.id, s.expense_id, s.outcome_json, e.accounting_category, e.asset_tag, e.job_id, e.total, e.status,
                   COALESCE(v.name, e.vendor_name_raw) AS vendor
            FROM expense_suggestions s
            JOIN expenses e ON e.id = s.expense_id
            LEFT JOIN vendors v ON v.id = e.vendor_id
            WHERE s.source = 'live' AND s.status IN ('accepted', 'edited') AND s.decided_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
              AND e.status IN ('approved', 'forwarded')
            ORDER BY RAND() LIMIT " . self::SAMPLE
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $checked++;
            $diff = self::receiptDrift(json_decode((string)$r['outcome_json'], true) ?: [], $r);
            if ($diff) $wrong[] = ['what' => 'Receipt #' . $r['expense_id'] . ' (' . ($r['vendor'] ?: 'unknown') . ')', 'changed' => $diff];
        }
        // Bank lines approved on her card: still on that account?
        try {
            $rows = $this->db->query("
                SELECT r.transaction_id, r.final_account_id, t.account_id, t.description
                FROM bank_line_reviews r JOIN accounting_transactions t ON t.id = r.transaction_id
                WHERE r.outcome IN ('accepted', 'edited') AND r.decided_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                ORDER BY RAND() LIMIT " . self::SAMPLE
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $checked++;
                if ((int)$r['final_account_id'] !== (int)$r['account_id']) {
                    $wrong[] = ['what' => 'Bank line: ' . mb_substr((string)$r['description'], 0, 40), 'changed' => ['account']];
                }
            }
        } catch (Throwable $e) { /* before migration 1129 */ }

        $result = ['at' => date('c'), 'checked' => $checked, 'wrong' => count($wrong),
                   'rate' => $checked ? round(count($wrong) / $checked * 100) : null, 'examples' => array_slice($wrong, 0, 5)];
        $this->db->prepare("
            INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'Penny weekly self-audit (latest result)')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([self::KEY, json_encode($result)]);
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Fields where the receipt no longer says what was approved. */
    public static function receiptDrift(array $outcome, array $now): array
    {
        $changed = [];
        $final = fn($f) => $outcome[$f]['final'] ?? null;
        if (array_key_exists('accounting_category', $outcome) && (string)$final('accounting_category') !== '' && (string)$final('accounting_category') !== (string)$now['accounting_category']) $changed[] = 'category';
        if (array_key_exists('asset_tag', $outcome) && (string)($final('asset_tag') === 'none' ? '' : $final('asset_tag')) !== (string)($now['asset_tag'] ?? '')) $changed[] = 'For tag';
        if (array_key_exists('job', $outcome) && (int)$final('job') !== (int)($now['job_id'] ?? 0)) $changed[] = 'job';
        if (array_key_exists('total', $outcome) && $final('total') !== null && abs((float)$final('total') - (float)$now['total']) > 0.009) $changed[] = 'total';
        return $changed;
    }
}
