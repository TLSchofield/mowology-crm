<?php
declare(strict_types=1);

/**
 * app/Core/Auth/PhoneSignIn.php — the phone (and BlueMoon web) sign-in at one business: check the credential,
 * issue that business's JWT (2026-11-11, "one app per platform" steps 4–5).
 *
 * Moved verbatim out of public/api/auth/token.php so two callers share one copy:
 *   - token.php, at a business's own address: attempt(getDB(), Tenant::key(), …) and maps the status to HTTP;
 *   - BusinessDirectory, on ittai.ca: attempt() once per business, each on that business's own database.
 * It never echoes or exits; it answers a result array:
 *   ['status' => 'ok', 'token', 'user' => [id, email, name, role, business_name, chief_name], 'expires_in']
 *   ['status' => 'rate_limited'] | ['status' => 'invalid'] (failure recorded) | ['status' => 'web_only'] (accountant)
 *   ['status' => 'error'] (database failure, logged) | ['status' => 'not_configured'] (no JWT secret, logged)
 *   ['status' => 'match'] — check-only mode: the password is right here, nothing was written (see attempt()).
 *
 * The rate-limit helpers (auth.php) act on the given database, not this request's getDB().
 * No namespace / no autoloader in production: require_once.
 */

require_once dirname(__DIR__) . '/Tenant.php';
require_once __DIR__ . '/AccountantFence.php';

final class PhoneSignIn
{
    /** Tests only: replacement throttle functions and master secret (keys 'limited', 'failed', 'clear', 'secret'). */
    private static array $seams = [];

    /**
     * The key a business's tokens are signed with — the same derivation as jwtSecret() (JwtAuth.php): the original
     * business keeps the install's secret itself; every other business its own, derived from it (Ittai, 2026-10-23).
     */
    public static function secretFor(string $key, string $masterSecret): string
    {
        return $key !== Tenant::DEFAULT
            ? hash_hmac('sha256', 'tenant:' . $key, $masterSecret)
            : $masterSecret;
    }

