-- ============================================================================
-- Migration 511: Expense approval workflow columns + RBAC permission
-- ============================================================================
-- Purpose: Adds approval tracking to expenses. Expenses with high anomaly
--          scores auto-route to the approval queue. Managers approve/reject
--          with optional rejection reasons.
--
--          Status flow: draft → [pending_approval] → approved → forwarded
--                                                  → rejected
--
-- MySQL 5.7 compatible — run outside of a transaction (DDL auto-commits)
-- ============================================================================

ALTER TABLE `expenses` ADD COLUMN `approved_by` int NULL
    COMMENT 'FK to users.id — who approved this expense'
    AFTER `status`;

ALTER TABLE `expenses` ADD COLUMN `approved_at` datetime NULL
    COMMENT 'When the expense was approved'
    AFTER `approved_by`;

ALTER TABLE `expenses` ADD COLUMN `rejection_reason` text NULL
    COMMENT 'Reason for rejection (if status = rejected)'
    AFTER `approved_at`;

ALTER TABLE `expenses` ADD CONSTRAINT `fk_exp_approver`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

CREATE INDEX `idx_exp_approved` ON `expenses` (`approved_by`);

-- Add RBAC permission for expense approval
INSERT IGNORE INTO `permissions` (`key`, `description`, `created_at`)
VALUES ('expenses.approve', 'Approve or reject submitted expenses', NOW());

-- Grant to admin and manager roles
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM `roles` r
CROSS JOIN `permissions` p
WHERE r.`name` IN ('admin', 'manager')
  AND p.`key` = 'expenses.approve';
