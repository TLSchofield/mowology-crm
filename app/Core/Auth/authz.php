<?php
declare(strict_types=1);

/**
 * /app/Core/Auth/authz.php
 * RBAC authorization helpers for Mowology CRM.
 *
 * Provides permission checking against the roles/permissions/user_roles/role_permissions
 * tables created by Migration 400.
 *
 * Usage:
 *   require_once APP_ROOT . '/Core/Auth/authz.php';    // after auth.php
 *   requirePermission('jobs.edit');                      // 403 if denied
 *   if (userHasPermission('billing.view')) { ... }
 *
 * The admin role short-circuits: admin always has all permissions.
 * Permissions are cached in $_SESSION['perm_cache'] with a version stamp.
 */

// Ensure auth.php is already loaded (provides getDB, getCurrentUser, isLoggedIn, etc.)
if (!function_exists('getCurrentUser')) {
    throw new RuntimeException('authz.php requires auth.php to be loaded first.');
}

// Cache version — bump this when you change role_permissions seeds
// or when a user's roles are modified via the admin UI.
if (!defined('PERM_CACHE_VERSION')) {
    define('PERM_CACHE_VERSION', 4);
}

/**
 * Get all RBAC role names for a given user ID.
 *
 * @param int $userId
 * @return string[] e.g. ['admin'], ['staff','viewer']
 */
