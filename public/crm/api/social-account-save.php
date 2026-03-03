<?php
/**
 * /crm/api/social-account-save.php
 * Save or disconnect a social_accounts record.
 *
 * POST body (JSON):
 *   csrf_token          string  (required)
 *   action              string  'save' (default) | 'disconnect'
 *
 *   For 'save':
 *     platform            string  gbp | facebook | instagram
 *     account_name        string
 *     account_id_external string|null
 *
 *   For 'disconnect':
 *     id                  int
 */

declare(strict_types=1);

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
require_once CRM_INCLUDES . '/functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('marketing.edit');
session_write_close();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!isset($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? 'save';

try {
    $db = getDB();

    if ($action === 'disconnect') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing account ID']);
            exit;
        }
        $db->prepare("DELETE FROM social_accounts WHERE id = ?")->execute([$id]);

        try {
            $db->prepare("
                INSERT INTO social_audit_log (user_id, action, entity_type, entity_id, ip_address)
                VALUES (?, 'disconnect_account', 'social_account', ?, ?)
            ")->execute([$user['id'], $id, $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {}

        echo json_encode(['success' => true]);
        exit;
    }

    // ── Save / upsert account ─────────────────────────────────────────────
    $validPlatforms = ['gbp', 'facebook', 'instagram'];
    $platform    = $input['platform']            ?? '';
    $accountName = trim($input['account_name']   ?? '');
    $externalId  = trim($input['account_id_external'] ?? '') ?: null;

    if (!in_array($platform, $validPlatforms, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid platform']);
        exit;
    }
    if (!$accountName) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Account name is required']);
        exit;
    }

    // Check for existing record on this platform
    $existing = $db->prepare("SELECT id FROM social_accounts WHERE platform = ? LIMIT 1");
    $existing->execute([$platform]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        // Update
        $db->prepare("
            UPDATE social_accounts
            SET account_name = ?, account_id_external = ?, is_active = 1,
                connected_by = ?, connected_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ")->execute([$accountName, $externalId, $user['id'], $existingId]);
        $accountId = $existingId;
    } else {
        // Insert
        $db->prepare("
            INSERT INTO social_accounts
                (platform, account_name, account_id_external, is_active, is_verified,
                 connected_by, connected_at, created_at, updated_at)
            VALUES (?, ?, ?, 1, 0, ?, NOW(), NOW(), NOW())
        ")->execute([$platform, $accountName, $externalId, $user['id']]);
        $accountId = (int)$db->lastInsertId();
    }

    try {
        $db->prepare("
            INSERT INTO social_audit_log (user_id, action, entity_type, entity_id, detail, ip_address)
            VALUES (?, 'connect_account', 'social_account', ?, ?, ?)
        ")->execute([
            $user['id'],
            $accountId,
            json_encode(['platform' => $platform, 'name' => $accountName]),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {}

    echo json_encode([
        'success'    => true,
        'account_id' => $accountId,
        'message'    => 'Account saved'
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error: ' . $e->getMessage()
    ]);
}
