<?php
declare(strict_types=1);

/**
 * PasswordChangeRules — who may set another user's password, and how long it must be
 * (Fable review 2026-10-12). Pure; used by public/crm/users_appstack.php.
 *
 * - Minimum length is 12, the same as the reset-password pages.
 * - Setting an ADMIN's password needs users.manage AND the caller to be an admin: a manager with
 *   users.manage could otherwise take over an admin account by setting its password.
 *
 * Tested in tests/Unit/Team/PasswordChangeRulesTest.php.
 */
final class PasswordChangeRules
{
    public const MIN_LENGTH = 12;

    /** Returns an error message, or null when the change is allowed. */
    public static function check(
        string $newPassword,
        string $confirm,
        bool $targetIsAdmin,
        bool $callerHasUsersManage,
        bool $callerIsAdmin
    ): ?string {
        if (!$callerHasUsersManage) return 'You do not have permission to change passwords.';
        if ($targetIsAdmin && !$callerIsAdmin) return 'Only an admin can change an admin\'s password.';
        if (strlen($newPassword) < self::MIN_LENGTH) return 'Password must be at least ' . self::MIN_LENGTH . ' characters.';
        if ($newPassword !== $confirm) return 'Passwords do not match.';
        return null;
    }
}
