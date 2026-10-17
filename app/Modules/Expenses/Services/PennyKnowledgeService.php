<?php
/**
 * Penny's read-only, tenant-local vendor knowledge scorecard.
 *
 * Operational tables are already isolated by the current tenant PDO.  head_lessons is
 * the exception: it is shared and is therefore always restricted by site_id here.
 * No namespaces/autoloader: callers must require_once this file.
 */
declare(strict_types=1);

final class PennyKnowledgeService
{
    public const MASTERED_RECEIPTS = 5;
    public const MASTERED_MATCHED = 3;
    public const MASTERED_UNCHANGED_STREAK = 3;
    public const VERIFIED_WITHIN_DAYS = 365;

    private PDO $db;
    private int $siteId;

    public function __construct(PDO $db, int $siteId = 1)
    {
        $this->db = $db;
        $this->siteId = $siteId;
    }

    public static function thresholds(): array
    {
        return [
            'mastered_receipts' => self::MASTERED_RECEIPTS,
            'mastered_matched' => self::MASTERED_MATCHED,
            'mastered_unchanged_streak' => self::MASTERED_UNCHANGED_STREAK,
            'verified_within_days' => self::VERIFIED_WITHIN_DAYS,
        ];
    }

    /** Pure. Internal items_unconfirmed is accepted but is not emitted by vendors(). */
    public static function stageFor(array $evidence): array
    {
        $approved = (int)($evidence['receipts_approved'] ?? 0);
        $matched = (int)($evidence['transactions_matched'] ?? 0);
        $streak = (int)($evidence['unchanged_streak'] ?? 0);
        $missingItems = (int)($evidence['items_unconfirmed'] ?? 0);
        $exceptions = (array)($evidence['exceptions'] ?? []);
        $last = $evidence['last_verified'] ?? null;
        $recent = $last && strtotime((string)$last) !== false
            && strtotime((string)$last) >= strtotime('-' . self::VERIFIED_WITHIN_DAYS . ' days');

        // Understood needs a person-approved receipt to have a bank/card match.  The
        // gatherer only counts matches among approved receipt ids, so these two counts suffice.
        if ($approved < 1) {
            return ['stage' => 'recognized', 'next' => ['Approve 1 receipt from this vendor']];
        }
        if ($matched < 1) {
            return ['stage' => 'identified', 'next' => ['Match 1 approved receipt from this vendor to its bank line']];
        }

        $next = [];
        if ($approved < self::MASTERED_RECEIPTS) {
            $next[] = self::more(self::MASTERED_RECEIPTS - $approved, 'approved receipt');
        }
        if ($matched < self::MASTERED_MATCHED) {
            $next[] = 'Match ' . self::moreText(self::MASTERED_MATCHED - $matched, 'more receipt') . ' to its bank line';
        }
        if ($streak < self::MASTERED_UNCHANGED_STREAK) {
            $next[] = self::more(self::MASTERED_UNCHANGED_STREAK - $streak, 'unchanged category decision');
        }
        if ($missingItems > 0) {
            $next[] = self::more($missingItems, 'repeated item confirmation');
        }
        if ($exceptions) {
            $next[] = self::more(count($exceptions), 'open exception') . ' to resolve';
        }
        if (!$recent) {
            $next[] = 'Verify this vendor with an owner decision within 365 days';
        }
        return ['stage' => $next ? 'understood' : 'mastered', 'next' => $next];
    }

    private static function more(int $n, string $noun): string
    {
        return self::moreText($n, 'more ' . $noun);
    }

    private static function moreText(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }

