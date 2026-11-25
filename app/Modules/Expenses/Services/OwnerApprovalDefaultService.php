<?php
declare(strict_types=1);

/**
 * "Admin is admin" (Tim, 2026-11-15): every active user with role='admin' may approve their own
 * receipts, so a solo operator is never stuck. Staff roles (manager, user, accountant) keep the
 * separation-of-duties block in ExpenseApprovalService::selfApprovalBlocked().
 */
class OwnerApprovalDefaultService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Switch the exemption on for every active admin. Idempotent. Returns rows touched. */
    public function grantToAdmins(): int
    {
        $stmt = $this->db->prepare("UPDATE users SET can_approve_own_expenses = 1 WHERE role = 'admin' AND is_active = 1");
        $stmt->execute();
        return $stmt->rowCount();
    }

    /** Default flag for a user created or promoted through Team management. */
    public function defaultForNewUser(string $role): int
    {
        return $role === 'admin' ? 1 : 0;
    }
}
