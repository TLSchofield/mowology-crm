<?php
declare(strict_types=1);

require_once __DIR__ . '/PasswordChangeRules.php';
if (!class_exists('RoleRules')) {
    require_once dirname(__DIR__, 3) . '/Core/Auth/RoleRules.php';
}

/**
 * EmployeeAccountService — account rules for the Team page's employees API (Fable audit 2026-10-12,
 * #3 and #11). Admins AND managers edit staff (Tim); anything touching an admin needs users.manage.
 *
 *   - isAdminTarget(): admin by legacy users.role OR by an RBAC 'admin' role (a truck login can be
 *     legacy 'manager' with RBAC admin — the old check missed those).
 *   - updateRefusal(): who may change what on another account (admin targets, promotion to admin,
 *     someone else's email, passwords via PasswordChangeRules — 12 characters).
 *   - assignRbacRole() / syncRbacRoleOnChange(): every account has the RBAC role matching users.role
 *     (staff for crew, manager, admin), so nobody falls back to the legacy permission list.
 *   - changeLog(): before → after lines for role / active / email / password, for activity_log.
 *
 * Tested in tests/Unit/Team/EmployeeAccountServiceTest.php.
 */
final class EmployeeAccountService
{
    public static function isAdminTarget(PDO $db, int $userId): bool
    {
        $st = $db->prepare("SELECT u.role,
                                   EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                                          WHERE ur.user_id = u.id AND r.name = 'admin') AS has_admin_role
                            FROM users u WHERE u.id = ? LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return RoleRules::isAdmin($row['role'] ?? '') || !empty($row['has_admin_role']);
    }

    /**
     * Error message, or null when the update may go ahead.
     *
     * @param array $before  target row: email, role
     * @param array $input   request fields
     */
    public static function updateRefusal(
        array $before,
        array $input,
        bool $targetIsAdmin,
        bool $isSelf,
        bool $callerCanEdit,
        bool $callerUsersManage,
        bool $callerIsAdmin
    ): ?string {
        if (!$callerCanEdit) return 'Admin access required';
        if (!$callerUsersManage) {
            if ($targetIsAdmin) return 'Only an admin can edit an admin account';
            if (isset($input['role']) && RoleRules::isAdmin((string)$input['role'])) return 'Only an admin can make someone an admin';
            if (!$isSelf && isset($input['email'])
                && strtolower(trim((string)$input['email'])) !== strtolower(trim((string)($before['email'] ?? '')))) {
                return 'Only an admin can change another person\'s email';
            }
        }
        $pw = (string)($input['password'] ?? '');
        if ($pw !== '') {
            $confirm = array_key_exists('password_confirm', $input) ? (string)$input['password_confirm'] : $pw;
            $err = PasswordChangeRules::check($pw, $confirm, $targetIsAdmin, $callerCanEdit, $callerIsAdmin);
            if ($err !== null) return $err;
        }
        return null;
    }

    /** Give $userId the RBAC role matching $legacyRole (idempotent). Returns the role name, or null. */
    public static function assignRbacRole(PDO $db, int $userId, string $legacyRole): ?string
    {
        $name = RoleRules::rbacRoleFor($legacyRole);
        $st = $db->prepare("SELECT id FROM roles WHERE name = ? LIMIT 1");
        $st->execute([$name]);
        $roleId = (int)$st->fetchColumn();
        if ($roleId <= 0) return null;
        $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$userId, $roleId]);
        return $name;
    }

    /**
     * users.role changed: swap the RBAC role that matched the old value for the one matching the new.
     * Other RBAC roles the person holds are kept.
     */
    public static function syncRbacRoleOnChange(PDO $db, int $userId, string $oldRole, string $newRole): void
    {
        $old = RoleRules::rbacRoleFor($oldRole);
        $new = RoleRules::rbacRoleFor($newRole);
        if ($old === $new) {
            self::assignRbacRole($db, $userId, $newRole);
            return;
        }
        $db->prepare("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = ?")
           ->execute([$userId, $old]);
        self::assignRbacRole($db, $userId, $newRole);
    }

    /** Lines like "role: user → manager" for the audited fields that changed. Never includes a password. */
    public static function changeLog(array $before, array $after, bool $passwordChanged): array
    {
        $lines = [];
        foreach (['role', 'is_active', 'email'] as $f) {
            if (!array_key_exists($f, $after)) continue;
            $b = (string)($before[$f] ?? '');
            $a = (string)$after[$f];
            if ($f === 'email' ? strcasecmp($a, $b) !== 0 : $a !== $b) {
                $lines[] = $f . ': ' . ($b === '' ? '(none)' : $b) . ' → ' . ($a === '' ? '(none)' : $a);
            }
        }
        if ($passwordChanged) $lines[] = 'password: changed';
        return $lines;
    }
}
