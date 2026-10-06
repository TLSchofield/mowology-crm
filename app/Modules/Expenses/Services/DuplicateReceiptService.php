<?php
/**
 * DuplicateReceiptService — Penny's "sort duplicates before approval" step.
 *
 * The owner's rule (2026-10-05): a possible duplicate is never offered for approval;
 * it is offered for de-duplication first. This reuses what the receipts page already
 * has rather than a second detector:
 *   detection — ExpenseLookupService::findDuplicates() (same total to the cent within
 *               ±3 days, same vendor) — the same rule as the receipts page grouping and
 *               the review-form warning;
 *   merging   — expenses.php action=merge (keep one, the other is removed), called by
 *               the card itself.
 * New here: "not a duplicate" is remembered for good (expense_duplicate_dismissals,
 * migration 1127). The receipts page only remembered it for the browser session.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/ExpenseLookupService.php';

class DuplicateReceiptService
{
    public const WAITING = ['draft', 'pending_approval'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function dismissalsReady(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'expense_duplicate_dismissals'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** "minId-maxId" => true for every pair the owner said is not a duplicate. */
    public function dismissed(): array
    {
        if (!$this->dismissalsReady()) return [];
        $out = [];
        foreach ($this->db->query("SELECT expense_a, expense_b FROM expense_duplicate_dismissals")->fetchAll(PDO::FETCH_NUM) as [$a, $b]) {
            $out[self::key((int)$a, (int)$b)] = true;
        }
        return $out;
    }

    /**
     * Open duplicate pairs among the given waiting receipts. a = the waiting receipt
     * (the older one when both wait), b = its possible twin.
     * @param int[] $expenseIds
     * @return array<int, array{a: array, b: array}>
     */
    public function pairsFor(array $expenseIds): array
    {
        $expenseIds = array_values(array_unique(array_map('intval', $expenseIds)));
        if (!$expenseIds) return [];
        $in = implode(',', array_fill(0, count($expenseIds), '?'));
        $stmt = $this->db->prepare("
            SELECT e.id, e.expense_date, e.total, e.status, e.vendor_id, e.vendor_name_raw, e.receipt_media_id,
                   v.name AS vendor_name, u.full_name AS submitted_by
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN users u ON u.id = e.created_by
            WHERE e.id IN ({$in}) AND e.status IN ('draft', 'pending_approval')
        ");
        $stmt->execute($expenseIds);
        $mine = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mine as &$m) {
            $m['receipt_path'] = $m['receipt_media_id'] ? '/crm/api/serve-receipt.php?id=' . (int)$m['receipt_media_id'] : null;
        }
        unset($m);

        $lookup = new ExpenseLookupService($this->db);
        $candidates = [];
        foreach ($mine as $e) {
            $candidates[(int)$e['id']] = $lookup->findDuplicates(
                $e['vendor_name'] ?: $e['vendor_name_raw'], $e['vendor_id'] ? (int)$e['vendor_id'] : null,
                (float)$e['total'], (string)$e['expense_date'], (int)$e['id']
            );
        }
        $pairs = self::pairUp($mine, $candidates, $this->dismissed());
        // Who sent each one in, for the side-by-side.
        $who = $this->db->prepare("SELECT u.full_name FROM expenses e LEFT JOIN users u ON u.id = e.created_by WHERE e.id = ?");
        foreach ($pairs as &$p) {
            foreach (['a', 'b'] as $side) {
                if (!array_key_exists('submitted_by', $p[$side])) {
                    $who->execute([(int)$p[$side]['id']]);
                    $p[$side]['submitted_by'] = $who->fetchColumn() ?: null;
                }
            }
        }
        unset($p);
        return $pairs;
    }

    /**
     * Pairs among the next receipts in Penny's line (her preparation order: submitted
     * first, oldest first) — what the card sorts before anything is approved.
     */
    public function pairsInLine(int $n = 60): array
    {
        $ids = $this->db->query("
            SELECT id FROM expenses WHERE status IN ('draft', 'pending_approval')
            ORDER BY (status = 'pending_approval') DESC, expense_date ASC, id ASC
            LIMIT " . max(1, min(200, $n))
        )->fetchAll(PDO::FETCH_COLUMN);
        return $this->pairsFor($ids);
    }

    /** Waiting receipts in an open pair: held back from approval and from Penny's AI read. */
    public static function heldIds(array $pairs): array
    {
        $ids = [];
        foreach ($pairs as $p) {
            foreach (['a', 'b'] as $side) {
                if (in_array($p[$side]['status'] ?? '', self::WAITING, true)) $ids[(int)$p[$side]['id']] = true;
            }
        }
        return array_keys($ids);
    }

    /** Owner: "not a duplicate" — never pair these two again (here or on the receipts page). */
    public function dismiss(int $a, int $b, array $user): array
    {
        if (!$a || !$b || $a === $b) return ['ok' => false, 'message' => 'Pick two receipts'];
        if (!$this->dismissalsReady()) return ['ok' => false, 'message' => 'Needs migration 1127'];
        $this->db->prepare("INSERT IGNORE INTO expense_duplicate_dismissals (expense_a, expense_b, dismissed_by) VALUES (?, ?, ?)")
           ->execute([min($a, $b), max($a, $b), (int)$user['id']]);
        return ['ok' => true, 'message' => 'Got it — not a duplicate. Both go on for approval.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function key(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /**
     * @param array $mine       the waiting receipts checked
     * @param array $candidates expense id => findDuplicates() rows
     * @param array $dismissed  key() => true
     */
    public static function pairUp(array $mine, array $candidates, array $dismissed = []): array
    {
        $out = [];
        foreach ($mine as $e) {
            foreach ($candidates[(int)$e['id']] ?? [] as $c) {
                if (($c['status'] ?? '') === 'rejected') continue;
                $k = self::key((int)$e['id'], (int)$c['id']);
                if (isset($dismissed[$k]) || isset($out[$k])) continue;
                $bothWait = in_array($c['status'] ?? '', self::WAITING, true);
                [$a, $b] = ($bothWait && (int)$c['id'] < (int)$e['id']) ? [$c, $e] : [$e, $c];
                $out[$k] = ['a' => $a, 'b' => $b];
            }
        }
        return array_values($out);
    }
}
