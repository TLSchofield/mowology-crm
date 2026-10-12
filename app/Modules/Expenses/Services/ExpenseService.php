<?php
/**
 * ExpenseService — user-correctable edits to an expense's own fields.
 *
 * Mirrors the field-edit half of app/Modules/Expenses/Api/expenses.php's handleUpdate()
 * so the JWT/mobile edit endpoint (expense-update.php) shares one ownership- and
 * status-guarded code path. This service deliberately does NOT change status, approval,
 * or forwarding — those audited transitions stay in ExpenseApprovalService /
 * ReceiptArchiveService. Permission checks (expenses.edit) stay in the caller, since the
 * session vs JWT mechanisms differ; $currentUser only needs 'id' and 'is_admin'.
 *
 * Global-namespace (no production autoloader): require_once the file and `new
 * ExpenseService($db)` — matches every other service in this module.
 */
require_once __DIR__ . '/ExpenseGate.php';

class ExpenseService
{
    private PDO $db;
    /** @var ExpenseGate|null every write goes through the expense gate (migration 1233) */
    private $gate;

    public function __construct(PDO $db, $gate = null)
    {
        $this->db = $db;
        $this->gate = $gate;
    }

    private function gate(): ExpenseGate
    {
        if ($this->gate === null) {
            require_once __DIR__ . '/ExpenseGate.php';
            $this->gate = new ExpenseGate($this->db);
        }
        return $this->gate;
    }

    /**
     * Edit the user-correctable fields of an expense (vendor, date, amounts, category,
     * payment method, notes). Preserves status and every audit column.
     *
     * @param int   $expenseId
     * @param array $currentUser  Must include 'id' and 'is_admin' (bool).
     * @param array $input        Editable fields (expense_date, vendor_name_raw, amount,
     *                            gst_amount, total, accounting_category, payment_method,
     *                            description, notes).
     * @return array ['success'=>true, 'message'=>string, 'expense_id'=>int]
     * @throws Exception on missing id, not found, wrong owner, or already-sent.
     */
    /**
     * Who may change a receipt — the one rule for the iPhone (update) and the desktop edit + line
     * items (expenses.php), review round 2 2026-10-12. Pure (tested).
     *   - crew edit their own; admins/managers (actor['is_admin']) edit any;
     *   - nobody edits a receipt already sent to accounting;
     *   - an approved receipt is in the books: only an admin changes it.
     * @param array $expense needs created_by, status, forwarded_to_accounting
     * @param array $actor   ['id' => int, 'is_admin' => bool]
     */
    public static function editBlockReason(array $expense, array $actor): ?string
    {
        $isAdmin = !empty($actor['is_admin']);
        if (!$isAdmin && (int)($expense['created_by'] ?? 0) !== (int)($actor['id'] ?? 0)) {
            return 'You can only edit your own expenses';
        }
        if (($expense['status'] ?? '') === 'forwarded' || (int)($expense['forwarded_to_accounting'] ?? 0) === 1) {
            return 'This expense has been sent to accounting and can no longer be edited';
        }
        if (!$isAdmin && ($expense['status'] ?? '') === 'approved') {
            return 'This receipt is approved — ask the office to change it';
        }
        return null;
    }

    /** The office sees and changes everyone's receipts: admin/manager role, or the approve permission. */
    public static function isOffice(string $role, bool $canApprove): bool
    {
        return in_array($role, ['admin', 'manager'], true) || $canApprove;
    }

    /**
     * Only an approved receipt goes to the accountant (operating rules: phone receipts are
     * capture-only; approval happens in the office). Null = may be sent. Pure (tested).
     */
    public static function sendBlockReason(array $expense): ?string
    {
        if (!empty($expense['forwarded_to_accounting']) || ($expense['status'] ?? '') === 'forwarded') {
            return 'This receipt was already sent to the accountant.';
        }
        if (($expense['status'] ?? '') !== 'approved') {
            return 'Only approved receipts are sent to the accountant — approve it in the office first.';
        }
        return null;
    }

