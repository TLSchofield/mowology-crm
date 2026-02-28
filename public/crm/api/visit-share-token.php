<?php
/**
 * Visit Share Token API
 * ──────────────────────
 * Manages the public gallery share token for a visit.
 *
 * POST actions (JSON body):
 *   generate — Create or replace the share token for a visit
 *   revoke   — Revoke the active token
 *   info     — Get current token status
 *
 * All require admin or assigned crew, plus CSRF.
 *
 * Token scheme:
 *   raw = bin2hex(random_bytes(32))   → 64 hex chars given to client
 *   stored = hash('sha256', raw)      → 64 hex chars in DB (never raw)
 *   URL = /client/proof.php?token={raw}&visit={visit_id}
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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    requireLogin();
    $user    = getCurrentUser();
    $db      = getDB();
    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $csrfToken = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    // Only admins can manage share tokens
    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin permission required']);
        exit;
    }

    $action  = $input['action'] ?? '';
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;

    if ($visitId < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'visit_id required']);
        exit;
    }

    // Verify visit exists
    $vstmt = $db->prepare("SELECT id FROM job_visits WHERE id = ?");
    $vstmt->execute([$visitId]);
    if (!$vstmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    $siteUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';

    switch ($action) {

        // ── Generate / Regenerate token ───────────────────────────────────
        case 'generate':
            $rawToken    = bin2hex(random_bytes(32)); // 64 hex chars
            $tokenHash   = hash('sha256', $rawToken);
            $tokenPreview = substr($rawToken, 0, 8);

            $expireDays = isset($input['expire_days']) ? (int)$input['expire_days'] : 0;
            $expiresAt  = $expireDays > 0
                ? date('Y-m-d H:i:s', strtotime('+' . $expireDays . ' days'))
                : null;

            // Upsert: replace existing row if present
            $db->prepare("
                INSERT INTO visit_share_tokens
                    (visit_id, token_hash, token_preview, created_by, expires_at, revoked_at, access_count)
                VALUES (?, ?, ?, ?, ?, NULL, 0)
                ON DUPLICATE KEY UPDATE
                    token_hash    = VALUES(token_hash),
                    token_preview = VALUES(token_preview),
                    created_by    = VALUES(created_by),
                    created_at    = NOW(),
                    expires_at    = VALUES(expires_at),
                    revoked_at    = NULL,
                    access_count  = 0
            ")->execute([$visitId, $tokenHash, $tokenPreview, $user['id'], $expiresAt]);

            $galleryUrl = $siteUrl . '/client/proof.php?token=' . $rawToken . '&visit=' . $visitId;

            echo json_encode([
                'success'     => true,
                'token'       => $rawToken, // Only time raw token is sent to client
                'preview'     => $tokenPreview . '…',
                'gallery_url' => $galleryUrl,
                'expires_at'  => $expiresAt,
            ]);
            break;

        // ── Revoke token ──────────────────────────────────────────────────
        case 'revoke':
            $updated = $db->prepare("
                UPDATE visit_share_tokens
                SET revoked_at = NOW()
                WHERE visit_id = ? AND revoked_at IS NULL
            ");
            $updated->execute([$visitId]);

            echo json_encode([
                'success'  => true,
                'revoked'  => $updated->rowCount() > 0,
            ]);
            break;

        // ── Info ──────────────────────────────────────────────────────────
        case 'info':
            $stmt = $db->prepare("
                SELECT token_preview, created_at, expires_at, revoked_at, access_count
                FROM visit_share_tokens
                WHERE visit_id = ?
            ");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['success' => true, 'token' => null]);
                break;
            }

            $isRevoked = ($row['revoked_at'] !== null);
            $isExpired = ($row['expires_at'] !== null && strtotime($row['expires_at']) < time());
            $isActive  = !$isRevoked && !$isExpired;

            echo json_encode([
                'success'      => true,
                'token'        => [
                    'preview'      => $row['token_preview'] . '…',
                    'created_at'   => $row['created_at'],
                    'expires_at'   => $row['expires_at'],
                    'revoked_at'   => $row['revoked_at'],
                    'access_count' => (int)$row['access_count'],
                    'is_active'    => $isActive,
                    'status'       => $isRevoked ? 'revoked' : ($isExpired ? 'expired' : 'active'),
                ],
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }

} catch (PDOException $e) {
    error_log('visit-share-token.php DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (Throwable $e) {
    error_log('visit-share-token.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
