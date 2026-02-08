<?php
/**
 * Get Free Quote Form Component
 * This renders the jobFlow quote form without the full page wrapper
 * Used as an embedded component on contact.php
 */

// Only load config if not already loaded (contact.php loads bootstrap)
if (!function_exists('getDB')) {
    require_once __DIR__ . '/app_config/config.php';
}

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/app_config/session_config.php';
    session_start();
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Campaign & Source Tracking
$trackableParams = ['service', 'property_type', 'src', 'promo', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    foreach ($trackableParams as $param) {
        $val = isset($_GET[$param]) ? trim((string)$_GET[$param]) : '';
        if ($val !== '') {
            $_SESSION['jf_track'][$param] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $val);
        }
    }
}

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

// reCAPTCHA verification
require_once __DIR__ . '/jobFlow/recaptcha-helpers.php';

$error = '';
$errors = [];
$formData = [];
$showV2Challenge = false;
$useV3 = function_exists('is_recaptcha_v3_configured') && is_recaptcha_v3_configured() && !$showV2Challenge;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = cleanPhone($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $name = cleanName($_POST['name'] ?? '');
    $address = cleanAddress($_POST['address'] ?? '');

    $formData = [
        'phone' => $phone,
        'email' => $email,
        'name' => $name,
        'address' => $address,
    ];

    // Validate CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $errors['csrf'] = 'Security check failed. Please refresh and try again.';
    }

    // Validate form fields
    if (empty($name)) $errors['name'] = 'Name is required';
    if (empty($email)) $errors['email'] = 'Email is required';
    if (empty($phone)) $errors['phone'] = 'Phone is required';
    if (empty($address)) $errors['address'] = 'Address is required';

    // reCAPTCHA verification
    if (empty($errors)) {
        $v3Token = (string)($_POST['recaptcha_v3_token'] ?? '');
        $v2Token = (string)($_POST['recaptcha_v2_token'] ?? '');

        if ($useV3) {
            $v3Result = verify_recaptcha_v3($v3Token, 'quote_submit');
            if ($v3Result['passed']) {
                // Score was good
            } elseif ($v3Result['needs_v2']) {
                $showV2Challenge = true;
                $errors['recaptcha'] = 'Please complete the security check below.';
            } else {
                $errors['recaptcha'] = 'Security verification failed. Please try again.';
            }
        } elseif (!empty($v2Token)) {
            if (!verify_recaptcha_v2_token($v2Token)) {
                $errors['recaptcha'] = 'Please complete the security check below.';
            }
        } else {
            $errors['recaptcha'] = 'Security check required. Please ensure JavaScript is enabled.';
        }
    }

    // If no errors, save to database and redirect
    if (empty($errors)) {
        try {
            $db = getDB();

            // Create or update contact
            $stmt = $db->prepare("
                SELECT id FROM contacts
                WHERE email = ? OR phone = ?
                LIMIT 1
            ");
            $stmt->execute([$email, $phone]);
            $existing = $stmt->fetch();

            if ($existing) {
                $contactId = $existing['id'];
            } else {
                $stmt = $db->prepare("
                    INSERT INTO contacts (first_name, last_name, email, phone, created_at, updated_at)
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$name, '', $email, $phone]);
                $contactId = $db->lastInsertId();
            }

            // Store quote request in session for next step
            $_SESSION['temp_quote_data'] = [
                'contact_id' => $contactId,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
            ];

            // Redirect to confirmation
            header('Location: /jobFlow/jobFlow-confirm.php');
            exit;
        } catch (Exception $e) {
            error_log('Quote form error: ' . $e->getMessage());
            $errors['database'] = 'An error occurred. Please try again.';
        }
    }
}
?>

<style>
.quote-form-embed {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 4px;
}

.quote-form-embed h3 {
    margin-top: 0;
    color: #1a5f4a;
    font-size: 18px;
    font-weight: 600;
}

.quote-form-embed .form-group {
    margin-bottom: 15px;
}

.quote-form-embed label {
    display: block;
    font-weight: 500;
    margin-bottom: 5px;
    font-size: 14px;
    color: #333;
}

.quote-form-embed input[type="text"],
.quote-form-embed input[type="email"],
.quote-form-embed input[type="tel"],
.quote-form-embed select,
.quote-form-embed textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
    font-family: inherit;
}

