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

/**
 * Per-row idempotency for upload endpoints (receipt-upload, expense-save,
 * visit-photo-upload). Tables carry an `idempotency_key VARCHAR(36)` column
 * with a UNIQUE index — see migration 1023.
 *
 * Flow in an endpoint:
 *   $idemKey = readIdempotencyKeyHeader();
 *   if ($idemKey) {
 *       $existingId = lookupIdempotencyRow($db, 'media_assets', $idemKey);
 *       if ($existingId) { ...return existing id with deduplicated:true... }
 *   }
 *   // ...do INSERT, passing $idemKey as the idempotency_key column value...
 *
 * The client UUID is the source of truth; we don't hash it. Mobile clients
 * generate one UUIDv4 at capture time and reuse it on every retry.
 */

/**
 * Read and validate the Idempotency-Key request header.
 * Accepts UUIDs (with or without dashes) up to 36 chars.
 * Returns null when the header is absent or malformed.
 */
function readIdempotencyKeyHeader(): ?string
{
    $raw = trim($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($raw === '' || strlen($raw) > 36) return null;
    // Allow hex chars + dashes only — rejects injection attempts cheaply.
    if (!preg_match('/^[0-9a-fA-F-]{8,36}$/', $raw)) return null;
    return $raw;
}

/**
 * Look up the row id of an upload that was already processed under this key.
 * $table must be one of an allow-list to keep the query SQL-safe.
 * Returns the existing row id, or null if none.
 */
function lookupIdempotencyRow(PDO $db, string $table, string $key): ?int
{
    static $allowed = ['media_assets' => 'id', 'expenses' => 'id', 'visit_photos' => 'id'];
    if (!isset($allowed[$table]) || $key === '') return null;

    try {
        $stmt = $db->prepare("SELECT {$allowed[$table]} FROM {$table} WHERE idempotency_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (PDOException $e) {
        // Column may not exist yet (migration 1023 not applied) — treat as no match.
        return null;
    }
}
