<?php
/**
 * ExpenseGate — the one door for every change to an expense (Penny backlog item 2, migration 1233).
 *
 * Before (2026-10-07) an expense's money was written from ~20 places: the desktop modal, Penny's
 * card, the iOS endpoints, the receipts@ inbox, the bank desk, trip attribution, duplicate
 * clean-up, receipt facts, send-to-accounting… each with its own guards, its own learning (or
 * none) and no record of who changed what. Now every one of them calls
 *
 *     (new ExpenseGate($db))->apply($expenseId, $changes, $actor, $source, $opts)
 *
 * which, in this order:
 *   1. validates — the date, the amounts, the status (draft / submitted only, unless the
 *      audited transition that owns it — approve, reject, forward, cancel — says so), and a
 *      receipt sent to accounting keeps its money unless the send itself is the change;
 *   2. writes only the fields that really changed (+ line items, + a split by line);
 *   3. records an audit row in expense_change_log: who, from where, which fields, before / after;
 *   4. re-reads the printed facts and re-runs the duplicate check;
 *   5. teaches Penny — once per change: the capture baseline and line-item lessons on a save,
 *      a single line's rename / add / remove, the header lessons when it is approved or sent,
 *      the owner's split, and a bank rule when he re-categorises a receipt that a bank line
 *      carries;
 *   6. keeps the books append-only: a receipt already posted whose books fields changed is
 *      reversed and posted again; one that is rejected / cancelled after posting is reversed.
 *
 * $changes: expense columns (COLUMNS) plus
 *   'line_items'      => list   replace every line (desktop save, iOS save)
 *   'line_item'       => array  one line: ['op' => add|update|delete, 'id' => …, name, quantity, unit_price, line_total, product_id, sku_raw]
 *   'line_items_from' => int    move another expense's lines here when this one has none (duplicates)
 *   'allocations'     => list|null  split by line (ExpenseSplitService::save); [] / null ends the split
 *   ExpenseGate::DELETE => true remove the expense (its posted entry is reversed first)
 * $actor:  ['id' => ?int, 'kind' => 'user'|'penny'|'system']
 * $opts:   transition (approve|reject|forward|cancel), only_if (fn(array $before): bool),
 *          allow_locked, ocr_parsed (capture baseline), learn_lines (save payload),
 *          learn_baseline (fallback baseline on approval), price_intel (bool)
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 3) . '/Core/paths.php';
}
require_once __DIR__ . '/ExpenseSplitService.php';
require_once __DIR__ . '/ExpenseGateHooks.php';
if (!function_exists('saveLineItems')) {
    require_once APP_ROOT . '/Services/Receipts/ExpenseLineItems.php';
}

class ExpenseGate
{
    public const DELETE = '__delete';

    /** Expense columns the gate writes. */
    public const COLUMNS = [
        'expense_date', 'vendor_id', 'vendor_name_raw', 'description', 'amount', 'gst_amount', 'pst_amount', 'total',
        'accounting_category', 'gbp_category', 'payment_method', 'receipt_media_id', 'receipt_lat', 'receipt_lng',
        'match_confidence', 'anomaly_flags', 'anomaly_score', 'raw_ocr_json', 'line_items_source', 'job_id', 'property_id',
        'contact_id', 'notes', 'status', 'odometer_start', 'odometer_end', 'fuel_litres', 'fuel_price_per_litre', 'asset_tag',
        'source', 'created_by', 'approved_by', 'approved_at', 'rejection_reason', 'forwarded_to_accounting', 'forwarded_at',
    ];
    /** The money fields — locked once sent to accounting. */
    public const MONEY = ['expense_date', 'vendor_id', 'vendor_name_raw', 'amount', 'gst_amount', 'pst_amount', 'total',
                          'accounting_category', 'payment_method', 'job_id', 'asset_tag'];
    /** What the books carry for a posted receipt — a change re-posts it. */
    public const BOOKS = ['expense_date', 'vendor_id', 'amount', 'gst_amount', 'pst_amount', 'total', 'accounting_category',
                          'payment_method', 'job_id', 'asset_tag'];
    public const OPEN = ['draft', 'pending_approval'];
    public const POSTED = ['approved', 'forwarded'];
    /** The audited transition that may set each closed status. */
    public const TRANSITIONS = ['approve' => 'approved', 'reject' => 'rejected', 'forward' => 'forwarded', 'cancel' => 'cancelled'];

    private const INTS = ['vendor_id', 'job_id', 'property_id', 'contact_id', 'receipt_media_id', 'created_by', 'approved_by',
                          'odometer_start', 'odometer_end'];
    private const INTS_NOT_NULL = ['match_confidence', 'anomaly_score', 'forwarded_to_accounting'];
    private const MONEY_COLS = ['amount', 'gst_amount', 'pst_amount', 'total'];
    private const FLOATS = ['receipt_lat', 'receipt_lng', 'fuel_litres', 'fuel_price_per_litre'];

    private PDO $db;
    private ExpenseGateHooks $hooks;
    private ExpenseSplitService $split;
    /** column => exists (later-migration columns) */
    private array $columns = [];
    /** expense_change_log not there yet (before migration 1233) — said once */
    private bool $auditMissing = false;

    public function __construct(PDO $db, ?ExpenseGateHooks $hooks = null, ?ExpenseSplitService $split = null)
    {
        $this->db = $db;
        $this->hooks = $hooks ?? new ExpenseGateHooks($db);
        $this->split = $split ?? new ExpenseSplitService($db);
    }

    /**
     * An expense row from a save payload (desktop create / iOS save): the columns the review
     * form sends, with $overrides (date, total, anomaly score, status, created_by) on top. Pure.
     */
    public static function rowFromInput(array $input, array $overrides = []): array
    {
        $keys = ['vendor_id', 'vendor_name_raw', 'description', 'amount', 'gst_amount', 'pst_amount', 'accounting_category',
                 'gbp_category', 'payment_method', 'receipt_media_id', 'receipt_lat', 'receipt_lng', 'match_confidence',
                 'raw_ocr_json', 'job_id', 'property_id', 'contact_id', 'notes', 'odometer_start', 'odometer_end',
                 'fuel_litres', 'fuel_price_per_litre', 'line_items_source'];
        $row = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $input)) $row[$k] = $input[$k];
        }
        if (isset($row['line_items_source'])) $row['line_items_source'] = substr((string)$row['line_items_source'], 0, 20);
        return $overrides + $row;
    }

    /** The actor array from a session / JWT user. */
    public static function actor(?array $user, string $kind = 'user'): array
    {
        return ['id' => isset($user['id']) && (int)$user['id'] > 0 ? (int)$user['id'] : null, 'kind' => $kind];
    }

    /**
     * Apply a change to one expense (or create one when $expenseId is null).
     * @return array{ok: bool, expense_id: int, action: string, changed: list<string>, noop: bool,
     *               duplicates: array, reposted: bool, line_item_id?: int, allocations?: array, message?: string}
     * @throws InvalidArgumentException on a bad value, Exception when the expense is missing or locked
     */
    public function apply(?int $expenseId, array $changes, array $actor, string $source, array $opts = []): array
    {
        $create = !$expenseId;
        $delete = !empty($changes[self::DELETE]);
        $before = [];
        if (!$create) {
            $before = $this->row((int)$expenseId);
            if (!$before) throw new Exception('Expense not found');
            if (isset($opts['only_if']) && is_callable($opts['only_if']) && !$opts['only_if']($before)) {
                return self::result((int)$expenseId, 'none', [], true, ['message' => 'Nothing to change']);
            }
        }
        $transition = (string)($opts['transition'] ?? '');

        // ── 1. Validate and normalise ─────────────────────────────────────────
        $cols = [];
        foreach ($changes as $k => $v) {
            if (in_array($k, self::COLUMNS, true)) $cols[$k] = self::normalise($k, $v);
        }
        $cols = $this->withoutMissingColumns($cols);
        if (array_key_exists('status', $cols)) {
            $allowed = self::TRANSITIONS[$transition] ?? null;
            if (!in_array($cols['status'], self::OPEN, true) && $cols['status'] !== $allowed) {
                unset($cols['status']);          // only the audited path may close a receipt — keep what it is
            }
        }
        $set = $create ? $cols : self::diff($before, $cols);
        $linesChange = array_key_exists('line_items', $changes) || !empty($changes['line_item']) || !empty($changes['line_items_from']);
        $splitChange = array_key_exists('allocations', $changes) && $this->split->ready();
        if ($splitChange && empty($changes['allocations']) && ($create || !$this->split->hasSplit((int)$expenseId))) {
            $splitChange = false;                // "split off" on a receipt that isn't split: nothing to do
        }

        $locked = !$create && (($before['status'] ?? '') === 'forwarded' || (int)($before['forwarded_to_accounting'] ?? 0) === 1);
        if ($locked && empty($opts['allow_locked']) && ($delete || $linesChange || $splitChange || array_intersect(array_keys($set), array_merge(self::MONEY, ['status'])))) {
            throw new Exception('This expense has been sent to accounting and can no longer be edited');
        }
        if (!$delete && !$set && !$linesChange && !$splitChange) {
            return self::result((int)$expenseId, 'none', [], true);
        }

        // ── 2. Write ─────────────────────────────────────────────────────────
        if ($delete) {
            // The posted entry is reversed first (append-only) — posting runs its own transaction.
            $this->hooks->unpost((int)$expenseId, $actor['id'] ?? null, 'receipt deleted');
        }
        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        $extra = [];
        $splitBefore = !$create && $this->split->ready() ? $this->split->forExpense((int)$expenseId) : [];
        $linesBefore = !$create ? $this->lineSummary((int)$expenseId) : null;
        $lineBefore = null; $lineAfter = null;
        try {
            if ($delete) {
                $id = (int)$expenseId;
                $action = 'delete';
                if (function_exists('reverseLineItemInventory')) reverseLineItemInventory($this->db, $id);
                if ($this->split->ready()) $this->split->clear($id);
                $this->db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
                $this->audit($id, 'delete', $source, $actor, array_keys($before), self::auditValues($before), [], null);
                if ($own) $this->db->commit();
                return self::result($id, 'delete', [], false);
            }

            if ($create) {
                $cols += ['status' => 'draft'];
                $id = $this->insert($cols);
                $action = 'create';
            } else {
                $id = (int)$expenseId;
                if ($set) $this->update($id, $set);
                $action = self::actionFor($transition, $set, $linesChange, $splitChange);
            }

            if (array_key_exists('line_items', $changes)) {
                $items = is_array($changes['line_items']) ? $changes['line_items'] : [];
                if (!$create && !empty($opts['keep_linked'])) {
                    // A re-scan: only the unlinked (OCR) lines are replaced; product-linked ones were checked by hand.
                    $this->db->prepare("DELETE FROM expense_line_items WHERE expense_id = ? AND product_id IS NULL")->execute([$id]);
                } elseif (!$create) {
                    if (function_exists('reverseLineItemInventory')) reverseLineItemInventory($this->db, $id);
                    $this->db->prepare("DELETE FROM expense_line_items WHERE expense_id = ?")->execute([$id]);
                }
                if ($items) saveLineItems($this->db, $id, $items);
            }
            if (!empty($changes['line_item'])) {
                [$lineBefore, $lineAfter] = $this->lineOp($id, (array)$changes['line_item']);
                if ($lineAfter) $extra['line_item_id'] = (int)$lineAfter['id'];
            }
            if (!empty($changes['line_items_from'])) {
                $extra['lines_moved'] = $this->moveLines((int)$changes['line_items_from'], $id);
            }

            $allocations = null;
            if ($splitChange) {
                $choices = is_array($changes['allocations']) ? $changes['allocations'] : [];
                if ($choices) {
                    $allocations = $this->split->save($id, $choices, $actor['id'] ?? null, ($actor['kind'] ?? 'user') === 'penny' ? 'penny' : 'owner');
                } else {
                    $this->split->clear($id);    // the split ends: the receipt's own job and category again
                    $allocations = [];
                }
                $extra['allocations'] = $allocations;
                if (self::splitSummary($splitBefore) == self::splitSummary($allocations)) {
                    $splitChange = false;        // saved again as it was: not a change (no audit, no re-post)
                    unset($extra['allocations']);
                }
            } elseif ($splitBefore && ($linesChange || array_intersect(array_keys($set), ['total', 'gst_amount', 'pst_amount', 'accounting_category', 'job_id']))) {
                $extra['allocations'] = $this->split->rebalance($id, $actor['id'] ?? null);
                if (self::splitSummary($splitBefore) == self::splitSummary($extra['allocations'])) unset($extra['allocations']);
            }
            if (!$create && !$set && !$linesChange && !$splitChange) {
                if ($own) $this->db->commit();
                return self::result($id, 'none', [], true);
            }
            if (!$create) $action = self::actionFor($transition, $set, $linesChange, $splitChange);

            $after = $this->row($id);
            $changed = array_keys($set);
            if ($linesChange) $changed[] = 'lines';
            if ($splitChange) $changed[] = 'allocations';

            $wasPosted = !$create && in_array($before['status'] ?? '', self::POSTED, true);
            $isPosted = in_array($after['status'] ?? '', self::POSTED, true);
            $booksChanged = ($linesChange && $splitBefore) || $splitChange || isset($extra['allocations'])
                         || (bool)array_intersect(array_keys($set), self::BOOKS);
            $repost = $wasPosted && $isPosted && $booksChanged;
            $unpost = $wasPosted && !$isPosted;
            $deferred = ($repost || $unpost) && !$own;

            $this->audit($id, $action, $source, $actor, $changed,
                $create ? [] : self::auditValues(array_intersect_key($before, $set)) + ($linesChange ? ['lines' => $linesBefore] : []) + ($splitChange ? ['allocations' => self::splitSummary($splitBefore)] : []),
                self::auditValues($set) + ($linesChange ? ['lines' => $this->lineSummary($id)] : []) + (isset($extra['allocations']) ? ['allocations' => self::splitSummary($extra['allocations'])] : []),
                $repost ? ($deferred ? 'books: re-post waits for the nightly repost' : 'books re-posted') : ($unpost ? 'posted entry reversed' : null));
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }

        // ── 3. After: facts, duplicates, learning, books ─────────────────────
        $result = self::result($id, $action, $changed, false, $extra);
        $raw = trim((string)($after['raw_ocr_json'] ?? ''));
        if ($raw !== '' && ($create || $linesChange || array_intersect($changed, ['raw_ocr_json', 'expense_date', 'vendor_id', 'vendor_name_raw', 'total']))) {
            $this->hooks->facts($id);
        }
        if ($create || array_intersect($changed, ['total', 'expense_date', 'vendor_id', 'vendor_name_raw', 'receipt_media_id'])) {
            $result['duplicates'] = $this->hooks->duplicates($id);
        }
        $this->learn($id, $create, $before, $after, $changed, $changes, $actor, $source, $opts, $lineBefore, $lineAfter, $extra);

        $userId = $actor['id'] ?? null;
        if (!$deferred && $repost) {
            $result['reposted'] = $this->hooks->repost($id, $userId, 'receipt changed after posting (' . $source . ')');
        } elseif (!$deferred && $unpost) {
            $this->hooks->unpost($id, $userId, 'receipt ' . ($after['status'] ?? 'changed') . ' after posting');
        }
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Learning — each signal once per change
    // ─────────────────────────────────────────────────────────────────────────

    private function learn(int $id, bool $create, array $before, array $after, array $changed, array $changes, array $actor,
                           string $source, array $opts, ?array $lineBefore, ?array $lineAfter, array $extra): void
    {
        $person = ($actor['kind'] ?? 'user') === 'user';
        if ($create && !empty($opts['ocr_parsed']) && trim((string)($after['raw_ocr_json'] ?? '')) !== '') {
            $this->hooks->storeBaseline($id, $opts['ocr_parsed']);
        }
        if (!empty($opts['learn_lines']) && trim((string)($after['raw_ocr_json'] ?? '')) !== '') {
            $this->hooks->learnLines($id, $after, (array)$opts['learn_lines']);
        }
        if (!empty($changes['line_item'])) {
            $this->hooks->learnLineOp((string)($changes['line_item']['op'] ?? ''), $lineBefore, $lineAfter, $after);
        }
        if (!empty($opts['price_intel']) && !empty($changes['line_items']) && !empty($after['vendor_id'])) {
            $this->hooks->priceIntel($id, (int)$after['vendor_id'], (array)$changes['line_items'], (string)$after['expense_date']);
        }
        $nowConfirmed = in_array($after['status'] ?? '', self::POSTED, true) && ($create || !in_array($before['status'] ?? '', self::POSTED, true));
        if ($nowConfirmed) {
            $this->hooks->learnConfirmed($id, isset($opts['learn_baseline']) && is_array($opts['learn_baseline']) ? $opts['learn_baseline'] : null);
        }
        if (array_key_exists('allocations', $changes) && !empty($extra['allocations']) && ($person || ($actor['kind'] ?? '') === 'penny')) {
            $this->hooks->learnSplit($id, $extra['allocations']);
        }
        if (!$create && $person && in_array('accounting_category', $changed, true) && $source !== 'bank_desk') {
            $this->hooks->learnBank($id, $after, $actor['id'] ?? null);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Writing
    // ─────────────────────────────────────────────────────────────────────────

    private function row(int $id): array
    {
        $s = $this->db->prepare("SELECT * FROM expenses WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function insert(array $cols): int
    {
        $names = array_keys($cols);
        $sql = "INSERT INTO expenses (" . implode(', ', $names) . ") VALUES (" . implode(', ', array_fill(0, count($names), '?')) . ")";
        $this->exec($sql, array_values($cols));
        return (int)$this->db->lastInsertId();
    }

    private function update(int $id, array $set): void
    {
        if ($this->hasColumn('updated_at')) $set['updated_at'] = date('Y-m-d H:i:s');
        $sets = implode(', ', array_map(function ($c) { return "{$c} = ?"; }, array_keys($set)));
        $this->exec("UPDATE expenses SET {$sets} WHERE id = ?", array_merge(array_values($set), [$id]));
    }

    /** MySQL 1615 ("needs to be re-prepared") on this host's short table cache: re-prepare once. */
    private function exec(string $sql, array $params): void
    {
        try {
            $this->db->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), '1615') === false) throw $e;
            $this->db->prepare($sql)->execute($params);
        }
    }

    /** A column that arrived with a later migration: written when it exists, never fatal before. */
    private const OPTIONAL = ['line_items_source', 'asset_tag', 'source'];

    private function withoutMissingColumns(array $cols): array
    {
        foreach (self::OPTIONAL as $c) {
            if (array_key_exists($c, $cols) && !$this->hasColumn($c)) unset($cols[$c]);
        }
        return $cols;
    }

    private function hasColumn(string $col): bool
    {
        $seen = &$this->columns;
        $key = $col;
        if (!array_key_exists($key, $seen)) {
            try {
                $this->db->query("SELECT {$col} FROM expenses LIMIT 0");
                $seen[$key] = true;
            } catch (Throwable $e) {
                $seen[$key] = false;
            }
        }
        return $seen[$key];
    }

    /** One line: add / update / delete. @return array{0: ?array, 1: ?array} the line before and after */
    private function lineOp(int $expenseId, array $op): array
    {
        $kind = (string)($op['op'] ?? '');
        $before = null;
        if ($kind !== 'add') {
            $s = $this->db->prepare("SELECT * FROM expense_line_items WHERE id = ? AND expense_id = ?");
            $s->execute([(int)($op['id'] ?? 0), $expenseId]);
            $before = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$before) throw new Exception('Line item not found');
        }
        $name = trim((string)($op['name'] ?? ($before['name'] ?? '')));
        if ($kind !== 'delete' && $name === '') throw new InvalidArgumentException('Item name required');
        if ($kind === 'add') {
            $sort = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM expense_line_items WHERE expense_id = ?");
            $sort->execute([$expenseId]);
            $productId = !empty($op['product_id']) ? (int)$op['product_id'] : null;
            $qty = (float)($op['quantity'] ?? 1);
            $this->db->prepare("INSERT INTO expense_line_items (expense_id, product_id, name, quantity, unit_price, line_total, sku_raw, sort_order)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$expenseId, $productId, $name, $qty, $op['unit_price'] ?? null, round((float)($op['line_total'] ?? 0), 2),
                          $op['sku_raw'] ?? null, (int)$sort->fetchColumn()]);
            $newId = (int)$this->db->lastInsertId();
            if ($productId && function_exists('updateProductInventory')) updateProductInventory($this->db, $productId, $qty);
            return [null, $this->lineRow($newId)];
        }
        if ($kind === 'update') {
            $qty = array_key_exists('quantity', $op) ? (float)$op['quantity'] : (float)$before['quantity'];
            $unit = array_key_exists('unit_price', $op) ? ($op['unit_price'] === null || $op['unit_price'] === '' ? null : (float)$op['unit_price'])
                                                        : ($before['unit_price'] !== null ? (float)$before['unit_price'] : null);
            $total = array_key_exists('line_total', $op) ? (float)$op['line_total'] : (float)$before['line_total'];
            $this->db->prepare("UPDATE expense_line_items SET name = ?, quantity = ?, unit_price = ?, line_total = ? WHERE id = ?")
               ->execute([$name, $qty, $unit, $total, (int)$before['id']]);
            if (!empty($before['product_id']) && function_exists('updateProductInventory')) {
                updateProductInventory($this->db, (int)$before['product_id'], $qty - (float)$before['quantity']);
            }
            return [$before, $this->lineRow((int)$before['id'])];
        }
        if ($kind === 'delete') {
            if (!empty($before['product_id']) && function_exists('updateProductInventory')) {
                updateProductInventory($this->db, (int)$before['product_id'], -(float)$before['quantity']);
            }
            $this->db->prepare("DELETE FROM expense_line_items WHERE id = ?")->execute([(int)$before['id']]);
            return [$before, null];
        }
        throw new InvalidArgumentException('Unknown line change');
    }

    private function lineRow(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM expense_line_items WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** A copy's lines move to the kept receipt when that has none. @return bool moved */
    private function moveLines(int $fromId, int $toId): bool
    {
        try {
            $c = $this->db->prepare("SELECT COUNT(*) FROM expense_line_items WHERE expense_id = ?");
            $c->execute([$toId]);
            if ((int)$c->fetchColumn() > 0) return false;
            $u = $this->db->prepare("UPDATE expense_line_items SET expense_id = ? WHERE expense_id = ?");
            $u->execute([$toId, $fromId]);
            return $u->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function lineSummary(int $id): ?string
    {
        try {
            $s = $this->db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(line_total), 0) AS t FROM expense_line_items WHERE expense_id = ?");
            $s->execute([$id]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            return (int)$r['n'] . ' lines · $' . number_format((float)$r['t'], 2);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function audit(int $id, string $action, string $source, array $actor, array $fields, array $before, array $after, ?string $note): void
    {
        if ($this->auditMissing) return;              // before migration 1233: said once, not per change
        try {
            $this->db->prepare("INSERT INTO expense_change_log (expense_id, action, source, actor_user_id, actor_kind, fields, before_text, after_text, note, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$id, $action, mb_substr($source, 0, 40), $actor['id'] ?? null, mb_substr((string)($actor['kind'] ?? 'user'), 0, 20),
                          mb_substr(implode(',', $fields), 0, 500), $before ? json_encode($before) : null, $after ? json_encode($after) : null,
                          $note, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'expense_change_log') !== false) $this->auditMissing = true;
            error_log('ExpenseGate audit (expense ' . $id . ', ' . $source . '): ' . $e->getMessage());   // before migration 1233
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** A column's value as it is stored. @throws InvalidArgumentException */
    public static function normalise(string $col, $v)
    {
        if (in_array($col, self::MONEY_COLS, true)) {
            if ($v === null || $v === '') return 0.0;
            if (!is_numeric($v) || !is_finite((float)$v)) throw new InvalidArgumentException("That amount isn't a number");
            return round((float)$v, 2);
        }
        if (in_array($col, self::INTS, true)) return $v === null || $v === '' || (int)$v === 0 ? null : (int)$v;
        if (in_array($col, self::INTS_NOT_NULL, true)) return (int)$v;
        if (in_array($col, self::FLOATS, true)) return $v === null || $v === '' || (float)$v == 0.0 ? null : (float)$v;
        if ($col === 'expense_date') {
            $d = substr(trim((string)$v), 0, 10);
            $dt = DateTime::createFromFormat('Y-m-d', $d);
            if (!$dt || $dt->format('Y-m-d') !== $d) throw new InvalidArgumentException("That date isn't valid");
            return $d;
        }
        if (in_array($col, ['approved_at', 'forwarded_at'], true)) return $v === 'now' ? date('Y-m-d H:i:s') : ($v === '' ? null : $v);
        if ($v === null) return null;
        if (in_array($col, ['raw_ocr_json', 'notes', 'description'], true)) return (string)$v;
        $t = trim((string)$v);
        return $t === '' ? null : $t;
    }

    /** The columns whose value really changes. */
    public static function diff(array $before, array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            $old = $before[$k] ?? null;
            if (in_array($k, self::MONEY_COLS, true) || in_array($k, self::FLOATS, true)) {
                if ($old === null && $v === null) continue;
                if ($old !== null && $v !== null && abs((float)$old - (float)$v) < 0.005) continue;
                if (in_array($k, self::MONEY_COLS, true) && $old === null && (float)$v == 0.0) continue;
            } elseif (in_array($k, self::INTS, true)) {
                if (($old === null || (int)$old === 0 ? null : (int)$old) === $v) continue;
            } elseif (in_array($k, self::INTS_NOT_NULL, true)) {
                if ((int)$old === (int)$v) continue;
            } elseif ((string)($old ?? '') === (string)($v ?? '')) {
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    public static function actionFor(string $transition, array $set, bool $lines, bool $split): string
    {
        if ($transition !== '' && isset(self::TRANSITIONS[$transition]) && ($set['status'] ?? null) === self::TRANSITIONS[$transition]) return $transition;
        if (!$set && $split && !$lines) return 'split';
        if (!$set && $lines) return 'lines';
        return 'update';
    }

    /** Values for the audit row — OCR text is too big to keep twice. */
    private static function auditValues(array $vals): array
    {
        if (array_key_exists('raw_ocr_json', $vals)) $vals['raw_ocr_json'] = $vals['raw_ocr_json'] === null ? null : '[OCR text, ' . strlen((string)$vals['raw_ocr_json']) . ' bytes]';
        unset($vals['ocr_parsed_json']);
        return $vals;
    }

    private static function splitSummary(array $allocs): array
    {
        return array_map(function ($a) {
            return ['label' => $a['label'], 'job_id' => $a['job_id'], 'category' => $a['accounting_category'], 'stock' => (bool)$a['is_stock'],
                    'net' => $a['net_amount'], 'gst' => $a['gst_amount'], 'pst' => $a['pst_amount']];
        }, $allocs);
    }

    private static function result(int $id, string $action, array $changed, bool $noop, array $extra = []): array
    {
        return array_merge(['ok' => true, 'expense_id' => $id, 'action' => $action, 'changed' => $changed, 'noop' => $noop,
                            'duplicates' => [], 'reposted' => false], $extra);
    }
}
