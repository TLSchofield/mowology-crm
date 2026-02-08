<?php
/**
 * /crm/gsc/connect.php
 * Google Search Console OAuth connection flow
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only admins can connect GSC
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required');
}

$db = getDB();

// Step 1: User initiates connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start') {
    // Generate CSRF token for OAuth state
    $state = bin2hex(random_bytes(32));
    $_SESSION['gsc_oauth_state'] = $state;

    // Get OAuth credentials from secrets (must be set)
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        die('Google OAuth credentials not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to secrets.php');
    }

    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/crm/gsc/connect.php?step=callback';

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'state' => $state,
        'access_type' => 'offline',
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// Step 2: Handle OAuth callback
if (isset($_GET['step']) && $_GET['step'] === 'callback') {
    $code = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;
    $error = $_GET['error'] ?? null;

    if ($error) {
        die('OAuth error: ' . htmlspecialchars($error));
    }

    if (!$code || !$state || $state !== ($_SESSION['gsc_oauth_state'] ?? '')) {
        die('Invalid OAuth state or missing code');
    }

    // Exchange code for tokens
    $tokenResponse = exchangeOAuthCode($code);

    if (!$tokenResponse) {
        die('Failed to exchange OAuth code for tokens');
    }

    // Store tokens in database
    $stmt = $db->prepare("
        INSERT INTO gsc_properties (site_url, access_token_encrypted, refresh_token_encrypted, expires_at, connected_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            access_token_encrypted = VALUES(access_token_encrypted),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            expires_at = VALUES(expires_at)
    ");

    $siteUrl = 'https://mowology.ca'; // Production domain
    $accessToken = encryptToken($tokenResponse['access_token']);
    $refreshToken = encryptToken($tokenResponse['refresh_token'] ?? '');
    $expiresAt = date('Y-m-d H:i:s', time() + ($tokenResponse['expires_in'] ?? 3600));

    $stmt->execute([
        $siteUrl,
        $accessToken,
        $refreshToken,
        $expiresAt
    ]);

    // Log activity
    logActivity($user['id'], null, 'Google Search Console connected', 'Site: ' . $siteUrl);

    header('Location: /crm/portfolio/index.php?tab=insights&connected=1');
    exit;
}

// Step 3: Disconnect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'disconnect') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        die('CSRF token invalid');
    }

    $siteUrl = 'https://mowology.ca'; // Production domain
    $stmt = $db->prepare("DELETE FROM gsc_properties WHERE site_url = ?");
    $stmt->execute([$siteUrl]);

    logActivity($user['id'], null, 'Google Search Console disconnected', null);

    header('Location: /crm/portfolio/index.php?tab=insights&disconnected=1');
    exit;
}

// Default: show connection status
$siteUrl = 'https://mowology.ca'; // Production domain
$stmt = $db->prepare("SELECT connected_at, expires_at FROM gsc_properties WHERE site_url = ? LIMIT 1");
$stmt->execute([$siteUrl]);
$gscStatus = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Google Search Console';
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="card">
    <div class="card-body">
        <h5 class="card-title mb-4">Google Search Console Connection</h5>

        <?php if (isset($_GET['connected'])): ?>
            <div class="alert alert-success">
                ✓ Google Search Console connected successfully! Data will be pulled starting tomorrow.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['disconnected'])): ?>
            <div class="alert alert-info">
                Google Search Console disconnected.
            </div>
        <?php endif; ?>

        <?php if ($gscStatus): ?>
            <div class="alert alert-info">
                <strong>Connected</strong> since <?php echo formatDate($gscStatus['connected_at']); ?>
                <br>Token expires: <?php echo formatDate($gscStatus['expires_at']); ?>
            </div>

            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="disconnect">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Disconnect Google Search Console?')">
                    Disconnect
                </button>
            </form>
        <?php else: ?>
            <p class="text-muted mb-4">
                Connect your Google Search Console account to see top search queries, click-through rates, and ranking opportunities.
            </p>

            <form method="POST">
                <input type="hidden" name="action" value="start">
                <button type="submit" class="btn btn-primary">
                    <i data-feather="link-2"></i> Connect Google Account
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>

<?php

/**
 * Exchange OAuth code for access/refresh tokens
 */
function exchangeOAuthCode($code) {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        return null;
    }

    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/crm/gsc/connect.php?step=callback';

    $postData = [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ];

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("OAuth token exchange failed: $response");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Encrypt token for storage
 */
function encryptToken($token) {
    if (!defined('APP_ENCRYPTION_KEY')) {
        return $token; // Fallback: no encryption
    }

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token from storage
 */
function decryptToken($encryptedToken) {
    if (!defined('APP_ENCRYPTION_KEY')) {
        return $encryptedToken; // Fallback: no encryption
    }

    $data = base64_decode($encryptedToken);
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);
    return openssl_decrypt($encrypted, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
}
