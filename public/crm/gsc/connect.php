<?php
/**
 * /crm/gsc/connect.php
 * Google Search Console OAuth connection flow (DOMAIN PROPERTY FIRST)
 *
 * Key behavior:
 * - After OAuth, fetches /sites and stores sc-domain:<domain> if available
 * - This fixes the “connected but 0 rows” issue caused by URL-prefix properties having no data
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
 * ✅ IMPORTANT: Set your canonical domain here
 * Must match the DOMAIN property you see in Search Console (no protocol).
 */
const GSC_CANONICAL_DOMAIN = 'mowology.ca';

/**
 * ✅ Redirect URI must match Google Cloud Console OAuth client configuration EXACTLY.
 */
$REDIRECT_URI = 'https://mowology.ca/crm/gsc/connect.php?step=callback';

/**
 * Pick best property from the /sites list:
 * - Prefer sc-domain:<domain>
 * - Otherwise fall back to https://<domain>/ if present
 * - Otherwise: first site in the list (last resort)
 */
function pickBestGscProperty(array $sites, string $domain): ?string
{
    $domain = strtolower(trim($domain));
    $wantDomainProp = 'sc-domain:' . $domain;

    // 1) Prefer domain property exact match
    foreach ($sites as $site) {
        $siteUrl = $site['siteUrl'] ?? '';
        if (is_string($siteUrl) && strtolower($siteUrl) === $wantDomainProp) {
            return $siteUrl;
        }
    }

    // 2) Prefer URL-prefix https://domain/
    $wantPrefix = 'https://' . $domain . '/';
    foreach ($sites as $site) {
        $siteUrl = $site['siteUrl'] ?? '';
        if (is_string($siteUrl) && strtolower($siteUrl) === $wantPrefix) {
            return $siteUrl;
        }
    }

    // 3) Try www domain property
    $wantWwwDomainProp = 'sc-domain:www.' . $domain;
    foreach ($sites as $site) {
        $siteUrl = $site['siteUrl'] ?? '';
        if (is_string($siteUrl) && strtolower($siteUrl) === $wantWwwDomainProp) {
            return $siteUrl;
        }
    }

    // 4) Try https://www.domain/
    $wantWwwPrefix = 'https://www.' . $domain . '/';
    foreach ($sites as $site) {
        $siteUrl = $site['siteUrl'] ?? '';
        if (is_string($siteUrl) && strtolower($siteUrl) === $wantWwwPrefix) {
            return $siteUrl;
        }
    }

    // 5) Last resort: first available siteUrl
    foreach ($sites as $site) {
        $siteUrl = $site['siteUrl'] ?? '';
        if (is_string($siteUrl) && $siteUrl !== '') {
            return $siteUrl;
        }
    }

    return null;
}

/**
 * Fetch GSC sites list using access token
 */
function fetchGscSites(string $accessToken): ?array
{
    $ch = curl_init('https://www.googleapis.com/webmasters/v3/sites');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("GSC /sites cURL error: " . $err);
        return null;
    }
    if ($httpCode !== 200) {
        error_log("GSC /sites HTTP $httpCode: " . $response);
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) return null;

    // Expected: { "siteEntry": [ ... ] }
    $sites = $decoded['siteEntry'] ?? null;
    return is_array($sites) ? $sites : [];
}

// Step 1: User initiates connection (GET link — avoids jQuery AJAX form interception)
if (($_GET['action'] ?? '') === 'start') {
    $state = bin2hex(random_bytes(32));
    $_SESSION['gsc_oauth_state'] = $state;

    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('credentials_missing: GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are not defined in secrets.php'));
        exit;
    }

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

    // Use header() first; JS fallback handles "headers already sent" edge case
    if (!headers_sent()) {
        header('Location: ' . $authUrl);
    }
    $safeUrl = htmlspecialchars($authUrl, ENT_QUOTES);
    echo '<!DOCTYPE html><html><head>';
    echo '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">';
    echo '</head><body>';
    echo '<script>window.location.replace(' . json_encode($authUrl) . ');</script>';
    echo '<p>Redirecting to Google… <a href="' . $safeUrl . '">Click here if not redirected automatically.</a></p>';
    echo '</body></html>';
    exit;
}

