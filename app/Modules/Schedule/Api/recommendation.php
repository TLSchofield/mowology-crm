<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/recommendation.php
 *
 * Mobile Schedule API — Crew Service Recommendations
 *
 * Crew photograph work that needs doing, tap a service, and the client gets a
 * priced quote. See FieldRecommendationService for the business rules.
 *
 * Serves BOTH clients through requireLoginOrJwt():
 *   - iOS       → Authorization: Bearer <jwt>   (stateless, no CSRF)
 *   - Android/web → session cookie              (CSRF token required)
 *
 * GET  /api/schedule/recommendation?mode=options
 *      Response 200: { "success": true, "options": [
 *          { "product_id": 12, "label": "Half Day Cleanup", "price": 450.00,
 *            "auto_send": true, "fixed_price": true, "description": "..." } ] }
 *
 * GET  /api/schedule/recommendation?mode=recipients&visit_id=42
 *      Who each button goes to (Ask first → on-site contact, Send quote → site/billing
 *      contact), whether this user may send an ask, and whether they may be emailed (CASL).
 *      Response 200: { "success": true, "can_send": true, "ask_ready": true,
 *          "ask":   { "contact_id": 9, "name": "Gaby Stoian", "first_name": "Gaby",
 *                     "email": "…", "role": "onsite", "consent_ok": true, "consent": "…" },
 *          "quote": { "contact_id": 4, "name": "Darren Brattston", "email": "…", "role": "site" } }
 *
 * GET  /api/schedule/recommendation?mode=ask_draft&observation_id=9
 *      The Ask-first email as it would go (subject, body, recipient, consent, photos).
 *
 * POST /api/schedule/recommendation
 *      Body: { "action": "create", "visit_id": 42, "product_id": 12,
 *              "intent": "ask" | "quote" (default quote — old app builds send none),
 *              "note": "Back garden is knee deep in leaves",
 *              "media_ids": [881, 882], "csrf_token": "<session clients only>" }
 *      intent=ask → status "ask_draft"; the response carries "draft" when this user
 *      may send it (admin/manager), so the phone can show the editable email.
 *
 * POST { "action": "ask_send", "observation_id": 9, "subject": "…", "body": "…" }
 *      Admin/manager only (billing.edit). Server re-reads recipient + consent.
 *      Response 200: { "success": true, "observation_id": 9, "status": "email_sent",
 *                      "duplicate": false, "quote_id": 431, "auto_sent": true,
 *                      "message": "Quote sent to the client" }
 *
 * Photos are uploaded first via POST /api/schedule/job-photo, whose media_id is
 * passed here — so a photo ends up linked to both the visit and the recommendation.
 *
 * Crew may only recommend against visits assigned to them; admin/manager any.
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
require_once APP_ROOT . '/Core/Auth/auth.php';
require_once APP_ROOT . '/Modules/Products/Services/FieldRecommendationService.php';
require_once APP_ROOT . '/Modules/Products/Services/FieldAskService.php';