function getUserRoles(int $userId): array
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT r.name
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = ?
            ORDER BY r.id
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('getUserRoles() error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get all permission keys for a given user ID.
 * Uses session cache for performance.
 *
 * @param int $userId
 * @return string[] e.g. ['jobs.view','jobs.edit','schedule.view']
 */
function getUserPermissions(int $userId): array
{
    // Check session cache first
    if (
        isset($_SESSION['perm_cache'])
        && is_array($_SESSION['perm_cache'])
        && ($_SESSION['perm_cache']['user_id'] ?? 0) === $userId
        && ($_SESSION['perm_cache']['version'] ?? 0) === PERM_CACHE_VERSION
    ) {
        return $_SESSION['perm_cache']['permissions'];
    }

    try {
        $db = getDB();

        // Check if RBAC tables exist (graceful degradation)
        $tblCheck = $db->query("SHOW TABLES LIKE 'user_roles'");
        if (!$tblCheck || $tblCheck->rowCount() === 0) {
            // RBAC not yet migrated — fall back to legacy role
            return _legacyPermissions($userId);
        }

        $stmt = $db->prepare("
            SELECT DISTINCT p.`key`
            FROM user_roles ur
            JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id
            WHERE ur.user_id = ?
            ORDER BY p.`key`
        ");
        $stmt->execute([$userId]);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // If user has no RBAC roles yet, fall back to legacy
        if (empty($perms)) {
            $perms = _legacyPermissions($userId);
        }

    } catch (Throwable $e) {
        error_log('getUserPermissions() error: ' . $e->getMessage());
        $perms = _legacyPermissions($userId);
    }

    // Cache in session
    $_SESSION['perm_cache'] = [
        'user_id'     => $userId,
        'version'     => PERM_CACHE_VERSION,
        'permissions'  => $perms,
    ];

    return $perms;
}

/**
 * Legacy fallback: derive permissions from the users.role ENUM field.
 * Used when RBAC tables don't exist yet or user has no RBAC roles assigned.
 */
function _legacyPermissions(int $userId): array
{
    $user = getCurrentUser();
    if (!$user) return [];

    $role = $user['role'] ?? 'user';

    // Mirror the seed data from migration 400
    switch ($role) {
        case 'admin':
            // Admin gets everything — but we return a sentinel that
            // userHasPermission() recognizes.
            return ['*'];

        case 'manager':
            return [
                'jobs.view', 'jobs.edit', 'jobs.assign',
                'schedule.view', 'schedule.edit',
                'timer.start', 'timer.stop', 'timer.override',
                'photos.upload', 'photos.delete',
                'billing.view', 'billing.edit',
                'marketing.view', 'marketing.edit',
                'clients.view', 'clients.edit',
                'products.view', 'products.edit',
                'portfolio.view', 'portfolio.edit',
                'team.view', 'team.edit',
                'expenses.view', 'expenses.edit',
                'settings.view',
            ];

        case 'staff':
        case 'technician':
            return [
                'jobs.view',
                'schedule.view',
                'timer.start', 'timer.stop',
                'photos.upload',
                'clients.view',
                'products.view',
                'portfolio.view',
                'team.view',
                'expenses.view', 'expenses.edit',
            ];

        case 'user':
        default:
            return [
                'jobs.view',
                'schedule.view',
                'timer.start', 'timer.stop',
                'photos.upload',
                'clients.view',
                'billing.view',
                'products.view',
                'portfolio.view',
                'team.view',
                'expenses.view',
            ];
    }
}

/**
 * Check if the current logged-in user has a specific permission.
 *
 * @param string $perm Permission key, e.g. 'jobs.edit'
 * @return bool
 */
function userHasPermission(string $perm): bool
{
    $user = getCurrentUser();
    if (!$user) return false;

    // Admin short-circuit: users.role = 'admin' always passes
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $perms = getUserPermissions($user['id']);

    // Wildcard check (legacy admin fallback)
    if (in_array('*', $perms, true)) {
        return true;
    }

    return in_array($perm, $perms, true);
}

/**
 * Check if current user has ANY of the given permissions.
 *
 * @param string[] $perms
 * @return bool
 */
function userHasAnyPermission(array $perms): bool
{
    foreach ($perms as $p) {
        if (userHasPermission($p)) {
            return true;
        }
    }
    return false;
}

/**
 * Require a specific permission or send 403.
 * For HTML pages: shows a styled 403 page.
 * For JSON APIs: returns JSON error.
 *
 * @param string $perm Permission key
 */
function requirePermission(string $perm): void
{
    if (userHasPermission($perm)) {
        return;
    }

    _denyAccess($perm);
}

/**
 * Require ANY of the given permissions, or send 403.
 *
 * @param string[] $perms
 */
function requireAnyPermission(array $perms): void
{
    if (userHasAnyPermission($perms)) {
        return;
    }

    _denyAccess(implode(' | ', $perms));
}

/**
 * Invalidate the permission cache for the current session.
 * Call this after changing a user's roles via the admin UI.
 */
function clearPermissionCache(): void
{
    unset($_SESSION['perm_cache']);
}

/**
 * Check if current user has a specific RBAC role (by name).
 *
 * @param string $roleName e.g. 'admin', 'manager'
 * @return bool
 */
function userHasRole(string $roleName): bool
{
    $user = getCurrentUser();
    if (!$user) return false;

    // Admin short-circuit
    if (($user['role'] ?? '') === 'admin' && $roleName === 'admin') {
        return true;
    }

    $roles = getUserRoles($user['id']);
    return in_array($roleName, $roles, true);
}

/**
 * Internal: send 403 response and exit.
 */
function _denyAccess(string $permLabel): void
{
    http_response_code(403);

    // Detect JSON requests
    $isJson = (
        (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    );

    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => 'Permission denied',
            'required' => $permLabel,
            'success' => false,
        ]);
        exit;
    }

    // HTML 403 page
    echo '<!DOCTYPE html><html><head><title>403 Forbidden</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5}';
    echo '.box{text-align:center;padding:40px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);max-width:400px}';
    echo 'h1{color:#dc3545;font-size:48px;margin:0 0 8px}h2{color:#333;margin:0 0 16px}p{color:#666;margin:0 0 24px}';
    echo 'a{display:inline-block;padding:10px 24px;background:#2D8659;color:#fff;text-decoration:none;border-radius:4px}</style>';
    echo '</head><body><div class="box">';
    echo '<h1>403</h1><h2>Access Denied</h2>';
    echo '<p>You don\'t have permission to access this page.<br><small>Required: ' . htmlspecialchars($permLabel) . '</small></p>';
    echo '<a href="/crm/dashboard_appstack.php">Back to Dashboard</a>';
    echo '</div></body></html>';
    exit;
}
