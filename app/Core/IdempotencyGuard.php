<?php
declare(strict_types=1);

/**
 * Replay guard for the JWT write endpoints the phone apps call (Sam's sends, Otto's decisions,
 * equipment, the receipt actions, Penny's chase).
 *
 * The apps send one `Idempotency-Key` per user action and re-send the SAME key when a response is
 * lost. Without this, a lost answer plus a retry sends a second SMS/email/quote or approves a second time.
 *
 *   IdempotencyGuard::begin($db, $userId, 'sales-head-mobile', $mode, ['send', 'park', ...]);
 *
 * Call it after auth and permission checks, before the write. Behaviour:
 *   - no key, not POST, or an action outside the write list → does nothing (as before);
 *   - first request with a key → CLAIMS the key (INSERT IGNORE on a unique hash, so two simultaneous
 *     taps cannot both win), runs the endpoint, then stores the response it produced;
 *   - same key again → replays the stored answer without running the action again
 *     (`Idempotency-Replayed: true`), or answers 409 `in_progress` while the first request is still running;
 *   - a 5xx or empty answer RELEASES the claim, so the retry really runs;
 *   - a claim that never completed (killed process) is taken over after STALE_PENDING_SECONDS.
 * Keys are scoped by user + endpoint (the same UUID from two accounts or endpoints never collides) and kept 24 h
 * in `idempotency_keys` (migration 993). If the table is unreachable the guard fails OPEN and logs, so a
 * missing table can never stop crew work.
 */
final class IdempotencyGuard
{
    public const TTL_HOURS = 24;
    public const STALE_PENDING_SECONDS = 600;

    public const CLAIM = 'claim';
    public const REPLAY = 'replay';
    public const IN_PROGRESS = 'in_progress';
    public const TAKEOVER = 'takeover';

