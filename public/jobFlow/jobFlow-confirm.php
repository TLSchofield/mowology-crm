<?php
/**
 * jobFlow — Step 2: Review & Confirm
 *
 * Phases implemented:
 *  Phase 1 — Security hardening (CSRF, escaping, session gate, production error mode)
 *  Phase 2 — Conversion optimisation (upsells, price estimate, urgency badge, social proof)
 *  Phase 3 — Automation (lead classification, CRM enrichment, UTM capture, priority routing)
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Bootstrap ──────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
require_once dirname(__DIR__) . '/crm/includes/roi-functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Helpers ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/recaptcha-helpers.php';
require_once __DIR__ . '/helpers/validators.php';
require_once __DIR__ . '/helpers/pricing.php';
require_once __DIR__ . '/helpers/classification.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ── CSRF token (Step 2) ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_confirm'])) {
    $_SESSION['csrf_confirm'] = bin2hex(random_bytes(32));
}

// ── Session gate — must have quote_data ────────────────────────────────────
if (!isset($_SESSION['quote_data']) || !is_array($_SESSION['quote_data'])) {
    error_log('[jobFlow-confirm] No quote_data in session. SID=' . session_id() . ' IP=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    header('Location: jobFlow-getQuote.php');
    exit();
}

$data = $_SESSION['quote_data'];

// ── Normalise all fields defensively ──────────────────────────────────────
$data['service_types']   = (array)($data['service_types'] ?? []);
$data['email']           = (string)($data['email'] ?? '');
$data['phone']           = (string)($data['phone'] ?? '');
$data['postal_code']     = (string)($data['postal_code'] ?? '');
$data['city']            = (string)($data['city'] ?? 'Vancouver');
$data['preferred_contact'] = (string)($data['preferred_contact'] ?? 'phone');
$data['property_type']   = whitelistValue((string)($data['property_type'] ?? ''), VALID_PROPERTY_TYPES, 'residential');
$data['urgency']         = whitelistValue((string)($data['urgency'] ?? ''), VALID_URGENCY_VALUES, 'inquiring');
$data['description']     = (string)($data['description'] ?? '');
$data['lawn_size']       = whitelistValue((string)($data['lawn_size'] ?? ''), VALID_LAWN_SIZES, '');
$data['has_irrigation']  = !empty($data['has_irrigation']);
$data['consent_quote']   = !empty($data['consent_quote']);
$data['consent_marketing']= !empty($data['consent_marketing']);
$data['consent_sms']     = !empty($data['consent_sms']);
$data['ip_address']      = (string)($data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
$data['tracking']        = (array)($data['tracking'] ?? []);
$data['upsells']         = (array)($data['upsells'] ?? []);

// ── Source string ──────────────────────────────────────────────────────────
$quoteSource = !empty($data['tracking']['src']) ? (string)$data['tracking']['src'] : 'website';

// ── Phase 2: Upsells & estimate ────────────────────────────────────────────
$availableUpsells = getRelevantUpsells($data['service_types']);
$priceEstimate    = calculateEstimate(
    $data['service_types'],
    $data['lawn_size'],
    $data['has_irrigation'],
    $data['upsells']
);

// ── Phase 3: Lead classification ───────────────────────────────────────────
$classification = classifyLead($data);

// ── State ──────────────────────────────────────────────────────────────────
$showV2Challenge = false;
$error = '';

// ── POST handling — confirm submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    // 1. CSRF
    if (!hash_equals($_SESSION['csrf_confirm'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {

        // 2. Capture any upsell selections from POST (Phase 2)
        $selectedUpsells = [];
        foreach (array_keys($availableUpsells) as $upsellKey) {
            if (!empty($_POST['upsell_' . $upsellKey])) {
                $selectedUpsells[] = $upsellKey;
            }
        }
        $data['upsells'] = $selectedUpsells;
        // Recalculate estimate with upsells
        $priceEstimate = calculateEstimate(
            $data['service_types'],
            $data['lawn_size'],
            $data['has_irrigation'],
            $data['upsells']
        );

        // 3. reCAPTCHA
        $ip      = $_SERVER['REMOTE_ADDR'] ?? null;
        $v2Token = (string)($_POST['recaptcha_v2_token'] ?? '');
        $v3Token = (string)($_POST['recaptcha_v3_token'] ?? '');
        $captchaOk = false;

        if ($v2Token !== '') {
            $captchaOk = verify_recaptcha_v2_token($v2Token, $ip);
            if (!$captchaOk) $error = 'Security check failed. Please try again.';
        } elseif ($v3Token !== '') {
            $v3Result = verify_recaptcha_v3($v3Token, 'quote_confirm', $ip);
            if ($v3Result['passed']) {
                $captchaOk = true;
            } elseif ($v3Result['needs_v2']) {
                $showV2Challenge = true;
                $error = 'Please complete the security check below.';
            } else {
                $error = 'Security verification failed. Please try again.';
            }
        } else {
            $error = 'Security check required. Please ensure JavaScript is enabled.';
        }

        if ($error === '' && $captchaOk) {
            try {
                $db = getDB();
                $db->beginTransaction();

                // Extract first / last name
                $nameParts = explode(' ', (string)($data['name'] ?? ''), 2);
                $firstName = $nameParts[0] ?? '';
                $lastName  = $nameParts[1] ?? '';

                // ── 1. Find or create CONTACT ──────────────────────────────
                $checkContact = $db->prepare("
                    SELECT id FROM contacts
                    WHERE (phone = ? AND phone != '')
                       OR (email = ? AND email != '')
                    LIMIT 1
                ");
                $checkContact->execute([$data['phone'], $data['email']]);
                $existingContact = $checkContact->fetch(PDO::FETCH_ASSOC);
                $contactId = null;

                if ($existingContact) {
                    $contactId = (int)$existingContact['id'];
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO contacts (first_name, last_name, email, phone, is_active)
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$firstName, $lastName, $data['email'], $data['phone']]);
                    $contactId = (int)$db->lastInsertId();
                }

                // ── 2. Find or create PROPERTY ─────────────────────────────
                $checkProperty = $db->prepare("SELECT id FROM properties WHERE address = ? LIMIT 1");
                $checkProperty->execute([$data['address']]);
                $existingProperty = $checkProperty->fetch(PDO::FETCH_ASSOC);

                $propType = match($data['property_type']) {
                    'strata'     => 'strata',
                    'commercial' => 'commercial',
                    default      => 'single_family',
                };

                $propertyId = null;
                if ($existingProperty) {
                    $propertyId = (int)$existingProperty['id'];
                    $stmt = $db->prepare("
                        UPDATE properties SET
                            latitude        = COALESCE(?, latitude),
                            longitude       = COALESCE(?, longitude),
                            geocoded_at     = CASE WHEN ? IS NOT NULL THEN NOW() ELSE geocoded_at END,
                            site_contact_id = COALESCE(site_contact_id, ?),
                            updated_at      = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $data['latitude'] ?? null,
                        $data['longitude'] ?? null,
                        $data['latitude'] ?? null,
                        $contactId,
                        $propertyId,
                    ]);
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO properties
                            (property_name, property_type, address, city, province, postal_code,
                             latitude, longitude, geocoded_at, site_contact_id, status)
                        VALUES (?, ?, ?, ?, 'BC', ?, ?, ?, NOW(), ?, 'active')
                    ");
                    $stmt->execute([
                        $data['name'],
                        $propType,
                        $data['address'],
                        $data['city'],
                        $data['postal_code'],
                        $data['latitude'] ?? null,
                        $data['longitude'] ?? null,
                        $contactId,
                    ]);
                    $propertyId = (int)$db->lastInsertId();
                }

                // ── 3. Log lead event (ROI tracking) ───────────────────────
                $leadEventId = logLeadEvent(
                    $_SERVER['HTTP_REFERER'] ?? null,
                    [
                        'source'   => $data['tracking']['utm_source']   ?? $quoteSource,
                        'medium'   => $data['tracking']['utm_medium']   ?? 'website',
                        'campaign' => $data['tracking']['utm_campaign']  ?? null,
                        'content'  => $data['tracking']['utm_content']   ?? null,
                    ]
                );

                // ── 4. Create QUOTE_REQUEST ─────────────────────────────────
                // Build a rich metadata JSON-like string for notes (stored as text)
                $servicesCsv   = implode(',', $data['service_types']);
                $upsellsCsv    = implode(',', $data['upsells']);
                $classNotes    = 'job_type:' . $classification['job_type']
                               . ' tier:' . $classification['value_tier']
                               . ' freq:' . $classification['suggested_frequency'];
                if ($upsellsCsv !== '') $classNotes .= ' upsells:' . $upsellsCsv;
                if ($data['lawn_size'] !== '') $classNotes .= ' lawn_size:' . $data['lawn_size'];
                if ($data['has_irrigation']) $classNotes .= ' irrigated:1';
                if ($classification['is_priority']) $classNotes .= ' priority:1';

                $descriptionFull = $data['description'];
                if ($classNotes !== '') {
                    $descriptionFull = ltrim($descriptionFull . "\n\n[Classification: " . $classNotes . "]");
                }

                try {
                    $stmt = $db->prepare("
                        INSERT INTO quote_requests
                            (contact_id, property_id, lead_event_id, service_types, urgency,
                             project_description, status, source, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $contactId,
                        $propertyId,
                        $leadEventId > 0 ? $leadEventId : null,
                        $servicesCsv,
                        $data['urgency'],
                        $descriptionFull,
                        $quoteSource,
                        $data['ip_address'],
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                    ]);
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'lead_event_id') !== false) {
                        error_log('[jobFlow-confirm] lead_event_id column missing — using fallback INSERT');
                        $stmt = $db->prepare("
                            INSERT INTO quote_requests
                                (contact_id, property_id, service_types, urgency, project_description,
                                 status, source, ip_address, user_agent)
                            VALUES (?, ?, ?, ?, ?, 'new', ?, ?, ?)
                        ");
                        $stmt->execute([
                            $contactId,
                            $propertyId,
                            $servicesCsv,
                            $data['urgency'],
                            $descriptionFull,
                            $quoteSource,
                            $data['ip_address'],
                            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                        ]);
                    } else {
                        throw $e;
                    }
                }
                $quoteRequestId = (int)$db->lastInsertId();

                // ── 5. Non-blocking: conversion event + lifecycle update ────
                if ($leadEventId > 0) {
                    try { logConversionEvent($leadEventId, 'quote_request', $quoteRequestId); }
                    catch (Throwable $t) { error_log('[jobFlow-confirm] logConversionEvent failed: ' . $t->getMessage()); }
                }
                if ($leadEventId > 0 && $contactId > 0) {
                    try { updateContactLifecycleOnQuoteRequest($contactId); }
                    catch (Throwable $t) { error_log('[jobFlow-confirm] updateContactLifecycle failed: ' . $t->getMessage()); }
                }

                // ── 5b. Non-blocking: record inbound referral ─────────────
                $referralCode = trim($_SESSION['jf_track']['referral_code'] ?? '');
                if ($referralCode !== '') {
                    try {
                        $__refSvc = dirname(__DIR__, 2) . '/app/Modules/Referrals/Services/ReferralRewardService.php';
                        if (file_exists($__refSvc)) {
                            require_once $__refSvc;
                            ReferralRewardService::recordInboundReferral(
                                $referralCode,
                                [
                                    'name'  => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                                    'email' => $data['email'] ?? '',
                                    'phone' => $data['phone'] ?? '',
                                    'qr_id' => $quoteRequestId,
                                ],
                                $db
                            );
                        }
                    } catch (Throwable $__t) {
                        error_log('[jobFlow-confirm] referral recording failed: ' . $__t->getMessage());
                    }
                }

                // ── 6. Log consent ────────────────────────────────────────
                $consentEntries = [
                    'quote_followup'  => [$data['consent_quote'],    'I agree to be contacted about this quote request via email, phone, or text message.'],
                    'marketing_email' => [$data['consent_marketing'], 'Send me occasional seasonal reminders, promotions, and property care tips.'],
                    'sms'             => [$data['consent_sms'],       'I agree to receive SMS updates about scheduling and quote progress.'],
                ];
                foreach ($consentEntries as $type => [$given, $text]) {
                    $stmt = $db->prepare("
                        INSERT INTO consent_log
                            (contact_id, consent_type, consent_given, consent_text, ip_address, user_agent, consent_source)
                        VALUES (?, ?, ?, ?, ?, ?, 'website_form')
                    ");
                    $stmt->execute([
                        $contactId,
                        $type,
                        $given ? 1 : 0,
                        $text,
                        $data['ip_address'],
                        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    ]);
                }

                // ── 7. Update contact consent columns ─────────────────────
                $stmt = $db->prepare("
                    UPDATE contacts SET
                        receive_sms             = ?,
                        consent_sms             = ?,
                        consent_quote_followup  = ?,
                        consent_marketing_email = ?,
                        consent_timestamp       = NOW(),
                        consent_ip_address      = ?,
                        consent_source          = 'website_form'
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

                // ── 8. Activity log ────────────────────────────────────────
                $priorityFlag = $classification['is_priority'] ? ' [PRIORITY]' : '';
                $activityDetails = 'Via website form (src: ' . $quoteSource . ') | tier: ' . $classification['value_tier'] . $priorityFlag;
                $stmt = $db->prepare("
                    INSERT INTO activity_log
                        (user_id, contact_id, property_id, quote_request_id, action, details, ip_address)
                    VALUES (NULL, ?, ?, ?, 'Quote request submitted', ?, ?)
                ");
                $stmt->execute([$contactId, $propertyId, $quoteRequestId, $activityDetails, $data['ip_address']]);

                $db->commit();

                // ── 9. Notifications (non-blocking) ───────────────────────
                try {
                    $upsellLabels = array_map(fn($k) => $availableUpsells[$k]['label'] ?? $k, $data['upsells']);
                    $notificationData = [
                        'name'          => $data['name'],
                        'email'         => $data['email'],
                        'phone'         => $data['phone'],
                        'address'       => $data['address'],
                        'city'          => $data['city'],
                        'property_type' => $data['property_type'],
                        'service_types' => $servicesCsv,
                        'urgency'       => $data['urgency'],
                        'description'   => $data['description'],
                        'value_tier'    => $classification['value_tier'],
                        'is_priority'   => $classification['is_priority'],
                        'upsells'       => implode(', ', $upsellLabels),
                    ];
                    sendQuoteRequestNotifications($notificationData);
                } catch (Throwable $t) {
                    error_log('[jobFlow-confirm] sendQuoteRequestNotifications failed: ' . $t->getMessage());
                }

                // ── 9b. Campaign event: lead submitted ────────────────────
                try {
                    $__dir = dirname(__DIR__);
                    for ($__i = 0; $__i < 4; $__i++) {
                        if (is_file($__dir . '/app/Core/paths.php')) {
                            if (!defined('APP_ROOT')) require_once $__dir . '/app/Core/paths.php';
                            break;
                        }
                        $__dir = dirname($__dir);
                    }
                    unset($__dir, $__i);
                    if (defined('APP_ROOT')) {
                        $__emitter = APP_ROOT . '/Modules/CampaignConnector/Services/CampaignEventEmitter.php';
                        if (file_exists($__emitter)) {
                            require_once $__emitter;
                            CampaignEventEmitter::fire(
                                'lead_submitted', 'lead', $quoteRequestId, $contactId,
                                [
                                    'lead_quality'    => $classification['value_tier'] ?? 'unknown',
                                    'is_priority'     => $classification['is_priority'] ?? false,
                                    'service_types'   => $servicesCsv,
                                    'urgency'         => $data['urgency'],
                                    'city'            => $data['city'],
                                    'utm_source'      => $data['tracking']['utm_source'] ?? null,
                                    'utm_campaign'    => $data['tracking']['utm_campaign'] ?? null,
                                    'consent_sms'     => $data['consent_sms'],
                                    'consent_marketing' => $data['consent_marketing'],
                                    'source'          => $quoteSource,
                                ],
                                'jobflow'
                            );
                        }
                    }
                } catch (Throwable $__t) {
                    error_log('[jobFlow-confirm] campaign event error: ' . $__t->getMessage());
                }

                // ── 10. Session cleanup & success redirect ─────────────────
                // Set success flag BEFORE unsetting quote_data (success.php reads it)
                $_SESSION['quote_submitted'] = true;
                $_SESSION['submitted_name']  = $data['first_name'] ?? $data['name'] ?? '';
                $_SESSION['submitted_sms']   = $data['consent_sms'];
                unset($_SESSION['quote_data']);
                unset($_SESSION['jf_track']);
                $_SESSION['csrf_confirm'] = bin2hex(random_bytes(32));

                header('Location: jobFlow-success.php');
                exit();

            } catch (Throwable $e) {
                if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('[jobFlow-confirm] Submission error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
                $error = 'There was an error submitting your request. Please try again or call us at (778) 846-9273.';
            }
        }
    }
}

// ── reCAPTCHA mode ─────────────────────────────────────────────────────────
$useV3 = is_recaptcha_v3_configured() && !$showV2Challenge;

// ── Urgency availability text ──────────────────────────────────────────────
$availabilityText = '';
$month = (int)date('n');
if (in_array($month, [3, 4, 5, 6], true)) {
    $availabilityText = 'Limited seasonal availability — we\'re booking fast for spring.';
} elseif ($data['urgency'] === 'asap') {
    $availabilityText = 'We prioritise ASAP requests — you\'ll hear from us today.';
}

$serviceLabels = [
    'maintenance'    => 'Lawn Maintenance',
    'cleanup'        => 'Garden Cleanup',
    'hedge_trimming' => 'Hedge Trimming',
    'lawn_care'      => 'Lawn Care',
    'snow_removal'   => 'Snow Removal',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Your Quote Request — Mowology</title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if ($useV3): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo h(RECAPTCHA_V3_SITE_KEY); ?>"></script>
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
            <div class="progress-circle">&#x2713;</div>
            <div class="progress-label">Your Info</div>
        </div>
        <div class="progress-step active">
            <div class="progress-circle">2</div>
            <div class="progress-label">Review</div>
        </div>
        <div class="progress-step">
            <div class="progress-circle">&#x2713;</div>
            <div class="progress-label">Complete</div>
        </div>
    </div>

    <div class="review-card">

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo h($error); ?></div>
        <?php endif; ?>

        <!-- Contact info -->
        <div class="section">
            <h2 class="section-title">Contact Information</h2>
            <div class="review-row"><div class="review-label">Name</div><div class="review-value"><?php echo h($data['name'] ?? ''); ?></div></div>
            <div class="review-row"><div class="review-label">Email</div><div class="review-value"><?php echo h($data['email'] !== '' ? $data['email'] : 'Not provided'); ?></div></div>
            <div class="review-row"><div class="review-label">Phone</div><div class="review-value"><?php echo h($data['phone'] !== '' ? $data['phone'] : 'Not provided'); ?></div></div>
            <div class="review-row"><div class="review-label">Preferred Contact</div><div class="review-value"><?php echo h(ucfirst($data['preferred_contact'])); ?></div></div>
        </div>

        <!-- Property & services -->
        <div class="section">
            <h2 class="section-title">Property &amp; Services</h2>
            <div class="review-row">
                <div class="review-label">Address</div>
                <div class="review-value">
                    <?php echo h($data['address']); ?><br>
                    <?php echo h($data['city'] . ', BC ' . $data['postal_code']); ?>
                </div>
            </div>
            <div class="review-row"><div class="review-label">Property Type</div><div class="review-value"><?php echo h(ucfirst($data['property_type'])); ?></div></div>
            <?php if ($data['lawn_size'] !== ''): ?>
                <div class="review-row"><div class="review-label">Lawn Size</div><div class="review-value"><?php echo h(ucfirst($data['lawn_size'])); ?></div></div>
            <?php endif; ?>
            <?php if ($data['has_irrigation']): ?>
                <div class="review-row"><div class="review-label">Irrigation</div><div class="review-value">Yes — has sprinkler system</div></div>
            <?php endif; ?>
            <div class="review-row">
                <div class="review-label">Services</div>
                <div class="review-value">
                    <?php foreach ($data['service_types'] as $svc): ?>
                        <span class="service-tag"><?php echo h($serviceLabels[$svc] ?? ucfirst(str_replace('_', ' ', $svc))); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="review-row"><div class="review-label">Urgency</div><div class="review-value"><?php echo h(ucwords(str_replace('_', ' ', $data['urgency']))); ?></div></div>
            <?php if ($data['description'] !== ''): ?>
                <div class="review-row"><div class="review-label">Notes</div><div class="review-value"><?php echo nl2br(h($data['description'])); ?></div></div>
            <?php endif; ?>
        </div>

        <!-- Consent summary -->
        <div class="section">
            <h2 class="section-title">Communication Preferences</h2>
            <div class="consent-summary">
                <?php
                $consentItems = [
                    'consent_quote'     => ['Quote follow-up (Required)', $data['consent_quote']],
                    'consent_marketing' => ['Marketing emails & seasonal tips', $data['consent_marketing']],
                    'consent_sms'       => ['Text message updates', $data['consent_sms']],
                ];
                foreach ($consentItems as [$label, $given]):
                    $cls = $given ? 'consent-yes' : 'consent-no';
                ?>
                <div class="consent-item <?php echo $cls; ?>">
                    <svg fill="currentColor" viewBox="0 0 20 20">
                        <?php if ($given): ?>
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        <?php else: ?>
                            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        <?php endif; ?>
                    </svg>
                    <span><?php echo h($label); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Phase 2: Price estimate ──────────────────────────────────── -->
        <?php if (!empty($priceEstimate)): ?>
        <div class="section estimate-section">
            <h2 class="section-title">Estimated Starting Price</h2>
            <div class="price-estimate">
                <span class="price-range">$<?php echo (int)$priceEstimate['min']; ?>–$<?php echo (int)$priceEstimate['max']; ?> <small>per visit</small></span>
                <p class="price-note"><?php echo h($priceEstimate['note']); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Phase 2: Upsells ─────────────────────────────────────────── -->
        <?php if (!empty($availableUpsells)): ?>
        <div class="section upsell-section">
            <h2 class="section-title">Enhance Your Service</h2>
            <p class="upsell-intro">Many customers add these to their plan — check any you'd like us to include in your quote:</p>

            <div class="upsell-grid" id="upsell-grid">
                <?php foreach ($availableUpsells as $key => $upsell): ?>
                    <?php $isSelected = in_array($key, $data['upsells'], true); ?>
                    <label class="upsell-card <?php echo $isSelected ? 'selected' : ''; ?>">
                        <?php if ($upsell['badge'] !== ''): ?>
                            <span class="upsell-badge"><?php echo h($upsell['badge']); ?></span>
                        <?php endif; ?>
                        <input type="checkbox" name="upsell_<?php echo h($key); ?>"
                               value="1" class="upsell-checkbox"
                               <?php echo $isSelected ? 'checked' : ''; ?>>
                        <strong><?php echo h($upsell['label']); ?></strong>
                        <span class="upsell-price">
                            <?php echo $upsell['price'] > 0
                                ? '+$' . h((string)$upsell['price']) . '/visit'
                                : 'Included'; ?>
                        </span>
                        <p class="upsell-desc"><?php echo h($upsell['description']); ?></p>
                        <?php if ($upsell['note'] !== ''): ?>
                            <small class="upsell-note"><?php echo h($upsell['note']); ?></small>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Phase 2: Availability urgency ─────────────────────────────── -->
        <?php if ($availabilityText !== ''): ?>
        <div class="urgency-banner">
            <span>&#x26A0;&#xFE0F;</span>
            <?php echo h($availabilityText); ?>
        </div>
        <?php endif; ?>

        <!-- Confirm form -->
        <form method="POST" action="" id="confirm-form">
            <input type="hidden" name="confirm"     value="1">
            <input type="hidden" name="csrf_token"  value="<?php echo h($_SESSION['csrf_confirm']); ?>">

            <?php if ($useV3): ?>
                <input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">
                <p style="text-align:center; margin: 18px 0 0; color:#6c757d; font-size:12px;">Protected by reCAPTCHA</p>
            <?php else: ?>
                <div style="margin: 18px 0 0;">
                    <div class="g-recaptcha"
                         data-sitekey="<?php echo h(RECAPTCHA_V2_SITE_KEY); ?>"
                         data-callback="onV2Completed"></div>
                    <input type="hidden" name="recaptcha_v2_token" id="recaptcha_v2_token" value="">
                </div>
            <?php endif; ?>

            <!-- Hidden upsell fields (submitted via confirm form, not separate) -->
            <!-- Upsell checkboxes are rendered inside #upsell-grid above and -->
            <!-- will submit their own name="upsell_*" fields naturally when   -->
            <!-- the form is submitted. JS moves them into this form on submit. -->

            <div class="button-group">
                <a href="jobFlow-getQuote.php" class="btn btn-secondary">&#8592; Edit Information</a>
                <button type="submit" class="btn btn-primary">Confirm &amp; Submit Request</button>
            </div>
        </form>

    </div>
</div>

<script>
// Move upsell checkboxes into the confirm form before submit
document.addEventListener('DOMContentLoaded', function() {
    var confirmForm = document.getElementById('confirm-form');
    var upsellGrid  = document.getElementById('upsell-grid');

    if (confirmForm && upsellGrid) {
        // Visual toggle for upsell cards
        upsellGrid.querySelectorAll('.upsell-card').forEach(function(card) {
            card.addEventListener('change', function() {
                var cb = this.querySelector('input[type="checkbox"]');
                if (cb) this.classList.toggle('selected', cb.checked);
            });
        });

        // On submit: move all upsell checkboxes into the confirm form
        confirmForm.addEventListener('submit', function() {
            upsellGrid.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                var clone = cb.cloneNode(true);
                confirmForm.appendChild(clone);
            });
        }, true); // capture phase so it runs before reCAPTCHA intercept
    }
});

<?php if ($useV3): ?>
// ── reCAPTCHA v3 ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('confirm-form');
    var tokenField = document.getElementById('recaptcha_v3_token');
    if (!form || !tokenField) return;

    var submitting = false;
    form.addEventListener('submit', function(e) {
        if (submitting) return;
        e.preventDefault();

        var siteKey = <?php echo json_encode(RECAPTCHA_V3_SITE_KEY); ?>;
        if (typeof grecaptcha === 'undefined') {
            submitting = true;
            form.submit();
            return;
        }
        grecaptcha.ready(function() {
            grecaptcha.execute(siteKey, { action: 'quote_confirm' }).then(function(token) {
                tokenField.value = token;
                submitting = true;
                form.submit();
            }).catch(function() {
                submitting = true;
                form.submit();
            });
        });
    });
});
<?php else: ?>
function onV2Completed(token) {
    var field = document.getElementById('recaptcha_v2_token');
    if (field) field.value = token;
}
<?php endif; ?>
</script>
</body>
</html>
