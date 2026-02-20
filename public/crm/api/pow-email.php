<?php
/**
 * Proof of Work — Email Endpoint
 * ────────────────────────────────
 * Sends the PoW PDF to a recipient with a branded email body.
 * Logs every send attempt in pow_email_log.
 *
 * POST JSON body:
 *   { "visit_id": 42, "csrf_token": "...", "recipient": "client@example.com", "message": "..." }
 *
 * Returns: { "success": bool, "error"?: string }
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

    if (!userHasPermission('jobs.edit') && ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required to send PoW emails']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    $visitId   = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;
    $recipient = trim($input['recipient'] ?? '');
    $message   = trim($input['message'] ?? '');

    if ($visitId < 1) {
        throw new InvalidArgumentException('visit_id is required');
    }
    if (!$recipient || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Valid recipient email is required');
    }

    $db = getDB();

    // Load visit
    $stmt = $db->prepare("
        SELECT v.*, p.title AS plan_title, p.plan_number,
               pr.address AS property_address, pr.city AS property_city,
               c.first_name AS contact_first, c.last_name AS contact_last
        FROM job_visits v
        JOIN job_plans p ON v.plan_id = p.id
        LEFT JOIN properties pr ON p.property_id = pr.id
        LEFT JOIN contacts c ON pr.site_contact_id = c.id
        WHERE v.id = ?
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['error' => 'Visit not found']);
        exit;
    }

    if (empty($visit['pdf_path'])) {
        throw new RuntimeException('No PDF has been generated for this visit yet. Please generate the PDF first.');
    }

    $pdfServerPath = PUBLIC_ROOT . $visit['pdf_path'];
    if (!file_exists($pdfServerPath)) {
        // May be an HTML fallback
        $htmlPath = str_replace('.pdf', '.html', $pdfServerPath);
        if (file_exists($htmlPath)) {
            $pdfServerPath = $htmlPath;
        } else {
            throw new RuntimeException('PDF file not found on server. Please regenerate.');
        }
    }

    $visitNum    = $visit['visit_number'] ?? "Visit #{$visitId}";
    $clientName  = trim(($visit['contact_first']??'') . ' ' . ($visit['contact_last']??'')) ?: 'Valued Client';
    $address     = trim(($visit['property_address']??'') . ', ' . ($visit['property_city']??''));
    $planTitle   = $visit['plan_title'] ?? '';
    $visitDate   = $visit['started_at'] ? date('F j, Y', strtotime($visit['started_at'])) : $visit['scheduled_date'];

    // Build email HTML
    $customMsg = $message
        ? '<p>' . nl2br(htmlspecialchars($message)) . '</p>'
        : '<p>Please find your Proof of Work report attached for the service completed at your property.</p>';

    $siteUrl = defined('SITE_URL') ? SITE_URL : 'https://mowology.ca';
    $year    = date('Y');

    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;max-width:600px;">

        <!-- Header -->
        <tr><td style="background:#1A5F4A;padding:24px 32px;">
          <h1 style="color:#fff;margin:0;font-size:22px;font-weight:700;">Mowology Landscaping</h1>
          <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px;">Proof of Work — Service Report</p>
        </td></tr>

        <!-- Body -->
        <tr><td style="padding:28px 32px;">
          <p style="margin:0 0 12px;font-size:16px;color:#1a1a1a;">Dear {$clientName},</p>
          {$customMsg}
          <table style="width:100%;background:#f0fbf3;border:1px solid #c3e6cb;border-radius:6px;padding:16px;margin:16px 0;" cellpadding="0" cellspacing="0">
            <tr><td style="padding:4px 0;"><strong style="color:#1A5F4A;">Visit:</strong> {$visitNum}</td></tr>
            <tr><td style="padding:4px 0;"><strong style="color:#1A5F4A;">Date:</strong> {$visitDate}</td></tr>
            <tr><td style="padding:4px 0;"><strong style="color:#1A5F4A;">Service:</strong> {$planTitle}</td></tr>
            <tr><td style="padding:4px 0;"><strong style="color:#1A5F4A;">Property:</strong> {$address}</td></tr>
          </table>
          <p style="font-size:14px;color:#555;">The attached PDF contains your complete service report including GPS route data, photos, materials used, and a completion checklist.</p>
          <p style="font-size:14px;color:#555;">If you have any questions about the service performed, please don't hesitate to get in touch.</p>
          <p style="margin-top:24px;font-size:14px;color:#1a1a1a;">Thank you for trusting Mowology with your property.</p>
          <p style="font-size:14px;margin:0;"><strong>The Mowology Team</strong></p>
        </td></tr>

        <!-- Footer -->
        <tr><td style="background:#0D3B2E;padding:16px 32px;text-align:center;">
          <p style="color:rgba(255,255,255,0.6);font-size:11px;margin:0;">
            &copy; {$year} Mowology Landscaping &middot; <a href="{$siteUrl}" style="color:#7FD858;">{$siteUrl}</a>
          </p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $subject = "Your Proof of Work Report — {$visitNum} ({$visitDate})";

    $result = sendEmail($recipient, $subject, $htmlBody, $pdfServerPath, 'Mowology Landscaping');

    // Log the attempt
    $db->prepare("
        INSERT INTO pow_email_log (visit_id, sent_by, recipient, status, error_msg)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $visitId,
        $user['id'],
        $recipient,
        $result['success'] ? 'sent' : 'failed',
        $result['error'] ?? null,
    ]);

    // Also write to audit log
    $db->prepare("
        INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
        VALUES (?, ?, 'email_sent', ?, ?)
    ")->execute([
        $visitId,
        $user['id'],
        json_encode(['recipient' => $recipient, 'method' => $result['method'] ?? null, 'success' => $result['success']]),
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    if ($result['success']) {
        echo json_encode(['success' => true, 'method' => $result['method'] ?? 'email']);
    } else {
        echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Email delivery failed']);
    }

} catch (PDOException $e) {
    error_log('PoW Email API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('PoW Email API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
