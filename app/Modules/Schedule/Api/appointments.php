<?php
/**
 * Appointments — quote visits booked from an enquiry (AppointmentService, migration 1369). Session (CRM) endpoint.
 *
 * GET  ?mode=request&request_id=N     the request's booked visit (or null), who can go, whether texts are allowed
 * POST {mode: 'book', request_id, date, time, assigned_to?, duration_minutes?, notes?, csrf_token}   books or moves
 * POST {mode: 'status', id, status: cancelled|done, csrf_token}
 * POST {mode: 'confirm', id, email: bool, sms: bool, csrf_token}   sends the client a confirmation — only on this click
 *
 * Viewing: clients.view. Booking, moving, cancelling, confirming: schedule.edit or jobs.edit.
 * ?mode=, not ?action= (the /api/ router appends its own action).
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';
header('Content-Type: application/json');

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

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    requireLogin();
    $user = getCurrentUser() ?? [];
    $uid = (int)($user['id'] ?? 0);

    $method = $_SERVER['REQUEST_METHOD'];
    $input  = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $mode   = $method === 'POST' ? (string)($input['mode'] ?? '') : (string)($_GET['mode'] ?? 'request');

    if ($method === 'POST' && !verifyCSRFToken((string)($input['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    if (!userHasPermission('clients.view')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied']);
        exit;
    }
    $canEdit = userHasPermission('schedule.edit') || userHasPermission('jobs.edit');
    if ($method === 'POST' && !$canEdit) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You can view visits but not book them.']);
        exit;
    }

    $db = getDB();
    session_write_close();
    require_once APP_ROOT . '/Modules/Schedule/Services/AppointmentService.php';
    $svc = new AppointmentService($db);
    if (!$svc->ready()) {
        echo json_encode(['ok' => false, 'error' => 'Quote visits need migration 1369 first.', 'needs_migration' => true]);
        exit;
    }
    $view = function (?array $row) use ($svc): ?array {
        if (!$row) return null;
        $a = $svc->find((int)$row['id']);
        if ($a) $a['label'] = AppointmentService::label($a['date'], $a['time']);
        return $a;
    };

    if ($mode === 'request') {
        $rid = (int)($_GET['request_id'] ?? 0);
        $s = $db->prepare('SELECT contact_id FROM quote_requests WHERE id = ?');
        $s->execute([$rid]);
        $contactId = (int)$s->fetchColumn();
        require_once CRM_INCLUDES . '/messaging.php';
        echo json_encode([
            'ok'          => true,
            'appointment' => $view($svc->forRequest($rid)),
            'people'      => $db->query('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll(PDO::FETCH_ASSOC),
            'me'          => $uid,
            'can_edit'    => $canEdit,
            'can_text'    => $contactId > 0 && hasSmConsent($contactId),
        ]);
        exit;
    }

    if ($mode === 'book') {
        $rid = (int)($input['request_id'] ?? 0);
        $r = $svc->bookForRequest($rid, [
            'date' => (string)($input['date'] ?? ''), 'time' => (string)($input['time'] ?? ''),
            'assigned_to' => (int)($input['assigned_to'] ?? 0), 'duration_minutes' => (int)($input['duration_minutes'] ?? 0),
            'notes' => (string)($input['notes'] ?? ''),
        ], $uid);
        if (!$r['ok']) { echo json_encode($r); exit; }
        $a = $view(['id' => $r['id']]);
        logActivityExtended($uid, $r['moved'] ? 'Quote visit moved' : 'Quote visit booked',
            ($a['label'] ?? '') . ' — request #' . $rid . ($a['assigned_name'] ? ' (' . $a['assigned_name'] . ')' : ''));
        echo json_encode(['ok' => true, 'moved' => $r['moved'], 'appointment' => $a]);
        exit;
    }

    if ($mode === 'status') {
        $id = (int)($input['id'] ?? 0);
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['cancelled', 'done'], true)) { echo json_encode(['ok' => false, 'error' => 'Unknown status']); exit; }
        $a = $svc->find($id);
        if (!$a) { echo json_encode(['ok' => false, 'error' => 'That visit no longer exists.']); exit; }
        $changed = $svc->setStatus($id, $status);
        if ($changed) logActivityExtended($uid, $status === 'done' ? 'Quote visit done' : 'Quote visit cancelled',
            AppointmentService::label($a['date'], $a['time']) . ' — request #' . (int)$a['quote_request_id']);
        echo json_encode(['ok' => true, 'changed' => $changed]);
        exit;
    }

    if ($mode === 'confirm') {
        $a = $svc->find((int)($input['id'] ?? 0));
        if (!$a || $a['status'] !== 'booked') { echo json_encode(['ok' => false, 'error' => 'That visit is not booked.']); exit; }
        require_once CRM_INCLUDES . '/messaging.php';
        require_once APP_ROOT . '/Services/Messaging/TemplateRenderer.php';
        $co = emailCompanyDetails();
        $company = (string)($co['name'] ?? '') ?: _idFromName();
        $phone = (string)($co['phone'] ?? '') ?: _idPhone();
        $sent = []; $problems = [];
        if (!empty($input['email'])) {
            if (!$a['contact_email']) $problems[] = 'no email address on file';
            else {
                $m = AppointmentService::confirmationEmail($a, $company, $phone);
                $r = sendEmail($a['contact_email'], $m['subject'], wrapInBrandedEmail($m['html']), null, $company);
                if (!empty($r['success'])) $sent[] = 'email'; else $problems[] = 'the email did not send';
            }
        }
        if (!empty($input['sms'])) {
            if (!$a['contact_phone']) $problems[] = 'no phone number on file';
            elseif (!$a['contact_id'] || !hasSmConsent((int)$a['contact_id'])) $problems[] = 'no consent to texts on file';
            else {
                $r = sendSms($a['contact_phone'], AppointmentService::confirmationSms($a, $company, $phone), $company);
                if (!empty($r['success'])) $sent[] = 'text'; else $problems[] = 'the text did not send';
            }
        }
        if ($sent) {
            $svc->markConfirmed((int)$a['appointment_id']);
            logActivityExtended($uid, 'Quote visit confirmation sent',
                implode(' and ', $sent) . ' to ' . ($a['contact_name'] ?? 'client') . ' — ' . AppointmentService::label($a['date'], $a['time']));
        }
        echo json_encode(['ok' => (bool)$sent, 'sent' => $sent, 'problems' => $problems,
                          'error' => $sent ? null : ($problems ? ucfirst(implode('; ', $problems)) . '.' : 'Pick email or text.')]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
} catch (Throwable $e) {
    error_log('[appointments] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong.']);
}
