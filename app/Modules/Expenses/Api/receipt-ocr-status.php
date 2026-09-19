<?php
/**
 * API: Receipt OCR Job Status (Phase 3 High).
 *
 * Returns the current state of an expense_ocr_jobs row.
 *
 * GET /crm/api/receipt-ocr-status.php?id=<ocr_job_id>
 *
 * Auth:
 *   - JWT (Authorization: Bearer ...) for iOS/native clients
 *   - Session (requireLogin) for web clients
 * Either is sufficient. The job row is also constrained to user_id so
 * you can't poll a job that isn't yours.
 *
 * 200 envelope:
 *   {
 *     "success": true,
 *     "data": {
 *       "id": 678, "media_id": 12345, "status": "complete",
 *       "parsed_vendor": "...", "parsed_total": "12.34", "parsed_date": "2026-05-09",
 *       "parsed_raw_text": "...", "parsed": {...}, "suggestions": {...},
 *       "ocr_source": "tesseract", "field_confidences": {...},
 *       "gst_validation": {...}, "job_suggestions": [...],
 *       "error": null, "attempts": 1,
 *       "created_at": "...", "completed_at": "..."
 *     }
 *   }
 *
 * Errors:
 *   400 invalid id
 *   401 unauthenticated
 *   404 not found / not owned
 *
 * TODO(api-envelope): replace inline json_encode with
 *   App\Core\Api\ApiResponse::ok()/error() once Task 13 merges to main
 *   (claude/peaceful-moser-f5855c, commit d204922).
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

header('Content-Type: application/json; charset=utf-8');

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';

    // Accept either JWT (iOS) or session (web). Try JWT first; if no Bearer
    // token is present, fall back to session.
    $userId = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($authHeader, 'bearer ') === 0) {
        $jwtUser = requireJwt();
        $userId  = (int)$jwtUser['id'];
    } else {
        requireLogin();
        $sessionUser = getCurrentUser();
        $userId      = (int)$sessionUser['id'];
        // No session writes after this point — release the lock so concurrent
        // polls don't serialise behind each other.
        if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_request', 'message' => 'id required', 'field' => 'id']);
        exit;
    }

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT id, media_id, expense_id, user_id, status,
               parsed_vendor, parsed_total, parsed_date, parsed_raw_text, parsed_json,
               error, attempts, started_at, completed_at, created_at
        FROM expense_ocr_jobs
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'not_found', 'message' => 'OCR job not found']);
        exit;
    }

    // Decode parsed_json into structured fields. Keep parsed_json available
    // for clients that want the raw envelope, but also surface common keys
    // (parsed, suggestions, ocr_source, field_confidences) at the top level
    // for ergonomic consumption.
    $payload = [
        'id'              => (int)$row['id'],
        'media_id'        => (int)$row['media_id'],
        'expense_id'      => $row['expense_id'] !== null ? (int)$row['expense_id'] : null,
        'status'          => (string)$row['status'],
        'parsed_vendor'   => $row['parsed_vendor'],
        'parsed_total'    => $row['parsed_total'],
        'parsed_date'     => $row['parsed_date'],
        'parsed_raw_text' => $row['parsed_raw_text'],
        'error'           => $row['error'],
        'attempts'        => (int)$row['attempts'],
        'started_at'      => $row['started_at'],
        'completed_at'    => $row['completed_at'],
        'created_at'      => $row['created_at'],
    ];

    if (!empty($row['parsed_json'])) {
        $decoded = json_decode((string)$row['parsed_json'], true);
        if (is_array($decoded)) {
            $payload['parsed']            = $decoded['parsed']            ?? [];
            $payload['suggestions']       = $decoded['suggestions']       ?? [];
            $payload['ocr_text']          = $decoded['ocr_text']          ?? $row['parsed_raw_text'];
            $payload['ocr_available']     = $decoded['ocr_available']     ?? false;
            $payload['ocr_source']        = $decoded['ocr_source']        ?? 'none';
            $payload['field_confidences'] = $decoded['field_confidences'] ?? new stdClass();
            $payload['gst_validation']    = $decoded['gst_validation']    ?? null;
            $payload['job_suggestions']   = $decoded['job_suggestions']   ?? [];
            $payload['file_path']         = $decoded['file_path']         ?? null;
        }
    }

    echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}
