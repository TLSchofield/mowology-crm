<?php
/**
 * jobFlow — Step 1: Quote Request Form
 *
 * Phases implemented:
 *  Phase 1 — Security hardening (CSRF, strict validation, whitelist, escaping, session lock)
 *  Phase 2 — Conversion optimisation (trust badges, service selector, lawn size, seasonal messaging)
 */

declare(strict_types=1);

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Turn off display_errors in production — error_log() still captures everything.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Helpers ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/recaptcha-helpers.php';
require_once __DIR__ . '/helpers/validators.php';
require_once __DIR__ . '/helpers/pricing.php';

if (!function_exists('h')) {
    function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ── CSRF token (Step 1) ────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Campaign & Source Tracking ─────────────────────────────────────────────
// Capture inbound query params on first GET load; persist via session.
$trackableParams = ['service', 'property_type', 'src', 'promo',
                    'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach ($trackableParams as $param) {
        $val = isset($_GET[$param]) ? trim((string)$_GET[$param]) : '';
        if ($val !== '') {
            // Alphanumeric + hyphens + underscores only; max 100 chars
            $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $val) ?? '';
            $_SESSION['jf_track'][$param] = substr($clean, 0, 100);
        }
    }
    if (!isset($_SESSION['jf_track']['referrer']) && !empty($_SERVER['HTTP_REFERER'])) {
        $_SESSION['jf_track']['referrer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 500);
    }
}
$jfTrack = $_SESSION['jf_track'] ?? [];

// ── Seasonal / trust messaging ─────────────────────────────────────────────
$seasonalMessage  = getSeasonalMessage();
$responseTimeBadge = getResponseTimeBadge();

// ── State ──────────────────────────────────────────────────────────────────
$showV2Challenge      = false;
$error                = '';
$errors               = [];
$existingAddresses    = [];
$showAddressOptions   = false;

