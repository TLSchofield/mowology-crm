<?php
/**
 * Unified Messaging Module — Email + SMS Delivery
 *
 * Consolidates all CRM email and SMS delivery into a single reusable module.
 *
 * Email: PHPMailer SMTP (primary) with native mail() fallback
 * SMS:   Canadian carrier email-to-SMS gateways via mail()
 *
 * SMTP credentials loaded from secrets.php (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS)
 * From address: no-reply@mowology.ca
 * Reply-To: office@mowology.ca
 *
 * Usage:
 *   require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
 *
 *   $result = sendEmail($to, $subject, $htmlBody);
 *   // $result = ['success' => true, 'method' => 'PHPMailer SMTP', 'error' => null]
 *
 *   $result = sendSms($phone, $message);
 *   // $result = ['success' => true, 'carrier' => 'Bell', 'attempts' => 1, 'errors' => []]
 *
 * Migrated from: public/crm/includes/messaging.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__, 2) . '/Core/paths.php';
}

require_once APP_ROOT . '/Services/Messaging/EmailLogger.php';

// ─── Canadian Carrier Email-to-SMS Gateways ─────────────────────────

const CANADIAN_SMS_GATEWAYS = [
    'Bell'      => 'txt.bellmobility.ca',
    'Rogers'    => 'sms.rogers.com',
    'Telus'     => 'msg.telus.com',
    'Koodo'     => 'msg.koodomobile.com',
    'Virgin'    => 'sms.virginmobile.ca',
    'Fido'      => 'sms.fido.ca',
    'Freedom'   => 'sms.freedommobile.ca',
    'PC Mobile' => 'sms.pcmobilecanada.com',
    'Eastlink'  => 'sms.eastlinktelecom.com',
    'SaskTel'   => 'sms.sasktel.com',
];


// ═══════════════════════════════════════════════════════════════════════
// PUBLIC API
// ═══════════════════════════════════════════════════════════════════════

/**
 * Send an HTML email via PHPMailer SMTP with mail() fallback.
 *
 * @param string      $to             Recipient email
 * @param string      $subject        Subject line
 * @param string      $htmlBody       HTML body content
 * @param string|null $attachmentPath Filesystem path to attachment (or null)
 * @param string      $fromName       Display name (default: 'Mowology')
 * @return array      ['success' => bool, 'method' => string, 'error' => string|null]
 */
/**
 * Strip CR/LF from header name/value to prevent header injection.
 * Extra headers originate from our own code, but the unsubscribe URL
 * embeds a contact email — defence in depth.
 *
 * @param array<string,string> $headers
 * @return array<string,string>
 */
function _sanitizeExtraHeaders(array $headers): array
{
    $clean = [];
    foreach ($headers as $name => $value) {
        $name  = trim(str_replace(["\r", "\n"], '', (string)$name));
        $value = trim(str_replace(["\r", "\n"], '', (string)$value));
        if ($name !== '' && $value !== '') {
            $clean[$name] = $value;
        }
    }
    return $clean;
}

function sendEmail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath = null,
    string $fromName = 'Mowology',
    array $extraHeaders = []
): array {
    // Validate email
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'method' => 'none', 'error' => "Invalid recipient email: {$to}"];
    }

    $extraHeaders = _sanitizeExtraHeaders($extraHeaders);

    // Validate attachment exists
    if ($attachmentPath && !file_exists($attachmentPath)) {
        error_log("sendEmail: attachment not found: {$attachmentPath}, sending without it");
        $attachmentPath = null;
    }

    $subject  = trim($subject);
    $htmlBody = trim($htmlBody);

    // Try PHPMailer SMTP first
    $phpMailerResult = _sendViaPhpMailer($to, $subject, $htmlBody, $attachmentPath, $fromName, $extraHeaders);

    if ($phpMailerResult !== null) {
        // PHPMailer was available and attempted the send
        return [
            'success' => $phpMailerResult['success'],
            'method'  => 'PHPMailer SMTP',
            'error'   => $phpMailerResult['error'] ?? null,
        ];
    }

    // Fallback: native mail()
    error_log("PHPMailer unavailable — falling back to native mail()");

    if ($attachmentPath) {
        $result = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName, $extraHeaders);
    } else {
        $result = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName, $extraHeaders);
    }

    return [
        'success' => $result,
        'method'  => 'native mail()',
        'error'   => $result ? null : 'mail() returned false',
    ];
}

/**
 * Send SMS via Canadian carrier email-to-SMS gateways.
 *
 * Tries carriers in order and stops after the first one accepts the message.
 *
 * @param string $phone      Phone number (any format — digits extracted)
 * @param string $message    SMS text (truncated to 160 chars)
 * @param string $senderName From display name (default: 'Mowology')
 * @return array ['success' => bool, 'carrier' => string|null, 'attempts' => int, 'errors' => string[]]
 */
