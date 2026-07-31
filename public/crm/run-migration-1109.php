<?php
/**
 * Migration 1109 — provision the dedicated QA crawler login.
 *
 * Creates a low-privilege user (role='user', never 'admin') for
 * tools/crm-crawl.php to log in as, plus a read-only RBAC 'viewer' role
 * grant extended with marketing.view/settings.view (view-only — never
 * *.edit/*.manage/*.delete) so the crawler can reach nearly every page.
 *
 * Introspects live columns/enum values before inserting instead of
 * assuming the committed schema dumps are accurate — this repo's `users`
 * table has known drift from every static .sql file on file.
 */
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); die('Admin only'); }

$db = getDB();
$results = [];

function colExists(PDO $db, string $t, string $c): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $s->execute([$t, $c]); return (bool)$s->fetchColumn();
}

function tableExists(PDO $db, string $t): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
    $s->execute([$t]); return (bool)$s->fetchColumn();
}

function roleEnumAllows(PDO $db, string $value): bool {
    $s = $db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role' LIMIT 1");
    $s->execute();
    $type = (string)$s->fetchColumn();
    if ($type === '' || stripos($type, 'enum') !== 0) return true; // not an enum (e.g. varchar) — any value allowed
    return (bool)preg_match("/'" . preg_quote($value, '/') . "'/", $type);
}

const QA_EMAIL    = 'qa.crawler@mowology.ca';
const QA_USERNAME = 'qa_crawler';
const QA_HASH     = '$2y$12$sY4RNZNdEBcA9c/zFwdwR.5uh03yZkKlV62snOB1q5tDn8au4POVS';

// ── 1. Insert the user row (idempotent — skip if it already exists) ──────────
try {
    $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
    $exists->execute([QA_EMAIL]);
    $qaUserId = $exists->fetchColumn();

    if ($qaUserId) {
        $results[] = ['users row', "skip (already exists, id={$qaUserId})"];
    } else {
        $role = roleEnumAllows($db, 'user') ? 'user' : 'staff';

        $cols = ['email' => QA_EMAIL, 'password_hash' => QA_HASH, 'full_name' => 'QA Crawler Bot', 'role' => $role, 'is_active' => 1];
        if (colExists($db, 'users', 'username'))   $cols['username']   = QA_USERNAME;
        if (colExists($db, 'users', 'first_name')) $cols['first_name'] = 'QA';
        if (colExists($db, 'users', 'last_name'))  $cols['last_name']  = 'Crawler';
        if (colExists($db, 'users', 'is_driver'))  $cols['is_driver']  = 0;

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colNames = implode(',', array_map(fn($c) => "`$c`", array_keys($cols)));
        $db->prepare("INSERT INTO users ($colNames) VALUES ($placeholders)")->execute(array_values($cols));
        $qaUserId = (int)$db->lastInsertId();

        $results[] = ['users row', "OK (id={$qaUserId}, role={$role}, columns: " . implode(', ', array_keys($cols)) . ')'];
    }
} catch (PDOException $e) {
    $results[] = ['users row', 'ERROR: ' . $e->getMessage()];
    $qaUserId = null;
}

// ── 2. Grant the read-only 'viewer' RBAC role ─────────────────────────────────
if ($qaUserId && tableExists($db, 'user_roles') && tableExists($db, 'roles')) {
    try {
        $stmt = $db->prepare("
            INSERT IGNORE INTO user_roles (user_id, role_id)
            SELECT ?, r.id FROM roles r WHERE r.name = 'viewer'
        ");
        $stmt->execute([$qaUserId]);
        $results[] = ['user_roles (viewer)', $stmt->rowCount() > 0 ? 'OK' : 'skip (already granted, or no viewer role found)'];
    } catch (PDOException $e) {
        $results[] = ['user_roles (viewer)', 'ERROR: ' . $e->getMessage()];
    }
} else {
    $results[] = ['user_roles (viewer)', 'skip (RBAC tables not present or user insert failed)'];
}

// ── 3. Extend 'viewer' with view-only marketing/settings visibility ──────────
if (tableExists($db, 'role_permissions') && tableExists($db, 'permissions')) {
    foreach (['marketing.view', 'settings.view'] as $permKey) {
        try {
            $stmt = $db->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id FROM roles r JOIN permissions p ON p.`key` = ?
                WHERE r.name = 'viewer'
            ");
            $stmt->execute([$permKey]);
            $results[] = ["viewer + {$permKey}", $stmt->rowCount() > 0 ? 'OK' : 'skip (already granted, or permission not seeded)'];
        } catch (PDOException $e) {
            $results[] = ["viewer + {$permKey}", 'ERROR: ' . $e->getMessage()];
        }
    }
} else {
    $results[] = ['viewer permission extension', 'skip (RBAC tables not present)'];
}
?>
<!DOCTYPE html><html><head><title>Migration 1109</title></head><body>
<h2>Migration 1109 — QA crawler test account</h2>
<table border="1" cellpadding="8" style="border-collapse:collapse">
<tr><th>Step</th><th>Status</th></tr>
<?php foreach ($results as $r): ?><tr><td><?= htmlspecialchars($r[0]) ?></td><td><?= htmlspecialchars($r[1]) ?></td></tr><?php endforeach; ?>
</table>
<p>Login email: <code><?= htmlspecialchars(QA_EMAIL) ?></code> — password is in the gitignored <code>public/app_config/qa-test-credentials.php</code>, not shown here.</p>
<p><a href="/crm/users_appstack.php">&larr; Users &amp; Roles</a></p>
</body></html>