    /** Contract items, in permanent vendor-id discovery order (slot is zero based). */
    public function vendors(): array
    {
        $vendors = $this->rows("SELECT v.id, v.name, MIN(e.created_at) AS discovered_at
                                FROM expenses e JOIN vendors v ON v.id = e.vendor_id
                                WHERE e.vendor_id IS NOT NULL GROUP BY v.id, v.name ORDER BY v.id", []);
        if (!$vendors) return [];
        $out = [];
        foreach ($vendors as $slot => $v) {
            $id = (int)$v['id'];
            $out[$id] = [
                'key' => 'vendor:' . $id, 'slot' => $slot, 'label' => (string)$v['name'],
                'stage' => 'recognized', 'discovered_at' => $v['discovered_at'] ?: null,
                'evidence' => [
                    'receipts_approved' => 0, 'transactions_matched' => 0,
                    'items_recognized' => 0, 'items_new' => 0,
                    'classification_accuracy' => null, 'classification_decided' => 0,
                    'unchanged_streak' => 0, 'last_verified' => null, 'exceptions' => [],
                    'items_unconfirmed' => 0,
                ],
                'next' => [],
            ];
        }

        $approvedIds = [];
        foreach ($this->rows("SELECT id, vendor_id FROM expenses
                              WHERE vendor_id IS NOT NULL AND approved_by IS NOT NULL", []) as $r) {
            $vid = (int)$r['vendor_id'];
            if (!isset($out[$vid])) continue;
            $out[$vid]['evidence']['receipts_approved']++;
            $approvedIds[(int)$r['id']] = $vid;
        }

        $matchSql = "SELECT matched_expense_id AS expense_id FROM accounting_transactions WHERE matched_expense_id IS NOT NULL";
        if ($this->tableExists('bank_line_receipts')) $matchSql .= " UNION SELECT expense_id FROM bank_line_receipts";
        $matchedExpenseIds = [];
        foreach ($this->rows($matchSql, []) as $r) $matchedExpenseIds[(int)$r['expense_id']] = true;
        foreach ($matchedExpenseIds as $eid => $_) {
            if (isset($approvedIds[$eid], $out[$approvedIds[$eid]])) $out[$approvedIds[$eid]]['evidence']['transactions_matched']++;
        }

        if ($this->tableExists('expense_suggestions')) {
            $decisions = $this->rows("SELECT e.vendor_id, s.status, s.outcome_json, s.decided_at, s.id
                                      FROM expense_suggestions s JOIN expenses e ON e.id = s.expense_id
                                      WHERE e.vendor_id IS NOT NULL AND s.source = 'live'
                                        AND s.status IN ('accepted','edited') AND s.decided_at IS NOT NULL
                                        AND s.decided_by IS NOT NULL  -- a person decided (Codex review 2026-10-17: never count a system decision toward mastery)
                                      ORDER BY e.vendor_id, s.decided_at DESC, s.id DESC", []);
            $broken = [];
            foreach ($decisions as $r) {
                $vid = (int)$r['vendor_id'];
                if (!isset($out[$vid])) continue;
                $o = json_decode((string)$r['outcome_json'], true);
                $category = is_array($o) && isset($o['accounting_category']) && is_array($o['accounting_category'])
                    && array_key_exists('accepted', $o['accounting_category'])
                    ? (bool)$o['accounting_category']['accepted'] : $r['status'] === 'accepted';
                $ev =& $out[$vid]['evidence'];
                $ev['classification_decided']++;
                if ($category) $ev['_classification_right'] = (int)($ev['_classification_right'] ?? 0) + 1;
                if ($ev['last_verified'] === null) $ev['last_verified'] = $r['decided_at'];
                if (empty($broken[$vid])) {
                    if ($category) $ev['unchanged_streak']++; else $broken[$vid] = true;
                }
                unset($ev);
            }
        }

        if ($this->tableExists('vendor_product_skus')) {
            foreach ($this->rows("SELECT vendor_id, times_seen, times_confirmed FROM vendor_product_skus", []) as $r) {
                $vid = (int)$r['vendor_id'];
                if (!isset($out[$vid])) continue;
                $seen = (int)$r['times_seen']; $confirmed = (int)$r['times_confirmed'];
                if ($seen < 2) $out[$vid]['evidence']['items_new']++;
                elseif ($confirmed >= 1) $out[$vid]['evidence']['items_recognized']++;
                else $out[$vid]['evidence']['items_unconfirmed']++;
            }
        }

        foreach ($this->rows("SELECT vendor_id, anomaly_flags FROM expenses
                              WHERE vendor_id IS NOT NULL AND approved_by IS NULL
                                AND status NOT IN ('approved','forwarded')
                                AND anomaly_flags IS NOT NULL AND anomaly_flags <> ''", []) as $r) {
            $vid = (int)$r['vendor_id'];
            if (!isset($out[$vid])) continue;
            foreach (preg_split('/\s*,\s*/', (string)$r['anomaly_flags']) as $flag) {
                if ($flag !== '') $out[$vid]['evidence']['exceptions'][] = ['kind' => 'anomaly', 'text' => self::plainFlag($flag)];
            }
        }
        if ($this->tableExists('penny_questions')) {
            foreach ($this->rows("SELECT COALESCE(q.vendor_id, e.vendor_id) AS vendor_id, q.question
                                  FROM penny_questions q LEFT JOIN expenses e ON e.id = q.expense_id
                                  WHERE q.status = 'open' AND COALESCE(q.vendor_id, e.vendor_id) IS NOT NULL", []) as $r) {
                $vid = (int)$r['vendor_id'];
                if (isset($out[$vid])) $out[$vid]['evidence']['exceptions'][] = ['kind' => 'question', 'text' => trim((string)$r['question'])];
            }
        }

        foreach ($out as &$v) {
            $ev =& $v['evidence'];
            if ($ev['classification_decided'] > 0) {
                $ev['classification_accuracy'] = round((int)($ev['_classification_right'] ?? 0) / $ev['classification_decided'], 4);
            }
            $stage = self::stageFor($ev);
            $v['stage'] = $stage['stage']; $v['next'] = $stage['next'];
            unset($ev['_classification_right'], $ev['items_unconfirmed']);
            unset($ev);
        }
        unset($v);
        return array_values($out);
    }

    /** Item details for one tenant-local vendor. */
    public function items(int $vendorId): array
    {
        if ($vendorId < 1 || !$this->vendorExists($vendorId) || !$this->tableExists('vendor_product_skus')) return [];
        $correctedAt = null;
        if ($this->tableExists('head_lessons')) {
            $r = $this->rows("SELECT MAX(h.created_at) AS corrected_at FROM head_lessons h JOIN vendors v
                              ON h.subject_key = LOWER(TRIM(v.name))
                              WHERE h.site_id = ? AND h.head = 'penny' AND h.subject_kind = 'vendor'
                                AND h.field IN ('reader_line_items','line_items','item_name') AND v.id = ?", [$this->siteId, $vendorId]);
            $correctedAt = $r[0]['corrected_at'] ?? null;
        }
        $rows = $this->rows("SELECT COALESCE(NULLIF(item_name, ''), sku_raw) AS label, times_seen, times_confirmed, last_seen_at
                             FROM vendor_product_skus WHERE vendor_id = ? ORDER BY times_seen DESC, sku_raw", [$vendorId]);
        $items = [];
        foreach ($rows as $r) {
            $seen = (int)$r['times_seen']; $confirmed = (int)$r['times_confirmed'];
            $correctedSince = $correctedAt && (!$r['last_seen_at'] || strtotime((string)$correctedAt) > strtotime((string)$r['last_seen_at']));
            $stage = $confirmed >= 3 && !$correctedSince ? 'mastered' : ($confirmed >= 1 ? 'understood' : 'recognized');
            $items[] = ['label' => (string)$r['label'], 'stage' => $stage, 'times_seen' => $seen, 'times_confirmed' => $confirmed];
        }
        return $items;
    }

    private function vendorExists(int $vendorId): bool
    {
        $r = $this->rows("SELECT v.id FROM vendors v WHERE v.id = ? AND EXISTS
                          (SELECT 1 FROM expenses e WHERE e.vendor_id = v.id) LIMIT 1", [$vendorId]);
        return (bool)$r;
    }

    private function rows(string $sql, array $args): array
    {
        try {
            $s = $this->db->prepare($sql); $s->execute($args);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[PennyKnowledgeService] read skipped: ' . $e->getMessage());
            return [];
        }
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        $key = spl_object_hash($this->db) . ':' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $s = $this->db->prepare("SELECT 1 FROM `{$table}` WHERE 1 = 0"); $s->execute();
            return $cache[$key] = true;
        } catch (Throwable $e) { return $cache[$key] = false; }
    }

    private static function plainFlag(string $flag): string
    {
        $s = strtolower(trim(str_replace(['_', '-'], ' ', $flag)));
        return $s === '' ? 'Receipt needs review' : ucfirst($s);
    }
}
