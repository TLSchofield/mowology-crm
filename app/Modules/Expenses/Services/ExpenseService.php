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
class ExpenseService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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
    public function update(int $expenseId, array $currentUser, array $input): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }

        $check = $this->db->prepare(
            "SELECT id, created_by, status, forwarded_to_accounting, vendor_id, vendor_name_raw
             FROM expenses WHERE id = ?"
        );
        $check->execute([$expenseId]);
        $expense = $check->fetch(PDO::FETCH_ASSOC);
        if (!$expense) {
            throw new Exception('Expense not found');
        }

        // Ownership: crew edit their own; admins/managers edit any.
        $isAdmin = !empty($currentUser['is_admin']);
        if (!$isAdmin && (int)$expense['created_by'] !== (int)($currentUser['id'] ?? 0)) {
            throw new Exception('You can only edit your own expenses');
        }

        // Editable in any status EXCEPT sent — once forwarded to accounting the record is
        // part of the books and must not drift.
        if ($expense['status'] === 'forwarded' || (int)$expense['forwarded_to_accounting'] === 1) {
            throw new Exception('This expense has been sent to accounting and can no longer be edited');
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

        $stmt = $this->db->prepare("
            UPDATE expenses SET
                expense_date        = ?,
                vendor_id           = ?,
                vendor_name_raw     = ?,
                description         = ?,
                amount              = ?,
                gst_amount          = ?,
                total               = ?,
                accounting_category = ?,
                payment_method      = ?,
                updated_at          = NOW()
            WHERE id = ?
        ");
        // Note: `notes` is intentionally not touched here — the iOS client only surfaces
        // `description`, so editing from the phone must not wipe desktop-entered notes.
        $stmt->execute([
            $expenseDate,
            $vendorId,
            $vendorRaw,
            $this->nullIfBlank($input['description'] ?? null),
            round((float)($input['amount'] ?? 0), 2),
            round((float)($input['gst_amount'] ?? 0), 2),
            round((float)($input['total'] ?? 0), 2),
            $this->nullIfBlank($input['accounting_category'] ?? null),
            $this->nullIfBlank($input['payment_method'] ?? null),
            $expenseId,
        ]);

        return ['success' => true, 'message' => 'Expense updated', 'expense_id' => $expenseId];
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
