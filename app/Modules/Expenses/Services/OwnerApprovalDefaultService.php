<?php
declare(strict_types=1);

/**
 * A solo operator must be able to approve their own receipts. The business OWNER is the
 * lowest-id active admin (role='admin' alone is not unique). Employees and other staff keep
 * the separation-of-duties block in ExpenseApprovalService::selfApprovalBlocked().
 */
class OwnerApprovalDefaultService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Id of the founding admin, or 0 when the business has no active admin. */
    public function ownerUserId(): int
    {
        $stmt = $this->db->prepare("SELECT MIN(id) FROM users WHERE role = 'admin' AND is_active = 1");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /** Switch the self-approval exemption on for the owner. Idempotent. Returns the owner id (0 = none). */
    public function grantToOwner(): int
    {
        $ownerId = $this->ownerUserId();
        if ($ownerId > 0) {
            $this->db->prepare("UPDATE users SET can_approve_own_expenses = 1 WHERE id = ?")->execute([$ownerId]);
        }
        return $ownerId;
    }

    /**
     * Default for a user being created through Team management: on only when they are the
     * business's first admin (no active admin exists yet). Everyone else defaults off.
     */
    public function defaultForNewUser(string $role): int
    {
        return ($role === 'admin' && $this->ownerUserId() === 0) ? 1 : 0;
    }
}
