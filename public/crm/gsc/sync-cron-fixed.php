<?php
/**
 * /crm/gsc/sync-cron-fixed.php
 * ✅ FIXED VERSION — Uses exact API identifiers from database
 *
 * Pulls daily data from Google Search Console
 * Run via cron: 0 2 * * * php /path/to/sync-cron-fixed.php
 *
 * CRITICAL CHANGES:
 * 1. Uses api_site_url VERBATIM (no sc-domain: transformation)
 * 2. Validates identifier format matches property_type
 * 3. Tracks sync errors for debugging
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
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Admin access required']));
    }

    // Verify CSRF token on web requests
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
    }
}

$db = getDB();

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * ✅ Validate that site URL format matches property type
 *
 * @param string $siteUrl The site URL from database
 * @param string $propertyType 'domain' or 'url_prefix'
 * @return bool True if format is valid for property type
 */
function validateSiteUrlFormat(string $siteUrl, string $propertyType): bool {
    if ($propertyType === 'domain') {
        // Domain properties must start with sc-domain:
        if (strpos($siteUrl, 'sc-domain:') !== 0) {
            return false;
        }
        // Should not contain http:// or https://
        if (strpos(substr($siteUrl, 10), '://') !== false) {
            return false;
        }
        return true;
    } else {
        // URL-prefix properties must start with http:// or https://
        if (strpos($siteUrl, 'https://') !== 0 && strpos($siteUrl, 'http://') !== 0) {
            return false;
        }
        // Should not contain sc-domain:
        if (strpos($siteUrl, 'sc-domain:') !== false) {
            return false;
        }
        return true;
    }
}

/**
 * ✅ Fetch GSC data via API using EXACT site URL from database
 *
 * KEY CHANGE: Uses $siteUrl VERBATIM — no transformation!
 *
 * @param string $accessToken OAuth access token
 * @param string $siteUrl The exact API site URL (e.g., https://example.com or sc-domain:example.com)
 * @param string $propertyType For error reporting only
 * @return array|null JSON decoded response or null on error
 */
function fetchGSCData(string $accessToken, string $siteUrl, string $propertyType): ?array {
    if (empty($accessToken)) {
        error_log("GSC: Empty access token for {$siteUrl}");
        return null;
    }

    // ✅ CRITICAL: Use siteUrl EXACTLY as stored in database
    // No transformation like 'sc-domain:' . preg_replace(...)
    // The siteUrl is already in the correct format returned by Google
    $encodedSiteUrl = rawurlencode($siteUrl);
    $apiUrl = "https://www.googleapis.com/webmasters/v3/sites/{$encodedSiteUrl}/searchAnalytics/query";

    $ch = curl_init($apiUrl);
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
        'rowLimit' => 25000,
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        error_log("GSC API error ($httpCode) for {$propertyType} property {$siteUrl}");
        error_log("GSC API response: {$response}");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Refresh access token using refresh token
 */
function refreshAccessToken(string $refreshToken): ?array {
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) {
        return null;
    }

    if (empty($refreshToken)) {
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
        error_log("Token refresh failed: {$response}");
        return null;
    }

    return json_decode($response, true);
}

/**
 * Encrypt token for storage
 */
