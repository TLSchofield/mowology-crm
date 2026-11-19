-- 1332 — every active user gets the RBAC role matching users.role (Fable audit 2026-10-12, #3).
-- Users with no user_roles rows fell back to authz.php _legacyPermissions(), whose crew list carried
-- billing.view (users 8 and 9 on production could export every invoice). admin → admin,
-- manager → manager, everything else (user/staff/technician/team_member) → staff.
-- Only users with NO user_roles rows are touched; is_active NULL counts as active (as at login).
-- Idempotent (INSERT IGNORE + NOT EXISTS). MySQL 5.7 compatible.
INSERT IGNORE INTO user_roles (user_id, role_id)
    SELECT u.id, r.id
    FROM users u
    JOIN roles r ON r.name = CASE u.role WHEN 'admin' THEN 'admin' WHEN 'manager' THEN 'manager' ELSE 'staff' END
    WHERE (u.is_active = 1 OR u.is_active IS NULL)
      AND NOT EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id);