function sendSms(
    string $phone,
    string $message,
    string $senderName = 'Mowology'
): array {
    // Normalize phone: extract digits only
    $cleanPhone = preg_replace('/\D/', '', $phone);

    // Strip leading country code 1
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
        $cleanPhone = substr($cleanPhone, 1);
    }

    if (strlen($cleanPhone) !== 10) {
        return [
            'success' => false,
            'carrier' => null,
            'attempts' => 0,
            'errors' => ['Invalid phone number format. Expected 10 digits (North American).'],
        ];
    }

    if (empty($message)) {
        return [
            'success' => false,
            'carrier' => null,
            'attempts' => 0,
            'errors' => ['Message cannot be empty'],
        ];
    }

    // Truncate to SMS limit
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }

    $attempts = 0;
    $errors = [];
    $sentCarriers = [];
    $fromEmail = 'no-reply@mowology.ca';

    // Use native mail() for SMS gateways — NOT PHPMailer.
    // PHPMailer adds MIME headers (Content-Type, MIME-Version, Message-ID, etc.)
    // that carrier email-to-SMS gateways reject. Native mail() with minimal
    // headers is exactly what worked in the diagnostics test yesterday.
    // Send to ALL carriers since we can't detect which one the recipient uses.
    foreach (CANADIAN_SMS_GATEWAYS as $carrierName => $smsDomain) {
        $attempts++;
        $smsRecipient = $cleanPhone . '@' . $smsDomain;

        try {
            $headers = "From: {$senderName} <{$fromEmail}>\r\n"
                     . "X-Mailer: Mowology SMS Gateway\r\n";

            $result = @mail($smsRecipient, '', $message, $headers);

            if ($result) {
                $sentCarriers[] = $carrierName;
            } else {
                $errors[] = "{$carrierName}: mail() returned false";
            }
        } catch (\Throwable $e) {
            $errors[] = "{$carrierName}: " . $e->getMessage();
        }
    }

    $success = !empty($sentCarriers);

    if ($success) {
        error_log("SMS: sent to {$phone} via " . implode(', ', $sentCarriers));
    } else {
        error_log("SMS: failed to send to {$phone} — all carriers rejected");
    }

    return [
        'success' => $success,
        'carrier' => $success ? $sentCarriers[0] : null,
        'attempts' => $attempts,
        'errors' => $errors,
    ];
}


// ═══════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════

/**
 * Check if a contact has SMS consent.
 *
 * @param int $contactId Contact ID from contacts table
 * @return bool True if customer consented to SMS
 */