    /** Valid keys: a UUID-ish token the app generated. Anything else is ignored (treated as no key). */
    public static function normaliseKey(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) < 8 || strlen($raw) > 128) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9._:\-]+$/', $raw) === 1 ? $raw : null;
    }

    public static function keyHash(int $userId, string $endpoint, string $key): string
    {
        return hash('sha256', $userId . ':' . $endpoint . ':' . $key);
    }

    /** What to do with an existing row (null = no row; `age` = seconds since the claim, measured by the database). Pure, so it is unit-tested. */
    public static function classify(?array $row): string
    {
        if ($row === null) {
            return self::CLAIM;
        }
        if ((string)($row['response_json'] ?? '') !== '') {
            return self::REPLAY;
        }
        return (int)($row['age'] ?? 0) > self::STALE_PENDING_SECONDS ? self::TAKEOVER : self::IN_PROGRESS;
    }

    /** A stored answer is `{"s":status,"b":body}`; null when it is not one we wrote. */
    public static function decodeStored(string $stored): ?array
    {
        $d = json_decode($stored, true);
        return (is_array($d) && isset($d['s']) && array_key_exists('b', $d)) ? ['status' => (int)$d['s'], 'body' => (string)$d['b']] : null;
    }

    /** Whether an answer should be remembered: only definite ones (not a crash, not empty). */
    public static function shouldStore(int $status, string $body): bool
    {
        return $status < 500 && $body !== '';
    }

    public static function begin(PDO $db, int $userId, string $endpoint, string $action, array $writeActions = []): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        if ($writeActions !== [] && !in_array($action, $writeActions, true)) {
            return;
        }
        $key = self::normaliseKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
        if ($key === null || $userId <= 0) {
            return;
        }
        $hash = self::keyHash($userId, $endpoint, $key);
        $label = substr($action, 0, 20);

        try {
            $outcome = self::claimOrDecide($db, $hash, $userId, substr($endpoint, 0, 80), $label);
        } catch (Throwable $e) {
            error_log('[IdempotencyGuard] ' . $endpoint . ': ' . $e->getMessage());
            return;   // fail open
        }

        if ($outcome['result'] === self::REPLAY) {
            $stored = self::decodeStored((string)$outcome['row']['response_json']);
            if ($stored !== null) {
                http_response_code($stored['status'] > 0 ? $stored['status'] : 200);
                header('Content-Type: application/json; charset=utf-8');
                header('Idempotency-Replayed: true');
                echo $stored['body'];
                exit;
            }
            return;   // unreadable stored answer: run normally rather than block
        }
        if ($outcome['result'] === self::IN_PROGRESS) {
            http_response_code(409);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false, 'success' => false, 'code' => 'in_progress',
                'error' => 'That is already being processed. Give it a moment before trying again.',
                'message' => 'That is already being processed. Give it a moment before trying again.',
            ]);
            exit;
        }

        // We own the claim: capture what this request answers, then store or release it.
        ob_start(static function (string $buffer) use ($db, $hash): string {
            self::finish($db, $hash, http_response_code() ?: 200, $buffer);
            return $buffer;
        });
    }

    /** Remember a definite answer for the claim, or release the claim so a retry really runs. */
    public static function finish(PDO $db, string $hash, int $status, string $body): void
    {
        try {
            if (self::shouldStore($status, $body)) {
                $db->prepare('UPDATE idempotency_keys SET response_json = ? WHERE key_hash = ?')
                   ->execute([json_encode(['s' => $status, 'b' => $body]), $hash]);
            } else {
                $db->prepare("DELETE FROM idempotency_keys WHERE key_hash = ? AND response_json = ''")->execute([$hash]);
            }
        } catch (Throwable $e) {
            error_log('[IdempotencyGuard] store: ' . $e->getMessage());
        }
    }

    /** @return array{result:string,row:?array} */
    public static function claimOrDecide(PDO $db, string $hash, int $userId, string $endpoint, string $label): array
    {
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';   // the unit tests run on in-memory SQLite
        $now    = $sqlite ? "datetime('now')" : 'NOW()';
        $expiry = $sqlite ? "datetime('now', '+" . self::TTL_HOURS . " hours')" : 'DATE_ADD(NOW(), INTERVAL ' . self::TTL_HOURS . ' HOUR)';
        $age    = $sqlite ? "CAST(strftime('%s', 'now') - strftime('%s', created_at) AS INTEGER)" : 'TIMESTAMPDIFF(SECOND, created_at, NOW())';

        // An expired row would block the unique key; clear just this hash first.
        $db->prepare("DELETE FROM idempotency_keys WHERE key_hash = ? AND expires_at < $now")->execute([$hash]);

        $ins = $db->prepare(
            ($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') .
            " INTO idempotency_keys (key_hash, user_id, endpoint, action, response_json, created_at, expires_at)
             VALUES (?, ?, ?, ?, '', $now, $expiry)"
        );
        $ins->execute([$hash, $userId, $endpoint, $label]);
        if ($ins->rowCount() === 1) {
            return ['result' => self::CLAIM, 'row' => null];
        }

        $sel = $db->prepare("SELECT response_json, created_at, $age AS age FROM idempotency_keys WHERE key_hash = ? LIMIT 1");
        $sel->execute([$hash]);
        $row = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
        $result = self::classify($row);

        if ($result === self::TAKEOVER) {
            // Only one taker wins: the update matches the exact stale row.
            $up = $db->prepare("UPDATE idempotency_keys SET created_at = $now WHERE key_hash = ? AND response_json = '' AND created_at = ?");
            $up->execute([$hash, $row['created_at']]);
            return $up->rowCount() === 1 ? ['result' => self::CLAIM, 'row' => null] : ['result' => self::IN_PROGRESS, 'row' => $row];
        }
        if ($result === self::CLAIM) {   // the row vanished between the insert and the read: run as the owner
            return ['result' => self::CLAIM, 'row' => null];
        }
        return ['result' => $result, 'row' => $row];
    }
}
