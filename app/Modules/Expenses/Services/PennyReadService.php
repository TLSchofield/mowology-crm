<?php
/**
 * PennyReadService — "Penny's read" on the receipt screens (iPhone detail, web/Android modal).
 *
 * Why (2026-10-13, Chevron #424): the receipt reader kept two "CRF 0.07" lines and guessed
 * Fuel; Penny's suggestion read the coffee, the cokes, the deposit and the CRF correctly and
 * said Meals — but only her desk card on desktop showed it, so on the phone it looked as if
 * she had missed the drinks. This shows her pending suggestion wherever the receipt is open:
 * the fields where she differs from the receipt (each with her reason), her line items when
 * they differ, and her notes; "Use Penny's version" applies all of it, "Keep mine" closes it.
 *
 * Nothing is re-implemented: applying is BookkeeperDeskService::decide() with
 * overrides.line_items = 'penny' (ExpenseGate rules, her scorecard, PennyLessons; approval
 * teaches the reader the layout via teachReaderFromPenny()).
 *
 * Who (actor = ['id', 'office' (admin/manager role or expenses.approve), 'can_approve']):
 *   - sees it: the office for any receipt, anyone else for a receipt they created;
 *   - "Use Penny's version": approvers → applied and approved (the self-approval rule still
 *     holds — then it is saved as a draft); crew on their own open receipt (the
 *     ExpenseService::editBlockReason rule) → applied, left for the office to approve;
 *   - "Keep mine": approvers only — her suggestion is closed as 'kept' (not prepared again)
 *     and each field she differed on is a head_lessons row (source 'receipt_keep_mine').
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once __DIR__ . '/ExpenseService.php';
require_once __DIR__ . '/ReceiptBookkeeperService.php';
require_once __DIR__ . '/BookkeeperDeskService.php';
if (!function_exists('sameVendorName')) {
    require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
}

class PennyReadService
{
    /** Her fields, in the order shown, with their labels. */
    public const FIELDS = [
        'vendor' => 'Vendor', 'accounting_category' => 'Category', 'asset_tag' => 'For', 'job' => 'Job',
        'subtotal' => 'Subtotal', 'gst' => 'GST', 'pst' => 'PST', 'total' => 'Total',
    ];
    private const AMOUNT_COLS = ['subtotal' => 'amount', 'gst' => 'gst_amount', 'pst' => 'pst_amount', 'total' => 'total'];
    private const TAG_LABELS = ['truck' => 'Truck', 'equipment' => 'Equipment', 'stock' => 'Shop stock'];

    private PDO $db;
    private $desk;

    /** @param BookkeeperDeskService|null $desk tests pass a spy */
    public function __construct(PDO $db, $desk = null)
    {
        $this->db = $db;
        $this->desk = $desk;
    }

    private function desk(): BookkeeperDeskService
    {
        return $this->desk ??= new BookkeeperDeskService($this->db, new ReceiptBookkeeperService($this->db));
    }

    // ── Pure (unit tested) ────────────────────────────────────────────────────

    /** What this person may do with Penny's read on this receipt. */
    public static function rights(array $expense, array $actor): array
    {
        $office = !empty($actor['office']);
        $own = (int)($expense['created_by'] ?? 0) === (int)($actor['id'] ?? 0) && (int)($actor['id'] ?? 0) > 0;
        $see = $office || $own;
        $open = in_array($expense['status'] ?? '', ['draft', 'pending_approval'], true) && empty($expense['forwarded_to_accounting']);
        $approve = $see && $open && !empty($actor['can_approve']);
        $editable = $see && $open && ExpenseService::editBlockReason($expense, ['id' => (int)($actor['id'] ?? 0), 'is_admin' => $office]) === null;
        return ['see' => $see, 'apply' => $approve || $editable, 'approve' => $approve, 'keep' => $approve];
    }

    /** Two money values the same to the cent? */
    private static function sameMoney($a, $b): bool
    {
        if ($a === null || $a === '' || $b === null || $b === '') return ($a === null || $a === '') && ($b === null || $b === '');
        return abs((float)$a - (float)$b) < 0.005;
    }

    /**
     * Her fields that differ from the receipt. $expense carries vendor_display; $jobTitles maps job id → title.
     * @return list<array{field: string, label: string, penny: ?string, receipt: ?string, reason: string, confidence: string}>
     */
    public static function differences(array $suggestion, array $expense, array $jobTitles = []): array
    {
        $out = [];
        foreach (self::FIELDS as $f => $label) {
            if (!isset($suggestion[$f]) || !is_array($suggestion[$f]) || !array_key_exists('value', $suggestion[$f])) continue;
            $p = $suggestion[$f]['value'];
            $differs = false;
            $pennyText = null;
            $receiptText = null;
            switch ($f) {
                case 'vendor':
                    $cur = (string)($expense['vendor_display'] ?? '');
                    if ($p === null || trim((string)$p) === '') continue 2;
                    $differs = !sameVendorName((string)$p, $cur);
                    [$pennyText, $receiptText] = [trim((string)$p), $cur !== '' ? $cur : null];
                    break;
                case 'accounting_category':
                    if ($p === null || $p === '') continue 2;
                    $differs = (string)$p !== (string)($expense['accounting_category'] ?? '');
                    [$pennyText, $receiptText] = [(string)$p, ($expense['accounting_category'] ?? '') !== '' ? (string)$expense['accounting_category'] : null];
                    break;
                case 'asset_tag':
                    $pv = ($p === 'none' || $p === '' || $p === null) ? null : (string)$p;
                    $cv = ($expense['asset_tag'] ?? '') !== '' ? (string)$expense['asset_tag'] : null;
                    $differs = $pv !== $cv;
                    [$pennyText, $receiptText] = [$pv ? (self::TAG_LABELS[$pv] ?? ucfirst($pv)) : 'None', $cv ? (self::TAG_LABELS[$cv] ?? ucfirst($cv)) : 'None'];
                    break;
                case 'job':
                    $pv = $p === null || (int)$p === 0 ? null : (int)$p;
                    $cv = empty($expense['job_id']) ? null : (int)$expense['job_id'];
                    $differs = $pv !== $cv;
                    $pennyText = $pv ? ($jobTitles[$pv] ?? ('Job #' . $pv)) : 'No job';
                    $receiptText = $cv ? ($jobTitles[$cv] ?? ('Job #' . $cv)) : 'No job';
                    break;
                default:
                    if ($p === null || $p === '' || !is_numeric($p)) continue 2;
                    $cur = $expense[self::AMOUNT_COLS[$f]] ?? null;
                    $differs = !self::sameMoney($p, $cur);
                    $pennyText = '$' . number_format((float)$p, 2, '.', '');
                    $receiptText = $cur !== null && $cur !== '' ? '$' . number_format((float)$cur, 2, '.', '') : null;
            }
            if (!$differs) continue;
            $out[] = ['field' => $f, 'label' => $label, 'penny' => $pennyText, 'receipt' => $receiptText,
                      'reason' => trim((string)($suggestion[$f]['reason'] ?? '')), 'confidence' => (string)($suggestion[$f]['confidence'] ?? '')];
        }
        return $out;
    }

    /**
     * Her line items vs the receipt's, when they differ (by amounts — names are cosmetic), else null.
     * @param array $receiptItems [{name, line_total}]
     */
    public static function itemsDiff(array $suggestion, array $receiptItems): ?array
    {
        $penny = [];
        foreach ((array)($suggestion['line_items'] ?? []) as $it) {
            if (trim((string)($it['name'] ?? '')) === '' || !isset($it['amount']) || !is_numeric($it['amount'])) continue;
            $penny[] = ['name' => trim((string)$it['name']), 'amount' => round((float)$it['amount'], 2)];
        }
        if (!$penny) return null;
        $receipt = array_map(fn($r) => ['name' => (string)$r['name'], 'amount' => round((float)$r['line_total'], 2)], $receiptItems);
        if (!readerItemsDiffer($receipt, $penny)) return null;
        return [
            'penny' => $penny, 'receipt' => $receipt,
            'penny_sum' => round(array_sum(array_column($penny, 'amount')), 2),
            'receipt_sum' => round(array_sum(array_column($receipt, 'amount')), 2),
        ];
    }

    // ── Reads ─────────────────────────────────────────────────────────────────

    private function expense(int $expenseId): ?array
    {
        $s = $this->db->prepare("SELECT e.*, COALESCE(v.name, e.vendor_name_raw) AS vendor_display
                                 FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function pending(int $expenseId): ?array
    {
        try {
            $s = $this->db->prepare("SELECT * FROM expense_suggestions WHERE expense_id = ? AND source = 'live' AND status = 'pending'
                                     ORDER BY id DESC LIMIT 1");
            $s->execute([$expenseId]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;                                       // before migration 1125
        }
    }

    private function items(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT name, line_total FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $s->execute([$expenseId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** job id → "Title — address" */
    private function titles(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $s = $this->db->prepare("SELECT jp.id, CONCAT(COALESCE(jp.title, ''), ' — ', COALESCE(p.address, '')) AS t
                                 FROM job_plans jp LEFT JOIN properties p ON p.id = jp.property_id
                                 WHERE jp.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $s->execute($ids);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = trim((string)$r['t'], ' —');
        return $out;
    }

    /**
     * Penny's read for one receipt.
     * @return array{ok: bool, read?: ?array, error?: string, code?: int}
     *   read = null when there is nothing of hers to show (no pending suggestion, receipt closed).
     */
    public function read(int $expenseId, array $actor): array
    {
        $e = $this->expense($expenseId);
        if (!$e) return ['ok' => false, 'code' => 404, 'error' => 'Receipt not found'];
        $r = self::rights($e, $actor);
        if (!$r['see']) return ['ok' => false, 'code' => 403, 'error' => 'You can only see your own receipts'];
        $row = $this->pending($expenseId);
        $open = in_array($e['status'], ['draft', 'pending_approval'], true) && empty($e['forwarded_to_accounting']);
        // No read of hers yet (Penny prepares only some receipts each day): offer "Ask Penny" to
        // whoever may apply her read here, when she can run (Chevron #355, 2026-10-13).
        if (!$row || !$open) {
            return ['ok' => true, 'read' => null, 'can_ask' => !$row && $open && $r['apply'] && $this->desk()->canAsk()];
        }

        $s = json_decode((string)$row['suggestion_json'], true) ?: [];
        $titles = $this->titles(array_filter([(int)($s['job']['value'] ?? 0), (int)($e['job_id'] ?? 0)]));
        $fields = self::differences($s, $e, $titles);
        $items = self::itemsDiff($s, $this->items($expenseId));
        $draft = (json_decode((string)($row['outcome_json'] ?? ''), true) ?: [])['draft'] ?? null;
        $differs = $fields || $items;
        return ['ok' => true, 'read' => [
            'suggestion_id'  => (int)$row['id'],
            'made_at'        => $row['created_at'] ?? null,
            'differs'        => (bool)$differs,
            'fields'         => $fields,
            'items'          => $items,
            'notes'          => trim((string)($s['notes'] ?? '')),
            // Saved as a draft from this screen or her card — waiting for the office.
            'applied_draft'  => $draft !== null && !$differs,
            'can_apply'      => $r['apply'] && $differs,
            'apply_approves' => false,   // "Use Penny's version" never approves (2026-10-14)
            'can_keep'       => $r['keep'] && $differs,
            'apply_note'     => $r['approve'] ? 'Puts her read on the receipt; check it, then Approve.' : 'Applies her read; the office approves it.',
        ]];
    }

    // ── Writes ────────────────────────────────────────────────────────────────

    /**
     * "Use Penny's version": every field of hers, and her line items, through decide() — never approved.
     * @param array $user ['id', 'role', 'full_name'] — what decide() / approve() read
     */
    public function apply(int $expenseId, array $user, array $actor): array
    {
        $e = $this->expense($expenseId);
        if (!$e) return ['ok' => false, 'message' => 'Receipt not found'];
        $r = self::rights($e, $actor);
        if (!$r['apply']) return ['ok' => false, 'message' => 'You can\'t change this receipt'];
        $row = $this->pending($expenseId);
        if (!$row) return ['ok' => false, 'message' => 'Penny has no open suggestion for this receipt'];
        $s = json_decode((string)$row['suggestion_json'], true) ?: [];
        $overrides = self::itemsDiff($s, $this->items($expenseId)) ? ['line_items' => 'penny'] : [];
        // Never approves (Tim, 2026-10-14: "sometimes not all of it is correct"): her read goes onto the
        // receipt as a draft; a person checks it and presses Approve.
        $res = $this->desk()->decide((int)$row['id'], $overrides, $user, false);
        if (!empty($res['ok'])) {
            $res['message'] = $r['approve'] ? 'Penny\'s version is on the receipt — check it, then Approve'
                                            : 'Penny\'s version is on the receipt — the office approves it';
        }
        return $res;
    }

    /**
     * "Ask Penny": her live read of this one receipt now (text first, the photo when the text
     * doesn't add up), within her daily cap and the AI-call ceiling (LlmDailyCap). Anyone who may
     * apply her read here may ask. Nothing on the receipt changes — the read shows as usual.
     * @return array{ok: bool, message: string, read?: ?array}
     */
    public function ask(int $expenseId, array $actor): array
    {
        $e = $this->expense($expenseId);
        if (!$e) return ['ok' => false, 'message' => 'Receipt not found'];
        if (!self::rights($e, $actor)['apply']) return ['ok' => false, 'message' => 'You can\'t change this receipt'];
        $res = $this->desk()->askOne($expenseId);
        if (empty($res['ok'])) return $res;
        $read = $this->read($expenseId, $actor);
        return ['ok' => true, 'message' => $res['message'], 'read' => $read['read'] ?? null];
    }

    /**
     * "Keep mine": the receipt stays as it is; her suggestion is closed as 'kept' and each field
     * she differed on is recorded as an override of hers (head_lessons). Approvers only.
     */
    public function keep(int $expenseId, array $user, array $actor): array
    {
        $e = $this->expense($expenseId);
        if (!$e) return ['ok' => false, 'message' => 'Receipt not found'];
        if (!self::rights($e, $actor)['keep']) return ['ok' => false, 'message' => 'Only someone who approves receipts can set Penny\'s read aside'];
        $row = $this->pending($expenseId);
        if (!$row) return ['ok' => false, 'message' => 'Penny has no open suggestion for this receipt'];
        $s = json_decode((string)$row['suggestion_json'], true) ?: [];
        $titles = $this->titles(array_filter([(int)($s['job']['value'] ?? 0), (int)($e['job_id'] ?? 0)]));
        $fields = self::differences($s, $e, $titles);
        $items = self::itemsDiff($s, $this->items($expenseId));

        $outcome = [];
        foreach ($fields as $f) $outcome[$f['field']] = ['suggested' => $f['penny'], 'final' => $f['receipt'], 'accepted' => false];
        if ($items) $outcome['line_items'] = ['suggested' => lineItemsSummary($items['penny']), 'final' => lineItemsSummary($items['receipt']), 'accepted' => false];
        $u = $this->db->prepare("UPDATE expense_suggestions SET status = 'kept', outcome_json = ?, decided_by = ?, decided_at = NOW()
                                 WHERE id = ? AND status = 'pending'");
        $u->execute([json_encode(['kept' => $outcome]), (int)$user['id'], (int)$row['id']]);
        if ($u->rowCount() === 0) return ['ok' => false, 'message' => 'Already decided'];

        try {
            require_once APP_ROOT . '/Modules/Accounting/Services/PennyLessons.php';
            foreach ($outcome as $field => $o) {
                PennyLessons::expense($this->db, $expenseId, $field === 'job' ? 'job_id' : $field, $o['suggested'], $o['final'],
                                      'receipt_keep_mine', (int)$user['id']);
            }
        } catch (Throwable $ex) {
            error_log('Penny keep-mine lessons #' . $expenseId . ': ' . $ex->getMessage());
        }
        return ['ok' => true, 'message' => 'Kept yours — Penny\'s read is set aside'];
    }
}
