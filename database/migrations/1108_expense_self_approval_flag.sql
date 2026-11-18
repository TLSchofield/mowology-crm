-- 1108_expense_self_approval_flag.sql
-- ExpenseApprovalService::approve() unconditionally blocks self-approval
-- (creator cannot approve their own expense) as a deliberate anti-fraud
-- control. That's correct for staff, but Mowology is owner + office manager
-- (both role='admin') + crew, and the owner does a lot of the purchasing
-- himself — with no one else to approve it, that control becomes a deadlock.
--
-- The RBAC 'admin' role already short-circuits to "all permissions" for
-- everyone with that role, so there's no existing way to exempt just the
-- owner without also exempting the office manager. This adds a narrow,
-- per-user, admin-configurable flag instead — set per employee in Team
-- management, defaulting off for everyone (including existing admins).

ALTER TABLE users ADD COLUMN can_approve_own_expenses TINYINT(1) NOT NULL DEFAULT 0;
