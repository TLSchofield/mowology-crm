<?php
/**
 * Visit Share Token API
 * ──────────────────────
 * Manages the public gallery share token for a visit.
 *
 * POST actions (JSON body):
 *   generate — Create or replace the share token for a visit
 *               Optional: notify_client=true → emails the gallery link to the contact
 *   revoke   — Revoke the active token
 *   info     — Get current token status
 *
 * All require admin or assigned crew, plus CSRF.
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
            $rawToken     = bin2hex(random_bytes(32));
            $tokenHash    = hash('sha256', $rawToken);
            $tokenPreview = substr($rawToken, 0, 8);

            $expireDays = isset($input['expire_days']) ? (int)$input['expire_days'] : 0;
            $expiresAt  = $expireDays > 0
                ? date('Y-m-d H:i:s', strtotime('+' . $expireDays . ' days'))
                : null;

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

            // ── Notify client if requested ────────────────────────────────
            $notified = false;
            if (!empty($input['notify_client'])) {
                $cstmt = $db->prepare("
                    SELECT c.first_name, c.email, c.mobile, c.receive_sms,
                           v.started_at,
                           COALESCE(v.service_type, jp.service_type, 'general') AS svc_type
                    FROM job_visits v
                    JOIN job_plans jp ON jp.id = v.plan_id
                    JOIN properties pr ON pr.id = jp.property_id
                    JOIN contacts c ON c.id = pr.site_contact_id
                    WHERE v.id = ?
                ");
                $cstmt->execute([$visitId]);
                $cinfo = $cstmt->fetch(PDO::FETCH_ASSOC);

                if ($cinfo && filter_var($cinfo['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    require_once APP_ROOT . '/Services/Messaging/MessagingService.php';

                    $firstName = $cinfo['first_name'] ?: 'there';
                    $svcMap    = [
                        'fertilizer'     => 'Fertilizer Treatment',
                        'lawn_treatment' => 'Lawn Treatment',
                        'salt'           => 'Salt / De-Icing',
                        'de_ice'         => 'De-Icing Service',
                        'snow'           => 'Snow Clearance',
                        'snow_clearance' => 'Snow Clearance',
                        'mowing'         => 'Lawn Mowing',
                        'general'        => 'Landscaping Service',
                    ];
                    $svcLabel  = $svcMap[$cinfo['svc_type']] ?? 'Landscaping Service';
                    $visitDate = $cinfo['started_at']
                        ? date('F j, Y', strtotime($cinfo['started_at']))
                        : 'your recent service';
                    $year = date('Y');

                    $emailHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body{font-family:Inter,Arial,sans-serif;background:#f2f5f3;margin:0;padding:20px;}
  .card{background:#fff;max-width:540px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  .hd{background:linear-gradient(135deg,#1A5F4A,#0D3B2E);padding:28px 32px;color:#fff;}
  .hd h1{font-size:20px;margin:0 0 6px;font-weight:700;}
  .hd p{font-size:13px;opacity:0.75;margin:0;}
  .bd{padding:28px 32px;}
  .bd p{color:#444;font-size:14px;line-height:1.7;margin:0 0 16px;}
  .btn{display:inline-block;background:#2D8659;color:#fff!important;text-decoration:none!important;padding:12px 28px;border-radius:6px;font-weight:600;font-size:14px;margin:4px 0 16px;}
  .note{font-size:12px;color:#888;}
  .ft{padding:16px 32px;background:#f8fbfa;border-top:1px solid #e8f3f0;font-size:11px;color:#999;text-align:center;}
  .ft a{color:#2D8659;text-decoration:none;}
</style>
</head>
<body>
<div class="card">
  <div class="hd">
    <h1>Your Service Photos Are Ready</h1>
    <p>Mowology Landscaping</p>
  </div>
  <div class="bd">
    <p>Hi {$firstName},</p>
    <p>Photos from your <strong>{$svcLabel}</strong> on {$visitDate} are now available in your private gallery.</p>
    <p><a href="{$galleryUrl}" class="btn">View Your Photos &rarr;</a></p>
    <p>This is a private link for your eyes only &mdash; please don&rsquo;t share it with others.</p>
    <p class="note">Questions about your service? Reply to this email or call us at <strong>(778) 846-9273</strong>.</p>
  </div>
  <div class="ft">
    &copy; {$year} Mowology Landscaping &nbsp;&middot;&nbsp; <a href="https://mowology.ca">mowology.ca</a>
  </div>
</div>
</body>
</html>
HTML;
                    $sent = sendCrmEmail(
                        $cinfo['email'],
                        "Your {$svcLabel} Photos Are Ready \u{2014} Mowology",
                        $emailHtml
                    );

                    if ($sent) {
                        $notified = true;
                        // SMS — NO URL, plain text only, max 160 chars per CLAUDE.md rule 10
                        if (!empty($cinfo['mobile']) && !empty($cinfo['receive_sms'])) {
                            $smsText = "Hi {$firstName}, your Mowology service photos from {$visitDate} are ready. Check your email for the gallery link. Questions? Call (778) 846-9273";
                            if (strlen($smsText) <= 160) {
                                sendSms($cinfo['mobile'], $smsText);
                            }
                        }
                    }
                }
            }

            echo json_encode([
                'success'     => true,
                'token'       => $rawToken,
                'preview'     => $tokenPreview . '…',
                'gallery_url' => $galleryUrl,
                'expires_at'  => $expiresAt,
                'notified'    => $notified,
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
            echo json_encode(['success' => true, 'revoked' => $updated->rowCount() > 0]);
            break;

        // ── Info ──────────────────────────────────────────────────────────
        case 'info':
            $stmt = $db->prepare("
                SELECT token_preview, created_at, expires_at, revoked_at, access_count
                FROM visit_share_tokens WHERE visit_id = ?
            ");
            $stmt->execute([$visitId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) { echo json_encode(['success' => true, 'token' => null]); break; }

            $isRevoked = ($row['revoked_at'] !== null);
            $isExpired = ($row['expires_at'] !== null && strtotime($row['expires_at']) < time());
            $isActive  = !$isRevoked && !$isExpired;

            echo json_encode([
                'success' => true,
                'token'   => [
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