    /**
     * One sign-in attempt at the business whose database is $db and whose key is $key. $credential is the
     * lower-cased, trimmed email or username; $audience 'web' or 'mobile'.
     *
     * $checkOnly (the directory's sweep over every business, review 2026-11-11): verify the password and the
     * fence, write NOTHING — no failed-login row (a right password at business A must not count as a wrong one
     * at B and C: on a shared office IP that locked B's crew out), no last_login, no activity, no token. It answers
     * ['status' => 'match'] instead of 'ok'; the caller records failures itself when no business matched.
     */
    public static function attempt(PDO $db, string $key, string $credential, string $password, string $audience, string $ip, bool $checkOnly = false): array
    {
        $email = $credential;   // the rate-limit rows are keyed by what was typed, as before
        $limited = self::$seams['limited'] ?? 'isLoginRateLimited';
        $failed  = self::$seams['failed'] ?? 'recordFailedLogin';
        $clear   = self::$seams['clear'] ?? 'clearLoginAttempts';

        // ── Rate limiting ──────────────────────────────────────────────────────
        if ($limited($email, $ip, $db)) {
            return ['status' => 'rate_limited'];
        }

        // ── Credential validation ──────────────────────────────────────────────
        try {
            $stmt = $db->prepare("
                SELECT id, email, password_hash, full_name, role, is_active
                FROM users
                WHERE LOWER(email) = ? OR LOWER(username) = ?
                LIMIT 1
            ");
            $stmt->execute([$credential, $credential]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check active — treat NULL/missing is_active as active (matches web login behaviour)
            if ($user && isset($user['is_active']) && (int)$user['is_active'] === 0) {
                $user = false; // explicitly deactivated
            }
        } catch (Throwable $e) {
            error_log('[BlueMoon/token] DB error: ' . $e->getMessage());
            return ['status' => 'error'];
        }

        // An unknown email costs the same as a wrong password (review 2026-11-11): without this the answer came
        // back in a few milliseconds for an email no business knows, and a stopwatch told which emails exist.
        $hash = ($user && isset($user['password_hash'])) ? (string)$user['password_hash'] : self::dummyHash();
        if (!password_verify($password, $hash) || !$user || !isset($user['password_hash'])) {
            if (!$checkOnly) $failed($email, $ip, $db);
            return ['status' => 'invalid'];
        }

        // The outside accountant's login is web-only and confined to the Ledger — never an app token (2026-10-13).
        if (!AccountantFence::jwtAllowed((string)($user['role'] ?? ''))) {
            return ['status' => 'web_only'];
        }
        if ($checkOnly) {
            return ['status' => 'match'];
        }

        // ── Success — clear rate limit, log activity ───────────────────────────
        $clear($email, $ip, $db);

        try {
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ? LIMIT 1")
               ->execute([(int)$user['id']]);

            $loginAction = $audience === 'mobile' ? 'iOS app login' : 'BlueMoon web login';
            $db->prepare("
                INSERT INTO activity_log (user_id, action, details, ip_address)
                VALUES (?, ?, ?, ?)
            ")->execute([(int)$user['id'], $loginAction, 'JWT issued', $ip]);

        } catch (Throwable $e) {
            // Non-fatal — log but continue
            error_log('[BlueMoon/token] Post-auth logging error: ' . $e->getMessage());
        }

        // ── Build JWT (hand-rolled, no dependency) ─────────────────────────────
        // No fallback key (Fable review 2026-10-12): without a real secret, sign nothing.
        $master = self::$seams['secret'] ?? (defined('BLUEMOON_JWT_SECRET') ? (string)BLUEMOON_JWT_SECRET : '');
        if (strlen($master) < 32) {
            error_log('token.php: BLUEMOON_JWT_SECRET missing or too short — refusing to issue tokens');
            return ['status' => 'not_configured'];
        }
        [$token, $ttl] = self::token($user, $key, $audience, self::secretFor($key, $master), time());

        // ── Respond ────────────────────────────────────────────────────────────
        // The app greets the business and its chief of staff by their shown names (Claudia walk 1, 2026-11-03: the
        // Android day screen still said "Charlie"). Both helpers never throw; empty means "use your default".
        $businessName = $chiefName = '';
        try {
            require_once dirname(__DIR__, 2) . '/Services/BusinessIdentity.php';
            require_once dirname(__DIR__, 2) . '/Services/HeadIdentity.php';
            $businessName = (string)(BusinessIdentity::get($db)['name'] ?? '');
            $chiefName = HeadIdentity::chiefName($db);
        } catch (Throwable $e) { error_log('[token] identity names: ' . get_class($e)); }

        return [
            'status' => 'ok',
            'token'  => $token,
            'user'   => [
                'id'    => (int)$user['id'],
                'email' => (string)$user['email'],
                'name'  => (string)($user['full_name'] ?? ''),
                'role'  => (string)($user['role'] ?? 'user'),
                'business_name' => $businessName,
                'chief_name'    => $chiefName,
            ],
            'expires_in' => $ttl,
        ];
    }

    /**
     * Pure: the signed HS256 token for this user at business $key → [token, ttl seconds].
     * Mobile tokens live 30 days — crew must survive server outages and long weekends without being forced to
     * re-authenticate. Web tokens stay short.
     */
    public static function token(array $user, string $key, string $audience, string $secret, int $now): array
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $aud = $audience === 'mobile' ? 'ios' : 'bluemoon';
        $ttl = $audience === 'mobile' ? (30 * 24 * 3600) : (8 * 3600);

        $payload = self::b64(json_encode([
            'iss'   => 'mowology',
            'aud'   => $aud,
            'sub'   => (string)$user['id'],
            'email' => (string)$user['email'],
            'name'  => (string)($user['full_name'] ?? ''),
            'role'  => (string)($user['role'] ?? 'user'),
            'iat'   => $now,
            'exp'   => $now + $ttl,
            // A password change or reset invalidates this token (Fable review 2026-10-12; JwtAuth checks it).
            'pwd_v' => substr(hash('sha256', (string)$user['password_hash']), 0, 8),
            // The business it was issued for: validateJwtToken() refuses it anywhere else (2026-11-11).
            'biz'   => $key,
        ]));

        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        return [$header . '.' . $payload . '.' . $signature, $ttl];
    }

    /** A hash to verify against when there is no user, so the time taken does not say so. Made once per request. */
    private static function dummyHash(): string
    {
        static $h = null;
        return $h ?? ($h = password_hash('not-a-real-password-' . bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
    }

    /** URL-safe base64 without padding (token.php's old base64url_encode). */
    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Tests only: replace the throttle functions / master secret; [] restores the real ones. */
    public static function forTests(array $seams): void
    {
        self::$seams = $seams;
    }
}
