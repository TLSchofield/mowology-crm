<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Canonical location: /public/jobFlow/
// dirname(__DIR__) = /public/
require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/crm/includes/roi-functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper if not already loaded
if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// CSRF token for confirm step
if (empty($_SESSION['csrf_confirm'])) {
    $_SESSION['csrf_confirm'] = bin2hex(random_bytes(32));
}

// Must have quote data
if (!isset($_SESSION['quote_data']) || !is_array($_SESSION['quote_data'])) {
    error_log("jobFlow-confirm: No quote_data in session. Session ID: " . session_id() . " | POST: " . json_encode($_POST));
    header('Location: jobFlow-getQuote.php');
    exit();
}

$data = $_SESSION['quote_data'];
$error = '';

// Normalize for display safety
$data['service_types'] = $data['service_types'] ?? [];
$data['email'] = $data['email'] ?? '';
$data['phone'] = $data['phone'] ?? '';
$data['postal_code'] = $data['postal_code'] ?? '';
$data['city'] = $data['city'] ?? 'Vancouver';
$data['preferred_contact'] = $data['preferred_contact'] ?? 'phone';
$data['property_type'] = $data['property_type'] ?? 'residential';
$data['urgency'] = $data['urgency'] ?? 'inquiring';
$data['description'] = $data['description'] ?? '';
$data['consent_quote'] = !empty($data['consent_quote']);
$data['consent_marketing'] = !empty($data['consent_marketing']);
$data['consent_sms'] = !empty($data['consent_sms']);
$data['ip_address'] = $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$data['tracking'] = $data['tracking'] ?? [];

// Build source string from tracking data (e.g., "strata-landing" or "website")
$quoteSource = !empty($data['tracking']['src']) ? $data['tracking']['src'] : 'website';

// Shared reCAPTCHA verification helpers (v3 + v2 fallback)
require_once __DIR__ . '/recaptcha-helpers.php';

// Track whether the v2 checkbox fallback should be shown
$showV2Challenge = false;

