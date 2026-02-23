<?php
/**
 * Proof of Work — Visit Actions API
 * ───────────────────────────────────
 * Handles all PoW state transitions and data saves via AJAX POST.
 *
 * Actions (sent as JSON body or form POST):
 *  start_visit       — Mark visit in_progress, record GPS arrival
 *  save_checklist    — Save checklist JSON to visit
 *  save_materials    — Save materials JSON to visit
 *  save_service_data — Save service-type-specific fields
 *  save_notes        — Add a crew note to the visit
 *  save_signature    — Save base64 signature image
 *  end_visit         — Mark visit completed, record GPS departure
 *  generate_pdf      — Generate (or regenerate) the PoW PDF
 *  lock_visit        — Admin: lock the visit (makes PDF final)
 *  unlock_visit      — Admin: unlock for edits (logged in audit)
 *  upload_photo      — Save a photo to visit_photos
 *
 * Returns: { "success": bool, "error"?: string, ... }
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
    require_once APP_ROOT . '/Services/Pow/PowPdfGenerator.php';
    // MapSnapshotService is required inside generate_pdf action branch

    requireLogin();
    $user = getCurrentUser();
    $db   = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    // Accept JSON body or form POST
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    // CSRF verification
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $action  = $input['action'] ?? '';
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;

    if ($visitId < 1) {
        throw new InvalidArgumentException('visit_id is required');
    }

    // Load visit and check authorization
    $visit = loadVisit($db, $visitId);
    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    $isCrew  = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];

    if (!$isAdmin && !$isCrew) {
        http_response_code(403);
        echo json_encode(['error' => 'Not authorized for this visit']);
        exit;
    }

    // Lock guard — most write actions blocked on locked visits
    $lockGuarded = ['save_checklist','save_materials','save_service_data','save_notes','save_signature','end_visit','upload_photo'];
    if (in_array($action, $lockGuarded) && $visit['locked_at'] !== null && !$isAdmin) {
        http_response_code(409);
        echo json_encode(['error' => 'Visit is locked. Contact admin to unlock.']);
        exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    switch ($action) {

        // ── Start Visit ───────────────────────────────────────────────────
        case 'start_visit':
            if ($visit['status'] !== 'scheduled') {
                echo json_encode(['success' => false, 'error' => 'Visit is not in scheduled state']);
                break;
            }
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $svcType = $input['service_type'] ?? $visit['service_type'] ?? null;

            $db->prepare("
                UPDATE job_visits SET
                    status = 'in_progress',
                    started_at = NOW(),
                    gps_arrival_lat = ?,
                    gps_arrival_lng = ?,
                    service_type = COALESCE(?, service_type)
                WHERE id = ?
            ")->execute([$lat, $lng, $svcType, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'start', null, $ip);
            echo json_encode(['success' => true, 'started_at' => date('c')]);
            break;

        // ── Save Checklist ─────────────────────────────────────────────────
        case 'save_checklist':
            $items = $input['items'] ?? [];
            if (!is_array($items)) {
                throw new InvalidArgumentException('items must be an array');
            }
            $sanitized = [];
            foreach ($items as $item) {
                $sanitized[] = [
                    'item'    => substr(strip_tags((string)($item['item'] ?? '')), 0, 255),
                    'checked' => !empty($item['checked']),
                    'note'    => substr(strip_tags((string)($item['note'] ?? '')), 0, 500),
                ];
            }
            $json = json_encode($sanitized);
            $db->prepare("
                UPDATE job_visits SET
                    checklist_json = ?,
                    checklist_completed_at = NOW(),
                    checklist_completed_by = ?,
                    checklist_completed = ?
                WHERE id = ?
            ")->execute([$json, $user['id'], $json, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'checklist_saved', ['count' => count($sanitized)], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Save Materials ─────────────────────────────────────────────────
        case 'save_materials':
            $items = $input['items'] ?? [];
            if (!is_array($items)) throw new InvalidArgumentException('items must be an array');
            $sanitized = [];
            foreach ($items as $m) {
                $sanitized[] = [
                    'name'           => substr(strip_tags((string)($m['name'] ?? '')), 0, 255),
                    'qty'            => is_numeric($m['qty'] ?? '') ? round((float)$m['qty'], 4) : null,
                    'unit'           => substr(strip_tags((string)($m['unit'] ?? '')), 0, 50),
                    'rate_per_unit'  => is_numeric($m['rate_per_unit'] ?? '') ? round((float)$m['rate_per_unit'], 2) : null,
                    'note'           => substr(strip_tags((string)($m['note'] ?? '')), 0, 500),
                ];
            }
            $db->prepare("UPDATE job_visits SET materials_json = ? WHERE id = ?")
               ->execute([json_encode($sanitized), $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'materials_saved', ['count' => count($sanitized)], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Save Service Data ──────────────────────────────────────────────
        case 'save_service_data':
            $svcData = $input['data'] ?? [];
            if (!is_array($svcData)) throw new InvalidArgumentException('data must be an object');
            // Sanitize all string values
            array_walk_recursive($svcData, function(&$v) {
                if (is_string($v)) $v = substr(strip_tags($v), 0, 1000);
            });
            $db->prepare("UPDATE job_visits SET service_data_json = ? WHERE id = ?")
               ->execute([json_encode($svcData), $visitId]);
            echo json_encode(['success' => true]);
            break;

        // ── Save Note ─────────────────────────────────────────────────────
        case 'save_notes':
            $content  = trim($input['content'] ?? '');
            $noteType = $input['note_type'] ?? 'general';
            $visible  = !empty($input['visible_to_customer']) ? 1 : 0;
            $allowed  = ['general','customer_request','issue','follow_up','internal'];
            if (!in_array($noteType, $allowed)) $noteType = 'general';
            if (strlen($content) < 1) throw new InvalidArgumentException('Note content required');
            $db->prepare("
                INSERT INTO visit_notes (visit_id, note_type, content, is_visible_to_customer, created_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$visitId, $noteType, $content, $visible, $user['id']]);
            echo json_encode(['success' => true]);
            break;

        // ── Save Signature ─────────────────────────────────────────────────
        case 'save_signature':
            $dataUrl = $input['signature'] ?? '';
            if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
                throw new InvalidArgumentException('Invalid signature format');
            }
            $imgData = base64_decode(substr($dataUrl, 22));
            if ($imgData === false) throw new InvalidArgumentException('Invalid base64');

            $sigDir = PUBLIC_ROOT . '/uploads/pow/signatures/';
            if (!is_dir($sigDir)) mkdir($sigDir, 0755, true);

            $filename = 'sig_' . $visitId . '_' . time() . '.png';
            file_put_contents($sigDir . $filename, $imgData);

            $sigPath = '/uploads/pow/signatures/' . $filename;
            $db->prepare("UPDATE job_visits SET signature_path = ? WHERE id = ?")
               ->execute([$sigPath, $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'signature_saved', null, $ip);
            echo json_encode(['success' => true, 'path' => $sigPath]);
            break;

        // ── End Visit ─────────────────────────────────────────────────────
        case 'end_visit':
            if (!in_array($visit['status'], ['in_progress', 'scheduled'])) {
                echo json_encode(['success' => false, 'error' => 'Visit cannot be ended from current status']);
                break;
            }
            $lat   = isset($input['lat'])   ? (float)$input['lat']   : null;
            $lng   = isset($input['lng'])   ? (float)$input['lng']   : null;
            $notes = trim($input['notes'] ?? '');

            $db->prepare("
                UPDATE job_visits SET
                    status = 'completed',
                    completed_at = NOW(),
                    gps_departure_lat = ?,
                    gps_departure_lng = ?,
                    gps_confirmed_at = NOW(),
                    completion_notes = CASE WHEN ? != '' THEN ? ELSE completion_notes END
                WHERE id = ?
            ")->execute([$lat, $lng, $notes, $notes, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'complete', null, $ip);

            // Capture labor cost + margin snapshot at completion time
            $vcService = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
            if (file_exists($vcService)) {
                require_once $vcService;
                VisitCompletionService::capture($visitId, (int)$user['id']);
            }

            echo json_encode(['success' => true, 'completed_at' => date('c')]);
            break;

        // ── Generate PDF ───────────────────────────────────────────────────
        case 'generate_pdf':
            if (!$isAdmin && !$isCrew) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized to generate PDF']);
                break;
            }
            $forceRegen = !empty($input['force']);
            require_once APP_ROOT . '/Services/Pow/MapSnapshotService.php';
            $gen = new PowPdfGenerator(
                $db,
                PUBLIC_ROOT,
                defined('SITE_URL') ? SITE_URL : 'https://mowology.ca'
            );
            $result = $gen->generate($visitId, $forceRegen);
            if ($result['success']) {
                $action_name = $forceRegen ? 'regenerate_pdf' : 'generate_pdf';
                addAuditLog($db, $visitId, (int)$user['id'], $action_name, ['path' => $result['path']], $ip);
            }
            echo json_encode($result);
            break;

        // ── Lock Visit (admin) ─────────────────────────────────────────────
        case 'lock_visit':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                break;
            }
            $db->prepare("UPDATE job_visits SET locked_at = NOW(), locked_by = ?, proof_complete = 1 WHERE id = ?")
               ->execute([$user['id'], $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'lock', null, $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Unlock Visit (admin) ───────────────────────────────────────────
        case 'unlock_visit':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                break;
            }
            $reason = substr(strip_tags($input['reason'] ?? ''), 0, 500);
            $db->prepare("UPDATE job_visits SET locked_at = NULL, locked_by = NULL WHERE id = ?")
               ->execute([$visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'unlock', ['reason' => $reason], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Upload Photo ───────────────────────────────────────────────────
        case 'upload_photo':
            if (empty($_FILES['photo'])) {
                throw new InvalidArgumentException('No photo file received');
            }
            $file     = $_FILES['photo'];
            $allowed  = ['image/jpeg','image/png','image/gif','image/webp','image/heic'];
            $mime     = $file['type'] ?? '';
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($realMime, $allowed)) {
                throw new InvalidArgumentException('Invalid image type');
            }
            if ($file['size'] > 20 * 1024 * 1024) {
                throw new InvalidArgumentException('Photo exceeds 20MB limit');
            }

            $photoType = $input['photo_type'] ?? 'after';
            $allowed_types = ['before','during','after','issue','other'];
            if (!in_array($photoType, $allowed_types)) $photoType = 'other';

            $caption = substr(strip_tags($input['caption'] ?? ''), 0, 255);

            $uploadDir = PUBLIC_ROOT . '/uploads/photos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'pow_' . $visitId . '_' . $photoType . '_' . uniqid() . '.' . strtolower($ext);
            move_uploaded_file($file['tmp_name'], $uploadDir . $filename);

            $db->prepare("
                INSERT INTO visit_photos (visit_id, photo_type, filename, original_filename, file_size, mime_type, caption, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $visitId, $photoType, $filename, $file['name'],
                $file['size'], $realMime, $caption, $user['id']
            ]);

            addAuditLog($db, $visitId, (int)$user['id'], 'photo_upload', ['type' => $photoType, 'file' => $filename], $ip);
            echo json_encode(['success' => true, 'filename' => $filename]);
            break;

        default:
            throw new InvalidArgumentException('Unknown action: ' . $action);
    }

} catch (PDOException $e) {
    error_log('PoW Actions API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('PoW Actions API error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function loadVisit(PDO $db, int $visitId): ?array
{
    $stmt = $db->prepare("SELECT * FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function addAuditLog(PDO $db, int $visitId, int $userId, string $action, ?array $payload, ?string $ip): void
{
    $db->prepare("
        INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $visitId, $userId, $action,
        $payload ? json_encode($payload) : null,
        $ip ? substr($ip, 0, 45) : null,
    ]);
}
