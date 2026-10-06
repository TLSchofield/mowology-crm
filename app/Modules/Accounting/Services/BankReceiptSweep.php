<?php
/**
 * BankReceiptSweep — link every bank spending line to its receipt where the match is clear.
 *
 * At import, a bank line is matched only to a receipt that was already approved, same
 * amount, ±3 days — a receipt approved later is never looked at again. The unmatched
 * bank line is posted to the books on its own AND the receipt is posted too: the cost
 * counted twice. Penny fixes them one at a time; this sweep does the clear ones at once.
 *
 * Clear = the receipts page's own scoring (BankImportService::candidateExpensesForTransaction)
 * gives the best receipt at least STRONG, and no other receipt comes within GAP of it.
 * Best matches are taken first; a receipt is linked to one line only. Linking uses
 * BankDeskService::linkReceipt (attachExpenseMatch + reverse the bank line's own entry —
 * append-only, never deleted).
 * Weaker matches are left for Penny's review on the dashboard.
 *
 * Run from public/crm/api/run-link-bank-receipts.php (dry-run first, typed confirm).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/BankImportService.php';
require_once __DIR__ . '/BankDeskService.php';

class BankReceiptSweep
{
    /** Exact amount (50) + within 3 days (10+) + vendor name on the statement (20). */
    public const STRONG = 80;
    public const GAP = 15;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{links: array, weak: int, lines: int, double_counted: float} */
    public function plan(): array
    {
        $lines = $this->db->query("
            SELECT at.id, at.transaction_date, at.description, at.amount,
                   EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'bank_import' AND je.source_id = at.id) AS posted
            FROM accounting_transactions at
            WHERE at.reference_type = 'bank_import' AND at.type = 'expense' AND at.matched_expense_id IS NULL
            ORDER BY at.transaction_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $bis = new BankImportService($this->db);
        $offers = [];
        $weak = 0;
        foreach ($lines as $l) {
            $c = $bis->candidateExpensesForTransaction((int)$l['id'], 2);
            if (!$c) continue;
            if (!self::isClear($c)) { $weak++; continue; }
            $offers[] = ['line' => $l, 'receipt' => $c[0]];
        }
        $links = self::assign($offers);

        $expenseIds = array_map(fn($x) => (int)$x['receipt']['expense_id'], $links);
        $postedReceipts = [];
        if ($expenseIds) {
            $in = implode(',', $expenseIds);
            foreach ($this->db->query("SELECT source_id FROM journal_entries WHERE source_type = 'expense' AND source_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $postedReceipts[(int)$id] = true;
            }
        }
        $double = 0.0;
        foreach ($links as &$x) {
            $x['double'] = (bool)$x['line']['posted'] && isset($postedReceipts[(int)$x['receipt']['expense_id']]);
            if ($x['double']) $double += (float)$x['line']['amount'];
        }
        unset($x);
        return ['links' => $links, 'weak' => $weak, 'lines' => count($lines), 'double_counted' => round($double, 2)];
    }

    /** @return array{linked: int, failed: int} */
    public function apply(int $userId): array
    {
        $desk = new BankDeskService($this->db);
        $ok = 0; $failed = 0;
        foreach ($this->plan()['links'] as $x) {
            if ($desk->linkReceipt((int)$x['line']['id'], (int)$x['receipt']['expense_id'], $userId)) $ok++; else $failed++;
        }
        return ['linked' => $ok, 'failed' => $failed];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Best candidate strong enough, and no runner-up close behind it. */
    public static function isClear(array $candidates): bool
    {
        $best = $candidates[0]['confidence'] ?? 0;
        $next = $candidates[1]['confidence'] ?? 0;
        return $best >= self::STRONG && ($best - $next) >= self::GAP;
    }

    /** Strongest first; each receipt goes to one line only. */
    public static function assign(array $offers): array
    {
        usort($offers, fn($a, $b) => $b['receipt']['confidence'] <=> $a['receipt']['confidence']);
        $taken = [];
        $out = [];
        foreach ($offers as $o) {
            $eid = (int)$o['receipt']['expense_id'];
            if (isset($taken[$eid])) continue;
            $taken[$eid] = true;
            $out[] = $o;
        }
        return $out;
    }
}