    public function update(int $expenseId, array $currentUser, array $input): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }

        $check = $this->db->prepare(
            "SELECT id, created_by, status, forwarded_to_accounting, vendor_id, vendor_name_raw, raw_ocr_json
             FROM expenses WHERE id = ?"
        );
        $check->execute([$expenseId]);
        $expense = $check->fetch(PDO::FETCH_ASSOC);
        if (!$expense) {
            throw new Exception('Expense not found');
        }

        if (($why = self::editBlockReason($expense, $currentUser)) !== null) {
            throw new Exception($why);
        }

        $expenseDate = $this->sanitizeDate($input['expense_date'] ?? null) ?? date('Y-m-d');
        $vendorRaw   = $this->nullIfBlank($input['vendor_name_raw'] ?? null);

        // If the vendor name was retyped, unlink vendor_id so the typed name is what
        // displays — expense-list COALESCEs vendors.name over vendor_name_raw, so a stale
        // vendor_id would otherwise mask the edit. Unchanged name keeps the existing link.
        $vendorChanged = trim((string)($input['vendor_name_raw'] ?? '')) !== trim((string)($expense['vendor_name_raw'] ?? ''));
        $vendorId = $vendorChanged
            ? null
            : ($expense['vendor_id'] !== null ? (int)$expense['vendor_id'] : null);

        // Through the expense gate: only changed fields are written, the change is audited,
        // the printed facts and duplicate check re-run, and the line-item lessons recorded
        // (header / category fixes are learned once, at approval or send). A posted receipt
        // is re-posted. `notes` is intentionally not touched — the iOS client only surfaces
        // `description`, so editing from the phone must not wipe desktop-entered notes.
        // Only the fields the phone sent: a missing amount/GST/total used to be written as 0.00.
        $set = [];
        if (array_key_exists('expense_date', $input))        $set['expense_date'] = $expenseDate;
        if (array_key_exists('vendor_name_raw', $input))     { $set['vendor_id'] = $vendorId; $set['vendor_name_raw'] = $vendorRaw; }
        if (array_key_exists('description', $input))         $set['description'] = $this->nullIfBlank($input['description']);
        foreach (['amount', 'gst_amount', 'pst_amount', 'total'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== null && $input[$k] !== '') $set[$k] = round((float)$input[$k], 2);
        }
        if (array_key_exists('accounting_category', $input)) $set['accounting_category'] = $this->nullIfBlank($input['accounting_category']);
        if (array_key_exists('payment_method', $input))      $set['payment_method'] = $this->nullIfBlank($input['payment_method']);
        $this->gate()->apply($expenseId, $set, ['id' => (int)($currentUser['id'] ?? 0) ?: null, 'kind' => 'user'], (string)($currentUser['source'] ?? 'ios_update'), [
            'learn_lines' => !empty($expense['raw_ocr_json']) ? ($input + ['vendor_name_raw' => $vendorRaw]) : null,
        ]);

        return ['success' => true, 'message' => 'Expense updated', 'expense_id' => $expenseId];
    }

    /**
     * Delete an expense. Same ownership rule as update() (crew delete their own,
     * admins/managers any) and the same forwarded guard — a receipt already sent to
     * accounting is part of the books and must not silently vanish. Reverses any
     * inventory movements its line items created before the CASCADE removes them.
     *
     * Shared by the web swipe-to-delete (expenses.php action=delete) and the iOS
     * swipe action (expense-delete.php) so both clients enforce identical rules.
     *
     * @return array ['success'=>true, 'message'=>string, 'expense_id'=>int]
     * @throws Exception on missing id, not found, wrong owner, or already-sent.
     */
    public function delete(int $expenseId, array $currentUser): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }

        $check = $this->db->prepare(
            "SELECT id, created_by, status, forwarded_to_accounting FROM expenses WHERE id = ?"
        );
        $check->execute([$expenseId]);
        $expense = $check->fetch(PDO::FETCH_ASSOC);
        if (!$expense) {
            throw new Exception('Expense not found');
        }

        $isAdmin = !empty($currentUser['is_admin']);
        if (!$isAdmin && (int)$expense['created_by'] !== (int)($currentUser['id'] ?? 0)) {
            throw new Exception('You can only delete your own expenses');
        }

        if ($expense['status'] === 'forwarded' || (int)$expense['forwarded_to_accounting'] === 1) {
            throw new Exception('This expense has been sent to accounting and can no longer be deleted');
        }

        // Through the gate: stock reversed, a posted entry reversed (append-only), the split
        // removed, and the deleted row kept in the audit log.
        $this->gate()->apply($expenseId, [ExpenseGate::DELETE => true],
            ['id' => (int)($currentUser['id'] ?? 0) ?: null, 'kind' => 'user'], (string)($currentUser['source'] ?? 'delete'));

        return ['success' => true, 'message' => 'Expense deleted', 'expense_id' => $expenseId];
    }

    private function nullIfBlank(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    /** Accept only a valid YYYY-MM-DD; anything else returns null (caller falls back to today). */
    private function sanitizeDate(?string $v): ?string
    {
        if (!$v) {
            return null;
        }
        $candidate = substr($v, 0, 10);
        $d = DateTime::createFromFormat('Y-m-d', $candidate);
        return ($d && $d->format('Y-m-d') === $candidate) ? $candidate : null;
    }
}