// Step 2: Handle OAuth callback
if (($_GET['step'] ?? '') === 'callback') {
    $code  = $_GET['code'] ?? null;
    $state = $_GET['state'] ?? null;
    $error = $_GET['error'] ?? null;

    if ($error) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('Google returned: ' . (string)$error));
        exit;
    }

    if (!$code || !$state || $state !== ($_SESSION['gsc_oauth_state'] ?? '')) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('State mismatch — try again (session may have expired)'));
        exit;
    }

    $tokenResponse = exchangeOAuthCode((string)$code, $REDIRECT_URI);

    if (!$tokenResponse || empty($tokenResponse['access_token'])) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('Token exchange failed — check GOOGLE_CLIENT_ID/SECRET and that the redirect URI matches Google Cloud Console exactly: ' . $REDIRECT_URI));
        exit;
    }

    $accessTokenPlain = (string)$tokenResponse['access_token'];
    $expiresIn = (int)($tokenResponse['expires_in'] ?? 3600);
    $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

    $accessTokenEnc = encryptToken($accessTokenPlain);
    $newRefreshTokenPlain = (string)($tokenResponse['refresh_token'] ?? '');

    // If Google doesn't return refresh_token, we may already have one stored
    $existing = $db->prepare("SELECT id, refresh_token_encrypted FROM gsc_properties ORDER BY id ASC LIMIT 1");
    $existing->execute();
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    $hasExistingRefresh = $existingRow && !empty($existingRow['refresh_token_encrypted']);

    if ($newRefreshTokenPlain === '' && !$hasExistingRefresh) {
        // Save access token anyway (but cron won’t be reliable)
        $stmt = $db->prepare("
            INSERT INTO gsc_properties (site_url, access_token_encrypted, refresh_token_encrypted, expires_at, connected_at)
            VALUES ('', ?, '', ?, NOW())
            ON DUPLICATE KEY UPDATE
                access_token_encrypted = VALUES(access_token_encrypted),
                expires_at = VALUES(expires_at)
        ");
        $stmt->execute([$accessTokenEnc, $expiresAt]);

        logActivity($user['id'], null, 'GSC OAuth callback received (NO refresh token)', 'Domain: ' . GSC_CANONICAL_DOMAIN);

        header('Location: /crm/portfolio/index.php?tab=insights&connected=0&needs_refresh=1');
        exit;
    }

    // Determine refresh token to store
    $refreshTokenEnc = $hasExistingRefresh ? (string)$existingRow['refresh_token_encrypted'] : '';
    if ($newRefreshTokenPlain !== '') {
        $refreshTokenEnc = encryptToken($newRefreshTokenPlain);
    }

    // ✅ Fetch /sites and choose best property (domain property preferred)
    $sites = fetchGscSites($accessTokenPlain);
    if ($sites === null) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('Connected to Google, but failed to read Search Console sites list. Check that the account has GSC access.'));
        exit;
    }

    $chosenSiteUrl = pickBestGscProperty($sites, GSC_CANONICAL_DOMAIN);
    if (!$chosenSiteUrl) {
        header('Location: /crm/gsc/connect.php?oauth_error=' . urlencode('Connected, but no Search Console properties found for this Google account. Make sure mowology.ca is verified in Google Search Console.'));
        exit;
    }

    // Determine property type from the chosen URL format
    $chosenPropertyType = (strpos($chosenSiteUrl, 'sc-domain:') === 0) ? 'domain' : 'url_prefix';

    // Delete any stale rows (old empty-site_url rows from pre-migration connects)
    // then insert/update the single canonical row.
    $db->exec("DELETE FROM gsc_properties WHERE site_url = '' OR site_url IS NULL");

    $stmt = $db->prepare("
        INSERT INTO gsc_properties (site_url, api_site_url, property_type, access_token_encrypted, refresh_token_encrypted, expires_at, connected_at, is_active)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE
            api_site_url = VALUES(api_site_url),
            property_type = VALUES(property_type),
            access_token_encrypted = VALUES(access_token_encrypted),
            refresh_token_encrypted = VALUES(refresh_token_encrypted),
            expires_at = VALUES(expires_at),
            connected_at = NOW(),
            is_active = 1
    ");
    $stmt->execute([$chosenSiteUrl, $chosenSiteUrl, $chosenPropertyType, $accessTokenEnc, $refreshTokenEnc, $expiresAt]);

    logActivity($user['id'], null, 'Google Search Console connected', 'Property: ' . $chosenSiteUrl);

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

    // Remove all GSC properties (you only need one anyway)
    $db->exec("DELETE FROM gsc_properties");

    logActivity($user['id'], null, 'Google Search Console disconnected', 'Domain: ' . GSC_CANONICAL_DOMAIN);

    header('Location: /crm/portfolio/index.php?tab=insights&disconnected=1');
    exit;
}

