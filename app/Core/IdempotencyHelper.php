<?php
declare(strict_types=1);

/**
 * Idempotency helpers for job-timer endpoints.
 *
 * Clients generate a UUID per timer action, send it as the Idempotency-Key
 * HTTP header, and retry with the same key on timeout or reconnect.
 * The server stores key → response for 24 hours and short-circuits
 * duplicate requests by returning the cached response.
 *
 * Usage pattern in API endpoint:
 *   $idempKey = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
 *   if ($idempKey) {
 *       $cached = idempotencyCheck($db, $idempKey, $userId);
 *       if ($cached !== null) { echo $cached; exit; }
 *   }
 *   // ... compute $response array ...
 *   $responseJson = json_encode($response);
 *   if ($idempKey) idempotencyStore($db, $idempKey, $userId, 'timer', $action, $responseJson);
 *   echo $responseJson;
 */

/**
 * Check if this key has been processed for this user within the last 24 hours.
 * Returns the cached JSON response string, or null if not seen before.
 */
function idempotencyCheck(PDO $db, string $rawKey, int $userId): ?string
{
    if ($rawKey === '' || strlen($rawKey) > 128) {
        return null;
    }
    $hash = hash('sha256', $userId . ':' . $rawKey);
    $stmt = $db->prepare(
        "SELECT response_json FROM idempotency_keys
         WHERE key_hash = ? AND expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['response_json'] : null;
}

/**
 * Persist a key → response mapping with a 24-hour TTL.
 * Uses INSERT IGNORE so concurrent duplicate requests race safely.
 */
function idempotencyStore(
    PDO    $db,
    string $rawKey,
    int    $userId,
    string $endpoint,
    string $action,
    string $responseJson
): void {
    if ($rawKey === '' || strlen($rawKey) > 128) {
        return;
    }
    $hash = hash('sha256', $userId . ':' . $rawKey);
    $db->prepare(
        "INSERT IGNORE INTO idempotency_keys
         (key_hash, user_id, endpoint, action, response_json, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))"
    )->execute([$hash, $userId, $endpoint, $action, $responseJson]);

    // Prune expired rows ~5% of the time to keep the table lean.
    if (mt_rand(1, 20) === 1) {
        try {
            $db->exec("DELETE FROM idempotency_keys WHERE expires_at < NOW()");
        } catch (Throwable $e) {
            // Non-fatal housekeeping — ignore.
        }
    }
}