// ── POST handling ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log('[jobFlow-getQuote] POST received | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    // ── Address confirmation sub-step ──────────────────────────────────────
    if (isset($_POST['address_confirmed'])) {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $_SESSION['quote_data'] = $_SESSION['temp_quote_data'] ?? [];
            unset($_SESSION['temp_quote_data']);
            header('Location: jobFlow-confirm.php');
            exit();
        }

    } else {
        // ── Main form submission ───────────────────────────────────────────

        // 1. CSRF
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {

            // 1b. IP rate limit — max 10 form submissions per 15 minutes per IP.
            // Protects the DB insert path independently of reCAPTCHA.
            // Uses the same recaptcha_rate_limit table created by RecaptchaVerify.
            $submissionIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $rateLimitHit = false;
            if ($submissionIp !== '') {
                try {
                    $rlDb        = getDB();
                    $rlWindow    = 900;   // 15 minutes
                    $rlMax       = 10;    // submissions, not verification calls
                    $rlWindowTs  = date('Y-m-d H:i:s', time() - $rlWindow);
                    // Ensure table exists (RecaptchaVerify may not have run yet if token absent)
                    $rlDb->exec("
                        CREATE TABLE IF NOT EXISTS recaptcha_rate_limit (
                            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            ip            VARCHAR(45)  NOT NULL,
                            window_start  DATETIME     NOT NULL,
                            attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
                            INDEX idx_ip_window (ip, window_start)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                    $rlDb->prepare("DELETE FROM recaptcha_rate_limit WHERE window_start < ?")
                         ->execute([$rlWindowTs]);
                    $rlStmt = $rlDb->prepare(
                        "SELECT attempt_count FROM recaptcha_rate_limit
                          WHERE ip = ? AND window_start >= ? LIMIT 1"
                    );
                    $rlStmt->execute([$submissionIp, $rlWindowTs]);
                    $rlRow = $rlStmt->fetch(PDO::FETCH_ASSOC);
                    if ($rlRow === false) {
                        $rlDb->prepare(
                            "INSERT INTO recaptcha_rate_limit (ip, window_start, attempt_count)
                             VALUES (?, NOW(), 1)"
                        )->execute([$submissionIp]);
                    } elseif ((int)$rlRow['attempt_count'] >= $rlMax) {
                        $rateLimitHit = true;
                        recaptcha_audit_log('form_rate_limited', [
                            'form'    => 'quote_submit',
                            'count'   => (int)$rlRow['attempt_count'],
                            'max'     => $rlMax,
                            'window'  => $rlWindow,
                        ], $submissionIp);
                        error_log('[jobFlow-getQuote] rate limit hit for IP: ' . $submissionIp);
                    } else {
                        $rlDb->prepare(
                            "UPDATE recaptcha_rate_limit
                                SET attempt_count = attempt_count + 1
                              WHERE ip = ? AND window_start >= ?"
                        )->execute([$submissionIp, $rlWindowTs]);
                    }
                } catch (\Throwable $rlEx) {
                    // DB error — fail open (don't block real users)
                    error_log('[jobFlow-getQuote] rate limit check error: ' . $rlEx->getMessage());
                }
            }

            if ($rateLimitHit) {
                $error = 'Too many requests. Please wait a few minutes before trying again.';
            } else {

            // 2. Strict validation (via helper)
            $validated = validateQuoteForm($_POST);
            $errors    = $validated['errors'];
            $clean     = $validated['data'];

            // 3. reCAPTCHA v3 → v2 fallback
            $ip      = $_SERVER['REMOTE_ADDR'] ?? null;
            $v2Token = (string)($_POST['recaptcha_v2_token'] ?? '');
            $v3Token = (string)($_POST['recaptcha_v3_token'] ?? '');

            if ($v2Token !== '') {
                if (!verify_recaptcha_v2_audited($v2Token, $ip)) {
                    $errors['recaptcha'] = 'Security check failed. Please try again.';
                }
            } elseif ($v3Token !== '') {
                $v3Result = verify_recaptcha_v3_audited($v3Token, 'quote_submit', $ip);
                if (!$v3Result['passed']) {
                    if ($v3Result['needs_v2']) {
                        $showV2Challenge = true;
                        $errors['recaptcha'] = 'Please complete the security check below.';
                    } else {
                        $errors['recaptcha'] = 'Security verification failed. Please try again.';
                    }
                }
            } else {
                $errors['recaptcha'] = 'Security check required. Please ensure JavaScript is enabled.';
            }

            if (empty($errors)) {
                // 4. Check for existing contact
                $db = getDB();
                $stmt = $db->prepare("
                    SELECT c.id AS contact_id, c.first_name, c.last_name, c.email, c.phone,
                           p.id AS property_id, p.address, p.city, p.postal_code
                    FROM contacts c
                    LEFT JOIN properties p ON c.id = p.site_contact_id
                    WHERE (c.phone = ? AND c.phone != '')
                       OR (c.email = ? AND c.email != '')
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$clean['phone'], $clean['email']]);
                $existingRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $sessionPayload = array_merge($clean, [
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'timestamp'  => date('Y-m-d H:i:s'),
                    'tracking'   => $jfTrack,
                    'upsells'    => [],
                ]);

                if (!empty($existingRecords)) {
                    // Show address choice to returning contact
                    $showAddressOptions = true;
                    $existingAddresses  = $existingRecords;
                    $sessionPayload['existing_contact_id']  = $existingRecords[0]['contact_id'] ?? null;
                    $sessionPayload['existing_property_id'] = $existingRecords[0]['property_id'] ?? null;
                    $sessionPayload['address_relationship'] = 'same_address';
                    $_SESSION['temp_quote_data'] = $sessionPayload;
                } else {
                    // New contact — straight to confirm
                    $_SESSION['quote_data'] = $sessionPayload;
                    // Regenerate CSRF after successful Step 1
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: jobFlow-confirm.php');
                    exit();
                }

            } else {
                $error = $showV2Challenge && count($errors) === 1 && isset($errors['recaptcha'])
                    ? 'Please complete the security check to continue.'
                    : 'Please correct the errors below and try again.';
            }
        } // end: rate limit passed
        } // end: CSRF passed
    }
}