// Default: show connection status — newest non-empty row
$stmt = $db->query("SELECT site_url, connected_at, expires_at FROM gsc_properties WHERE site_url != '' AND site_url IS NOT NULL ORDER BY id DESC LIMIT 1");
$gscStatus = $stmt->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Google Search Console';
$activePage = 'portfolio';
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

<div class="card">
  <div class="card-body">
    <h5 class="card-title mb-4">Google Search Console Connection</h5>

    <?php if (isset($_GET['oauth_error']) && $_GET['oauth_error'] !== ''): ?>
      <div class="alert alert-danger">
        <strong>Connection failed:</strong> <?php echo htmlspecialchars((string)$_GET['oauth_error']); ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['needs_refresh']) && $_GET['needs_refresh'] === '1'): ?>
      <div class="alert alert-warning">
        Connected, but Google did not return a refresh token.
        <br><strong>Fix:</strong> Go to <a href="https://myaccount.google.com/permissions" target="_blank">Google Account → Third-party apps</a>, remove Mowology access, then reconnect here.
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
      <?php
        $tokenExpiry = strtotime((string)($gscStatus['expires_at'] ?? ''));
        $tokenExpired = $tokenExpiry && $tokenExpiry < time();
        $property = (string)($gscStatus['site_url'] ?? '');
        $propertyDisplay = $property !== '' ? $property : '(not set)';
      ?>
      <div class="row mb-4">
        <div class="col-md-6">
          <div class="card border-0 bg-light">
            <div class="card-body py-3">
              <div class="d-flex align-items-center mb-2">
                <span class="badge badge-success mr-2">Connected</span>
                <small class="text-muted">since <?php echo formatDate($gscStatus['connected_at']); ?></small>
              </div>
              <div class="mb-1">
                <small class="text-muted d-block">Property</small>
                <code class="text-dark"><?php echo htmlspecialchars($propertyDisplay); ?></code>
              </div>
              <div>
                <small class="text-muted d-block">Access token</small>
                <?php if ($tokenExpired): ?>
                  <span class="text-warning"><i data-feather="alert-circle" style="width:14px;height:14px;"></i> Expired — will auto-refresh on next sync</span>
                <?php else: ?>
                  <span class="text-success"><i data-feather="check-circle" style="width:14px;height:14px;"></i> Valid until <?php echo formatDate($gscStatus['expires_at']); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex align-items-center" style="gap:12px;">
        <a href="/crm/portfolio/index.php?tab=insights" class="btn btn-primary">
          <i data-feather="bar-chart-2"></i> View GSC Insights
        </a>
        <form method="POST" class="d-inline m-0">
          <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
          <input type="hidden" name="action" value="disconnect">
          <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Disconnect Google Search Console?')">
            <i data-feather="x-circle"></i> Disconnect
          </button>
        </form>
      </div>
    <?php else: ?>
      <p class="text-muted mb-4">
        Connect your Google Search Console account to see top search queries, click-through rates, and ranking opportunities.
      </p>

      <a href="/crm/gsc/connect.php?action=start" class="btn btn-primary">
        <i data-feather="link-2"></i> Connect Google Account
      </a>
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
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("OAuth token exchange cURL error: " . $curlErr);
        return null;
    }

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
