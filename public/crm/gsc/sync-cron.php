<?php
/**
 * /crm/gsc/sync-cron.php
 * Pulls daily data from Google Search Console (query + page)
 * Can be run via cron or manually (admin only).
 */

declare(strict_types=1);
require_once dirname(__DIR__) . '/../app_config/config.php';

// Allow CLI or authenticated admin
$user = null;
if (php_sapi_name() !== 'cli') {
    require_once dirname(__DIR__) . '/../loginAuth/auth.php';
    requireLogin();
    $user = getCurrentUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Admin access required']));
    }

    // Verify CSRF token on web requests
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'CSRF token invalid']));
    }
}

$db = getDB();

// Sync context
$syncType = (php_sapi_name() === 'cli') ? 'cron' : 'manual';
$userId   = (php_sapi_name() === 'cli') ? null : ($user['id'] ?? null);

// Tracking
$syncHistoryId = null;
$syncStartTime = date('Y-m-d H:i:s');
$startEpoch    = time();

$pulledProps = 0;
$failedProps = 0;
$errors      = [];

$totalRowsProcessed = 0;
$totalRowsInserted  = 0;
$totalRowsUpdated   = 0; // kept for UI; we do replace-per-snapshot so "updated" is typically 0

// We will set property_id in history to a real gsc_properties.id (first property)
$historyPropertyId = null;

