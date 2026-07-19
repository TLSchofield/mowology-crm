<?php
/**
 * ExpenseApprovalService — the audited approve/reject transitions for an expense.
 *
 * Extracted from app/Modules/Expenses/Api/expenses.php's handleApprove()/handleReject()
 * so the session-authenticated web endpoint (expenses.php action:approve/reject) and the
 * JWT-authenticated mobile endpoint (app/Modules/Expenses/Api/receipt-actions.php) share
 * one code path — the exact self-approval check and approved_by/approved_at audit stamp
 * apply everywhere an expense can be approved or rejected, not just from the desktop
 * Review Queue tab. $currentUser only needs an 'id' key; both getCurrentUser() (session)
 * and a JWT payload from requireJwt() satisfy that shape, so no auth-specific branching
 * is needed inside the service.
 *
 * Permission checks (expenses.approve) stay in each caller, since the session vs JWT
 * checks (userHasPermission() vs jwtUserHasPermission()) are auth-mechanism-specific.
 */
class ExpenseApprovalService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param int   $expenseId
     * @param array $currentUser  Must include 'id'.
     * @return array ['success' => bool, 'message' => string]
     * @throws Exception if the expense doesn't exist or the caller created it (self-approval)
     */
    public function approve(int $expenseId, array $currentUser): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }

        $check = $this->db->prepare("SELECT id, status, created_by FROM expenses WHERE id = ?");
        $check->execute([$expenseId]);
        $expense = $check->fetch(PDO::FETCH_ASSOC);
        if (!$expense) {
            throw new Exception('Expense not found');
        }

        // Prevent self-approval (the creator cannot approve their own expense)
        if ((int)$expense['created_by'] === (int)$currentUser['id']) {
            throw new Exception('Cannot approve your own expense');
        }

        $stmt = $this->db->prepare("
            UPDATE expenses SET
                status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['id'], $expenseId]);

        return ['success' => true, 'message' => 'Expense approved'];
    }

    /**
     * @param int    $expenseId
     * @param array  $currentUser  Must include 'id'.
     * @param string $reason       Required — rejection reason.
     * @return array ['success' => bool, 'message' => string]
     * @throws Exception if the expense doesn't exist or no reason was given
     */
    public function reject(int $expenseId, array $currentUser, string $reason): array
    {
        if (!$expenseId) {
            throw new Exception('Expense ID required');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new Exception('Rejection reason is required');
        }

        $check = $this->db->prepare("SELECT id, created_by FROM expenses WHERE id = ?");
        $check->execute([$expenseId]);
        $expense = $check->fetch(PDO::FETCH_ASSOC);
        if (!$expense) {
            throw new Exception('Expense not found');
        }

        $stmt = $this->db->prepare("
            UPDATE expenses SET
                status = 'rejected',
                approved_by = ?,
                approved_at = NOW(),
                rejection_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$currentUser['id'], $reason, $expenseId]);

        // Notify the expense creator via activity log
        try {
            $this->db->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, created_at)
                VALUES (?, 'expense_rejected', 'expense', ?, ?, NOW())
            ")->execute([$currentUser['id'], $expenseId, json_encode(['reason' => $reason])]);
        } catch (Throwable $e) {
            // Activity log is non-critical
        }

        return ['success' => true, 'message' => 'Expense rejected'];
    }

    /**
     * Approve multiple expenses in one call — the mobile bulk-approval entry point.
     * Mirrors handleBatchForward()'s capped-array pattern (expenses.php) but continues
     * past per-item failures (e.g. one self-approval attempt in a mixed batch) instead
     * of aborting the whole batch, so a bad id doesn't block the rest.
     *
     * @param int[] $expenseIds
     * @param array $currentUser Must include 'id'.
     * @return array ['success' => true, 'approved' => int[], 'failed' => [['id' => int, 'error' => string], ...]]
     */
    public function approveBatch(array $expenseIds, array $currentUser): array
    {
        return $this->runBatch($expenseIds, fn(int $id) => $this->approve($id, $currentUser));
    }

    /**
     * Reject multiple expenses with the same reason in one call.
     *
     * @param int[]  $expenseIds
     * @param array  $currentUser Must include 'id'.
     * @param string $reason      Required — applied to every expense in the batch.
     * @return array ['success' => true, 'rejected' => int[], 'failed' => [['id' => int, 'error' => string], ...]]
     */
    public function rejectBatch(array $expenseIds, array $currentUser, string $reason): array
    {
        return $this->runBatch($expenseIds, fn(int $id) => $this->reject($id, $currentUser, $reason), 'rejected');
    }

    /**
     * @param int[]    $expenseIds
     * @param callable $action     fn(int $id): array — approve() or reject() for one id
     * @param string   $successKey Response key for the list of succeeded ids
     */
    private function runBatch(array $expenseIds, callable $action, string $successKey = 'approved'): array
    {
        $ids = array_map('intval', $expenseIds);
        $ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));

        if (empty($ids)) {
            throw new Exception('expense_ids array is required');
        }
        if (count($ids) > 50) {
            throw new Exception('Maximum 50 expenses per batch');
        }

        $succeeded = [];
        $failed    = [];
        foreach ($ids as $id) {
            try {
                $action($id);
                $succeeded[] = $id;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return ['success' => true, $successKey => $succeeded, 'failed' => $failed];
    }
}
