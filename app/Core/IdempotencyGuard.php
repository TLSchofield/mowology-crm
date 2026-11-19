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
 * Call it after auth and permission checks, before the write. The rule is "never run the same
 * key + action twice by itself":
 *   - no key, not POST, or an action outside the write list → does nothing (as before);
 *   - first request → CLAIMS the key (INSERT IGNORE on a unique hash, so two simultaneous taps cannot
 *     both win), runs the endpoint, then settles the claim from what the endpoint answered:
 *       STORE       2xx and not `ok:false`/`success:false` → the answer is kept and replayed for the same key
 *                   (`Idempotency-Replayed: true`);
 *       RELEASE     4xx or `ok:false`/`success:false` (a refusal: nothing happened) → claim removed, so a retry
 *                   re-checks permissions/state instead of replaying a stale refusal;
 *       UNCONFIRMED 5xx or an empty answer (we cannot know whether the SMS/email/booking already went) → a 409
 *                   `unconfirmed` answer is kept for that key; the retry is NOT re-run, the app tells the user to
 *                   check before sending again (a new tap is a new key);
 *   - same key while the first request is still running → 409 `in_progress`;
 *   - a claim that never settled (process killed) is NEVER re-run by age: after STALE_PENDING_SECONDS it answers
 *     409 `unconfirmed`, and it expires with everything else after 24 h.
 * The key is scoped by user + endpoint + action. If the table is unreachable the guard fails OPEN and logs, so a
 * missing table can never stop crew work. Table: `idempotency_keys` (migration 993).
 */
final class IdempotencyGuard
{
    public const TTL_HOURS = 24;
    public const STALE_PENDING_SECONDS = 600;

    public const CLAIM = 'claim';
    public const REPLAY = 'replay';
    public const IN_PROGRESS = 'in_progress';
    public const UNCONFIRMED = 'unconfirmed';

    public const STORE = 'store';
    public const RELEASE = 'release';

