<?php
/**
 * /crm/gsc/connect.php
 * Google Search Console OAuth connection flow (hardened)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();
$user = getCurrentUser();

// Only admins can connect GSC
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Admin access required');
}

$db = getDB();

/**
 * ✅ Canonicalize a site URL for *DB storage* and *GSC API usage*
 * GSC properties are typically stored as either:
 *  - https://example.com/
 *  - sc-domain:example.com
 *
 * Your sync error indicates it expects: https://mowology.ca
 */
function canonicalGscProperty(string $input): string {
    $input = trim($input);

    // If already sc-domain:..., normalize only the domain part
    if (stripos($input, 'sc-domain:') === 0) {
        $domain = substr($input, strlen('sc-domain:'));
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = trim($domain, " \t\n\r\0\x0B/");
        return 'sc-domain:' . $domain;
    }

    // If a bare domain is provided, treat it as https://domain
    if (!preg_match('~^https?://~i', $input)) {
        $input = 'https://' . $input;
    }

    // Parse and rebuild as https://host (no trailing slash)
    $parts = parse_url($input);
    $host = $parts['host'] ?? '';
    if ($host === '') {
        return $input; // fallback
    }

    return 'https://' . strtolower($host);
}

/**
 * ✅ Choose ONE canonical property for your app.
 * If your sync expects https://mowology.ca, store EXACTLY that.
 */
$SITE_PROPERTY_DB = canonicalGscProperty('https://mowology.ca'); // -> https://mowology.ca

/**
 * ✅ Use a fixed redirect URI to avoid www/non-www mismatches.
 * This should match what you configured in Google Cloud Console.
 */
$REDIRECT_URI = 'https://mowology.ca/crm/gsc/connect.php?step=callback';

// Step 1: User initiates connection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    // Generate CSRF token for OAuth state
    $state = bin2hex(random_bytes(32));
    $_SESSION['gsc_oauth_state'] = $state;

    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        http_response_code(500);
        die('Google OAuth credentials not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to secrets.php');
    }

    // Build OAuth URL — MUST request offline + force consent to obtain refresh_token
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => $REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'state' => $state,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'include_granted_scopes' => 'true',
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// Step 2: Handle OAuth callback
if (($_GET['step'] ?? '') === 'callback') {
    $code  = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;
    $error = $_GET['error'] ?? null;

    if ($error) {
        http_response_code(400);
        die('OAuth error: ' . htmlspecialchars($error));
    }

    if (!$code || !$state || $state !== ($_SESSION['gsc_oauth_state'] ?? '')) {
        http_response_code(400);
        die('Invalid OAuth state or missing code');
    }

    // Exchange code for tokens
    $tokenResponse = exchangeOAuthCode($code, $REDIRECT_URI);

    if (!$tokenResponse || empty($tokenResponse['access_token'])) {
        http_response_code(500);
        die('Failed to exchange OAuth code for tokens');
    }

    $accessTokenPlain = (string)$tokenResponse['access_token'];
    $expiresIn = (int)($tokenResponse['expires_in'] ?? 3600);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    $accessTokenEnc = encryptToken($accessTokenPlain);
    $newRefreshTokenPlain = (string)($tokenResponse['refresh_token'] ?? '');

    // Load existing refresh token if present
    $existing = $db->prepare("SELECT refresh_token_encrypted FROM gsc_properties WHERE site_url = ? LIMIT 1");
    $existing->execute([$SITE_PROPERTY_DB]);
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    $hasExistingRefresh = $existingRow && !empty($existingRow['refresh_token_encrypted']);

    // If no refresh token returned and none stored, we cannot run cron reliably.
    if ($newRefreshTokenPlain === '' && !$hasExistingRefresh) {
        // Save access token anyway, but clearly tell admin what to do next.
        $stmt = $db->prepare("
            INSERT INTO gsc_properties (site_url, access_token_encrypted, refresh_token_encrypted, expires_at, connected_at)
            VALUES (?, ?, '', ?, NOW())
            ON DUPLICATE KEY UPDATE
                access_token_encrypted = VALUES(access_token_encrypted),
                expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$SITE_PROPERTY_DB, $accessTokenEnc, $expiresAt]);

        logActivity($user['id'], null, 'GSC OAuth callback received (NO refresh token)', 'Site: ' . $SITE_PROPERTY_DB);

        // Helpful message: usually means Google didn’t re-issue refresh_token.
        header('Location: /crm/portfolio/index.php?tab=insights&connected=0&needs_refresh=1');
        exit;
    }

    // Determine refresh token to store: prefer new, otherwise keep existing
    $refreshTokenEnc = $hasExistingRefresh ? $existingRow['refresh_token_encrypted'] : '';
    if ($newRefreshTokenPlain !== '') {
        $refreshTokenEnc = encryptToken($newRefreshTokenPlain);
    }

    // Upsert tokens for this property
    $stmt = $db->prepare("
        INSERT INTO gsc_properties (site_url, access_token_encrypted, refresh_token_encrypted, expires_at, connected_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            access_token_encrypted = VALUES(access_token_encrypted),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            expires_at = VALUES(expires_at)
    ");
    $stmt->execute([$SITE_PROPERTY_DB, $accessTokenEnc, $refreshTokenEnc, $expiresAt]);

    logActivity($user['id'], null, 'Google Search Console connected', 'Site: ' . $SITE_PROPERTY_DB);

    header('Location: /crm/portfolio/index.php?tab=insights&connected=1');
    exit;
}

// Step 3: Disconnect
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disconnect') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(400);
        die('CSRF token invalid');
    }

    $stmt = $db->prepare("DELETE FROM gsc_properties WHERE site_url = ?");
    $stmt->execute([$SITE_PROPERTY_DB]);

    logActivity($user['id'], null, 'Google Search Console disconnected', 'Site: ' . $SITE_PROPERTY_DB);

    header('Location: /crm/portfolio/index.php?tab=insights&disconnected=1');
    exit;
}