// ── Form pre-fill ──────────────────────────────────────────────────────────
// Priority: session data (returning from confirm) > POST data (validation errors) > URL params
$formData = $_SESSION['quote_data'] ?? $_SESSION['temp_quote_data'] ?? [];
if (empty($formData) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $formData = $clean ?? [];
}
if (empty($formData['property_type']) && !empty($jfTrack['property_type'])) {
    $formData['property_type'] = whitelistValue($jfTrack['property_type'], VALID_PROPERTY_TYPES, 'residential');
}
if (empty($formData['service_types']) && !empty($jfTrack['service'])) {
    $svc = whitelistValue($jfTrack['service'], VALID_SERVICE_TYPES, '');
    $formData['service_types'] = $svc !== '' ? [$svc] : [];
}

// ── reCAPTCHA mode ─────────────────────────────────────────────────────────
$useV3 = is_recaptcha_v3_configured() && !$showV2Challenge;

$serviceOptions = [
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
    <title>Get Your Free Quote — Mowology</title>
    <meta name="description" content="Get a free landscaping or snow removal quote from Mowology. Vancouver's trusted property care company. Fast response guaranteed.">
    <link rel="stylesheet" href="/assets/css/master.css">
    <link rel="stylesheet" href="/assets/css/pages/jobflow-quote.css?v=20260305">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script async src="https://maps.googleapis.com/maps/api/js?key=<?php echo h(GOOGLE_MAPS_API_KEY); ?>&libraries=places"></script>
<?php if ($useV3): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo h(RECAPTCHA_V3_SITE_KEY); ?>"></script>
<?php else: ?>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
<?php endif; ?>
</head>

<body class="jobflow-page">
<div class="container">

    <!-- Header -->
    <div class="jobflow-header">
        <div class="jobflow-logo">🌱</div>
        <h1>Get Your Free Quote</h1>
        <p>Vancouver's trusted landscaping &amp; snow removal experts</p>

        <!-- Trust indicators -->
        <div class="trust-badges">
            <span class="trust-badge">✓ Free estimate</span>
            <span class="trust-badge">✓ No obligation</span>
            <span class="trust-badge">✓ <?php echo h($responseTimeBadge); ?></span>
        </div>
    </div>

    <!-- Progress bar -->
    <div class="progress-bar">
        <div class="progress-step active">
            <div class="progress-circle">1</div>
            <div class="progress-label">Your Info</div>
        </div>
        <div class="progress-step">
            <div class="progress-circle">2</div>
            <div class="progress-label">Review</div>
        </div>
        <div class="progress-step">
            <div class="progress-circle">&#x2713;</div>
            <div class="progress-label">Complete</div>
        </div>
    </div>

<?php if ($seasonalMessage): ?>
    <!-- Seasonal urgency / tip banner -->
    <div class="seasonal-banner seasonal-<?php echo h($seasonalMessage['type']); ?>">
        <span class="seasonal-icon"><?php echo h($seasonalMessage['icon']); ?></span>
        <div>
            <strong><?php echo h($seasonalMessage['heading']); ?></strong>
            <?php echo h($seasonalMessage['body']); ?>
        </div>
    </div>
<?php endif; ?>

    <main class="form-card">

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo h($error); ?></div>
        <?php endif; ?>

        <?php if ($showAddressOptions): ?>
        <!-- ── Address confirmation ─────────────────────────────────────── -->
        <div class="address-options">
            <h3>We Found Your Information!</h3>
            <p>It looks like you've requested a quote before. Is this for the same address?</p>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="address_confirmed" value="1">

                <?php foreach ($existingAddresses as $existing): ?>
                    <?php if (empty($existing['address'])) continue; ?>
                    <label class="address-card">
                        <input type="radio" name="address_relationship" value="same_address" checked>
                        <strong><?php echo h($existing['address'] ?? ''); ?></strong><br>
                        <small><?php echo h(trim(($existing['city'] ?? '') . ' ' . ($existing['postal_code'] ?? ''))); ?></small>
                    </label>
                <?php endforeach; ?>

                <label class="address-card">
                    <input type="radio" name="address_relationship" value="new_address">
                    <strong>A different property address</strong><br>
                    <small>I'm requesting service at a new location</small>
                </label>

                <button type="submit" class="submit-button">Continue to Quote Review</button>
            </form>
        </div>

        <?php else: ?>
        <!-- ── Main quote form ─────────────────────────────────────────── -->
        <form method="POST" action="" id="quote-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
            <input type="hidden" id="latitude"  name="latitude"  value="<?php echo h((string)($formData['latitude']  ?? '')); ?>">
            <input type="hidden" id="longitude" name="longitude" value="<?php echo h((string)($formData['longitude'] ?? '')); ?>">

            <!-- Contact info -->
            <h2 class="form-section-title">Your Details</h2>
            <div class="form-grid">
                <div class="form-group <?php echo isset($errors['first_name']) ? 'error' : ''; ?>">
                    <label for="first_name" class="required">First Name</label>
                    <input type="text" id="first_name" name="first_name"
                           value="<?php echo h($formData['first_name'] ?? ''); ?>"
                           maxlength="80" autocomplete="given-name" required>
                    <?php if (isset($errors['first_name'])): ?>
                        <div class="error-message"><?php echo h($errors['first_name']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group <?php echo isset($errors['last_name']) ? 'error' : ''; ?>">
                    <label for="last_name" class="required">Last Name</label>
                    <input type="text" id="last_name" name="last_name"
                           value="<?php echo h($formData['last_name'] ?? ''); ?>"
                           maxlength="80" autocomplete="family-name" required>
                    <?php if (isset($errors['last_name'])): ?>
                        <div class="error-message"><?php echo h($errors['last_name']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group full-width <?php echo isset($errors['email']) ? 'error' : ''; ?>">
                    <label for="email">Email <span class="optional-label">(optional)</span></label>
                    <input type="email" id="email" name="email"
                           value="<?php echo h($formData['email'] ?? ''); ?>"
                           maxlength="254" autocomplete="email">
                    <?php if (isset($errors['email'])): ?>
                        <div class="error-message"><?php echo h($errors['email']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group full-width <?php echo isset($errors['phone']) ? 'error' : ''; ?>">
                    <label for="phone" class="required">Phone</label>
                    <input type="tel" id="phone" name="phone"
                           value="<?php echo h($formData['phone'] ?? ''); ?>"
                           maxlength="20" autocomplete="tel" required
                           placeholder="(604) 555-1234">
                    <?php if (isset($errors['phone'])): ?>
                        <div class="error-message"><?php echo h($errors['phone']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Property info -->
            <h2 class="form-section-title" style="margin-top: 28px;">Property Details</h2>
            <div class="form-grid">
                <div class="form-group full-width <?php echo isset($errors['address']) ? 'error' : ''; ?>">
                    <label for="address" class="required">Property Address</label>
                    <input type="text" id="address" name="address"
                           value="<?php echo h($formData['address'] ?? ''); ?>"
                           maxlength="255" autocomplete="street-address" required
                           placeholder="Start typing your address…">
                    <?php if (isset($errors['address'])): ?>
                        <div class="error-message"><?php echo h($errors['address']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city"
                           value="<?php echo h($formData['city'] ?? 'Vancouver'); ?>"
                           maxlength="100" autocomplete="address-level2">
                </div>

                <div class="form-group">
                    <label for="postalCode">Postal Code <span class="optional-label">(optional)</span></label>
                    <input type="text" id="postalCode" name="postal_code"
                           value="<?php echo h($formData['postal_code'] ?? ''); ?>"
                           maxlength="10" autocomplete="postal-code" placeholder="V5K 0A1">
                </div>

                <div class="form-group full-width">
                    <label for="property_type">Property Type</label>
                    <select id="property_type" name="property_type">
                        <?php foreach (VALID_PROPERTY_TYPES as $pt): ?>
                            <option value="<?php echo h($pt); ?>"
                                <?php echo (($formData['property_type'] ?? 'residential') === $pt) ? 'selected' : ''; ?>>
                                <?php echo h(ucfirst($pt)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Services needed -->
            <h2 class="form-section-title" style="margin-top: 28px;">Services Needed</h2>
            <div class="form-group full-width <?php echo isset($errors['service_types']) ? 'error' : ''; ?>">
                <label class="required">Select all that apply</label>
                <div class="service-grid">
                    <?php
                    $selectedServices = (array)($formData['service_types'] ?? []);
                    $serviceIcons = [
                        'maintenance'    => '🌿',
                        'cleanup'        => '🍂',
                        'hedge_trimming' => '✂️',
                        'lawn_care'      => '🌱',
                        'snow_removal'   => '❄️',
                    ];
                    foreach ($serviceOptions as $key => $label):
                        $checked = in_array($key, $selectedServices, true);
                    ?>
                    <label class="service-card <?php echo $checked ? 'selected' : ''; ?>">
                        <input type="checkbox" name="service_types[]" value="<?php echo h($key); ?>"
                               <?php echo $checked ? 'checked' : ''; ?>>
                        <span class="service-icon"><?php echo $serviceIcons[$key] ?? ''; ?></span>
                        <span class="service-label"><?php echo h($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php if (isset($errors['service_types'])): ?>
                    <div class="error-message"><?php echo h($errors['service_types']); ?></div>
                <?php endif; ?>
            </div>

            <!-- Lawn size (micro-commitment: shown conditionally) -->
            <div class="form-group full-width lawn-size-row" id="lawn-size-section" style="display:none;">
                <label>Lawn / Property Size</label>
                <div class="size-selector">
                    <?php foreach (VALID_LAWN_SIZES as $sz): ?>
                        <label class="size-card <?php echo (($formData['lawn_size'] ?? '') === $sz) ? 'selected' : ''; ?>">
                            <input type="radio" name="lawn_size" value="<?php echo h($sz); ?>"
                                   <?php echo (($formData['lawn_size'] ?? '') === $sz) ? 'checked' : ''; ?>>
                            <span class="size-label"><?php echo h(ucfirst($sz)); ?></span>
                            <?php if ($sz === 'small'): ?><small>Under 3,000 sq ft</small>
                            <?php elseif ($sz === 'medium'): ?><small>3,000–6,000 sq ft</small>
                            <?php else: ?><small>Over 6,000 sq ft</small>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Irrigation toggle (micro-commitment) -->
            <div class="form-group full-width" id="irrigation-section" style="display:none;">
                <label class="toggle-label">
                    <input type="checkbox" id="has_irrigation" name="has_irrigation" value="1"
                           <?php echo !empty($formData['has_irrigation']) ? 'checked' : ''; ?>>
                    <span>This property has an irrigation / sprinkler system</span>
                </label>
            </div>

            <!-- Urgency -->
            <div class="form-group full-width" style="margin-top: 12px;">
                <label for="urgency">How soon do you need service?</label>
                <select id="urgency" name="urgency">
                    <?php foreach (VALID_URGENCY_VALUES as $uv): ?>
                        <option value="<?php echo h($uv); ?>"
                            <?php echo (($formData['urgency'] ?? 'inquiring') === $uv) ? 'selected' : ''; ?>>
                            <?php
                            $urgencyLabels = ['inquiring' => 'Just inquiring', 'soon' => 'Within 2 weeks', 'asap' => 'ASAP — urgent'];
                            echo h($urgencyLabels[$uv] ?? ucfirst($uv));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Description -->
            <div class="form-group full-width">
                <label for="description">Tell us about your property <span class="optional-label">(optional)</span></label>
                <textarea id="description" name="description" rows="4" maxlength="2000"
                          placeholder="Any special considerations, access instructions, or specific areas to focus on…"><?php echo h($formData['description'] ?? ''); ?></textarea>
            </div>

            <!-- Communication preferences -->
            <div class="consent-section">
                <h2 class="form-section-title">Communication Preferences</h2>
                <p class="consent-intro">How you'd like to hear from us:</p>

                <div class="consent-item required <?php echo isset($errors['consent_quote']) ? 'error' : ''; ?>">
                    <input type="checkbox" id="consent_quote" name="consent_quote" required
                           <?php echo (empty($errors) || !empty($formData['consent_quote']) || $_SERVER['REQUEST_METHOD'] === 'GET') ? 'checked' : ''; ?>>
                    <div class="consent-label">
                        <strong>Quote Follow-up (Required)</strong>
                        I agree to be contacted about this quote request via email, phone, or text message.
                        <small>Required to process your request.</small>
                    </div>
                </div>
                <?php if (isset($errors['consent_quote'])): ?>
                    <div class="error-message" style="margin-top: -8px; margin-bottom: 12px;"><?php echo h($errors['consent_quote']); ?></div>
                <?php endif; ?>

                <div class="consent-item">
                    <input type="checkbox" id="consent_marketing" name="consent_marketing"
                           <?php echo !empty($formData['consent_marketing']) ? 'checked' : ''; ?>>
                    <div class="consent-label">
                        <strong>Optional: Promotions &amp; Tips</strong>
                        Send me occasional seasonal reminders, promotions, and property care tips.
                    </div>
                </div>

                <div class="consent-item">
                    <input type="checkbox" id="consent_sms" name="consent_sms"
                           <?php echo (!empty($formData['consent_sms']) || (empty($errors) && $_SERVER['REQUEST_METHOD'] === 'GET')) ? 'checked' : ''; ?>>
                    <div class="consent-label">
                        <strong>Optional: Text Updates</strong>
                        I agree to receive SMS updates about scheduling and quote progress.
                    </div>
                </div>
            </div>

            <!-- reCAPTCHA -->
            <?php if ($useV3): ?>
                <input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">
                <input type="hidden" name="quote_form_submit" id="quote_form_submit" value="1">
            <?php else: ?>
                <div class="form-group <?php echo isset($errors['recaptcha']) ? 'error' : ''; ?>" style="margin-top: 18px;">
                    <label class="required">Security Check</label>
                    <div class="g-recaptcha"
                         data-sitekey="<?php echo h(RECAPTCHA_V2_SITE_KEY); ?>"
                         data-callback="onV2Completed"></div>
                    <input type="hidden" name="recaptcha_v2_token" id="recaptcha_v2_token" value="">
                    <?php if (isset($errors['recaptcha'])): ?>
                        <div class="error-message"><?php echo h($errors['recaptcha']); ?></div>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="quote_form_submit" id="quote_form_submit" value="1">
            <?php endif; ?>

            <?php if (isset($errors['recaptcha']) && $useV3): ?>
                <div class="form-group error" style="margin-top: 6px;">
                    <div class="error-message"><?php echo h($errors['recaptcha']); ?></div>
                </div>
            <?php endif; ?>

            <button type="submit" class="submit-button">Continue to Quote Review &rarr;</button>
            <p class="form-privacy-note">Your information is never shared or sold.</p>
        </form>
        <?php endif; ?>

    </main>
</div>

<script>
// ── Google Maps Autocomplete ───────────────────────────────────────────────
let autocomplete;
function initAutocomplete() {
    const input = document.getElementById('address');
    if (!input) return;
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        setTimeout(initAutocomplete, 100);
        return;
    }
    autocomplete = new google.maps.places.Autocomplete(input, {
        types: ['address'],
        componentRestrictions: { country: ['ca'] },
        fields: ['address_components', 'geometry', 'formatted_address']
    });
    autocomplete.addListener('place_changed', () => {
        const place = autocomplete.getPlace();
        if (!place || !place.geometry) return;
        const lat = place.geometry.location.lat();
        const lng = place.geometry.location.lng();
        const latIn = document.getElementById('latitude');
        const lngIn = document.getElementById('longitude');
        if (latIn) latIn.value = lat;
        if (lngIn) lngIn.value = lng;

        let streetAddress = '', city = '', postalCode = '';
        if (place.address_components) {
            for (const c of place.address_components) {
                if (c.types.includes('street_number')) streetAddress = c.long_name + ' ' + streetAddress;
                if (c.types.includes('route'))         streetAddress = streetAddress + c.long_name;
                if (c.types.includes('postal_code'))   postalCode = c.long_name;
                if (c.types.includes('locality'))      city = c.long_name;
            }
        }
        if (streetAddress.trim()) input.value = streetAddress.trim();
        const cityIn   = document.getElementById('city');
        const postalIn = document.getElementById('postalCode');
        if (cityIn   && city)       cityIn.value   = city;
        if (postalIn && postalCode) postalIn.value = postalCode;
    });
}

// ── Lawn size / irrigation toggle ─────────────────────────────────────────
(function() {
    const lawnServices = ['lawn_care', 'maintenance'];
    const lawnSection  = document.getElementById('lawn-size-section');
    const irrSection   = document.getElementById('irrigation-section');

    function updateSections() {
        const boxes = document.querySelectorAll('input[name="service_types[]"]:checked');
        const selected = Array.from(boxes).map(b => b.value);
        const hasLawn = selected.some(s => lawnServices.includes(s));
        if (lawnSection) lawnSection.style.display = hasLawn ? '' : 'none';
        if (irrSection)  irrSection.style.display  = hasLawn ? '' : 'none';
    }

    document.querySelectorAll('input[name="service_types[]"]').forEach(cb => {
        cb.addEventListener('change', updateSections);
    });

    // Service card visual toggle
    document.querySelectorAll('.service-card').forEach(card => {
        card.addEventListener('change', function() {
            this.classList.toggle('selected', this.querySelector('input[type="checkbox"]').checked);
        });
    });

    // Size card visual toggle
    document.querySelectorAll('.size-card').forEach(card => {
        card.addEventListener('change', function() {
            document.querySelectorAll('.size-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
        });
    });

    updateSections();
})();

<?php if ($useV3): ?>
// ── reCAPTCHA v3 ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete();
    var form = document.getElementById('quote-form');
    var tokenField = document.getElementById('recaptcha_v3_token');
    if (!form || !tokenField) return;

    var submitting = false;
    var captchaReady = false;

    var poll = setInterval(function() {
        if (typeof grecaptcha !== 'undefined') {
            captchaReady = true;
            clearInterval(poll);
        }
    }, 100);
    setTimeout(function() { clearInterval(poll); }, 8000);

    form.addEventListener('submit', function(e) {
        if (submitting) return;
        e.preventDefault();

        var siteKey = <?php echo json_encode(RECAPTCHA_V3_SITE_KEY); ?>;

        if (!captchaReady || typeof grecaptcha === 'undefined') {
            // Wait briefly then submit without token (server will demand v2)
            var waited = 0;
            var wait = setInterval(function() {
                waited++;
                if (typeof grecaptcha !== 'undefined') {
                    clearInterval(wait);
                    form.dispatchEvent(new Event('submit'));
                } else if (waited > 40) {
                    clearInterval(wait);
                    submitting = true;
                    form.submit();
                }
            }, 100);
            return;
        }

        grecaptcha.ready(function() {
            grecaptcha.execute(siteKey, { action: 'quote_submit' }).then(function(token) {
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
// ── reCAPTCHA v2 ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete();
});
function onV2Completed(token) {
    var field = document.getElementById('recaptcha_v2_token');
    if (field) field.value = token;
}
<?php endif; ?>

// ── Scroll to first error on page load ───────────────────────────────────
<?php if (!empty($errors)): ?>
document.addEventListener('DOMContentLoaded', function() {
    var firstError = document.querySelector('.form-group.error, .consent-item.error');
    if (firstError) {
        // Scroll the error field into view with some top offset
        var rect = firstError.getBoundingClientRect();
        var scrollTarget = window.pageYOffset + rect.top - 120;
        window.scrollTo({ top: Math.max(0, scrollTarget), behavior: 'smooth' });

        // Add shake class to all error fields, then remove after animation
        var errorGroups = document.querySelectorAll('.form-group.error');
        errorGroups.forEach(function(g) { g.classList.add('shake'); });
        setTimeout(function() {
            errorGroups.forEach(function(g) { g.classList.remove('shake'); });
        }, 600);

        // Focus the first error input
        var firstInput = firstError.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(function() { firstInput.focus(); }, 400);
        }
    }
});
<?php endif; ?>
</script>
</body>
</html>
