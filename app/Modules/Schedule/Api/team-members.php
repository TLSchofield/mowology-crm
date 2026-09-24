<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/team-members.php
 *
 * Mobile Schedule API — Active Team Members (for the crew-assignment picker)
 *
 * GET /api/schedule/team-members
 * Authorization: Bearer <jwt>
 *
 * Response 200: { "success": true, "members": [ { "id": 10, "name": "Alice Crew" }, ... ] }
 *
 * Admin/manager only — same restriction as crew assignment itself.
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';

$jwtUser = requireJwt();

if (!jwtIsAdmin($jwtUser['role'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

try {
    $db   = getDB();
    $rows = $db->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name")
                ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[schedule/team-members] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

$members = array_map(
    static fn (array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['full_name']],
    $rows
);

echo json_encode(['success' => true, 'members' => $members]);