function hasSmConsent(int $contactId): bool
{
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT receive_sms, consent_sms
            FROM contacts
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            return false;
        }

        return !empty($contact['receive_sms']) || !empty($contact['consent_sms']);
    } catch (\Throwable $e) {
        error_log("SMS consent check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a company prefers PDF attachments on emails.
 *
 * @param int $companyId
 * @return bool
 */
function companyPrefersAttachment(int $companyId): bool
{
    if (!$companyId) {
        return true;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT pref_attach_pdf FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool)($row['pref_attach_pdf'] ?? 1);
    } catch (\PDOException $e) {
        return true;
    }
}

/**
 * Send a quote notification SMS with a shortened URL.
 *
 * @param string $phoneNumber
 * @param string $quoteNumber
 * @param string $quoteUrl
 * @param string $companyName
 * @return array Result from sendSms()
 */
function sendQuoteNotificationSms(
    string $phoneNumber,
    string $quoteNumber,
    string $quoteUrl,
    string $companyName = 'Mowology'
): array {
    $shortUrl = parse_url($quoteUrl, PHP_URL_HOST) . '/quote';

    $message = "Hi! Your quote {$quoteNumber} from {$companyName} is ready. "
             . "View and accept it here: {$shortUrl}";

    if (strlen($message) > 160) {
        $message = "Your quote {$quoteNumber} from {$companyName} is ready. "
                 . "Visit your account to view: {$shortUrl}";
    }

    return sendSms($phoneNumber, $message, $companyName);
}

/**
 * Send an invoice notification SMS.
 *
 * NO URLs in the message body — carrier email-to-SMS gateways silently drop
 * messages containing links. Direct the customer to check their email instead.
 *
 * @param string $phoneNumber
 * @param string $invoiceNumber  e.g. INV-2026-0001
 * @param float  $balanceDue
 * @return array Result from sendSms()
 */
function sendInvoiceNotificationSms(
    string $phoneNumber,
    string $invoiceNumber,
    float  $balanceDue
): array {
    $amount  = '$' . number_format($balanceDue, 2);
    $message = "Mowology: Invoice {$invoiceNumber} for {$amount} is ready. Check your email to view and pay. Questions? Call (778) 846-9273.";

    if (strlen($message) > 160) {
        $message = "Mowology: Invoice {$invoiceNumber} ({$amount} due). Check your email to pay. Call (778) 846-9273.";
    }

    return sendSms($phoneNumber, $message, 'Mowology');
}

/**
 * Send a fertilizer application completion notification to the customer.
 *
 * Fires automatically when a visit on a prepaid bundle plan is marked complete.
 * Sends both SMS (plain text, no URLs) and a rich HTML email with:
 *  - Product icon, application details, auto-calculated materials
 *  - Visit photos (before/after)
 *  - GPS arrival/departure timestamps
 *  - Bundle progress indicator
 *  - Link to customer proof-of-work portal
 *
 * Guards against double-send via job_visits.notification_sent_at.
 *
 * @param int $visitId
 * @return bool True if both SMS and email were sent
 */
function sendFertilizerCompletionNotification(int $visitId): bool
{
    $db = getDB();

    // ── Load visit + plan + contact + product ────────────────────
    $stmt = $db->prepare("
        SELECT v.*,
               jp.plan_number, jp.title AS plan_title, jp.quote_id,
               jp.is_prepaid_bundle, jp.source_bundle_id,
               jp.bundle_applications_used, jp.property_id,
               p.address AS property_address, p.city AS property_city,
               p.province AS property_province,
               COALESCE(ct.first_name, '') AS contact_first,
               COALESCE(ct.last_name, '')  AS contact_last,
               COALESCE(ct.email, '')       AS contact_email,
               COALESCE(ct.phone, COALESCE(ct.mobile, '')) AS contact_phone,
               q.access_token,
               pr.name AS product_name, pr.application_notes,
               pr.icon_base_path, pr.is_sold AS product_is_sold
        FROM job_visits v
        JOIN job_plans jp ON v.plan_id = jp.id
        LEFT JOIN properties p ON jp.property_id = p.id
        LEFT JOIN contacts ct ON p.site_contact_id = ct.id
        LEFT JOIN quotes q ON jp.quote_id = q.id
        LEFT JOIN plan_line_items pli ON pli.plan_id = jp.id
            AND pli.product_id IS NOT NULL
        LEFT JOIN products pr ON pli.product_id = pr.id
        WHERE v.id = ?
        LIMIT 1
    ");
    $stmt->execute([$visitId]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$visit) return false;

    // Guard: already sent
    if (!empty($visit['notification_sent_at'])) return false;

    // Guard: no contact email
    if (empty($visit['contact_email'])) return false;

    // ── Load photos ───────────────────────────────────────────────
    $photoStmt = $db->prepare("
        SELECT filename, photo_type, caption
        FROM visit_photos
        WHERE visit_id = ?
          AND photo_type IN ('before', 'after', 'during', 'label')
        ORDER BY FIELD(photo_type,'before','label','during','after'), id ASC
        LIMIT 6
    ");
    $photoStmt->execute([$visitId]);
    $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Load all visits in this plan for progress indicator ───────
    $allVisitsStmt = $db->prepare("
        SELECT id, scheduled_date, status, sequence_index
        FROM job_visits
        WHERE plan_id = ?
        ORDER BY sequence_index ASC, scheduled_date ASC
    ");
    $allVisitsStmt->execute([$visit['plan_id']]);
    $allVisits = $allVisitsStmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Parse materials ───────────────────────────────────────────
    $materials = [];
    if (!empty($visit['materials_json'])) {
        $materials = json_decode($visit['materials_json'], true) ?: [];
    }

    // ── Build visit label ─────────────────────────────────────────
    $visitLabel = $visit['plan_title'] ?: 'Fertilizer Application';
    if (!empty($visit['sequence_index'])) {
        $visitLabel .= ' — Application ' . $visit['sequence_index'];
    }

    // ── Format GPS timestamps ─────────────────────────────────────
    $arrivalTime   = '';
    $departureTime = '';
    if (!empty($visit['started_at'])) {
        $arrivalTime = date('g:ia', strtotime($visit['started_at']));
    }
    if (!empty($visit['completed_at'])) {
        $departureTime = date('g:ia', strtotime($visit['completed_at']));
    }

    // Duration
    $durationStr = '';
    if (!empty($visit['actual_duration_minutes']) && $visit['actual_duration_minutes'] > 0) {
        $mins = (int)$visit['actual_duration_minutes'];
        $durationStr = $mins >= 60
            ? floor($mins / 60) . 'h ' . ($mins % 60) . 'm'
            : $mins . 'min';
    }

    // ── Build portal URL ──────────────────────────────────────────
    $portalUrl  = '';
    $siteUrl    = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';
    if (!empty($visit['access_token'])) {
        $portalUrl = $siteUrl . '/customer/pow.php?token=' . urlencode($visit['access_token']) . '&visit_id=' . $visitId;
    }

    // ── Build bundle progress dots ────────────────────────────────
    $totalApps    = count($allVisits);
    $completedApp = (int)($visit['bundle_applications_used'] ?? 0); // will be incremented AFTER this call
    $progressDots = '';
    for ($i = 0; $i < $totalApps; $i++) {
        $progressDots .= $i < $completedApp ? '●' : '○';
    }
    // Next application
    $nextAppLabel = '';
    foreach ($allVisits as $av) {
        if ($av['status'] === 'scheduled') {
            $nextAppLabel = date('F', strtotime($av['scheduled_date']));
            break;
        }
    }

    // ── Product icon URL ──────────────────────────────────────────
    $iconHtml = '';
    if (!empty($visit['icon_base_path'])) {
        $isSold    = !empty($visit['product_is_sold']);
        $iconFile  = $isSold ? 'icon-full.png' : 'icon-grey.png';
        $iconPath  = rtrim($visit['icon_base_path'], '/') . '/' . $iconFile;
        $iconUrl   = $siteUrl . '/' . ltrim($iconPath, '/');
        $iconHtml  = '<img src="' . htmlspecialchars($iconUrl) . '" alt="" style="width:64px;height:64px;object-fit:contain;" onerror="this.style.display=\'none\'">';
    }

    // ── Build photos HTML ─────────────────────────────────────────
    $photosHtml = '';
    if (!empty($photos)) {
        $photosHtml = '<tr><td colspan="2" style="padding:16px 0 8px;"><strong style="color:#1A5F4A;">Photos</strong></td></tr>
        <tr><td colspan="2" style="padding-bottom:16px;">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">';
        foreach ($photos as $photo) {
            $photoUrl = $siteUrl . '/uploads/photos/' . rawurlencode($photo['filename']);
            $photosHtml .= '<img src="' . htmlspecialchars($photoUrl) . '" alt="' . htmlspecialchars($photo['photo_type']) . '" style="width:120px;height:90px;object-fit:cover;border-radius:6px;" onerror="this.style.display=\'none\'">';
        }
        $photosHtml .= '</div></td></tr>';
    }

    // ── Build materials HTML ──────────────────────────────────────
    $materialsHtml = '';
    if (!empty($materials)) {
        $materialsHtml = '<tr><td colspan="2" style="padding:16px 0 8px;"><strong style="color:#1A5F4A;">What We Applied</strong></td></tr>';
        foreach ($materials as $mat) {
            $materialsHtml .= '<tr>
              <td style="padding:4px 0;color:#555;">' . htmlspecialchars($mat['product_name'] ?? '') . '</td>
              <td style="padding:4px 0;text-align:right;color:#333;"><strong>'
                . htmlspecialchars($mat['qty'] . ' ' . ($mat['unit'] ?? 'bags'))
                . '</strong>'
                . (!empty($mat['area_sqft']) ? '<br><small style="color:#888;">' . number_format($mat['area_sqft']) . ' sq ft</small>' : '')
              . '</td>
            </tr>';
        }
    } elseif (!empty($visit['product_name'])) {
        $materialsHtml = '<tr><td colspan="2" style="padding:16px 0 8px;"><strong style="color:#1A5F4A;">What We Applied</strong></td></tr>
        <tr><td style="padding:4px 0;color:#555;">' . htmlspecialchars($visit['product_name']) . '</td><td></td></tr>';
    }

    // ── Progress section ──────────────────────────────────────────
    $progressHtml = '';
    if ($totalApps > 1) {
        $progressHtml = '<tr><td colspan="2" style="padding:16px 0 0;">
          <div style="background:#f0f7f4;border-radius:8px;padding:12px 16px;">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px;color:#666;margin-bottom:4px;">Your Program</div>
            <div style="font-size:22px;letter-spacing:4px;color:#2D8659;">' . $progressDots . '</div>
            <div style="font-size:13px;color:#555;margin-top:4px;">Application ' . $completedApp . ' of ' . $totalApps . ' complete'
                . ($nextAppLabel ? ' · Next: ' . $nextAppLabel : '') . '</div>
          </div>
        </td></tr>';
    }

    // ── GPS / time section ────────────────────────────────────────
    $gpsHtml = '';
    if ($arrivalTime || $departureTime) {
        $gpsHtml = '<tr><td colspan="2" style="padding:16px 0 8px;"><strong style="color:#1A5F4A;">We Were There ✓</strong></td></tr>
        <tr><td colspan="2" style="padding:4px 0;color:#555;font-size:13px;">';
        if ($arrivalTime)   $gpsHtml .= 'Arrived <strong>' . $arrivalTime . '</strong>';
        if ($arrivalTime && $departureTime) $gpsHtml .= ' &middot; ';
        if ($departureTime) $gpsHtml .= 'Departed <strong>' . $departureTime . '</strong>';
        if ($durationStr)   $gpsHtml .= ' <span style="color:#888;">(' . $durationStr . ')</span>';
        if (!empty($visit['property_address'])) {
            $gpsHtml .= '<br>' . htmlspecialchars($visit['property_address'] . ', ' . ($visit['property_city'] ?? ''));
        }
        $gpsHtml .= '</td></tr>';
    }

    // ── Ensure EmailWrapper is available ──────────────────────────
    if (!class_exists('EmailWrapper')) {
        $wrapperPath = __DIR__ . '/EmailWrapper.php';
        if (is_file($wrapperPath)) require_once $wrapperPath;
    }
    $companyInfo = class_exists('EmailWrapper') ? EmailWrapper::getCompanyInfo() : [
        'company_name'    => 'Mowology Landscaping',
        'company_phone'   => '(778) 846-9273',
        'company_email'   => 'office@mowology.ca',
        'company_website' => 'https://mowology.ca',
    ];

    // ── Visit summary card (replaces old custom dark header) ─────
    $visitDate    = date('l, F j, Y');
    $propertyLine = trim(($visit['property_address'] ?? '') . ($visit['property_city'] ? ', ' . $visit['property_city'] : ''));
    $iconInCard   = $iconHtml ? '<div style="margin-bottom:10px;">' . $iconHtml . '</div>' : '';

    $summaryCard = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
      <tr><td style="background:#E8F3F0;border-left:4px solid #2D8659;border-radius:4px;padding:14px 18px;">
        ' . $iconInCard . '
        <p style="margin:0;font-size:16px;font-weight:700;color:#1A5F4A;font-family:\'Helvetica Neue\',Arial,sans-serif;">' . htmlspecialchars($visitLabel) . '</p>
        <p style="margin:4px 0 0;font-size:13px;color:#555;font-family:\'Helvetica Neue\',Arial,sans-serif;">'
          . $visitDate
          . ($propertyLine ? ' &middot; ' . htmlspecialchars($propertyLine) : '')
        . '</p>
      </td></tr>
    </table>';

    // ── Compose body content (materials, photos, GPS, progress) ──
    $greeting = '<p style="margin:0 0 8px;color:#555;font-family:\'Helvetica Neue\',Arial,sans-serif;">Hi ' . htmlspecialchars($visit['contact_first']) . ',</p>'
              . '<p style="margin:0 0 20px;color:#555;font-family:\'Helvetica Neue\',Arial,sans-serif;">Your lawn application has been completed. Here\'s a summary of what was done today.</p>';

    $detailTable = '<table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #eee;">'
        . $materialsHtml
        . $photosHtml
        . $gpsHtml
        . $progressHtml
        . '</table>';

    $customBody = $summaryCard . $greeting . $detailTable;

    // ── Wrap in EmailWrapper (consistent branded shell) ───────────
    $htmlBody = class_exists('EmailWrapper')
        ? EmailWrapper::wrap($customBody, 'View Full Report', $portalUrl ?: 'https://mowology.ca', $companyInfo)
        : $customBody; // fallback: bare content (should never happen in production)

    // ── Send email ────────────────────────────────────────────────
    $emailResult = sendEmail(
        $visit['contact_email'],
        'Your ' . $visitLabel . ' is Complete!',
        $htmlBody
    );

    // ── Send SMS (no URL, 160 chars) ──────────────────────────────
    $smsResult = ['success' => false];
    if (!empty($visit['contact_phone'])) {
        $smsMsg = 'Mowology: Your ' . ($visit['plan_title'] ?: 'fertilizer application') . ' is done! Check your email for photos and details. Questions? (778) 846-9273';
        if (strlen($smsMsg) > 160) {
            $smsMsg = 'Mowology: Lawn application complete! Check your email for the report. (778) 846-9273';
        }
        $smsResult = sendSms($visit['contact_phone'], $smsMsg);
    }

    // ── Mark notification sent ────────────────────────────────────
    if ($emailResult['success'] || $smsResult['success']) {
        $db->prepare("UPDATE job_visits SET notification_sent_at = NOW() WHERE id = ?")
           ->execute([$visitId]);
    }

    return $emailResult['success'];
}

/**
 * Send a job-completion notification for a non-fertilizer (general) service visit.
 *
 * Fires for every non-prepaid-bundle visit marked complete.
 * Fertilizer (prepaid-bundle) completions are handled by sendFertilizerCompletionNotification().
 *
 * @param int $visitId
 * @return bool True if the email was sent successfully
 */
if (!function_exists('sendJobCompleteNotification')) {
    function sendJobCompleteNotification(int $visitId): bool
    {
        $db = getDB();

        // Load visit + plan + property + contact in one query
        $stmt = $db->prepare("
            SELECT jv.id, jv.plan_id, jv.customer_token, jv.notification_sent_at,
                   jv.completed_at, jv.scheduled_date,
                   jp.service_type, jp.title AS plan_title, jp.is_prepaid_bundle,
                   p.address AS property_address, p.city AS property_city,
                   c.email    AS contact_email,
                   c.first_name AS contact_first,
                   c.last_name  AS contact_last
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            LEFT JOIN properties p  ON jp.property_id = p.id
            LEFT JOIN contacts   c  ON p.site_contact_id = c.id
            WHERE jv.id = ?
            LIMIT 1
        ");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) return false;

        // Guard: only for non-prepaid-bundle plans (fertilizer handles those)
        if (!empty($visit['is_prepaid_bundle'])) return false;

        // Guard: already sent
        if (!empty($visit['notification_sent_at'])) return false;

        // Guard: no contact email — no-op, not an error
        if (empty($visit['contact_email'])) return false;

        // Ensure EmailWrapper is available
        if (!class_exists('EmailWrapper')) {
            $wrapperPath = __DIR__ . '/EmailWrapper.php';
            if (is_file($wrapperPath)) require_once $wrapperPath;
        }

        // Build display values
        $completedAt     = !empty($visit['completed_at']) ? $visit['completed_at'] : ($visit['scheduled_date'] ?? 'now');
        $jobDate         = date('F j, Y', strtotime($completedAt));
        $serviceType     = $visit['service_type'] ?: ($visit['plan_title'] ?: 'Service');
        $propertyAddress = trim(($visit['property_address'] ?? '') . ($visit['property_city'] ? ', ' . $visit['property_city'] : ''));

        $companyInfo = class_exists('EmailWrapper') ? EmailWrapper::getCompanyInfo() : [
            'company_name'  => 'Mowology Landscaping',
            'company_phone' => '(778) 846-9273',
            'company_email' => 'office@mowology.ca',
            'company_website' => 'https://mowology.ca',
        ];

        $tplVars = [
            '{{customer_first_name}}' => $visit['contact_first'] ?: 'there',
            '{{customer_name}}'       => trim(($visit['contact_first'] ?? '') . ' ' . ($visit['contact_last'] ?? '')) ?: 'Valued Customer',
            '{{service_type}}'        => $serviceType,
            '{{job_date}}'            => $jobDate,
            '{{property_address}}'    => $propertyAddress ?: 'your property',
            '{{company_name}}'        => $companyInfo['company_name'],
            '{{company_phone}}'       => $companyInfo['company_phone'],
        ];

        $tpl = loadEmailTemplate('job_complete', $tplVars);

        // Build portal URL
        $siteUrl    = defined('SITE_URL') ? rtrim(SITE_URL, '/') : 'https://mowology.ca';
        $portalUrl  = !empty($visit['customer_token'])
            ? $siteUrl . '/customer/pow.php?token=' . urlencode($visit['customer_token'])
            : $siteUrl;

        $emailBody = class_exists('EmailWrapper')
            ? EmailWrapper::wrap($tpl['body_html'], 'View Service Report', $portalUrl, $companyInfo)
            : $tpl['body_html'];

        $result = sendEmail($visit['contact_email'], $tpl['subject'], $emailBody);

        // Mark notification sent
        if ($result['success']) {
            $db->prepare("UPDATE job_visits SET notification_sent_at = NOW() WHERE id = ?")
               ->execute([$visitId]);
        }

        return $result['success'];
    }
}

/**
 * Test email configuration — sends a test email.
 */
function testEmailConfig(string $testEmail): array
{
    $subject = "Mowology CRM Email Test";
    $body = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2>Email Configuration Test</h2>
            <p>If you received this email, the email system is working correctly.</p>
            <p><strong>Method:</strong> " . (_loadPhpMailer() ? 'PHPMailer SMTP' : 'Native mail()') . "</p>
            <p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>
            <p><strong>Server:</strong> " . gethostname() . "</p>
        </body>
        </html>
    ";

    $result = sendEmail($testEmail, $subject, $body);

    return [
        'success'   => $result['success'],
        'method'    => $result['method'],
        'timestamp' => date('Y-m-d H:i:s'),
        'recipient' => $testEmail,
        'message'   => $result['success'] ? 'Test email sent successfully' : ('Test email failed: ' . ($result['error'] ?? 'unknown')),
    ];
}

/**
 * Test SMS gateway to a specific carrier.
 */
function testSmsGateway(string $phoneNumber, string $carrierName = 'all'): array
{
    $message = "Test SMS from Mowology at " . date('H:i:s');

    if ($carrierName !== 'all' && isset(CANADIAN_SMS_GATEWAYS[$carrierName])) {
        $cleanPhone = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '1') {
            $cleanPhone = substr($cleanPhone, 1);
        }

        $domain = CANADIAN_SMS_GATEWAYS[$carrierName];
        $smsRecipient = $cleanPhone . '@' . $domain;

        $result = mail($smsRecipient, "Test", $message, "From: Mowology <no-reply@mowology.ca>\r\n");

        return [
            'success' => $result,
            'carrier' => $carrierName,
            'recipient' => $smsRecipient,
            'message' => $result ? "Test sent successfully" : "Test failed",
        ];
    }

    return sendSms($phoneNumber, $message);
}


// ═══════════════════════════════════════════════════════════════════════
// EMAIL TEMPLATE SYSTEM
// ═══════════════════════════════════════════════════════════════════════

/**
 * Load an email template from the database, substitute {{placeholders}},
 * and return the subject + rendered HTML body ready to pass to sendEmail().
 *
 * Falls back to hardcoded defaults if the DB lookup fails — sending must
 * never break because of a missing template.
 *
 * @param string $key   Template key: 'quote_sent', 'invoice_sent', 'receipt_sent', 'job_complete'
 * @param array  $vars  Placeholder map, e.g. ['{{customer_first_name}}' => 'Alex', ...]
 * @return array        ['subject' => string, 'body_html' => string]
 */
if (!function_exists('loadEmailTemplate')) {
    function loadEmailTemplate(string $key, array $vars): array
    {
        // Ensure EmailWrapper is available
        if (!class_exists('EmailWrapper')) {
            $wrapperPath = __DIR__ . '/EmailWrapper.php';
            if (is_file($wrapperPath)) {
                require_once $wrapperPath;
            }
        }

        // Hardcoded fallbacks (used if DB is unreachable or table doesn't exist yet)
        $fallbacks = [
            'quote_sent' => [
                'subject'  => 'Your quote {{quote_number}} from Mowology is ready',
                'body'     => "Hi {{customer_first_name}},\n\nThank you for reaching out! Your quote {{quote_number}} for {{quote_amount}} is ready to review.\n\nPlease click the button below to view and accept your quote.\n\nWe look forward to working with you!\n\n{{company_name}}\n{{company_phone}}",
            ],
            'quote_followup' => [
                'subject'  => 'Following up on your Mowology quote ({{quote_number}})',
                'body'     => "Hi {{customer_first_name}},\n\nJust following up on quote {{quote_number}} for {{quote_amount}} we sent over. We want to make sure it reached you — sometimes these land in junk mail!\n\nYour quote is valid until {{quote_valid_until}}. Click the button below to review and accept it — it only takes a moment.\n\nIf you have any questions or want to adjust anything, don't hesitate to reach out. We'd love to earn your business!\n\n{{company_name}}\n{{company_phone}}",
            ],
            'invoice_sent' => [
                'subject'  => 'Invoice {{invoice_number}} from Mowology — {{amount_due}} due',
                'body'     => "Hi {{customer_first_name}},\n\nYour invoice {{invoice_number}} for {{amount_due}} is due on {{due_date}}.\n\nPlease click the button below to view and pay your invoice online.\n\nThank you for your business!\n\n{{company_name}}\n{{company_phone}}",
            ],
            'receipt_sent' => [
                'subject'  => 'Payment received — Thank you, {{customer_first_name}}!',
                'body'     => "Hi {{customer_first_name}},\n\nGreat news — we've received your payment of {{amount_paid}} on {{payment_date}} for invoice {{invoice_number}}.\n\nYour receipt is available online via the link below. Thank you for your business!\n\n{{company_name}}\n{{company_phone}}",
            ],
            'job_complete' => [
                'subject'  => 'Your {{service_type}} service is complete — {{job_date}}',
                'body'     => "Hi {{customer_first_name}},\n\nYour {{service_type}} service at {{property_address}} has been completed.\n\nClick the button below to view your service report.\n\nThank you for choosing Mowology!\n\n{{company_name}}\n{{company_phone}}",
            ],
        ];

        $subject  = $fallbacks[$key]['subject'] ?? 'Message from Mowology';
        $bodyText = $fallbacks[$key]['body']    ?? 'Thank you for choosing Mowology.';

        // Attempt DB lookup
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT subject, body_text FROM email_templates WHERE template_key = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $subject  = $row['subject'];
                $bodyText = $row['body_text'];
            }
        } catch (Throwable $e) {
            // DB error — use fallback silently
        }

        // Substitute {{placeholders}} in both subject and body
        $subject  = str_replace(array_keys($vars), array_values($vars), $subject);
        $bodyText = str_replace(array_keys($vars), array_values($vars), $bodyText);

        // Convert plain text to HTML paragraphs
        $bodyHtml = class_exists('EmailWrapper')
            ? EmailWrapper::textToHtml($bodyText)
            : '<p>' . nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')) . '</p>';

        return [
            'subject'   => $subject,
            'body_html' => $bodyHtml,
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════
// BACKWARD COMPATIBILITY ALIASES
// ═══════════════════════════════════════════════════════════════════════

if (!function_exists('sendCrmEmail')) {
    /**
     * Backward-compatible alias for sendEmail().
     * Returns bool to match the original signature.
     */
    function sendCrmEmail(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $attachmentPath = null,
        string $from = 'Mowology',
        array $extraHeaders = []
    ): bool {
        $result = sendEmail($to, $subject, $htmlBody, $attachmentPath, $from, $extraHeaders);
        return $result['success'];
    }
}

if (!function_exists('sendEmailViaSMTP')) {
    /**
     * Backward-compatible alias for sendEmail().
     * Returns bool to match the original signature.
     */
    function sendEmailViaSMTP(
        string $to,
        string $subject,
        string $htmlBody,
        ?string $attachmentPath = null,
        string $from = 'Mowology'
    ): bool {
        $result = sendEmail($to, $subject, $htmlBody, $attachmentPath, $from);
        return $result['success'];
    }
}

if (!function_exists('sendSmsViaMail')) {
    /**
     * Backward-compatible alias for sendSms().
     * Returns the same array shape as the original sms_gateway.php.
     */
    function sendSmsViaMail(
        string $phoneNumber,
        string $message,
        string $senderName = 'Mowology'
    ): array {
        $result = sendSms($phoneNumber, $message, $senderName);

        // Map to original return shape
        return [
            'success' => $result['success'],
            'attempts' => $result['attempts'],
            'delivered_carriers' => $result['carrier'] ? [$result['carrier']] : [],
            'errors' => $result['errors'],
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════
// INTERNAL: PHPMailer SMTP
// ═══════════════════════════════════════════════════════════════════════

/**
 * Load PHPMailer vendor files.
 * @return bool True if PHPMailer is available
 */
function _loadPhpMailer(): bool
{
    static $loaded = null;

    if ($loaded !== null) {
        return $loaded;
    }

    $possibleBases = [
        PUBLIC_ROOT . '/crm/vendor/phpmailer/src',
        PUBLIC_ROOT . '/vendor/phpmailer/src',
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
        dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/vendor/phpmailer/src',
    ];

    foreach ($possibleBases as $base) {
        if (file_exists($base . '/PHPMailer.php')) {
            require_once $base . '/Exception.php';
            require_once $base . '/PHPMailer.php';
            require_once $base . '/SMTP.php';
            $loaded = true;
            return true;
        }
    }

    $loaded = false;
    return false;
}

/**
 * Create a pre-configured PHPMailer instance.
 *
 * @return \PHPMailer\PHPMailer\PHPMailer|null
 */
function _createMailer(): ?\PHPMailer\PHPMailer\PHPMailer
{
    if (!_loadPhpMailer()) {
        return null;
    }

    $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
    $smtpPort = defined('SMTP_PORT') ? (int)SMTP_PORT : null;
    $smtpUser = defined('SMTP_USER') ? SMTP_USER : null;
    $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : null;

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        return null;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string)$smtpHost;
        $mail->Port       = $smtpPort ?: 465;
        $mail->SMTPAuth   = true;
        $mail->Username   = (string)$smtpUser;
        $mail->Password   = (string)$smtpPass;
        $mail->SMTPSecure = ($mail->Port === 465)
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';
        $mail->Encoding   = '8bit';
        $mail->XMailer    = 'Mowology CRM';

        $mail->setFrom('no-reply@mowology.ca', 'Mowology');
        $mail->addReplyTo('office@mowology.ca', 'Mowology');

        return $mail;
    } catch (\Throwable $e) {
        error_log("_createMailer error: " . $e->getMessage());
        return null;
    }
}

/**
 * Send via PHPMailer SMTP.
 *
 * @return array|null ['success' => bool, 'error' => string|null] or null if PHPMailer unavailable
 */
function _sendViaPhpMailer(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $attachmentPath,
    string $fromName,
    array $extraHeaders = []
): ?array {
    $mail = _createMailer();
    if (!$mail) {
        return null; // PHPMailer not available
    }

    try {
        $mail->setFrom('no-reply@mowology.ca', $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;

        foreach ($extraHeaders as $hName => $hValue) {
            $mail->addCustomHeader($hName, $hValue);
        }

        if ($attachmentPath) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        $result = $mail->send();

        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (' . ($mail->Host ?? '') . ')', $result);

        if ($result) {
            error_log("PHPMailer: sent to {$to}");
        } else {
            error_log("PHPMailer: send failed — " . $mail->ErrorInfo);
        }

        return ['success' => $result, 'error' => $result ? null : $mail->ErrorInfo];

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("PHPMailer Exception: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer SMTP (FAILED)', false);

        // Fall back to native mail()
        error_log("Falling back to native mail() after PHPMailer error");
        if ($attachmentPath) {
            $fallbackResult = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName, $extraHeaders);
        } else {
            $fallbackResult = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName, $extraHeaders);
        }

        return [
            'success' => $fallbackResult,
            'error' => $fallbackResult ? null : ('PHPMailer failed: ' . $e->getMessage() . '; mail() fallback also failed'),
        ];

    } catch (\Throwable $e) {
        error_log("PHPMailer unexpected error: " . $e->getMessage());
        logEmailAttempt($to, $subject, $htmlBody, 'PHPMailer (ERROR)', false);

        if ($attachmentPath) {
            $fallbackResult = _sendEmailWithAttachment($to, $subject, $htmlBody, $attachmentPath, $fromName, $extraHeaders);
        } else {
            $fallbackResult = _sendSimpleHtmlEmail($to, $subject, $htmlBody, $fromName, $extraHeaders);
        }

        return [
            'success' => $fallbackResult,
            'error' => $fallbackResult ? null : ('Error: ' . $e->getMessage()),
        ];
    }
}


// ═══════════════════════════════════════════════════════════════════════
// INTERNAL: Native mail() Fallback
// ═══════════════════════════════════════════════════════════════════════

/**
 * Send simple HTML email via native mail().
 */
function _sendSimpleHtmlEmail(
    string $to,
    string $subject,
    string $htmlBody,
    string $fromName = 'Mowology',
    array $extraHeaders = []
): bool {
    try {
        $fromEmail = 'no-reply@mowology.ca';

        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";
        foreach (_sanitizeExtraHeaders($extraHeaders) as $hName => $hValue) {
            $headers .= "{$hName}: {$hValue}\r\n";
        }

        $result = @mail($to, trim($subject), trim($htmlBody), $headers);

        logEmailAttempt($to, $subject, $htmlBody, $headers, $result);

        if (!$result) {
            error_log("Fallback mail(): FAILED for {$to}");
        }

        return $result;
    } catch (\Throwable $e) {
        error_log("_sendSimpleHtmlEmail error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email with MIME attachment via native mail().
 */
function _sendEmailWithAttachment(
    string $to,
    string $subject,
    string $htmlBody,
    string $attachmentPath,
    string $fromName = 'Mowology',
    array $extraHeaders = []
): bool {
    try {
        $filename = basename($attachmentPath);

        $fileContent = file_get_contents($attachmentPath);
        if ($fileContent === false) {
            error_log("_sendEmailWithAttachment: failed to read {$attachmentPath}");
            return false;
        }

        $encodedFile = chunk_split(base64_encode($fileContent), 76, "\r\n");
        $boundary = '==BOUNDARY_' . md5((string)time() . $to . (string)rand()) . '==';

        $body = "This is a MIME encoded message.\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$filename}\"\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= $encodedFile . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $headers = "From: {$fromName} <no-reply@mowology.ca>\r\n";
        $headers .= "Reply-To: office@mowology.ca\r\n";
        $headers .= "Return-Path: office@mowology.ca\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        $headers .= "X-Mailer: Mowology CRM\r\n";
        foreach (_sanitizeExtraHeaders($extraHeaders) as $hName => $hValue) {
            $headers .= "{$hName}: {$hValue}\r\n";
        }

        $result = mail($to, trim($subject), $body, $headers);

        if (!$result) {
            error_log("Fallback mail() with attachment: FAILED for {$to}");
        }

        return $result;
    } catch (\Throwable $e) {
        error_log("_sendEmailWithAttachment error: " . $e->getMessage());
        return false;
    }
}