function encryptToken(string $token): string {
    if (!defined('APP_ENCRYPTION_KEY') || $token === '') {
        return $token;
    }

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token from storage
 */
function decryptToken(string $encryptedToken): string {
    if (!defined('APP_ENCRYPTION_KEY') || $encryptedToken === '') {
        return $encryptedToken;
    }

    $data = base64_decode($encryptedToken);
    if (!$data) {
        return '';
    }

    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return $decrypted ?: '';
}

// ============================================================================
// MAIN SYNC LOOP
// ============================================================================

try {
    // ✅ Fetch properties with their EXACT API identifiers
    $stmt = $db->query("
        SELECT
            id,
            api_site_url,
            property_type,
            display_domain,
            access_token_encrypted,
            refresh_token_encrypted,
            expires_at
        FROM gsc_properties
        WHERE is_active = TRUE
    ");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($properties)) {
        if (php_sapi_name() === 'cli') {
            echo "No active GSC properties configured\n";
        }
        exit(0);
    }

    $pulled = 0;
    $failed = 0;
    $errors = [];

    foreach ($properties as $property) {
        $apiSiteUrl = $property['api_site_url'];
        $propertyType = $property['property_type'];
        $displayDomain = $property['display_domain'];

        // ✅ Validate identifier format matches property type
        if (!validateSiteUrlFormat($apiSiteUrl, $propertyType)) {
            $msg = "Invalid {$propertyType} site_url format: {$apiSiteUrl}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $displayDomain, 'reason' => $msg];
            $failed++;
            continue;
        }

        // --- Refresh token if expired ---
        $expiresAt = strtotime($property['expires_at']);
        if ($expiresAt < time()) {
            $refreshToken = decryptToken($property['refresh_token_encrypted']);

            if (empty($refreshToken)) {
                $msg = "No refresh token for property {$displayDomain} ({$propertyType})";
                error_log("GSC: $msg");
                $errors[] = ['property' => $displayDomain, 'reason' => $msg];
                $failed++;
                continue;
            }

            $tokenResponse = refreshAccessToken($refreshToken);
            if ($tokenResponse) {
                $accessToken = encryptToken($tokenResponse['access_token']);
                $expiresAtNew = date('Y-m-d H:i:s', time() + ($tokenResponse['expires_in'] ?? 3600));
                $upd = $db->prepare("UPDATE gsc_properties SET access_token_encrypted = ?, expires_at = ? WHERE id = ?");
                $upd->execute([$accessToken, $expiresAtNew, $property['id']]);
                $property['access_token_encrypted'] = $accessToken;
            } else {
                $msg = "Failed to refresh token for property {$displayDomain}";
                error_log("GSC: $msg");
                $errors[] = ['property' => $displayDomain, 'reason' => $msg];
                $failed++;
                continue;
            }
        }

        // --- Pull GSC data using EXACT site URL ---
        $gscData = fetchGSCData(
            decryptToken($property['access_token_encrypted']),
            $apiSiteUrl,  // ✅ EXACT identifier from database
            $propertyType
        );

        if (!$gscData) {
            $msg = "Failed to fetch GSC data for {$propertyType} property {$displayDomain}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $displayDomain, 'reason' => $msg];
            $failed++;
            continue;
        }

        // --- Store snapshot ---
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

        // Get snapshot ID
        $snapshotQuery = $db->prepare("SELECT id FROM gsc_snapshots WHERE property_id = ? AND snapshot_date = ?");
        $snapshotQuery->execute([$property['id'], date('Y-m-d')]);
        $snapshotRow = $snapshotQuery->fetch(PDO::FETCH_ASSOC);
        $snapshotId = $snapshotRow ? (int)$snapshotRow['id'] : 0;

        if ($snapshotId === 0) {
            $msg = "Failed to get snapshot ID for property {$displayDomain}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $displayDomain, 'reason' => $msg];
            $failed++;
            continue;
        }

        // --- Parse and store query/page stats ---
        $rowsInserted = 0;
        if (isset($gscData['rows']) && is_array($gscData['rows'])) {
            error_log("GSC: First row from {$displayDomain}: " . json_encode($gscData['rows'][0] ?? null));

            $del = $db->prepare("DELETE FROM gsc_query_page_stats WHERE snapshot_id = ?");
            $del->execute([$snapshotId]);

            $ins = $db->prepare("
                INSERT INTO gsc_query_page_stats (snapshot_id, query, page, clicks, impressions, ctr, position)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($gscData['rows'] as $row) {
                try {
                    $ins->execute([
                        $snapshotId,
                        $row['keys'][0] ?? null,  // query
                        $row['keys'][1] ?? null,  // page
                        (int)($row['clicks'] ?? 0),
                        (int)($row['impressions'] ?? 0),
                        (float)($row['ctr'] ?? 0),
                        (float)($row['position'] ?? 0),
                    ]);
                    $rowsInserted++;
                } catch (PDOException $e) {
                    error_log("GSC: Row insert failed for {$displayDomain}: " . $e->getMessage());
                    if (!isset($lastRowError) || $lastRowError !== true) {
                        $errors[] = ['property' => $displayDomain, 'reason' => 'Row insert failed: ' . $e->getMessage()];
                        $lastRowError = true;
                    }
                }
            }
        }

        // --- Count this property as pulled only if rows were inserted ---
        if ($rowsInserted > 0) {
            $pulled++;
            // Update sync metadata
            $upd = $db->prepare("
                UPDATE gsc_properties
                SET last_sync_at = NOW(), sync_error_count = 0
                WHERE id = ?
            ");
            $upd->execute([$property['id']]);
        } else {
            $failed++;
            $errors[] = ['property' => $displayDomain, 'reason' => 'No rows inserted (0 rows or all inserts failed)'];
            // Increment error count for next run
            $upd = $db->prepare("
                UPDATE gsc_properties
                SET sync_error_count = sync_error_count + 1
                WHERE id = ?
            ");
            $upd->execute([$property['id']]);
        }
    }

    // --- Return results ---
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        die(json_encode([
            'success' => true,
            'pulled' => $pulled,
            'failed' => $failed,
            'message' => "Pulled $pulled properties, $failed failed",
            'errors' => $errors
        ]));
    } else {
        echo "✓ GSC sync completed: Pulled $pulled properties, $failed failed\n";
        if (!empty($errors)) {
            echo "\nErrors:\n";
            foreach ($errors as $err) {
                echo "  • {$err['property']}: {$err['reason']}\n";
            }
        }
    }

} catch (Throwable $e) {
    error_log("GSC sync fatal error: " . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => $e->getMessage(), 'errors' => [$e->getMessage()]]));
    } else {
        echo "❌ Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}
