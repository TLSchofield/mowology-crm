<?php
/**
 * /crm/gsc/sync-cron.php
 * Pulls daily data from Google Search Console
 * Run via cron: 0 2 * * * php /path/to/sync-cron.php
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../app_config/config.php';

// Allow CLI or authenticated admin
if (php_sapi_name() !== 'cli') {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    requireLogin();
    $user = getCurrentUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        die('Admin access required');
    }
}

$db = getDB();

try {
    // Get GSC properties
    $stmt = $db->query("SELECT id, site_url, access_token_encrypted, refresh_token_encrypted, expires_at FROM gsc_properties");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($properties)) {
        if (php_sapi_name() === 'cli') {
            echo "No GSC properties configured\n";
        }
        exit(0);
    }

    $pulled = 0;
    $failed = 0;

    foreach ($properties as $property) {
        // Refresh token if expired
        $expiresAt = strtotime($property['expires_at']);
        if ($expiresAt < time()) {
            $tokenResponse = refreshAccessToken(decryptToken($property['refresh_token_encrypted']));
            if ($tokenResponse) {
                $accessToken = encryptToken($tokenResponse['access_token']);
                $expiresAt = date('Y-m-d H:i:s', time() + ($tokenResponse['expires_in'] ?? 3600));
                $upd = $db->prepare("UPDATE gsc_properties SET access_token_encrypted = ?, expires_at = ? WHERE id = ?");
                $upd->execute([$accessToken, $expiresAt, $property['id']]);
                $property['access_token_encrypted'] = $accessToken;
            }
        }

        // Pull GSC data
        $gscData = fetchGSCData(
            decryptToken($property['access_token_encrypted']),
            $property['site_url']
        );

        if (!$gscData) {
            $failed++;
            continue;
        }

        // Store snapshot
        $snapshot = $db->prepare("
            INSERT INTO gsc_snapshots (property_id, snapshot_date, data_json, pulled_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                data_json = VALUES(data_json),
                pulled_at = NOW()
        ");

        $snapshot->execute([
            $property['id'],
            date('Y-m-d'),
            json_encode($gscData, JSON_UNESCAPED_SLASHES)
        ]);

        $snapshotId = $db->lastInsertId();

        // Parse and store query/page stats
        if (isset($gscData['rows']) && is_array($gscData['rows'])) {
            $del = $db->prepare("DELETE FROM gsc_query_page_stats WHERE snapshot_id = ?");
            $del->execute([$snapshotId]);

            $ins = $db->prepare("
                INSERT INTO gsc_query_page_stats (snapshot_id, query, page, clicks, impressions, ctr, position)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($gscData['rows'] as $row) {
                $ins->execute([
                    $snapshotId,
                    $row['keys'][0] ?? null,  // query
                    $row['keys'][1] ?? null,  // page
                    (int)($row['clicks'] ?? 0),
                    (int)($row['impressions'] ?? 0),
                    (float)($row['ctr'] ?? 0),
                    (float)($row['position'] ?? 0),
                ]);
            }
        }

        $pulled++;
    }

    if (php_sapi_name() !== 'cli') {
        die(json_encode([
            'success' => true,
            'pulled' => $pulled,
            'failed' => $failed,
            'message' => "Pulled $pulled properties, $failed failed"
        ]));
    } else {
        echo "Pulled $pulled properties, $failed failed\n";
    }

} catch (Throwable $e) {
    error_log("GSC sync error: " . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => $e->getMessage()]));
    } else {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ===== HELPER FUNCTIONS =====

/**
 * Fetch GSC data via API
 */
function fetchGSCData($accessToken, $siteUrl) {
    // Remove protocol for API
    $site = preg_replace('|^https?://|', '', $siteUrl);

    $ch = curl_init("https://www.googleapis.com/webmasters/v3/sites/sc-domain:$site/searchAnalytics/query");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);

    // Request last 28 days of data
    $requestBody = [
        'startDate' => date('Y-m-d', strtotime('-28 days')),
        'endDate' => date('Y-m-d', strtotime('-1 day')),
        'dimensions' => ['query', 'page'],
        'rowLimit' => 10000,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GSC API error ($httpCode): $response");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Refresh access token using refresh token
 */
function refreshAccessToken($refreshToken) {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        return null;
    }

    $postData = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
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
        error_log("Token refresh failed: $response");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Encrypt token
 */
function encryptToken($token) {
    if (!defined('APP_ENCRYPTION_KEY')) {
        return $token;
    }

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token
 */
function decryptToken($encryptedToken) {
    if (!defined('APP_ENCRYPTION_KEY')) {
        return $encryptedToken;
    }

    $data = base64_decode($encryptedToken);
    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);
    return openssl_decrypt($encrypted, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
}