try {
    // Load properties
    $stmt = $db->query("SELECT id, site_url, access_token_encrypted, refresh_token_encrypted, expires_at FROM gsc_properties ORDER BY id ASC");
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($properties)) {
        // Nothing to do, but return cleanly
        if (php_sapi_name() === 'cli') {
            echo "No GSC properties configured\n";
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'pulled' => 0, 'failed' => 0, 'message' => 'No GSC properties configured']);
        }
        exit(0);
    }

    $historyPropertyId = (int)$properties[0]['id'];

    // Create sync history row (pending)
    try {
        $histStmt = $db->prepare("
            INSERT INTO gsc_sync_history (property_id, sync_type, status, started_at, initiated_by_user_id, rows_processed, rows_inserted, rows_updated)
            VALUES (?, ?, 'pending', ?, ?, 0, 0, 0)
        ");
        $histStmt->execute([$historyPropertyId, $syncType, $syncStartTime, $userId]);
        $syncHistoryId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // History isn't required for sync to run; log and continue
        error_log("GSC: Failed to create sync history record: " . $e->getMessage());
        $syncHistoryId = null;
    }

    foreach ($properties as $property) {
        $propId  = (int)$property['id'];
        $siteUrl = (string)$property['site_url'];

        // Refresh token if expired or missing expires_at
        $expiresAt = strtotime((string)($property['expires_at'] ?? ''));
        if (!$expiresAt || $expiresAt < time()) {
            $refreshToken = decryptToken((string)$property['refresh_token_encrypted']);

            if ($refreshToken === '') {
                $msg = "No refresh token for property {$siteUrl}";
                error_log("GSC: $msg");
                $errors[] = ['property' => $siteUrl, 'reason' => $msg];
                $failedProps++;
                continue;
            }

            $tokenResponse = refreshAccessToken($refreshToken);
            if ($tokenResponse && !empty($tokenResponse['access_token'])) {
                $accessEnc   = encryptToken((string)$tokenResponse['access_token']);
                $expiresAtNew = date('Y-m-d H:i:s', time() + (int)($tokenResponse['expires_in'] ?? 3600));

                $upd = $db->prepare("UPDATE gsc_properties SET access_token_encrypted = ?, expires_at = ? WHERE id = ?");
                $upd->execute([$accessEnc, $expiresAtNew, $propId]);

                $property['access_token_encrypted'] = $accessEnc;
            } else {
                $msg = "Failed to refresh token for property {$siteUrl}";
                error_log("GSC: $msg");
                $errors[] = ['property' => $siteUrl, 'reason' => $msg];
                $failedProps++;
                continue;
            }
        }

        $accessToken = decryptToken((string)$property['access_token_encrypted']);
        if ($accessToken === '') {
            $msg = "Empty access token for property {$siteUrl}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $siteUrl, 'reason' => $msg];
            $failedProps++;
            continue;
        }

        // Fetch GSC data
        $gscData = fetchGSCData($accessToken, $siteUrl);
        if (!is_array($gscData)) {
            $msg = "Failed to fetch GSC data for property {$siteUrl}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $siteUrl, 'reason' => $msg];
            $failedProps++;
            continue;
        }

        $rows = $gscData['rows'] ?? [];
        $rowsCount = is_array($rows) ? count($rows) : 0;
        $totalRowsProcessed += $rowsCount;

        // Store snapshot (per day)
        $snapshotDate = date('Y-m-d');

        $snapshot = $db->prepare("
            INSERT INTO gsc_snapshots (property_id, snapshot_date, data_json, pulled_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), pulled_at = NOW()
        ");
        $snapshot->execute([$propId, $snapshotDate, json_encode($gscData, JSON_UNESCAPED_SLASHES)]);

        // Get snapshot ID
        $snapshotQuery = $db->prepare("SELECT id FROM gsc_snapshots WHERE property_id = ? AND snapshot_date = ?");
        $snapshotQuery->execute([$propId, $snapshotDate]);
        $snapshotRow = $snapshotQuery->fetch(PDO::FETCH_ASSOC);
        $snapshotId = $snapshotRow ? (int)$snapshotRow['id'] : 0;

        if ($snapshotId === 0) {
            $msg = "Failed to get snapshot ID for property {$siteUrl}";
            error_log("GSC: $msg");
            $errors[] = ['property' => $siteUrl, 'reason' => $msg];
            $failedProps++;
            continue;
        }

        // Replace query/page stats for this snapshot
        $del = $db->prepare("DELETE FROM gsc_query_page_stats WHERE snapshot_id = ?");
        $del->execute([$snapshotId]);

        $rowsInserted = 0;

        if ($rowsCount > 0) {
            $ins = $db->prepare("
                INSERT INTO gsc_query_page_stats (snapshot_id, query, page, clicks, impressions, ctr, position)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($rows as $row) {
                try {
                    $keys = $row['keys'] ?? [];
                    $ins->execute([
                        $snapshotId,
                        $keys[0] ?? null, // query
                        $keys[1] ?? null, // page
                        (int)($row['clicks'] ?? 0),
                        (int)($row['impressions'] ?? 0),
                        (float)($row['ctr'] ?? 0),
                        (float)($row['position'] ?? 0),
                    ]);
                    $rowsInserted++;
                } catch (PDOException $e) {
                    error_log("GSC insert failed: " . $e->getMessage() . " | row=" . json_encode($row));
                    // keep going, but record error once per property
                    $errors[] = ['property' => $siteUrl, 'reason' => 'Insert failed: ' . $e->getMessage()];
                }
            }
        }

        $totalRowsInserted += $rowsInserted;

        // Determine per-property success
        // Note: It can be valid for GSC to return 0 rows for a time window.
        // We'll count "pulled" if API call succeeded, even if rows=0.
        $pulledProps++;

        // But if rows existed and we inserted none, flag as partial failure
        if ($rowsCount > 0 && $rowsInserted === 0) {
            $failedProps++;
            $errors[] = ['property' => $siteUrl, 'reason' => 'API returned rows but no rows inserted (DB issue likely)'];
        }
    }

} catch (Throwable $e) {
    error_log("GSC sync fatal error: " . $e->getMessage());
    $errors[] = ['property' => ($historyPropertyId ? (string)$historyPropertyId : 'unknown'), 'reason' => $e->getMessage()];
    $failedProps = max($failedProps, 1);

} finally {
    // Always finalize history row (prevents stuck "Pending")
    if ($syncHistoryId) {
        $durationSecs = time() - $startEpoch;

        // status rules:
        // - success: no failures
        // - failed: pulledProps==0 OR every property failed
        // - partial: mixed
        $completionStatus = 'success';
        if ($pulledProps === 0) {
            $completionStatus = 'failed';
        } elseif ($failedProps > 0) {
            $completionStatus = 'partial';
        }

        $errorMsg = !empty($errors) ? json_encode($errors, JSON_UNESCAPED_SLASHES) : null;
        $notes    = !empty($errors) ? 'See error_message JSON for details' : null;

        try {
            // Try update with full columns (recommended)
            $updateHist = $db->prepare("
                UPDATE gsc_sync_history
                SET
                    status = ?,
                    rows_processed = ?,
                    rows_inserted  = ?,
                    rows_updated   = ?,
                    duration_seconds = ?,
                    completed_at = NOW(),
                    error_message = ?,
                    notes = ?
                WHERE id = ?
            ");
            $updateHist->execute([
                $completionStatus,
                $totalRowsProcessed,
                $totalRowsInserted,
                $totalRowsUpdated,
                $durationSecs,
                $errorMsg,
                $notes,
                $syncHistoryId
            ]);
        } catch (Throwable $e) {
            // Fallback if your table doesn't have those columns
            try {
                $fallback = $db->prepare("
                    UPDATE gsc_sync_history
                    SET status = ?, completed_at = NOW(), error_message = ?, notes = ?
                    WHERE id = ?
                ");
                $fallback->execute([$completionStatus, $errorMsg, $notes, $syncHistoryId]);
            } catch (Throwable $e2) {
                error_log("GSC: Failed to finalize sync history: " . $e2->getMessage());
            }
        }
    }

    // Output payload
    $payload = [
        'success'   => true,
        'pulled'    => $pulledProps,
        'failed'    => $failedProps,
        'processed' => $totalRowsProcessed,
        'inserted'  => $totalRowsInserted,
        'message'   => "Pulled {$pulledProps} properties, {$failedProps} failed",
        'errors'    => $errors
    ];

    if (php_sapi_name() === 'cli') {
        echo $payload['message'] . "\n";
        echo "Processed: {$totalRowsProcessed}, Inserted: {$totalRowsInserted}\n";
        if (!empty($errors)) {
            echo "Errors:\n";
            foreach ($errors as $err) {
                echo "  - {$err['property']}: {$err['reason']}\n";
            }
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($payload);
    }
    exit(0);
}

/* =========================
   HELPERS
   ========================= */

/**
 * Fetch GSC data via API.
 * Uses sc-domain: format (recommended).
 */
function fetchGSCData(string $accessToken, string $siteUrl): ?array
{
    if ($accessToken === '') return null;

    // Force sc-domain format
    $domain = preg_replace('|^(https?://)?sc-domain:|', '', trim($siteUrl));
    $domain = rtrim($domain, "/ \t\n\r\0\x0B");
    $apiSiteUrl = 'sc-domain:' . $domain;

    // Use a safe window (GSC lag)
    $startDate = date('Y-m-d', strtotime('-28 days'));
    $endDate   = date('Y-m-d', strtotime('-3 days'));

    $endpoint = "https://www.googleapis.com/webmasters/v3/sites/" . rawurlencode($apiSiteUrl) . "/searchAnalytics/query";

    $requestBody = [
        'startDate'  => $startDate,
        'endDate'    => $endDate,
        'dimensions' => ['query', 'page'],
        'rowLimit'   => 25000,
        'searchType' => 'web',
        'dataState'  => 'all',
    ];

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("GSC cURL error: " . $curlErr);
        return null;
    }

    if ($httpCode !== 200) {
        error_log("GSC API error ($httpCode): $response");
        return null;
    }

    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Refresh access token using refresh token
 */
function refreshAccessToken(string $refreshToken): ?array
{
    if (!defined('GOOGLE_CLIENT_ID') || !defined('GOOGLE_CLIENT_SECRET')) return null;
    if ($refreshToken === '') return null;

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Token refresh cURL error: " . $curlErr);
        return null;
    }
    if ($httpCode !== 200) {
        error_log("Token refresh failed ($httpCode): $response");
        return null;
    }

    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Encrypt token for storage
 */
function encryptToken(string $token): string
{
    if (!defined('APP_ENCRYPTION_KEY') || $token === '') return $token;

    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($token, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt token from storage
 */
function decryptToken(string $encryptedToken): string
{
    if (!defined('APP_ENCRYPTION_KEY') || $encryptedToken === '') return $encryptedToken;

    $data = base64_decode($encryptedToken, true);
    if ($data === false) return '';

    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    if (strlen($data) <= $ivLen) return '';

    $iv = substr($data, 0, $ivLen);
    $encrypted = substr($data, $ivLen);

    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', APP_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    return $decrypted ?: '';
}
