<?php
/**
 * Migration 400: RBAC (Role-Based Access Control) System
 *
 * Creates roles, permissions, role_permissions, user_roles tables.
 * Seeds default roles, permissions, and role-permission mappings.
 * Assigns existing admin users the admin role.
 * Safe to run multiple times (idempotent).
 *
 * Usage: Visit this URL as admin in your browser.
 */

require_once __DIR__ . '/../loginAuth/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

$db = getDB();
$results = [];

// Helper: check if table exists
function tableExists400(PDO $db, string $table): bool {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $stmt = $db->query("SHOW TABLES LIKE '{$safeTable}'");
    return $stmt && $stmt->rowCount() > 0;
}

try {

    // ── 1. roles ────────────────────────────────────────────
    if (!tableExists400($db, 'roles')) {
        $db->exec("CREATE TABLE `roles` (
            `id` int NOT NULL AUTO_INCREMENT,
            `name` varchar(50) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_role_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '&#9989; Created roles table';
    } else {
        $results[] = '&#9197; roles table already exists';
    }

    // ── 2. permissions ──────────────────────────────────────
    if (!tableExists400($db, 'permissions')) {
        $db->exec("CREATE TABLE `permissions` (
            `id` int NOT NULL AUTO_INCREMENT,
            `key` varchar(100) NOT NULL,
            `description` varchar(255) DEFAULT NULL,
            `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_perm_key` (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '&#9989; Created permissions table';
    } else {
        $results[] = '&#9197; permissions table already exists';
    }

    // ── 3. role_permissions ─────────────────────────────────
    if (!tableExists400($db, 'role_permissions')) {
        $db->exec("CREATE TABLE `role_permissions` (
            `role_id` int NOT NULL,
            `permission_id` int NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`),
            KEY `idx_rp_role` (`role_id`),
            KEY `idx_rp_perm` (`permission_id`),
            CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '&#9989; Created role_permissions table';
    } else {
        $results[] = '&#9197; role_permissions table already exists';
    }

    // ── 4. user_roles ───────────────────────────────────────
    if (!tableExists400($db, 'user_roles')) {
        $db->exec("CREATE TABLE `user_roles` (
            `user_id` int NOT NULL,
            `role_id` int NOT NULL,
            PRIMARY KEY (`user_id`, `role_id`),
            KEY `idx_ur_user` (`user_id`),
            KEY `idx_ur_role` (`role_id`),
            CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $results[] = '&#9989; Created user_roles table';
    } else {
        $results[] = '&#9197; user_roles table already exists';
    }

    // ── 5. Seed roles ───────────────────────────────────────
    $roleCount = (int)$db->query("SELECT COUNT(*) FROM roles")->fetchColumn();
    if ($roleCount === 0) {
        $db->exec("INSERT INTO `roles` (`name`, `description`) VALUES
            ('admin',   'Full system access — all permissions'),
            ('manager', 'Manages jobs, quotes, invoices, schedule, team — no system config'),
            ('staff',   'Field worker — view schedule, clock in/out, upload photos'),
            ('viewer',  'Read-only access — view schedule and jobs')");
        $results[] = '&#9989; Seeded 4 default roles';
    } else {
        $results[] = '&#9197; roles already has data (' . $roleCount . ' rows)';
    }

    // ── 6. Seed permissions ─────────────────────────────────
    $permCount = (int)$db->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    if ($permCount === 0) {
        $db->exec("INSERT INTO `permissions` (`key`, `description`) VALUES
            ('users.manage',      'Create, edit, deactivate users and assign roles'),
            ('roles.manage',      'Create and modify roles and permission mappings'),
            ('jobs.view',         'View job list and job details'),
            ('jobs.edit',         'Create, update, and delete jobs'),
            ('jobs.assign',       'Assign crew members to jobs'),
            ('schedule.view',     'View the schedule calendar'),
            ('schedule.edit',     'Create, move, and cancel scheduled visits'),
            ('timer.start',       'Clock in / start job timer'),
            ('timer.stop',        'Clock out / stop job timer'),
            ('timer.override',    'Edit or override time entries for any user'),
            ('photos.upload',     'Upload job photos and media'),
            ('photos.delete',     'Delete photos and media files'),
            ('billing.view',      'View quotes and invoices'),
            ('billing.edit',      'Create, edit, send quotes and invoices'),
            ('marketing.view',    'View marketing recommendations and SEO data'),
            ('marketing.edit',    'Apply SEO changes, manage CMS pages and content'),
            ('clients.view',      'View contact and company records'),
            ('clients.edit',      'Create and edit contacts and companies'),
            ('products.view',     'View products and pricing'),
            ('products.edit',     'Edit products, pricing rules, cost factors'),
            ('portfolio.view',    'View portfolio projects'),
            ('portfolio.edit',    'Create and edit portfolio projects'),
            ('settings.view',     'View system settings'),
            ('settings.edit',     'Modify system and business settings'),
            ('database.manage',   'Run migrations, view schema, database tools'),
            ('diagnostics.view',  'Access diagnostics and debug tools'),
            ('team.view',         'View team member list'),
            ('team.edit',         'Edit team member details and assignments')");
        $results[] = '&#9989; Seeded 28 permissions';
    } else {
        $results[] = '&#9197; permissions already has data (' . $permCount . ' rows)';
    }

    // ── 7. Seed role_permissions ─────────────────────────────
    $rpCount = (int)$db->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn();
    if ($rpCount === 0) {

        // Get all permission IDs keyed by name
        $allPerms = [];
        $pStmt = $db->query("SELECT id, `key` FROM permissions");
        while ($row = $pStmt->fetch(PDO::FETCH_ASSOC)) {
            $allPerms[$row['key']] = (int)$row['id'];
        }

        // Get role IDs keyed by name
        $allRoles = [];
        $rStmt = $db->query("SELECT id, name FROM roles");
        while ($row = $rStmt->fetch(PDO::FETCH_ASSOC)) {
            $allRoles[$row['name']] = (int)$row['id'];
        }

        // Define role -> permission mappings
        $mappings = [
            'admin' => array_keys($allPerms), // all permissions
            'manager' => [
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
                'settings.view',
            ],
            'staff' => [
                'jobs.view',
                'schedule.view',
                'timer.start', 'timer.stop',
                'photos.upload',
                'clients.view',
                'products.view',
                'portfolio.view',
                'team.view',
            ],
            'viewer' => [
                'jobs.view',
                'schedule.view',
                'clients.view',
                'billing.view',
                'products.view',
                'portfolio.view',
                'team.view',
            ],
        ];

        $insertStmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        $rpInserted = 0;
        foreach ($mappings as $roleName => $permKeys) {
            if (!isset($allRoles[$roleName])) continue;
            $roleId = $allRoles[$roleName];
            foreach ($permKeys as $pk) {
                if (!isset($allPerms[$pk])) continue;
                $insertStmt->execute([$roleId, $allPerms[$pk]]);
                $rpInserted++;
            }
        }
        $results[] = '&#9989; Seeded ' . $rpInserted . ' role-permission mappings';
    } else {
        $results[] = '&#9197; role_permissions already has data (' . $rpCount . ' rows)';
    }

    // ── 8. Assign existing admin users the admin RBAC role ──
    $adminRoleId = (int)$db->query("SELECT id FROM roles WHERE name = 'admin' LIMIT 1")->fetchColumn();
    if ($adminRoleId > 0) {
        $adminUsers = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        $assignStmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $assigned = 0;
        while ($row = $adminUsers->fetch(PDO::FETCH_ASSOC)) {
            $assignStmt->execute([(int)$row['id'], $adminRoleId]);
            $assigned++;
        }
        $results[] = '&#9989; Assigned admin RBAC role to ' . $assigned . ' existing admin user(s)';
    }

    // ── 9. Assign existing staff/technician users the staff RBAC role ──
    $staffRoleId = (int)$db->query("SELECT id FROM roles WHERE name = 'staff' LIMIT 1")->fetchColumn();
    if ($staffRoleId > 0) {
        $staffUsers = $db->query("SELECT id FROM users WHERE role IN ('staff','technician') AND is_active = 1");
        $assignStmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $assigned = 0;
        while ($row = $staffUsers->fetch(PDO::FETCH_ASSOC)) {
            $assignStmt->execute([(int)$row['id'], $staffRoleId]);
            $assigned++;
        }
        $results[] = '&#9989; Assigned staff RBAC role to ' . $assigned . ' existing staff/technician user(s)';
    }

    // ── 10. Assign existing 'user' role users the viewer RBAC role ──
    $viewerRoleId = (int)$db->query("SELECT id FROM roles WHERE name = 'viewer' LIMIT 1")->fetchColumn();
    if ($viewerRoleId > 0) {
        $viewerUsers = $db->query("SELECT id FROM users WHERE role = 'user' AND is_active = 1");
        $assignStmt = $db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $assigned = 0;
        while ($row = $viewerUsers->fetch(PDO::FETCH_ASSOC)) {
            $assignStmt->execute([(int)$row['id'], $viewerRoleId]);
            $assigned++;
        }
        $results[] = '&#9989; Assigned viewer RBAC role to ' . $assigned . ' existing basic user(s)';
    }

} catch (PDOException $e) {
    $results[] = '&#10060; Database error: ' . htmlspecialchars($e->getMessage());
}

// ── Output ──────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head>
    <title>Migration 400 — RBAC System</title>
    <style>
        body { font-family: monospace; padding: 40px; background: #f5f5f5; }
        h1 { color: #2D8659; }
        li { margin: 8px 0; font-size: 14px; }
        .ok { color: #2D8659; }
        .skip { color: #666; }
        .err { color: red; }
    </style>
</head>
<body>
    <h1>Migration 400: RBAC System</h1>
    <ul>
        <?php foreach ($results as $r):
            $cls = (strpos($r, '9989') !== false) ? 'ok' : (strpos($r, '10060') !== false ? 'err' : 'skip');
        ?>
        <li class="<?php echo $cls; ?>"><?php echo $r; ?></li>
        <?php endforeach; ?>
    </ul>
    <p><a href="/crm/dashboard_appstack.php">&larr; Back to Dashboard</a></p>
</body>
</html>
