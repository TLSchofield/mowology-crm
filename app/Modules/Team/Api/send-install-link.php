<?php
/**
 * Send Install Link API
 *
 * Sends an app install link to an employee via SMS and/or email.
 * Updates install_link_sent_at + install_link_sent_by on the user record.
 *
 * POST JSON:
 *   employee_id    int       required
 *   install_url    string    required — URL to the app install page
 *   send_email     int       0|1
 *   send_sms       int       0|1
 *   custom_message string    optional override body text
 *   csrf_token     string    required
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
    require_once APP_ROOT . '/Services/Messaging/MessagingService.php';

    requireLogin();
    $user = getCurrentUser();
    requirePermission('team.edit');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    $employeeId = (int)($input['employee_id'] ?? 0);
    if (!$employeeId) throw new Exception('Employee ID required');

    $installUrl = trim($input['install_url'] ?? '');
    if (!$installUrl) throw new Exception('Install URL is required');
    if (!filter_var($installUrl, FILTER_VALIDATE_URL)) throw new Exception('Install URL is not valid');

    $sendEmail = !empty($input['send_email']) ? (int)$input['send_email'] : 0;
    $sendSms   = !empty($input['send_sms'])   ? (int)$input['send_sms']   : 0;

    if (!$sendEmail && !$sendSms) {
        throw new Exception('Please select at least one delivery method (email or SMS)');
    }

    $db = getDB();

    // Load employee
    $stmt = $db->prepare("SELECT id, full_name, email, phone FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) throw new Exception('Employee not found or inactive');

    $firstName = explode(' ', trim($emp['full_name'] ?? 'there'))[0];

    // Build message body
    $customMessage = trim($input['custom_message'] ?? '');
    if (!$customMessage) {
        $customMessage = "Hi {$firstName}, please install the Mowology field app using the link below. Contact us if you need help.";
    }

    $results  = [];
    $anyError = false;

    // ── Send Email ─────────────────────────────────────────────────────────
    if ($sendEmail) {
        if (empty($emp['email'])) {
            $results['email'] = ['success' => false, 'error' => 'No email address on file'];
            $anyError = true;
        } else {
            $htmlBody = '
            <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">
                <div style="background:#1A5F4A;padding:20px 24px;border-radius:8px 8px 0 0;">
                    <h2 style="color:#fff;margin:0;font-size:20px;">Mowology Field App</h2>
                </div>
                <div style="padding:24px;background:#fff;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px;">
                    <p style="color:#333;">' . nl2br(htmlspecialchars($customMessage)) . '</p>
                    <p style="text-align:center;margin:28px 0;">
                        <a href="' . htmlspecialchars($installUrl) . '"
                           style="display:inline-block;padding:14px 28px;background:#2D8659;color:#fff;
                                  text-decoration:none;border-radius:6px;font-weight:600;font-size:16px;">
                            Install Mowology App
                        </a>
                    </p>
                    <p style="color:#888;font-size:13px;">Or copy this link: ' . htmlspecialchars($installUrl) . '</p>
                    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
                    <p style="color:#aaa;font-size:12px;margin:0;">
                        Sent by ' . htmlspecialchars($user['full_name'] ?? 'Admin') . ' via Mowology CRM
                    </p>
                </div>
            </div>';

            $emailResult = sendEmail(
                $emp['email'],
                'Install the Mowology Field App',
                $htmlBody
            );

            $results['email'] = $emailResult;
            if (!($emailResult['success'] ?? false)) $anyError = true;
        }
    }

    // ── Send SMS ───────────────────────────────────────────────────────────
    if ($sendSms) {
        if (empty($emp['phone'])) {
            $results['sms'] = ['success' => false, 'error' => 'No phone number on file'];
            $anyError = true;
        } else {
            // SMS must be short — strip to first sentence + URL
            $smsBody = "Mowology App Install: {$installUrl}";

            $smsResult = sendSms($emp['phone'], $smsBody);
            $results['sms'] = $smsResult;
            if (!($smsResult['success'] ?? false)) $anyError = true;
        }
    }

    // ── Update record ──────────────────────────────────────────────────────
    // Mark as sent even if one channel failed — partially delivered counts
    $anySuccess = false;
    foreach ($results as $r) {
        if ($r['success'] ?? false) { $anySuccess = true; break; }
    }

    if ($anySuccess) {
        $db->prepare("
            UPDATE users
            SET install_link_sent_at = NOW(),
                install_link_sent_by = ?
            WHERE id = ?
        ")->execute([$user['id'], $employeeId]);

        logActivity(
            $user['id'],
            null,
            'Sent install link to employee #' . $employeeId . ' (' . ($emp['full_name'] ?? '') . ')',
            'URL: ' . $installUrl . ' | email=' . ($sendEmail ? 'yes' : 'no') . ' sms=' . ($sendSms ? 'yes' : 'no')
        );
    }

    // ── Response ───────────────────────────────────────────────────────────
    if ($anySuccess && !$anyError) {
        $channels = [];
        if ($sendEmail && ($results['email']['success'] ?? false)) $channels[] = 'email';
        if ($sendSms   && ($results['sms']['success']   ?? false)) $channels[] = 'SMS';
        echo json_encode([
            'success' => true,
            'message' => 'Install link sent via ' . implode(' and ', $channels) . ' to ' . ($emp['full_name'] ?? 'employee'),
            'results' => $results,
        ]);
    } elseif ($anySuccess) {
        echo json_encode([
            'success' => true,
            'message' => 'Partially sent — check results for details',
            'results' => $results,
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Failed to send install link via any channel',
            'results' => $results,
        ]);
    }

} catch (PDOException $e) {
    error_log('Send install link DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'A database error occurred. Please try again.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