    /** Valid keys: a UUID-ish token the app generated. Anything else is ignored (treated as no key). */
    public static function normaliseKey(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '' || strlen($raw) < 8 || strlen($raw) > 128) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9._:\-]+$/', $raw) === 1 ? $raw : null;
    }

    public static function keyHash(int $userId, string $endpoint, string $action, string $key): string
    {
        return hash('sha256', $userId . ':' . $endpoint . ':' . $action . ':' . $key);
    }

    /** What to do with an existing row (null = no row; `age` = seconds since the claim, measured by the database). Pure. */
    public static function classify(?array $row): string
    {
        if ($row === null) {
            return self::CLAIM;
        }
        if ((string)($row['response_json'] ?? '') !== '') {
            return self::REPLAY;
        }
        return (int)($row['age'] ?? 0) > self::STALE_PENDING_SECONDS ? self::UNCONFIRMED : self::IN_PROGRESS;
    }

    /** What to do with the claim once the endpoint has answered. Pure. */
    public static function settle(int $status, string $body): string
    {
        if ($status >= 500 || $body === '') {
            return self::UNCONFIRMED;   // we cannot know whether the side effect happened
        }
        if ($status >= 400) {
            return self::RELEASE;       // a refusal: nothing happened, let the retry re-check
        }
        $json = json_decode($body, true);
        if (is_array($json) && (($json['ok'] ?? true) === false || ($json['success'] ?? true) === false)) {
            return self::RELEASE;       // logical refusal / "not ready": nothing to remember
        }
        return self::STORE;
    }

    /** A stored answer is `{"s":status,"b":body}`; null when it is not one we wrote. */
    public static function decodeStored(string $stored): ?array
    {
        $d = json_decode($stored, true);
        return (is_array($d) && isset($d['s']) && array_key_exists('b', $d)) ? ['status' => (int)$d['s'], 'body' => (string)$d['b']] : null;
    }

    public static function unconfirmedBody(): string
    {
        $msg = "We couldn't confirm whether that went through. Check before sending it again.";
        return (string)json_encode(['ok' => false, 'success' => false, 'code' => 'unconfirmed', 'error' => $msg, 'message' => $msg]);
    }

    public static function inProgressBody(): string
    {
        $msg = 'That is already being processed. Give it a moment before trying again.';
        return (string)json_encode(['ok' => false, 'success' => false, 'code' => 'in_progress', 'error' => $msg, 'message' => $msg]);
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
        $hash = self::keyHash($userId, $endpoint, $action, $key);

        try {
            $outcome = self::claimOrDecide($db, $hash, $userId, substr($endpoint, 0, 80), substr($action, 0, 20));
        } catch (Throwable $e) {
            error_log('[IdempotencyGuard] ' . $endpoint . ': ' . $e->getMessage());
            return;   // fail open
        }

        if ($outcome['result'] === self::REPLAY) {
            $stored = self::decodeStored((string)$outcome['row']['response_json']);
            if ($stored !== null) {
                self::reply($stored['status'] > 0 ? $stored['status'] : 200, $stored['body'], true);
            }
            return;   // unreadable stored answer: run normally rather than block
        }
        if ($outcome['result'] === self::IN_PROGRESS) {
            self::reply(409, self::inProgressBody());
        }
        if ($outcome['result'] === self::UNCONFIRMED) {
            self::reply(409, self::unconfirmedBody());
        }

        // We own the claim: capture what this request answers, then settle the claim.
        ob_start(static function (string $buffer) use ($db, $hash): string {
            self::finish($db, $hash, http_response_code() ?: 200, $buffer);
            return $buffer;
        });
    }

    private static function reply(int $status, string $body, bool $replayed = false): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if ($replayed) {
            header('Idempotency-Replayed: true');
        }
        echo $body;
        exit;
    }

    /** Settle the claim from the endpoint's answer (see class comment). */
    public static function finish(PDO $db, string $hash, int $status, string $body): void
    {
        try {
            $how = self::settle($status, $body);
            if ($how === self::STORE) {
                $db->prepare('UPDATE idempotency_keys SET response_json = ? WHERE key_hash = ?')
                   ->execute([json_encode(['s' => $status, 'b' => $body]), $hash]);
            } elseif ($how === self::UNCONFIRMED) {
                $db->prepare('UPDATE idempotency_keys SET response_json = ? WHERE key_hash = ?')
                   ->execute([json_encode(['s' => 409, 'b' => self::unconfirmedBody()]), $hash]);
            } else {
                $db->prepare("DELETE FROM idempotency_keys WHERE key_hash = ? AND response_json = ''")->execute([$hash]);
            }
        } catch (Throwable $e) {
            error_log('[IdempotencyGuard] settle: ' . $e->getMessage());
        }
    }

    /** @return array{result:string,row:?array} */
    public static function claimOrDecide(PDO $db, string $hash, int $userId, string $endpoint, string $label): array
    {
        $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';   // the unit tests run on in-memory SQLite
        $now    = $sqlite ? "datetime('now')" : 'NOW()';
        $expiry = $sqlite ? "datetime('now', '+" . self::TTL_HOURS . " hours')" : 'DATE_ADD(NOW(), INTERVAL ' . self::TTL_HOURS . ' HOUR)';
        $age    = $sqlite ? "CAST(strftime('%s', 'now') - strftime('%s', created_at) AS INTEGER)" : 'TIMESTAMPDIFF(SECOND, created_at, NOW())';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // An expired row would block the unique key; clear just this hash first.
            $db->prepare("DELETE FROM idempotency_keys WHERE key_hash = ? AND expires_at < $now")->execute([$hash]);

            $ins = $db->prepare(
                ($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') .
                " INTO idempotency_keys (key_hash, user_id, endpoint, action, response_json, created_at, expires_at)
                 VALUES (?, ?, ?, ?, '', $now, $expiry)"
            );
            $ins->execute([$hash, $userId, $endpoint, $label]);
            if ($ins->rowCount() === 1) {
                return ['result' => self::CLAIM, 'row' => null];   // we inserted it: it is ours
            }

            $sel = $db->prepare("SELECT response_json, created_at, $age AS age FROM idempotency_keys WHERE key_hash = ? LIMIT 1");
            $sel->execute([$hash]);
            $row = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($row === null) {
                continue;   // the other request released it between our insert and read: insert again, never assume ownership
            }
            return ['result' => self::classify($row), 'row' => $row];
        }
        // Could not settle ownership after retries: do not run an action we do not hold the claim for.
        return ['result' => self::IN_PROGRESS, 'row' => null];
    }
}