try {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
               ?? '';
    $isJwt = str_starts_with($authHeader, 'Bearer ');

    $user   = requireLoginOrJwt();
    $userId = (int)$user['id'];
    $role   = strtolower((string)($user['role'] ?? 'crew'));
    $isAdmin = in_array($role, ['admin', 'manager', 'staff'], true);

    $db  = getDB();
    $svc = new FieldRecommendationService($db);
    $ask = new FieldAskService($db);

    /** Crew may only act on visits assigned to them; admin/manager any. Exits on failure. */
    $checkVisit = function (int $visitId) use ($db, $isAdmin, $userId): void {
        if ($visitId <= 0) {
            return;
        }
        $vs = $db->prepare('SELECT id, assigned_crew_id FROM job_visits WHERE id = ? LIMIT 1');
        $vs->execute([$visitId]);
        $visit = $vs->fetch(PDO::FETCH_ASSOC);
        if (!$visit) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Visit not found']);
            exit;
        }
        if (!$isAdmin && (int)($visit['assigned_crew_id'] ?? 0) !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not your visit']);
            exit;
        }
    };

    // ── Route ────────────────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // NOT 'action': the /api/ rewrite passes its own action= to index.php with
        // QSA, so a query param of that name would collide and win.
        $action = trim((string)($_GET['mode'] ?? 'options'));
        $body   = [];
    } else {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = trim((string)($body['action'] ?? 'create'));
    }

    switch ($action) {

        // ── The chips the crew see ───────────────────────────────────────────
        case 'options':
            echo json_encode([
                'success' => true,
                'options' => $svc->getFieldOptions(),
            ]);
            break;

        // ── Who each button goes to ──────────────────────────────────────────
        case 'recipients':
            $visitId = (int)($_GET['visit_id'] ?? 0);
            $checkVisit($visitId);
            $propertyId = $svc->resolvePropertyId($visitId, ['property_id' => (int)($_GET['property_id'] ?? 0)]);
            $r = $ask->recipients($propertyId);
            $askOut = null;
            if ($r['ask']) {
                $c = $ask->consent((int)$r['ask']['contact_id'], $propertyId);
                $askOut = $r['ask'] + ['consent_ok' => $c['ok'], 'consent' => $c['reason']];
            }
            echo json_encode([
                'success'   => true,
                'can_send'  => FieldAskService::canSend($db, $user),
                'ask_ready' => $ask->ready(),
                'property'  => $r['property'],
                'ask'       => $askOut,
                'quote'     => $r['quote'],
                'same'      => $r['same'],
            ]);
            break;

        // ── The Ask-first email as it would go ───────────────────────────────
        case 'ask_draft':
            $obsId = (int)($_GET['observation_id'] ?? 0);
            $obs = $svc->getObservation($obsId);
            if (!$obs) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            if (!$isAdmin && (int)($obs['created_by'] ?? 0) !== $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not yours']);
                exit;
            }
            $d = $ask->draft($obsId, $user);
            echo json_encode(['success' => $d['ok']] + $d);
            break;

        // ── Log a recommendation ─────────────────────────────────────────────
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'POST required']);
                exit;
            }

            // Session clients carry CSRF; JWT is stateless and carries none.
            if (!$isJwt && !verifyCSRFToken((string)($body['csrf_token'] ?? ''))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                exit;
            }

            $checkVisit(isset($body['visit_id']) ? (int)$body['visit_id'] : 0);

            $result = $svc->create($userId, $body);

            // A manager/admin standing on site reads and sends the ask straight away.
            if (($result['intent'] ?? '') === FieldRecommendationService::INTENT_ASK
                && ($result['status'] ?? '') === 'ask_draft'
                && FieldAskService::canSend($db, $user)) {
                $d = $ask->draft((int)$result['observation_id'], $user);
                if ($d['ok']) {
                    $result['draft']   = $d;
                    $result['message'] = 'Read it, then send';
                }
            }
            echo json_encode(array_merge(['success' => true], $result));
            break;

        // ── Send an Ask-first email (admin/manager) ──────────────────────────
        case 'ask_send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'POST required']);
                exit;
            }
            if (!$isJwt && !verifyCSRFToken((string)($body['csrf_token'] ?? ''))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
                exit;
            }
            if (!FieldAskService::canSend($db, $user)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Only a manager or admin can send this — it is saved for them']);
                exit;
            }
            $r = $ask->send((int)($body['observation_id'] ?? 0), $user,
                            (string)($body['subject'] ?? ''), (string)($body['body'] ?? ''));
            echo json_encode(['success' => $r['ok'], 'message' => $r['message']] + ($r['ok'] ? [] : ['error' => $r['message']]));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
    }

} catch (InvalidArgumentException $e) {
    // Expected validation failures — the client can show these to the crew.
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[schedule/recommendation] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