.quote-form-embed input[type="text"]:focus,
.quote-form-embed input[type="email"]:focus,
.quote-form-embed input[type="tel"]:focus,
.quote-form-embed select:focus,
.quote-form-embed textarea:focus {
    outline: none;
    border-color: #2D8659;
    box-shadow: 0 0 0 2px rgba(45, 134, 89, 0.1);
}

.quote-form-embed button {
    background: #2D8659;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 4px;
    font-weight: 600;
    cursor: pointer;
    font-size: 14px;
    width: 100%;
}

.quote-form-embed button:hover {
    background: #1a5f4a;
}

.quote-form-embed .error-message {
    color: #d32f2f;
    font-size: 13px;
    margin-top: 4px;
}

.quote-form-embed .required::after {
    content: " *";
    color: #d32f2f;
}
</style>

<div class="quote-form-embed">
    <h3>Quick Quote Request</h3>

    <?php if (!empty($errors)): ?>
        <div style="background: #ffebee; border: 1px solid #ef5350; color: #c62828; padding: 12px; border-radius: 4px; margin-bottom: 15px; font-size: 14px;">
            <?php foreach ($errors as $error): ?>
                <div>✗ <?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <?php if ($useV3): ?>
            <input type="hidden" name="recaptcha_v3_token" id="recaptcha_v3_token" value="">
        <?php endif; ?>

        <div class="form-group">
            <label for="name" class="required">Name</label>
            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($formData['name'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="email" class="required">Email</label>
            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="phone" class="required">Phone</label>
            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
            <label for="address" class="required">Address</label>
            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($formData['address'] ?? ''); ?>" placeholder="Street address, City" required>
        </div>

        <?php if ($useV3): ?>
            <!-- reCAPTCHA v3 invisible -->
        <?php else: ?>
            <!-- reCAPTCHA v2 checkbox -->
            <div class="form-group">
                <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars(defined('RECAPTCHA_V2_SITE_KEY') ? RECAPTCHA_V2_SITE_KEY : '', ENT_QUOTES, 'UTF-8'); ?>" data-callback="onV2Completed"></div>
                <input type="hidden" name="recaptcha_v2_token" id="recaptcha_v2_token" value="">
            </div>
        <?php endif; ?>

        <button type="submit">Get Free Quote</button>
    </form>
</div>

<?php if ($useV3 && defined('RECAPTCHA_V3_SITE_KEY')): ?>
<script src="https://www.google.com/recaptcha/api.js?render=<?php echo htmlspecialchars(RECAPTCHA_V3_SITE_KEY, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php else: ?>
<script src="https://www.google.com/recaptcha/api.js" async defer></script>
<script>
function onV2Completed(token) {
    document.getElementById('recaptcha_v2_token').value = token;
}
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('.quote-form-embed form');
    if (!form) return;

    var submitting = false;
    var recaptchaReady = false;

    var recaptchaCheckInterval = setInterval(function() {
        if (typeof grecaptcha !== 'undefined') {
            recaptchaReady = true;
            clearInterval(recaptchaCheckInterval);
        }
    }, 100);

    setTimeout(function() {
        if (recaptchaCheckInterval) clearInterval(recaptchaCheckInterval);
    }, 5000);

    form.addEventListener('submit', function(e) {
        if (submitting) return;

        var siteKey = <?php echo json_encode(defined('RECAPTCHA_V3_SITE_KEY') ? RECAPTCHA_V3_SITE_KEY : ''); ?>;

        <?php if ($useV3): ?>
        e.preventDefault();

        if (typeof grecaptcha === 'undefined' || !recaptchaReady) {
            var waitCount = 0;
            var waitInterval = setInterval(function() {
                waitCount++;
                if (typeof grecaptcha !== 'undefined') {
                    clearInterval(waitInterval);
                    recaptchaReady = true;
                    form.dispatchEvent(new Event('submit'));
                } else if (waitCount > 50) {
                    clearInterval(waitInterval);
                    submitting = true;
                    form.submit();
                }
            }, 100);
            return;
        }

        grecaptcha.ready(function() {
            grecaptcha.execute(siteKey, { action: 'quote_submit' }).then(function(token) {
                document.getElementById('recaptcha_v3_token').value = token;
                submitting = true;
                form.submit();
            }).catch(function(err) {
                console.error('reCAPTCHA error:', err);
                submitting = true;
                form.submit();
            });
        });
        <?php endif; ?>
    });
});
</script>
