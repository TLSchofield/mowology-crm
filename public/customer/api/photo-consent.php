<?php
/**
 * Photo Consent Toggle API
 *
 * POST /customer/api/photo-consent.php
 *   token   - contacts.portal_token
 *   photo_id - visit_photos.id
 *   consent - 0 | 1
 *
 * Updates consent_portfolio on a photo belonging to the authenticated client.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/app_config/config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $token    = trim($_POST['token'] ?? '');
    $photoId  = (int)($_POST['photo_id'] ?? 0);
    $consent  = (int)($_POST['consent'] ?? 0) ? 1 : 0;

    if ($token === '' || $photoId <= 0) {
        throw new Exception('Missing token or photo_id');
    }

    $db = getDB();

    // Verify the photo belongs to this client via visit → plan → property → contact
    $stmt = $db->prepare("
        SELECT vp.id
        FROM visit_photos vp
        JOIN job_visits v ON v.id = vp.visit_id
        JOIN job_plans jp ON jp.id = v.plan_id
        JOIN properties pr ON pr.id = jp.property_id
        JOIN contacts c ON c.id = pr.site_contact_id
        WHERE vp.id = ?
          AND c.portal_token = ?
        LIMIT 1
    ");
    $stmt->execute([$photoId, $token]);
    if (!$stmt->fetch()) {
        throw new Exception('Photo not found for this client');
    }

    // Update consent (with fallback if migration 1025 hasn't run)
    try {
        $upd = $db->prepare("
            UPDATE visit_photos
            SET consent_portfolio = ?,
                consent_decided_at = NOW()
            WHERE id = ?
        ");
        $upd->execute([$consent, $photoId]);
    } catch (PDOException $e) {
        throw new Exception('Consent feature not yet enabled — please run migration 1025');
    }

    echo json_encode(['success' => true, 'consent' => $consent]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
