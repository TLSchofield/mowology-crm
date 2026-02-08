<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Canonical location: /public/jobFlow/
// dirname(__DIR__) = /public/
require_once dirname(__DIR__) . '/app_config/session_config.php';
require_once dirname(__DIR__) . '/app_config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token for form security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Campaign & Source Tracking ──
// Capture inbound query params on first load (GET request only).
// These persist through the multi-step form flow via session.
$trackableParams = ['service', 'property_type', 'src', 'promo', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach ($trackableParams as $param) {
        $val = isset($_GET[$param]) ? trim((string)$_GET[$param]) : '';
        if ($val !== '') {
            // Sanitize: alphanumeric, hyphens, underscores only
            $_SESSION['jf_track'][$param] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $val);
        }
    }
    // Also store the HTTP referer on first visit (if not already set)
    if (!isset($_SESSION['jf_track']['referrer']) && !empty($_SERVER['HTTP_REFERER'])) {
        $_SESSION['jf_track']['referrer'] = substr((string)$_SERVER['HTTP_REFERER'], 0, 500);
    }
}
$jfTrack = $_SESSION['jf_track'] ?? [];

// Data cleaning functions
function cleanPhone($phone) {
    if (empty($phone)) return null;
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 10) {
        return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
    }
    return $phone;
}

function cleanPostalCode($postal) {
    if (empty($postal)) return null;
    $postal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $postal));
    if (strlen($postal) === 6) {
        return substr($postal, 0, 3) . ' ' . substr($postal, 3, 3);
    }
    return $postal;
}

function cleanAddress($address) {
    $address = trim($address);
    $address = ucwords(strtolower($address));
    $address = preg_replace('/\bSt\b/i', 'Street', $address);
    $address = preg_replace('/\bAve\b/i', 'Avenue', $address);
    $address = preg_replace('/\bRd\b/i', 'Road', $address);
    $address = preg_replace('/\bDr\b/i', 'Drive', $address);
    $address = preg_replace('/\bBlvd\b/i', 'Boulevard', $address);
    return $address;
}

