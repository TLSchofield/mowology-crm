<?php
declare(strict_types=1);

/**
 * TwoStepService — two-step sign-in for the web (authenticator codes, backup codes, trusted devices).
 * Opt-in: nobody is asked for a code until they turn it on themselves (sign-in security plan step 2, 2026-11-06).
 *
 * Fail-safe rules:
 *  - tables missing (migration 1440 not run in this business) = nobody enrolled, never an error;
 *  - tables present and the person enrolled = a code is required, whatever else goes wrong (a database error
 *    while asking is thrown, never read as "not enrolled");
 *  - the secret is stored only encrypted (QboTokenVault); no key = setup unavailable, never a plain secret;
 *  - backup codes and device tokens are stored only hashed; no code value is ever logged.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Modules/QuickBooks/Services/QboTokenVault.php';

class TwoStepService
{
    public const PENDING_TTL  = 300;   // seconds the "code needed" state lives after the password
    public const MAX_TRIES    = 5;     // wrong codes before the pending state is thrown away
    public const TRUST_DAYS   = 30;
    public const BACKUP_COUNT = 10;
    private const BACKUP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I

    private PDO $db;
    private ?string $key;
    /** @var callable */
    private $clock;

    /** $key: raw 32-byte key (null = no key, setup unavailable). $clock: returns "now" as a unix time (tests). */
    public function __construct(PDO $db, ?string $key, ?callable $clock = null)
    {
        $this->db = $db;
        $this->key = $key;
        $this->clock = $clock ?: 'time';
    }

    /** MFA_SECRET_KEY if defined, else QBO_TOKEN_KEY; both only in secrets.php. A defined-but-bad MFA key is null, not a fallback. */
    public static function keyFromSecrets(): ?string
    {
        if (defined('MFA_SECRET_KEY')) return QboTokenVault::decodeKey((string)MFA_SECRET_KEY);
        return QboTokenVault::keyFromSecrets();
    }

    // ── Pending state (between a correct password and a correct code) — pure, tested ──────────────────

    public static function pendingNew(int $uid, string $email, bool $isDriver, string $role, string $pwdV, int $now): array
    {
        return ['uid' => $uid, 'email' => $email, 'at' => $now, 'tries' => 0,
                'is_driver' => $isDriver, 'role' => $role, 'pwd_v' => $pwdV];
    }

    /** 'none' (no/garbled state), 'expired', 'locked' (too many wrong codes) or 'ok'. */
    public static function pendingState($pending, int $now): string
    {
        if (!is_array($pending) || (int)($pending['uid'] ?? 0) <= 0 || !isset($pending['at'])) return 'none';
        if ($now - (int)$pending['at'] > self::PENDING_TTL || $now < (int)$pending['at']) return 'expired';
        if ((int)($pending['tries'] ?? 0) >= self::MAX_TRIES) return 'locked';
        return 'ok';
    }

    /** The trusted-device cookie name: per business, like the session cookie. */
    public static function cookieName(bool $isDefaultBusiness, string $businessKey): string
    {
        return $isDefaultBusiness ? 'MWTD' : 'ITTD_' . $businessKey;
    }

    // ── Base32 and TOTP (RFC 4648 / RFC 6238) — pure, tested ──────────────────────────────────────────

    public static function base32Encode(string $raw): string
    {
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($raw) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    /** Raw bytes, or null when the text holds anything that is not base32 (spaces, hyphens and case are ignored). */
    public static function base32Decode(string $text): ?string
    {
        $text = strtoupper(str_replace([' ', '-', '='], '', $text));
        if ($text === '') return null;
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($text) as $c) {
            $p = strpos($alpha, $c);
            if ($p === false) return null;
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }
        return $out;
    }

    /** The 6-digit code for a raw secret at a 30-second step counter (SHA1). */
    public static function totpCode(string $rawSecret, int $step, int $digits = 6): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $rawSecret, true);
        $o = ord($hash[19]) & 0x0f;
        $n = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step that matches the code within ±1 step of $now and is newer than $lastStep, or null. Pure.
     * A step at or below lastStep is refused: a code is good once.
     */
    public static function matchStep(string $rawSecret, string $code, int $now, ?int $lastStep): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) return null;
        $cur = intdiv($now, 30);
        foreach ([$cur - 1, $cur, $cur + 1] as $step) {
            if ($lastStep !== null && $step <= $lastStep) continue;
            if (hash_equals(self::totpCode($rawSecret, $step), $code)) return $step;
        }
        return null;
    }

    /** What a person typed → 6 digits, or an upper-case backup code without hyphen, or ''. */
    public static function normaliseInput(string $input): array
    {
        $s = strtoupper(preg_replace('/[\s-]+/', '', $input));
        if (preg_match('/^\d{6}$/', $s)) return ['totp', $s];
        if (preg_match('/^[A-Z2-9]{10}$/', $s)) return ['backup', $s];
        return ['', ''];
    }

    /** A short "Chrome on Mac" style label for the devices list; the raw agent text is never shown. */
    public static function describeAgent(string $ua): string
    {
        $browser = 'Browser';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $browser = $label; break; }
        }
        $os = 'unknown device';
        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS' => 'Mac', 'Linux' => 'Linux'] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $os = $label; break; }
        }
        return $browser . ' on ' . $os;
    }

    // ── Status ─────────────────────────────────────────────────────────────────────────────────────────

    /** True only for someone who has finished setup. Tables missing = false; any other database error is thrown. */
    public function isEnrolled(int $userId): bool
    {
        try {
            $st = $this->db->prepare("SELECT enabled_at FROM user_two_step WHERE user_id = ? LIMIT 1");
            $st->execute([$userId]);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null;
        } catch (PDOException $e) {
            if (self::isMissingTable($e)) return false;
            throw $e;
        }
    }

    private static function isMissingTable(PDOException $e): bool
    {
        $m = $e->getMessage();
        return $e->getCode() === '42S02' || stripos($m, "doesn't exist") !== false || stripos($m, 'no such table') !== false;
    }

    public function available(): bool
    {
        return $this->key !== null;
    }

    public function status(int $userId): array
    {
        $out = ['available' => $this->available(), 'tables' => true, 'enrolled' => false, 'setup_started' => false, 'backup_left' => 0];
        try {
            $st = $this->db->prepare("SELECT enabled_at FROM user_two_step WHERE user_id = ? LIMIT 1");
            $st->execute([$userId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $out['enrolled'] = $row['enabled_at'] !== null;
                $out['setup_started'] = !$out['enrolled'];
            }
            $c = $this->db->prepare("SELECT COUNT(*) FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL");
            $c->execute([$userId]);
            $out['backup_left'] = (int)$c->fetchColumn();
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) throw $e;
            $out['tables'] = false;
        }
        return $out;
    }

    // ── Setup ──────────────────────────────────────────────────────────────────────────────────────────

    /** Starts (or restarts) setup with a fresh secret; enabled_at stays NULL. Returns the base32 secret. Refused for someone already enrolled. */
    public function beginSetup(int $userId): string
    {
        if ($this->key === null) throw new RuntimeException('Two-step sign-in is not available (no encryption key).');
        if ($this->isEnrolled($userId)) throw new RuntimeException('Two-step sign-in is already on.');
        $raw = random_bytes(20);
        $enc = (new QboTokenVault($this->key))->encrypt($raw);
        $this->db->prepare("DELETE FROM user_two_step WHERE user_id = ?")->execute([$userId]);
        $this->db->prepare("INSERT INTO user_two_step (user_id, totp_secret_enc, enabled_at, last_step) VALUES (?, ?, NULL, NULL)")
            ->execute([$userId, $enc]);
        return self::base32Encode($raw);
    }

    /** The secret of a setup that is not yet confirmed (to show the QR again), or null. */
    public function setupSecret(int $userId): ?string
    {
        $row = $this->row($userId);
        if (!$row || $row['enabled_at'] !== null) return null;
        $raw = $this->rawSecret($row);
        return $raw === null ? null : self::base32Encode($raw);
    }

    /** Checks the first code, turns two-step on and returns the 10 backup codes (shown once); null when the code is wrong. */
    public function confirmSetup(int $userId, string $code): ?array
    {
        $row = $this->row($userId);
        if (!$row || $row['enabled_at'] !== null) return null;
        $raw = $this->rawSecret($row);
        if ($raw === null) return null;
        [$kind, $clean] = self::normaliseInput($code);
        if ($kind !== 'totp') return null;
        $step = self::matchStep($raw, $clean, $this->now(), null);
        if ($step === null) return null;
        $up = $this->db->prepare("UPDATE user_two_step SET enabled_at = ?, last_step = ? WHERE user_id = ? AND enabled_at IS NULL");
        $up->execute([$this->stamp(), $step, $userId]);
        if ($up->rowCount() !== 1) return null;
        $codes = $this->makeBackupCodes($userId);
        $this->log($userId, 'Two-step sign-in turned on', null);
        return $codes;
    }

    /** Turns it off: secret, backup codes and trusted devices all go. */
    public function disable(int $userId, ?int $byUserId = null): void
    {
        foreach (['user_two_step', 'user_backup_codes', 'user_trusted_devices'] as $t) {
            try {
                $this->db->prepare("DELETE FROM {$t} WHERE user_id = ?")->execute([$userId]);
            } catch (PDOException $e) {
                if (!self::isMissingTable($e)) throw $e;
            }
        }
        $this->log($userId, 'Two-step sign-in turned off', $byUserId !== null && $byUserId !== $userId ? 'by user #' . $byUserId : null);
    }

    /** Replaces the backup codes with 10 new ones (returned once). Only for someone enrolled. */
    public function regenerateBackupCodes(int $userId): ?array
    {
        if (!$this->isEnrolled($userId)) return null;
        $codes = $this->makeBackupCodes($userId);
        $this->log($userId, 'Two-step backup codes renewed', null);
        return $codes;
    }

    private function makeBackupCodes(int $userId): array
    {
        $this->db->prepare("DELETE FROM user_backup_codes WHERE user_id = ?")->execute([$userId]);
        $ins = $this->db->prepare("INSERT INTO user_backup_codes (user_id, code_hash, used_at) VALUES (?, ?, NULL)");
        $out = [];
        for ($i = 0; $i < self::BACKUP_COUNT; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) $c .= self::BACKUP_ALPHABET[random_int(0, strlen(self::BACKUP_ALPHABET) - 1)];
            $ins->execute([$userId, password_hash($c, PASSWORD_DEFAULT)]);
            $out[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $out;
    }

    // ── Checking a code at sign-in or before turning off ───────────────────────────────────────────────

    /** A current authenticator code or an unused backup code. Backup codes are marked used; a TOTP step is never accepted twice. */
    public function verify(int $userId, string $input): bool
    {
        $row = $this->row($userId);
        if (!$row || $row['enabled_at'] === null) return false;
        [$kind, $clean] = self::normaliseInput($input);
        $ok = false;
        if ($kind === 'totp') {
            $raw = $this->rawSecret($row);   // null (wrong/missing key) = the code can't be checked: refused
            if ($raw !== null) {
                $last = $row['last_step'] === null ? null : (int)$row['last_step'];
                $step = self::matchStep($raw, $clean, $this->now(), $last);
                if ($step !== null) {
                    // Atomic: two requests with the same code at once — only the one that moves last_step wins.
                    $up = $this->db->prepare("UPDATE user_two_step SET last_step = ? WHERE user_id = ? AND (last_step IS NULL OR last_step < ?)");
                    $up->execute([$step, $userId, $step]);
                    $ok = $up->rowCount() === 1;
                }
            }
        } elseif ($kind === 'backup') {
            $st = $this->db->prepare("SELECT id, code_hash FROM user_backup_codes WHERE user_id = ? AND used_at IS NULL");
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
                if (password_verify($clean, (string)$b['code_hash'])) {
                    $use = $this->db->prepare("UPDATE user_backup_codes SET used_at = ? WHERE id = ? AND used_at IS NULL");
                    $use->execute([$this->stamp(), (int)$b['id']]);
                    if ($use->rowCount() === 1) {
                        $ok = true;
                        $this->log($userId, 'Two-step backup code used', null);
                    }
                    break;
                }
            }
        }
        if (!$ok) $this->log($userId, 'Two-step code refused', null);
        return $ok;
    }

    // ── Trusted devices ────────────────────────────────────────────────────────────────────────────────

    /** Remembers this browser for TRUST_DAYS; returns the raw token for the cookie (only its sha256 is kept). */
    public function trust(int $userId, string $userAgent): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = $this->now();
        $this->db->prepare("DELETE FROM user_trusted_devices WHERE user_id = ? AND expires_at < ?")
            ->execute([$userId, $this->stamp($now)]);
        $this->db->prepare("INSERT INTO user_trusted_devices (user_id, token_hash, user_agent, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, hash('sha256', $raw), mb_substr($userAgent, 0, 255), $this->stamp($now), $this->stamp($now),
                       $this->stamp($now + self::TRUST_DAYS * 86400)]);
        $this->log($userId, 'Two-step device trusted', null);
        return $raw;
    }

    /** True when the cookie value is a live token that belongs to THIS user. */
    public function isTrusted(int $userId, string $rawToken): bool
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) return false;
        $now = $this->now();
        $st = $this->db->prepare("SELECT id FROM user_trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > ? LIMIT 1");
        $st->execute([$userId, hash('sha256', $rawToken), $this->stamp($now)]);
        $id = $st->fetchColumn();
        if ($id === false) return false;
        $this->db->prepare("UPDATE user_trusted_devices SET last_seen_at = ? WHERE id = ?")->execute([$this->stamp($now), (int)$id]);
        return true;
    }

    public function devices(int $userId): array
    {
        $st = $this->db->prepare("SELECT id, user_agent, created_at, last_seen_at, expires_at FROM user_trusted_devices WHERE user_id = ? AND expires_at > ? ORDER BY last_seen_at DESC");
        $st->execute([$userId, $this->stamp()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeDevice(int $userId, int $deviceId): bool
    {
        $d = $this->db->prepare("DELETE FROM user_trusted_devices WHERE id = ? AND user_id = ?");
        $d->execute([$deviceId, $userId]);
        if ($d->rowCount() === 1) { $this->log($userId, 'Two-step device revoked', null); return true; }
        return false;
    }

    /** Every trusted device for the person (password change, turning off, "revoke all"). Missing table = nothing to do. */
    public function revokeAllDevices(int $userId): int
    {
        try {
            $d = $this->db->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?");
            $d->execute([$userId]);
            if ($d->rowCount() > 0) $this->log($userId, 'Two-step devices revoked', (string)$d->rowCount() . ' device(s)');
            return $d->rowCount();
        } catch (PDOException $e) {
            if (self::isMissingTable($e)) return 0;
            throw $e;
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────────────────────────────────

    private function row(int $userId): ?array
    {
        $st = $this->db->prepare("SELECT user_id, totp_secret_enc, enabled_at, last_step FROM user_two_step WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function rawSecret(array $row): ?string
    {
        if ($this->key === null) return null;
        $p = (new QboTokenVault($this->key))->decrypt((string)$row['totp_secret_enc']);
        return ($p === null || strlen($p) !== 20) ? null : $p;
    }

    private function now(): int
    {
        return (int)call_user_func($this->clock);
    }

    private function stamp(?int $t = null): string
    {
        return date('Y-m-d H:i:s', $t ?? $this->now());
    }

    private function log(int $userId, string $action, ?string $details): void
    {
        if (function_exists('logActivity')) {
            try { logActivity($userId, null, $action, $details); } catch (Throwable $e) { /* audit is best effort here */ }
        }
    }
}
