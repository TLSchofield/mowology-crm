<?php
declare(strict_types=1);

require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/Services/Receipts/LineItemFit.php';
require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/Services/Receipts/ReceiptParser.php';
require_once (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/Services/Receipts/ReceiptLearning.php';
require_once __DIR__ . '/ExpenseGate.php';

final class LineItemBackfillService
{
    /** Candidates looked at per run (a re-parse is local and quick; production has about 400 receipts). */
    private const SCAN_MAX = 2000;

    private PDO $db;
    /** @var ExpenseGate|null */
    private $gate;

    public function __construct(PDO $db, $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate;
    }

    private function gate(): ExpenseGate
    {
        return $this->gate ?? ($this->gate = new ExpenseGate($this->db));
    }

    public function run(bool $commit = false, int $limit = 100, array $statuses = ['draft', 'pending_approval', 'approved']): array
    {
        $result = ['checked' => 0, 'filled' => 0, 'would_fill' => 0, 'skipped' => [], 'preview' => []];
        $allowed = ['draft', 'pending_approval', 'approved'];
        $statuses = array_values(array_intersect($allowed, $statuses));
        if (!$statuses) $statuses = $allowed;
        $limit = max(1, min(200, $limit));
        $marks = implode(',', array_fill(0, count($statuses), '?'));
        $sql = "SELECT e.id, e.vendor_id, COALESCE(v.name, e.vendor_name_raw, '') AS vendor,
                       e.raw_ocr_json, e.amount, e.gst_amount, e.pst_amount, e.total
                FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.status IN ({$marks})
                  AND COALESCE(e.forwarded_to_accounting, 0) <> 1
                  AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> ''
                  AND NOT EXISTS (SELECT 1 FROM expense_line_items li WHERE li.expense_id = e.id)
                  AND NOT EXISTS (SELECT 1 FROM expense_line_allocations a WHERE a.expense_id = e.id)
                  AND NOT EXISTS (SELECT 1 FROM expense_line_items lp WHERE lp.expense_id = e.id AND lp.product_id IS NOT NULL)
                ORDER BY e.id ASC LIMIT " . self::SCAN_MAX;
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($statuses);
        } catch (Throwable $e) {
            error_log('[LineItemBackfillService] batch: ' . $e->getMessage());
            $result['skipped']['error'] = 1;
            return $result;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // $limit caps the writes, not the scan: receipts the reader still can't fit stay candidates, so a
            // scan capped at $limit would re-check the same oldest ones every run and never reach the rest.
            if ($result['filled'] + $result['would_fill'] >= $limit) break;
            $id = (int)$row['id'];
            $result['checked']++;
            try {
                $stored = LineItemFit::readStored($row['raw_ocr_json']);
                if ($stored['text'] === '') {
                    $result['skipped']['no_text'] = ($result['skipped']['no_text'] ?? 0) + 1;
                    continue;
                }
                $profile = getVendorLineItemProfile($row['vendor_id'] !== null ? (int)$row['vendor_id'] : null);
                $parsed = parseReceiptText($stored['text'], $stored['raw_response'], $profile);
                $items = $parsed['line_items'] ?? [];
                $amount = $row['amount'] !== null ? (float)$row['amount'] : null;
                $total = $row['total'] !== null ? (float)$row['total'] : null;
                $gst = $row['gst_amount'] !== null ? (float)$row['gst_amount'] : null;
                $pst = $row['pst_amount'] !== null ? (float)$row['pst_amount'] : null;
                if (!LineItemFit::addsUp($items, $amount, $total, 0.05, $gst, $pst)) {
                    $result['skipped']['does_not_add_up'] = ($result['skipped']['does_not_add_up'] ?? 0) + 1;
                    continue;
                }
                if (count($result['preview']) < 200) {
                    $result['preview'][] = ['id' => $id, 'vendor' => (string)$row['vendor'], 'items' => $items,
                        'sum' => LineItemFit::sum($items), 'amount' => $amount, 'total' => $total];
                }
                if ($commit) {
                    $applied = $this->gate()->apply($id, ['line_items' => $items, 'line_items_source' => 'reread'],
                                         ['id' => null, 'kind' => 'system'], 'line_item_backfill', [
                        'locked_if' => static function (array $snapshot) use ($items): bool {
                            $expense = $snapshot['expense'];
                            // The totals may have been edited since the batch read them (Codex review 2026-11-05).
                            $amount = $expense['amount'] !== null ? (float)$expense['amount'] : null;
                            $total = $expense['total'] !== null ? (float)$expense['total'] : null;
                            $gst = ($expense['gst_amount'] ?? null) !== null ? (float)$expense['gst_amount'] : null;
                            $pst = ($expense['pst_amount'] ?? null) !== null ? (float)$expense['pst_amount'] : null;
                            return in_array($expense['status'] ?? '', ['draft', 'pending_approval', 'approved'], true)
                                && (int)($expense['forwarded_to_accounting'] ?? 0) !== 1
                                && empty($snapshot['lines']) && empty($snapshot['allocations'])
                                && LineItemFit::addsUp($items, $amount, $total, 0.05, $gst, $pst);
                        },
                    ]);
                    if (!empty($applied['noop'])) {
                        $result['skipped']['changed_before_apply'] = ($result['skipped']['changed_before_apply'] ?? 0) + 1;
                        continue;
                    }
                    $result['filled']++;
                } else {
                    $result['would_fill']++;
                }
            } catch (Throwable $e) {
                error_log('[LineItemBackfillService] expense #' . $id . ': ' . $e->getMessage());
                $result['skipped']['error'] = ($result['skipped']['error'] ?? 0) + 1;
            }
        }
        return $result;
    }
}
