<?php
declare(strict_types=1);

/**
 * app/Core/Auth/RoleRules.php — one place that says which users.role strings are office and which are
 * crew (Fable audit 2026-10-12). users.role has carried admin / manager / user / team_member / staff /
 * technician over the years; checks like `role === 'admin' || role === 'manager'` were scattered and
 * disagreed. Permission keys (authz.php) stay the rule for what a person may do; this is for the few
 * places that must branch on the legacy role string (idle timeout, RBAC role on create, backfill).
 *
 * Pure. Tested in tests/Unit/Core/RoleRulesTest.php.
 */
final class RoleRules
{
    public const OFFICE = ['admin', 'manager'];
    public const CREW   = ['user', 'team_member', 'staff', 'technician', ''];

    public static function normalize(?string $role): string
    {
        return strtolower(trim((string)$role));
    }

    /** Admin or manager — the office. */
    public static function isOffice(?string $role): bool
    {
        return in_array(self::normalize($role), self::OFFICE, true);
    }

    /** Anyone who is not office: crew, legacy 'user', unknown or empty roles. Fails toward least access. */
    public static function isCrew(?string $role): bool
    {
        return !self::isOffice($role);
    }

    public static function isAdmin(?string $role): bool
    {
        return self::normalize($role) === 'admin';
    }

    /**
     * The RBAC role (roles.name from migration 400) that matches a users.role value:
     * admin → admin, manager → manager, everything else → staff.
     */
    public static function rbacRoleFor(?string $role): string
    {
        $r = self::normalize($role);
        if ($r === 'admin') return 'admin';
        if ($r === 'manager') return 'manager';
        return 'staff';
    }
}