function cleanName($name) {
    $name = preg_replace("/[^a-zA-Z\s\-\']/", '', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    $name = ucwords(strtolower($name));
    return $name;
}

// Shared reCAPTCHA verification helpers (v3 + v2 fallback)
require_once __DIR__ . '/recaptcha-helpers.php';

// Track whether the v2 checkbox fallback should be shown
$showV2Challenge = false;

$error = '';
$errors = [];
$existingAddresses = [];
$showAddressOptions = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== JOBFLOW FORM SUBMITTED ===");

    if (isset($_POST['address_confirmed'])) {
        // Step 2: Address confirmation
        if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $_SESSION['quote_data'] = $_SESSION['temp_quote_data'] ?? [];
            unset($_SESSION['temp_quote_data']);
            header('Location: jobFlow-confirm.php');
            exit();
        }
    } else {
        // Step 1: Initial form submission
        $sessionCsrf = $_SESSION['csrf_token'] ?? '';
        $postCsrf = (string)($_POST['csrf_token'] ?? '');

        if (!hash_equals($sessionCsrf, $postCsrf)) {
            $error = 'Security check failed. Please refresh and try again.';
        } else {
            $firstName = cleanName($_POST['first_name'] ?? '');
            $lastName  = cleanName($_POST['last_name'] ?? '');
            $fullName  = trim($firstName . ' ' . $lastName);

            $email       = trim(strtolower($_POST['email'] ?? ''));
            $phone       = cleanPhone($_POST['phone'] ?? '');
            $address     = cleanAddress($_POST['address'] ?? '');
            $city        = trim(ucwords(strtolower($_POST['city'] ?? 'Vancouver')));
            $postal_code = cleanPostalCode($_POST['postal_code'] ?? '');

            $latitude  = $_POST['latitude'] ?? null;
            $longitude = $_POST['longitude'] ?? null;

            // Validate required fields
            if (empty($firstName)) $errors['first_name'] = 'Please enter your first name';
            if (empty($lastName))  $errors['last_name']  = 'Please enter your last name';
            if (empty($phone))     $errors['phone']      = 'Phone number is required';
            if (empty($address))   $errors['address']    = 'Please enter your property address';
            if (empty($_POST['service_types'])) $errors['service_types'] = 'Please select at least one service';
            if (empty($_POST['consent_quote'])) $errors['consent_quote'] = 'You must agree to be contacted';

            // ── reCAPTCHA v3 → v2 Fallback ──
            // 1. Check for v2 token first (user already completed checkbox challenge)
            // 2. Otherwise, check v3 token (invisible, scored)
            // 3. If v3 score is too low, show v2 checkbox and re-render form
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $v2Token = (string)($_POST['recaptcha_v2_token'] ?? '');
            $v3Token = (string)($_POST['recaptcha_v3_token'] ?? '');

            if ($v2Token !== '') {
                // User completed v2 checkbox fallback — verify it
                if (!verify_recaptcha_v2_token($v2Token, $ip)) {
                    $errors['recaptcha'] = 'Security check failed. Please try again.';
                }
            } elseif ($v3Token !== '') {
                // Verify v3 invisible token
                $v3Result = verify_recaptcha_v3($v3Token, 'quote_submit', $ip);

                if (!$v3Result['passed']) {
                    if ($v3Result['needs_v2']) {
                        // Score too low or verification error — require v2 checkbox
                        $showV2Challenge = true;
                        $errors['recaptcha'] = 'Please complete the security check below.';
                    } else {
                        $errors['recaptcha'] = 'Security verification failed. Please try again.';
                    }
                }
                // If passed, no error — continue with form processing
            } else {
                // No token at all — likely JS disabled or tampering
                $errors['recaptcha'] = 'Security check required. Please ensure JavaScript is enabled.';
            }

            if (empty($errors)) {
                $db = getDB();

                // Check for existing contact and their properties
                $stmt = $db->prepare("
                    SELECT c.id as contact_id, c.first_name, c.last_name, c.email, c.phone,
                           p.id as property_id, p.address, p.city, p.postal_code
                    FROM contacts c
                    LEFT JOIN properties p ON c.id = p.site_contact_id
                    WHERE (c.phone = ? OR (c.email = ? AND c.email != ''))
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute([$phone, $email]);
                $existingRecords = $stmt->fetchAll();

                if (!empty($existingRecords)) {
                    $addressMatch = false;
                    foreach ($existingRecords as $record) {
                        if (!empty($record['address']) && !empty($address) &&
                            (stripos($record['address'], $address) !== false ||
                             stripos($address, $record['address']) !== false)) {
                            $addressMatch = true;
                            break;
                        }
                    }

                    $showAddressOptions = true;
                    $existingAddresses  = $existingRecords;

                    $_SESSION['temp_quote_data'] = [
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'name'       => $fullName,
                        'email'      => $email,
                        'phone'      => $phone,
                        'address'    => $address,
                        'city'       => $city,
                        'postal_code'=> $postal_code,
                        'latitude'   => $latitude,
                        'longitude'  => $longitude,
                        'property_type'      => $_POST['property_type'] ?? 'residential',
                        'service_types'      => $_POST['service_types'] ?? [],
                        'urgency'            => $_POST['urgency'] ?? 'inquiring',
                        'preferred_contact'  => $_POST['preferred_contact'] ?? 'phone',
                        'description'        => trim($_POST['description'] ?? ''),
                        'consent_quote'      => true,
                        'consent_marketing'  => isset($_POST['consent_marketing']),
                        'consent_sms'        => isset($_POST['consent_sms']),
                        'ip_address'         => $_SERVER['REMOTE_ADDR'] ?? '',
                        'timestamp'          => date('Y-m-d H:i:s'),
                        'existing_contact_id' => $existingRecords[0]['contact_id'] ?? null,
                        'existing_property_id' => $existingRecords[0]['property_id'] ?? null,
                        'address_relationship' => $_POST['address_relationship'] ?? ($addressMatch ? 'same_address' : 'new_address'),
                        'tracking'           => $jfTrack,
                    ];
                } else {
                    $_SESSION['quote_data'] = [
                        'first_name' => $firstName,
                        'last_name'  => $lastName,
                        'name'       => $fullName,
                        'email'      => $email,
                        'phone'      => $phone,
                        'address'    => $address,
                        'city'       => $city,
                        'postal_code'=> $postal_code,
                        'latitude'   => $latitude,
                        'longitude'  => $longitude,
                        'property_type'      => $_POST['property_type'] ?? 'residential',
                        'service_types'      => $_POST['service_types'] ?? [],
                        'urgency'            => $_POST['urgency'] ?? 'inquiring',
                        'preferred_contact'  => $_POST['preferred_contact'] ?? 'phone',
                        'description'        => trim($_POST['description'] ?? ''),
                        'consent_quote'      => true,
                        'consent_marketing'  => isset($_POST['consent_marketing']),
                        'consent_sms'        => isset($_POST['consent_sms']),
                        'ip_address'         => $_SERVER['REMOTE_ADDR'] ?? '',
                        'timestamp'          => date('Y-m-d H:i:s'),
                        'tracking'           => $jfTrack,
                    ];

                    header('Location: jobFlow-confirm.php');
                    exit();
                }
            } else {
                // Set top-level error message — customize for v2 challenge vs field errors
                if ($showV2Challenge && count($errors) === 1 && isset($errors['recaptcha'])) {
                    $error = 'Please complete the security check to continue.';
                } else {
                    $error = 'Please fill in all required fields';
                }
            }
        }
    }
}