// Submit confirm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    $postedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_confirm'] ?? '', $postedCsrf)) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        // ── reCAPTCHA v3 → v2 Fallback (confirm step) ──
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $v2Token = (string)($_POST['recaptcha_v2_token'] ?? '');
        $v3Token = (string)($_POST['recaptcha_v3_token'] ?? '');
        $recaptchaError = '';

        if ($v2Token !== '') {
            // User completed v2 checkbox fallback
            if (!verify_recaptcha_v2_token($v2Token, $ip)) {
                $recaptchaError = 'Security check failed. Please try again.';
            }
        } elseif ($v3Token !== '') {
            // Verify v3 invisible token
            $v3Result = verify_recaptcha_v3($v3Token, 'quote_confirm', $ip);
            if (!$v3Result['passed']) {
                if ($v3Result['needs_v2']) {
                    $showV2Challenge = true;
                    $recaptchaError = 'Please complete the security check below.';
                } else {
                    $recaptchaError = 'Security verification failed. Please try again.';
                }
            }
        } else {
            $recaptchaError = 'Security check required. Please ensure JavaScript is enabled.';
        }

        if ($recaptchaError !== '') {
            $error = $recaptchaError;
        } else {
            try {
                $db = getDB();
                $db->beginTransaction();

                $nameParts = explode(' ', (string)$data['name'], 2);
                $firstName = $nameParts[0] ?? '';
                $lastName  = $nameParts[1] ?? '';

                // 1. Find or create CONTACT
                $contactId = null;
                $checkContact = $db->prepare("
                    SELECT id FROM contacts
                    WHERE (phone = ? AND phone != '')
                       OR (email = ? AND email != '')
                    LIMIT 1
                ");
                $checkContact->execute([$data['phone'], $data['email']]);
                $existingContact = $checkContact->fetch(PDO::FETCH_ASSOC);

                if ($existingContact) {
                    $contactId = (int)$existingContact['id'];
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO contacts
                            (first_name, last_name, email, phone, is_active)
                        VALUES
                            (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $firstName,
                        $lastName,
                        $data['email'],
                        $data['phone']
                    ]);
                    $contactId = (int)$db->lastInsertId();
                }

                // 2. Find or create PROPERTY
                $propertyId = null;
                $checkProperty = $db->prepare("
                    SELECT id FROM properties WHERE address = ? LIMIT 1
                ");
                $checkProperty->execute([$data['address']]);
                $existingProperty = $checkProperty->fetch(PDO::FETCH_ASSOC);

                $propType = 'single_family';
                if ($data['property_type'] === 'strata') $propType = 'strata';
                elseif ($data['property_type'] === 'commercial') $propType = 'commercial';

                if ($existingProperty) {
                    $propertyId = (int)$existingProperty['id'];
                    $stmt = $db->prepare("
                        UPDATE properties SET
                            latitude = COALESCE(?, latitude),
                            longitude = COALESCE(?, longitude),
                            geocoded_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE geocoded_at END,
                            site_contact_id = COALESCE(site_contact_id, ?),
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['latitude'] ?? null,
                        $data['longitude'] ?? null,
                        $data['latitude'] ?? null,
                        $contactId,
                        $propertyId
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO properties
                            (property_name, property_type, address, city, province, postal_code,
                             latitude, longitude, geocoded_at, site_contact_id, status)
                        VALUES
                            (?, ?, ?, ?, 'BC', ?, ?, ?, NOW(), ?, 'active')
                    ");
                    $stmt->execute([
                        $data['name'],
                        $propType,
                        $data['address'],
                        $data['city'],
                        $data['postal_code'],
                        $data['latitude'] ?? null,
                        $data['longitude'] ?? null,
                        $contactId
                    ]);
                    $propertyId = (int)$db->lastInsertId();
                }

                // 3. Log lead event for ROI tracking FIRST (before quote_request)
                $leadEventId = logLeadEvent(
                    $_SERVER['HTTP_REFERER'] ?? null,
                    [
                        'source' => $data['tracking']['utm_source'] ?? $quoteSource,
                        'medium' => $data['tracking']['utm_medium'] ?? 'website',
                        'campaign' => $data['tracking']['utm_campaign'] ?? null,
                        'content' => $data['tracking']['utm_content'] ?? null,
                    ]
                );

                // 3.1. Create QUOTE_REQUEST
                // Try with lead_event_id first (new schema), fallback to without it (old schema)
                try {
                    $stmt = $db->prepare("
                        INSERT INTO quote_requests
                            (contact_id, property_id, lead_event_id, service_types, urgency, project_description,
                             status, source, ip_address, user_agent)
                        VALUES
                            (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $contactId,
                        $propertyId,
                        $leadEventId > 0 ? $leadEventId : null,
                        implode(',', (array)$data['service_types']),
                        $data['urgency'],
                        $data['description'],
                        $quoteSource,
                        $data['ip_address'],
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
                    ]);
                } catch (PDOException $e) {
                    // If lead_event_id column doesn't exist, try without it (backward compatibility)
                    if (strpos($e->getMessage(), 'lead_event_id') !== false) {
                        error_log("lead_event_id column missing, using fallback INSERT");
                        $stmt = $db->prepare("
                            INSERT INTO quote_requests
                                (contact_id, property_id, service_types, urgency, project_description,
                                 status, source, ip_address, user_agent)
                            VALUES
                                (?, ?, ?, ?, ?, 'new', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $contactId,
                            $propertyId,
                            implode(',', (array)$data['service_types']),
                            $data['urgency'],
                            $data['description'],
                            $quoteSource,
                            $data['ip_address'],
                            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
                        ]);
                    } else {
                        throw $e;
                    }
                }
                $quoteRequestId = (int)$db->lastInsertId();

                // 3.2. Log conversion event for this quote request (non-blocking)
                if ($leadEventId > 0) {
                    try {
                        logConversionEvent($leadEventId, 'quote_request', $quoteRequestId);
                    } catch (Throwable $trackingError) {
                        error_log("logConversionEvent failed (non-blocking): " . $trackingError->getMessage());
                    }
                }

                // 3.3. Update contact lifecycle to prospect after quote request (non-blocking)
                if ($leadEventId > 0 && $contactId > 0) {
                    try {
                        updateContactLifecycleOnQuoteRequest($contactId);
                    } catch (Throwable $lifecycleError) {
                        error_log("updateContactLifecycleOnQuoteRequest failed (non-blocking): " . $lifecycleError->getMessage());
                    }
                }

                // 4. Log consent
                $consentTypes = [
                    'quote_followup'  => $data['consent_quote'],
                    'marketing_email' => $data['consent_marketing'],
                    'sms'             => $data['consent_sms'],
                ];

                foreach ($consentTypes as $type => $given) {
                    $stmt = $db->prepare("
                        INSERT INTO consent_log
                            (contact_id, consent_type, consent_given, consent_text, ip_address, user_agent, consent_source)
                        VALUES
                            (?, ?, ?, ?, ?, ?, 'website_form')
                    ");

                    $consentText = '';
                    if ($type === 'quote_followup')  $consentText = 'I agree to be contacted about this quote request via email, phone, or text message.';
                    if ($type === 'marketing_email') $consentText = 'Send me occasional seasonal reminders, promotions, and property care tips.';
                    if ($type === 'sms')             $consentText = 'I agree to receive SMS updates about scheduling and quote progress.';

                    $stmt->execute([
                        $contactId,
                        $type,
                        $given ? 1 : 0,
                        $consentText,
                        $data['ip_address'],
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    ]);
                }

                // 4b. Update contacts table consent columns (so CRM reads are consistent)
                $stmt = $db->prepare("
                    UPDATE contacts SET
                        receive_sms              = ?,
                        consent_sms              = ?,
                        consent_quote_followup   = ?,
                        consent_marketing_email  = ?,
                        consent_timestamp        = NOW(),
                        consent_ip_address       = ?,
                        consent_source           = 'website_form'
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['consent_sms'] ? 1 : 0,
                    $data['consent_sms'] ? 1 : 0,
                    $data['consent_quote'] ? 1 : 0,
                    $data['consent_marketing'] ? 1 : 0,
                    $data['ip_address'],
                    $contactId,
                ]);

                // 5. Activity log
                $activityDetails = 'Via website form (source: ' . $quoteSource . ')';
                $stmt = $db->prepare("
                    INSERT INTO activity_log
                        (user_id, contact_id, property_id, quote_request_id, action, details, ip_address)
                    VALUES
                        (NULL, ?, ?, ?, 'Quote request submitted', ?, ?)
                ");
                $stmt->execute([$contactId, $propertyId, $quoteRequestId, $activityDetails, $data['ip_address']]);

                $db->commit();

                // Send notifications (non-blocking)
                try {
                    $notificationData = [
                        'name' => $data['name'],
                        'email' => $data['email'],
                        'phone' => $data['phone'],
                        'address' => $data['address'],
                        'city' => $data['city'],
                        'property_type' => $data['property_type'],
                        'service_types' => is_array($data['service_types']) ? implode(',', $data['service_types']) : $data['service_types'],
                        'urgency' => $data['urgency'],
                        'description' => $data['description']
                    ];
                    sendQuoteRequestNotifications($notificationData);
                } catch (Throwable $notificationError) {
                    error_log("sendQuoteRequestNotifications failed (non-blocking): " . $notificationError->getMessage());
                }

                unset($_SESSION['quote_data']);
                unset($_SESSION['jf_track']);
                $_SESSION['csrf_confirm'] = bin2hex(random_bytes(32));

                header('Location: jobFlow-success.php');
                exit();
            } catch (Throwable $e) {
                if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Quote submission error: " . $e->getMessage());
                $error = 'There was an error submitting your request. Please try again or call us at (778) 846-9273.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Your Quote Request - Mowology</title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php
    $useV3 = is_recaptcha_v3_configured() && !$showV2Challenge;
?>
<?php if ($useV3): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(RECAPTCHA_V3_SITE_KEY, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
</head>
<body class="jobflow-page">
<div class="container">
    <div class="jobflow-header">
        <div class="jobflow-logo">🌱</div>
        <h1>Review Your Request</h1>
        <p>Please confirm your information is correct</p>
    </div>

    <div class="progress-bar">
        <div class="progress-step completed">
            <div class="progress-circle">✓</div>
            <div class="progress-label">Your Info</div>
        </div>
        <div class="progress-step active">
            <div class="progress-circle">2</div>
            <div class="progress-label">Review</div>
        </div>
        <div class="progress-step">
            <div class="progress-circle">✓</div>
            <div class="progress-label">Complete</div>
        </div>
    </div>

    <div class="review-card">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="section">
            <h2 class="section-title">Contact Information</h2>
            <div class="review-row"><div class="review-label">Name</div><div class="review-value"><?php echo h((string)$data['name']); ?></div></div>
            <div class="review-row"><div class="review-label">Email</div><div class="review-value"><?php echo h($data['email'] !== '' ? (string)$data['email'] : 'Not provided'); ?></div></div>
            <div class="review-row"><div class="review-label">Phone</div><div class="review-value"><?php echo h($data['phone'] !== '' ? (string)$data['phone'] : 'Not provided'); ?></div></div>
            <div class="review-row"><div class="review-label">Preferred Contact</div><div class="review-value"><?php echo h(ucfirst((string)$data['preferred_contact'])); ?></div></div>
        </div>

        <div class="section">
            <h2 class="section-title">Property & Services</h2>
            <div class="review-row">
                <div class="review-label">Address</div>
                <div class="review-value">
                    <?php echo h((string)$data['address']); ?><br>
                    <?php echo h((string)$data['city'] . ', BC ' . (string)$data['postal_code']); ?>
                </div>
            </div>
            <div class="review-row"><div class="review-label">Property Type</div><div class="review-value"><?php echo h(ucfirst((string)$data['property_type'])); ?></div></div>
            <div class="review-row">
                <div class="review-label">Services Needed</div>
                <div class="review-value">
                    <?php foreach ((array)$data['service_types'] as $service): ?>
                        <span class="service-tag"><?php echo h(ucfirst(str_replace('_', ' ', (string)$service))); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="review-row"><div class="review-label">Urgency</div><div class="review-value"><?php echo h(ucwords(str_replace('_', ' ', (string)$data['urgency']))); ?></div></div>
            <?php if (!empty($data['description'])): ?>
                <div class="review-row"><div class="review-label">Description</div><div class="review-value"><?php echo nl2br(h((string)$data['description'])); ?></div></div>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2 class="section-title">Communication Preferences</h2>
            <div class="consent-summary">
                <div class="consent-item <?php echo $data['consent_quote'] ? 'consent-yes' : 'consent-no'; ?>">
                    <svg fill="currentColor" viewBox="0 0 20 20">
                        <?php if ($data['consent_quote']): ?>
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        <?php else: ?>
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        <?php endif; ?>
                    </svg>
                    <span>Quote follow-up communications (Required)</span>
                </div>

                <div class="consent-item <?php echo $data['consent_marketing'] ? 'consent-yes' : 'consent-no'; ?>">
                    <svg fill="currentColor" viewBox="0 0 20 20">
                        <?php if ($data['consent_marketing']): ?>
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        <?php else: ?>
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        <?php endif; ?>
                    </svg>
                    <span>Marketing emails & seasonal tips</span>
                </div>

                <div class="consent-item <?php echo $data['consent_sms'] ? 'consent-yes' : 'consent-no'; ?>">
                    <svg fill="currentColor" viewBox="0 0 20 20">
                        <?php if ($data['consent_sms']): ?>
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        <?php else: ?>
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        <?php endif; ?>
                    </svg>
                    <span>Text message updates</span>
                </div>
            </div>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="confirm" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_confirm']); ?>">

            <?php if ($useV3): ?>
                <!-- reCAPTCHA v3: invisible, token added via JS on submit -->
                <input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">
                <div style="text-align:center; margin: 18px 0 0; color:#6c757d; font-size:12px;">
                    Protected by reCAPTCHA
                </div>
            <?php else: ?>
                <!-- reCAPTCHA v2 checkbox fallback -->
                <div style="margin: 18px 0 0;">
                    <div class="g-recaptcha" data-sitekey="<?php echo h(RECAPTCHA_V2_SITE_KEY); ?>" data-callback="onV2Completed"></div>
                    <input type="hidden" name="recaptcha_v2_token" id="recaptcha_v2_token" value="">
                    <div style="text-align:center; margin-top:10px; color:#6c757d; font-size:12px;">
                        Protected by reCAPTCHA
                    </div>
                </div>
            <?php endif; ?>

            <div class="button-group">
                <a href="jobFlow-getQuote.php" class="btn btn-secondary">Edit Information</a>
                <button type="submit" class="btn btn-primary">Confirm & Submit Quote Request</button>
            </div>
        </form>
    </div>
</div>

<script>
<?php if ($useV3): ?>
// ── reCAPTCHA v3: intercept form submit, get token, then submit ──
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form[method="POST"]');
    if (!form || !document.getElementById('recaptcha_v3_token')) return;

    var submitting = false;
    form.addEventListener('submit', function(e) {
        if (submitting) return;
        e.preventDefault();

        var siteKey = <?php echo json_encode(RECAPTCHA_V3_SITE_KEY); ?>;
        if (typeof grecaptcha === 'undefined') {
            form.submit();
            return;
        }

        grecaptcha.ready(function() {
            grecaptcha.execute(siteKey, { action: 'quote_confirm' }).then(function(token) {
                document.getElementById('recaptcha_v3_token').value = token;
                submitting = true;
                form.submit();
            }).catch(function() {
                form.submit();
            });
        });
    });
});
<?php else: ?>
// ── reCAPTCHA v2 checkbox: copy token to hidden field on completion ──
function onV2Completed(token) {
    var field = document.getElementById('recaptcha_v2_token');
    if (field) field.value = token;
}
<?php endif; ?>
</script>
</body>
</html>
