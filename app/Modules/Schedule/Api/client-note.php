<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/client-note.php
 *
 * Mobile Schedule API — a note on a client, left from the field (the crew app's "Add a note"
 * row when the property has no visit today; a property WITH a visit today uses visit-work add_note).
 *
 * POST /api/schedule/client-note   { property_id, content, note_type?, client_request_id? }
 *        → { success, contact_id }
 *        note_type: general (default) | customer_request | issue | follow_up | internal
 *
 * Authorization: Bearer <jwt>. Needs clients.view (the same right the Search tab needs to
 * show the property). `client_request_id` (one UUID per attempt) makes a retried POST safe.
 * (?mode=, never ?action= — the /api router's rewrite owns `action` on GET.)
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

try {
    require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once APP_ROOT . '/Core/IdempotencyHelper.php';
    require_once CRM_INCLUDES . '/functions.php';
    require_once APP_ROOT . '/Modules/Contacts/Services/ContactService.php';

    $jwtUser = requireJwt();
    $userId  = (int)$jwtUser['id'];

    if (!jwtUserHasPermission($jwtUser, 'clients.view')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Your account can't leave client notes. Ask the office."]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $db    = getDB();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $idempKey = trim((string)($input['client_request_id'] ?? ''));
    if ($idempKey !== '') {
        $cached = idempotencyCheck($db, $idempKey, $userId);
        if ($cached !== null) { echo $cached; exit; }
    }

    // Scalars only: a JSON array/object would cast to 1 / "Array" (Codex review 2026-10-27).
    $propertyRaw = $input['property_id'] ?? 0;
    $propertyId  = (is_int($propertyRaw) || (is_string($propertyRaw) && ctype_digit($propertyRaw))) ? (int)$propertyRaw : 0;
    $content     = is_scalar($input['content'] ?? null) ? (string)$input['content'] : '';
    $noteType    = is_string($input['note_type'] ?? null) ? $input['note_type'] : 'general';

    $result = (new ContactService($db))->addNoteForProperty($propertyId, $content, $noteType, $userId);

    if (empty($result['success'])) {
        http_response_code((int)($result['status'] ?? 422));
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Could not save.']);
        exit;
    }

    $json = json_encode($result);
    if ($idempKey !== '') {
        idempotencyStore($db, $idempKey, $userId, 'client-note', 'add', $json);
    }
    echo $json;

} catch (Throwable $e) {
    error_log('schedule/client-note API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}
