-- A solo operator must be able to approve their own receipts (1108 left the flag off for
-- everyone, so a new business's owner was stuck). Owner = lowest-id active admin.
-- Idempotent; the derived table keeps MySQL 5.7 from rejecting a self-referencing UPDATE.
UPDATE users
SET can_approve_own_expenses = 1
WHERE id = (SELECT owner_id FROM (SELECT MIN(id) AS owner_id FROM users WHERE role = 'admin' AND is_active = 1) AS o);
