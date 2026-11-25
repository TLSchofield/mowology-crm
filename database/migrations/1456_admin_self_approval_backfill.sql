-- Admin is admin: every active admin may approve their own receipts (supersedes the owner-only 1455).
-- Idempotent and MySQL 5.7-safe.
UPDATE users SET can_approve_own_expenses = 1 WHERE role = 'admin' AND is_active = 1;
