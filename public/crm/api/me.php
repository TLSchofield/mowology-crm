<?php
/**
 * API: /crm/api/me.php
 *
 * Returns the current user's profile, RBAC roles, and permissions.
 * Used by the mobile PWA to gate UI elements client-side.
 *
 * GET /crm/api/me.php
 *
 * Response:
 * {
 *   "user": { "id": 1, "email": "...", "name": "...", "legacy_role": "admin" },
 *   "roles": ["admin"],
 *   "permissions": ["jobs.view", "jobs.edit", ...],
 *   "capabilities": { "can_edit_jobs": true, "can_upload_photos": true, ... }
 * }
 */

declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

require_once PUBLIC_ROOT . '/loginAuth/auth.php';

// Session auth only — no token auth
requireLogin();
$user = getCurrentUser();
session_write_close();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userId = $user['id'];

// Get RBAC roles
$roles = getUserRoles($userId);

// Get permissions
$permissions = getUserPermissions($userId);

// Build capabilities map (booleans for common actions)
$capabilities = [
    'can_view_jobs'       => userHasPermission('jobs.view'),
    'can_edit_jobs'       => userHasPermission('jobs.edit'),
    'can_assign_jobs'     => userHasPermission('jobs.assign'),
    'can_view_schedule'   => userHasPermission('schedule.view'),
    'can_edit_schedule'   => userHasPermission('schedule.edit'),
    'can_start_timer'     => userHasPermission('timer.start'),
    'can_stop_timer'      => userHasPermission('timer.stop'),
    'can_override_timer'  => userHasPermission('timer.override'),
    'can_upload_photos'   => userHasPermission('photos.upload'),
    'can_delete_photos'   => userHasPermission('photos.delete'),
    'can_view_billing'    => userHasPermission('billing.view'),
    'can_edit_billing'    => userHasPermission('billing.edit'),
    'can_view_clients'    => userHasPermission('clients.view'),
    'can_edit_clients'    => userHasPermission('clients.edit'),
    'can_view_marketing'  => userHasPermission('marketing.view'),
    'can_edit_marketing'  => userHasPermission('marketing.edit'),
    'can_view_products'   => userHasPermission('products.view'),
    'can_edit_products'   => userHasPermission('products.edit'),
    'can_view_team'       => userHasPermission('team.view'),
    'can_edit_team'       => userHasPermission('team.edit'),
    'can_manage_users'    => userHasPermission('users.manage'),
    'can_manage_roles'    => userHasPermission('roles.manage'),
    'can_manage_settings' => userHasPermission('settings.edit'),
    'can_manage_database' => userHasPermission('database.manage'),
    'is_admin'            => in_array('admin', $roles) || ($user['role'] ?? '') === 'admin',
];

echo json_encode([
    'user' => [
        'id'          => $userId,
        'email'       => $user['email'],
        'name'        => $user['name'],
        'legacy_role' => $user['role'],
    ],
    'roles'        => $roles,
    'permissions'  => $permissions,
    'capabilities' => $capabilities,
], JSON_PRETTY_PRINT);