// Default: show connection status
$stmt = $db->prepare("SELECT connected_at, expires_at FROM gsc_properties WHERE site_url = ? LIMIT 1");
$stmt->execute([$SITE_PROPERTY_DB]);
$gscStatus = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Google Search Console';
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="card">
  <div class="card-body">
    <h5 class="card-title mb-4">Google Search Console Connection</h5>

    <?php if (isset($_GET['needs_refresh']) && $_GET['needs_refresh'] === '1'): ?>
      <div class="alert alert-warning">
        Connected, but Google did not return a refresh token.
        <br><strong>Fix:</strong> Remove app access in Google Account → Security → Third-party access, then reconnect.
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['connected']) && $_GET['connected'] === '1'): ?>
      <div class="alert alert-success">
        ✓ Google Search Console connected successfully!
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
        <br>Site key: <code><?php echo htmlspecialchars($SITE_PROPERTY_DB); ?></code>
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
function exchangeOAuthCode(string $code, string $redirectUri): ?array {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        return null;
    }

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("OAuth token exchange failed (HTTP $httpCode): " . ($response ?: '[no response]'));
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Encrypt token for storage
 */
function encryptToken(string $token): string {
    if (!defined('APP_ENCRYPTION_KEY') || $token === '') {
        return $token;
    }

    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = random_bytes($ivLen);

    $encrypted = openssl_encrypt($token, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return '';

    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token from storage (kept for completeness)
 */
function decryptToken(string $encryptedToken): string {
    if (!defined('APP_ENCRYPTION_KEY') || $encryptedToken === '') {
        return $encryptedToken;
    }

    $data = base64_decode($encryptedToken, true);
    if ($data === false) return '';

    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($data) <= $ivLen) return '';

    $iv = substr($data, 0, $ivLen);
    $ciphertext = substr($data, $ivLen);

    $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
}