// Pre-fill form: from session (returning from confirm page) or from POST (validation errors / v2 fallback)
$formData = $_SESSION['quote_data'] ?? $_SESSION['temp_quote_data'] ?? [];
if (empty($formData) && $_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($errors) || $showV2Challenge)) {
    $formData = [
        'first_name'    => $_POST['first_name'] ?? '',
        'last_name'     => $_POST['last_name'] ?? '',
        'email'         => $_POST['email'] ?? '',
        'phone'         => $_POST['phone'] ?? '',
        'address'       => $_POST['address'] ?? '',
        'city'          => $_POST['city'] ?? 'Vancouver',
        'postal_code'   => $_POST['postal_code'] ?? '',
        'latitude'      => $_POST['latitude'] ?? '',
        'longitude'     => $_POST['longitude'] ?? '',
        'property_type' => $_POST['property_type'] ?? 'residential',
        'service_types' => $_POST['service_types'] ?? [],
        'urgency'       => $_POST['urgency'] ?? 'inquiring',
        'description'   => $_POST['description'] ?? '',
    ];
}

// Pre-select property_type from URL param (only if form is empty)
if (empty($formData['property_type']) && !empty($jfTrack['property_type'])) {
    $formData['property_type'] = $jfTrack['property_type'];
}

// Pre-check service checkbox from URL param (only if form is empty)
if (empty($formData['service_types']) && !empty($jfTrack['service'])) {
    $formData['service_types'] = [$jfTrack['service']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Your Free Quote - Mowology</title>
    <link rel="stylesheet" href="/assets/css/master.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script async src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(GOOGLE_MAPS_API_KEY, ENT_QUOTES, 'UTF-8'); ?>&libraries=places"></script>
<?php
    // Load v3 invisible reCAPTCHA by default.
    // If v3 score was too low (page re-rendered), load v2 checkbox instead.
    // If v3 is not configured at all, fall back to v2 from the start.
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
            <h1>Get Your Free Quote</h1>
            <p>Vancouver's trusted landscaping & snow removal experts</p>
        </div>

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
                <div class="progress-circle">✓</div>
                <div class="progress-label">Complete</div>
            </div>
        </div>

        <main class="form-card">
            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($showAddressOptions): ?>
                <div class="address-options">
                    <h3>We Found Your Information!</h3>
                    <p style="margin-bottom: 16px;">It looks like you've requested a quote before. Is this for:</p>

                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="address_confirmed" value="1">

                        <?php foreach ($existingAddresses as $existing): ?>
                            <label class="address-card">
                                <input type="radio" name="address_relationship" value="same_address" checked>
                                <strong><?php echo htmlspecialchars($existing['address'] ?? ''); ?></strong><br>
                                <small><?php echo htmlspecialchars(($existing['city'] ?? '') . ' ' . ($existing['postal_code'] ?? '')); ?></small>
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

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" id="latitude" name="latitude" value="<?php echo htmlspecialchars($formData['latitude'] ?? ''); ?>">
                    <input type="hidden" id="longitude" name="longitude" value="<?php echo htmlspecialchars($formData['longitude'] ?? ''); ?>">

                    <div class="form-grid">
                        <div class="form-group <?php echo isset($errors['first_name']) ? 'error' : ''; ?>">
                            <label class="required">First Name</label>
                            <input type="text" name="first_name" value="<?php echo htmlspecialchars($formData['first_name'] ?? ''); ?>" required>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="error-message" style="display:block;"><?php echo $errors['first_name']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group <?php echo isset($errors['last_name']) ? 'error' : ''; ?>">
                            <label class="required">Last Name</label>
                            <input type="text" name="last_name" value="<?php echo htmlspecialchars($formData['last_name'] ?? ''); ?>" required>
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="error-message" style="display:block;"><?php echo $errors['last_name']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full-width">
                            <label>Email (optional)</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>">
                        </div>

                        <div class="form-group full-width <?php echo isset($errors['phone']) ? 'error' : ''; ?>">
                            <label class="required">Phone</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>" required>
                            <?php if (isset($errors['phone'])): ?>
                                <div class="error-message" style="display:block;"><?php echo $errors['phone']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full-width <?php echo isset($errors['address']) ? 'error' : ''; ?>">
                            <label class="required">Property Address</label>
                            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($formData['address'] ?? ''); ?>" required>
                            <?php if (isset($errors['address'])): ?>
                                <div class="error-message" style="display:block;"><?php echo $errors['address']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label>City</label>
                            <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($formData['city'] ?? 'Vancouver'); ?>">
                        </div>

                        <div class="form-group">
                            <label>Postal Code (optional)</label>
                            <input type="text" id="postalCode" name="postal_code" value="<?php echo htmlspecialchars($formData['postal_code'] ?? ''); ?>">
                        </div>

                        <div class="form-group full-width">
                            <label>Property Type</label>
                            <select name="property_type">
                                <option value="residential" <?php echo (($formData['property_type'] ?? '') === 'residential') ? 'selected' : ''; ?>>Residential</option>
                                <option value="strata" <?php echo (($formData['property_type'] ?? '') === 'strata') ? 'selected' : ''; ?>>Strata</option>
                                <option value="commercial" <?php echo (($formData['property_type'] ?? '') === 'commercial') ? 'selected' : ''; ?>>Commercial</option>
                            </select>
                        </div>

                        <div class="form-group full-width <?php echo isset($errors['service_types']) ? 'error' : ''; ?>">
                            <label class="required">Services Needed</label>
                            <div class="checkbox-group">
                                <?php
                                $selectedServices = $formData['service_types'] ?? [];
                                $serviceOptions = [
                                    'maintenance' => 'Maintenance',
                                    'cleanup' => 'Cleanup',
                                    'hedge_trimming' => 'Hedge Trimming',
                                    'lawn_care' => 'Lawn Care',
                                    'snow_removal' => 'Snow Removal'
                                ];
                                foreach ($serviceOptions as $key => $label):
                                ?>
                                    <label class="checkbox-item">
                                        <input type="checkbox" name="service_types[]" value="<?php echo $key; ?>"
                                            <?php echo in_array($key, $selectedServices) ? 'checked' : ''; ?>>
                                        <?php echo $label; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (isset($errors['service_types'])): ?>
                                <div class="error-message" style="display:block;"><?php echo $errors['service_types']; ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group full-width">
                            <label>How urgent is this?</label>
                            <select name="urgency">
                                <option value="inquiring" <?php echo (($formData['urgency'] ?? '') === 'inquiring') ? 'selected' : ''; ?>>Just inquiring</option>
                                <option value="soon" <?php echo (($formData['urgency'] ?? '') === 'soon') ? 'selected' : ''; ?>>Within 2 weeks</option>
                                <option value="asap" <?php echo (($formData['urgency'] ?? '') === 'asap') ? 'selected' : ''; ?>>ASAP</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Describe what you need</label>
                            <textarea name="description" placeholder="Tell us about your project..."><?php echo htmlspecialchars($formData['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="consent-section">
                        <h2 class="form-section-title">Communication Preferences</h2>
                        <p style="color: #6c757d; margin-bottom: 20px; font-size: 14px;">Please let us know how you'd like to hear from us:</p>

                        <div class="consent-item required <?php echo isset($errors['consent_quote']) ? 'error' : ''; ?>">
                            <input type="checkbox" id="consent_quote" name="consent_quote" required>
                            <div class="consent-label">
                                <strong>Quote Follow-up (Required)</strong>
                                I agree to be contacted about this quote request via email, phone, or text message.
                                <small>This allows us to respond to your inquiry. Required to process your request.</small>
                            </div>
                        </div>
                        <?php if (isset($errors['consent_quote'])): ?>
                            <div class="error-message" style="display: block; margin-top: -8px; margin-bottom: 12px;"><?php echo $errors['consent_quote']; ?></div>
                        <?php endif; ?>

                        <?php if ($useV3): ?>
                            <!-- reCAPTCHA v3: invisible, token added via JS on submit -->
                            <input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">
                        <?php else: ?>
                            <!-- reCAPTCHA v2 checkbox fallback (v3 not configured or score too low) -->
                            <div class="form-group <?php echo isset($errors['recaptcha']) ? 'error' : ''; ?>" style="margin-top: 18px;">
                                <label class="required">Security Check</label>
                                <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars(RECAPTCHA_V2_SITE_KEY, ENT_QUOTES, 'UTF-8'); ?>" data-callback="onV2Completed"></div>
                                <input type="hidden" name="recaptcha_v2_token" id="recaptcha_v2_token" value="">
                                <?php if (isset($errors['recaptcha'])): ?>
                                    <div class="error-message" style="display:block;"><?php echo htmlspecialchars($errors['recaptcha']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($errors['recaptcha']) && $useV3): ?>
                            <div class="form-group error" style="margin-top: 18px;">
                                <div class="error-message" style="display:block;"><?php echo htmlspecialchars($errors['recaptcha']); ?></div>
                            </div>
                        <?php endif; ?>
                        <input type="hidden" name="quote_form_submit" value="1" id="quote_form_submit">

                        <div class="consent-item">
                            <input type="checkbox" id="consent_marketing" name="consent_marketing">
                            <div class="consent-label">
                                <strong>Optional: Promotions & Tips</strong>
                                Send me occasional seasonal reminders, promotions, and property care tips.
                            </div>
                        </div>

                        <div class="consent-item">
                            <input type="checkbox" id="consent_sms" name="consent_sms">
                            <div class="consent-label">
                                <strong>Optional: Text Updates</strong>
                                I agree to receive SMS updates about scheduling and quote progress.
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="submit-button">Continue to Quote Review</button>
                </form>

            <?php endif; ?>
        </main>
    </div>

<script>
// ── Google Maps Autocomplete ──
let autocomplete;
function initAutocomplete() {
    const input = document.getElementById('address');
    if (!input) return;

    // Wait for Google Maps to be loaded
    if (typeof google === 'undefined' || !google.maps || !google.maps.places) {
        console.log('Google Maps not ready, retrying in 100ms...');
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

        // Extract and store geocodes (latitude, longitude)
        const latitude = place.geometry.location.lat();
        const longitude = place.geometry.location.lng();

        const latInput = document.getElementById('latitude');
        const lngInput = document.getElementById('longitude');

        if (latInput) latInput.value = latitude;
        if (lngInput) lngInput.value = longitude;

        // Log geocodes to console for verification
        console.log('✓ Geocodes extracted - Latitude:', latitude, 'Longitude:', longitude);

        // Extract address components (city, postal code)
        let city = '';
        let postalCode = '';

        if (place.address_components) {
            for (let component of place.address_components) {
                const types = component.types;

                // Extract postal code
                if (types.includes('postal_code')) {
                    postalCode = component.long_name;
                }

                // Extract city (locality)
                if (types.includes('locality')) {
                    city = component.long_name;
                }
            }
        }

        // Populate city field
        if (city) {
            const cityInput = document.getElementById('city');
            if (cityInput) {
                cityInput.value = city;
                console.log('✓ City populated:', city);
            }
        }

        // Populate postal code field
        if (postalCode) {
            const postalInput = document.getElementById('postalCode');
            if (postalInput) {
                postalInput.value = postalCode;
                console.log('✓ Postal Code populated:', postalCode);
            }
        }
    });
}

<?php if ($useV3): ?>
// ── reCAPTCHA v3: intercept form submit, get token, then submit ──
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Google Places Autocomplete
    if (typeof initAutocomplete === 'function') {
        initAutocomplete();
    }

    // Find the quote form (not the address confirmation form)
    var form = document.getElementById('quote_form_submit') ? document.getElementById('quote_form_submit').closest('form') : null;
    if (!form || !document.getElementById('recaptcha_v3_token')) return;

    console.log('Quote form found, attaching reCAPTCHA handler');

    var submitting = false;
    var recaptchaReady = false;

    // Wait for grecaptcha to be available
    var recaptchaCheckInterval = setInterval(function() {
        if (typeof grecaptcha !== 'undefined') {
            recaptchaReady = true;
            clearInterval(recaptchaCheckInterval);
        }
    }, 100);

    // Timeout after 5 seconds
    setTimeout(function() {
        if (recaptchaCheckInterval) clearInterval(recaptchaCheckInterval);
    }, 5000);

    form.addEventListener('submit', function(e) {
        // If we already have a v3 token, allow the submit to proceed
        if (submitting) return;

        e.preventDefault();

        var siteKey = <?php echo json_encode(RECAPTCHA_V3_SITE_KEY); ?>;

        // Check if grecaptcha is available
        if (typeof grecaptcha === 'undefined' || !recaptchaReady) {
            // reCAPTCHA not available yet - wait a bit and try again
            var waitCount = 0;
            var waitInterval = setInterval(function() {
                waitCount++;
                if (typeof grecaptcha !== 'undefined') {
                    clearInterval(waitInterval);
                    recaptchaReady = true;
                    // Trigger the submit again
                    form.dispatchEvent(new Event('submit'));
                } else if (waitCount > 50) {
                    // Waited 5 seconds total, give up
                    clearInterval(waitInterval);
                    console.error('reCAPTCHA failed to load - falling back to v2');
                    submitting = true;
                    form.submit();
                }
            }, 100);
            return;
        }

        grecaptcha.ready(function() {
            console.log('Executing reCAPTCHA v3 with site key:', siteKey.substring(0, 10) + '...');
            grecaptcha.execute(siteKey, { action: 'quote_submit' }).then(function(token) {
                console.log('✓ reCAPTCHA v3 token received:', token.substring(0, 20) + '...');
                document.getElementById('recaptcha_v3_token').value = token;
                submitting = true;
                form.submit();
            }).catch(function(err) {
                console.error('reCAPTCHA execute error:', err);
                // On error, submit without token — server will require v2 fallback
                submitting = true;
                form.submit();
            });
        });
    });
});
<?php else: ?>
// ── reCAPTCHA v2 checkbox: copy token to hidden field on completion ──
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Google Places Autocomplete
    if (typeof initAutocomplete === 'function') {
        initAutocomplete();
    }
});

function onV2Completed(token) {
    var field = document.getElementById('recaptcha_v2_token');
    if (field) field.value = token;
}
<?php endif; ?>
</script>
</body>
</html>
