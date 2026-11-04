<?php
declare(strict_types=1);

require_once __DIR__ . '/OnboardingService.php';

final class SignUpService
{
    private PDO $db;
    private string $dbName;

    public function __construct(PDO $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    /** @return array{0:string,1:string}|null */
    public static function splitName(string $name): ?array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $space = strpos($name, ' ');
        if ($length < 2 || $length > 80 || $space === false) return null;
        $first = trim(substr($name, 0, $space));
        $last = trim(substr($name, $space + 1));
        return $first !== '' && $last !== '' ? [$first, $last] : null;
    }

    public static function mobile(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        return strlen($digits) === 10 ? '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6) : null;
    }

    public static function validate(array $in, bool $withCode = false): array
    {
        $errors = [];
        if (self::splitName((string)($in['full_name'] ?? '')) === null) $errors['full_name'] = 'Enter your full name.';
        if (self::mobile((string)($in['mobile'] ?? '')) === null) $errors['mobile'] = 'Enter your mobile number (10 digits).';
        if (filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) $errors['email'] = 'Enter a valid email address.';
        if (strlen((string)($in['password'] ?? '')) < 10) $errors['password'] = 'Use at least 10 characters.';
        if ($withCode && !preg_match('/^\d{6}$/', (string)($in['code'] ?? ''))) $errors['code'] = 'Enter the 6-digit code.';
        return $errors;
    }

    public static function claimState(array $owner, ?string $claimedAt): string
    {
        return trim((string)$claimedAt) !== '' || !empty($owner['last_login']) ? 'claimed' : 'unclaimed';
    }

    public static function ownerEmailMatches(array $owner, string $email): bool
    {
        return strtolower(trim((string)($owner['email'] ?? ''))) === strtolower(trim($email));
    }

    /** Pure endpoint response builder: the plain code can only be added by an explicit local flag. */
    public static function requestResponse(array $sent, string $code, bool $local): array
    {
        $out = ['ok' => true, 'sent' => array_values($sent), 'expires_in' => 900];
        if ($local) $out['code'] = $code;
        return $out;
    }

    /** Check the provisioned claim and replace any earlier verification code. */
    public function requestCode(array $in, ?string $code = null, ?int $now = null): array
    {
        $owner = $this->owner(false);
        if ($owner === null) return ['ok' => false, 'status' => 404, 'error' => 'not_available'];
        $state = $this->claimCheck($owner, (string)($in['email'] ?? ''));
        if ($state !== null) return $state;
        $code = $code ?? str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $now ?? time();
        $this->setting('signup_code_hash', password_hash($code, PASSWORD_DEFAULT), 'Mobile sign-up verification code');
        $this->setting('signup_code_expires_at', date('Y-m-d H:i:s', $now + 900), 'Mobile sign-up code expiry');
        $this->setting('signup_code_tries', '0', 'Mobile sign-up wrong-code attempts');
        return ['ok' => true, 'status' => 200, 'code' => $code, 'expires_in' => 900];
    }

    public function clearCode(): void
    {
        $this->db->prepare("DELETE FROM ops_settings WHERE setting_key IN ('signup_code_hash','signup_code_expires_at','signup_code_tries')")->execute();
    }

    /** Verify the code and claim atomically. */
    public function confirm(array $in, string $ip, ?callable $issueToken = null, ?int $now = null): array
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $code = $this->codeState($lock);
            $now = $now ?? time();
            if (!$code['hash'] || !$code['expires'] || strtotime($code['expires']) < $now) {
                $this->db->rollBack();
                return self::expired();
            }
            if ($code['tries'] >= 5) {
                $this->clearCode();
                $this->db->commit();
                return self::expired();
            }
            if (!password_verify((string)($in['code'] ?? ''), $code['hash'])) {
                $this->setting('signup_code_tries', (string)($code['tries'] + 1), 'Mobile sign-up wrong-code attempts');
                $this->db->commit();
                return ['ok' => false, 'status' => 400, 'error' => 'code_wrong', 'message' => "That code isn't right."];
            }

            $owner = $this->owner(true);
            if ($owner === null) { $this->db->rollBack(); return ['ok' => false, 'status' => 404, 'error' => 'not_available']; }
            $state = $this->claimCheck($owner, (string)($in['email'] ?? ''), $lock);
            if ($state !== null) { $this->db->rollBack(); return $state; }

            [$first, $last] = self::splitName((string)$in['full_name']);
            $mobile = (string)self::mobile((string)$in['mobile']);
            $ownerId = (int)$owner['id'];
            $hash = password_hash((string)$in['password'], PASSWORD_DEFAULT);
            $this->db->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?')->execute([$hash, $ownerId]);
            $context = new OnboardingContext($this->db, $ownerId);
            $name = new OnboardingNameModule();
            $saved = $name->save($context, $first, $last, $first);
            if (!$saved['ok']) throw new RuntimeException($saved['message']);
            $reach = $name->saveReach($context, 'text', $mobile);
            if (!$reach['ok']) throw new RuntimeException($reach['message']);
            $context->saveSetting('owner_user_id', (string)$ownerId, 'Business: owner user id (mobile sign-up)');
            $context->saveSetting('tenant_claimed_at', date('Y-m-d H:i:s', $now), 'Business: tenant claimed at (mobile sign-up)');
            $this->clearCode();

            $owner['password_hash'] = $hash;
            $owner['full_name'] = trim($first . ' ' . $last);
            $owner['role'] = 'admin';
            $next = (new OnboardingService($context))->next();
            $token = $issueToken !== null ? $issueToken($owner) : null;
            try {
                $this->db->prepare("INSERT INTO activity_log (user_id, action, details, ip_address) VALUES (?, 'Sign-up (mobile)', 'Business claimed, JWT issued', ?)")->execute([$ownerId, $ip]);
            } catch (Throwable $logError) { error_log('[signup] Activity log failed: ' . get_class($logError)); }
            $this->db->commit();
            return ['ok' => true, 'status' => 201, 'user' => $owner, 'token' => $token, 'next' => $next];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[signup] Claim failed: ' . get_class($e));
            return ['ok' => false, 'status' => 500, 'error' => 'server'];
        }
    }

    private static function expired(): array
    {
        return ['ok' => false, 'status' => 400, 'error' => 'code_expired', 'message' => 'That code has expired. Ask for a new one.'];
    }

    private function owner(bool $lock): ?array
    {
        $suffix = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $row = $this->db->query("SELECT id, email, password_hash, full_name, role, last_login FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1" . $suffix)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function claimCheck(array $owner, string $email, string $lock = ''): ?array
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?' . $lock);
        $stmt->execute(['tenant_claimed_at']);
        $claimed = $stmt->fetchColumn();
        if (self::claimState($owner, $claimed === false ? null : (string)$claimed) === 'claimed') return ['ok' => false, 'status' => 409, 'error' => 'taken'];
        if (!self::ownerEmailMatches($owner, $email)) return ['ok' => false, 'status' => 400, 'error' => 'validation', 'fields' => ['email' => 'Use the email address this business was set up with.']];
        return null;
    }

    private function setting(string $key, string $value, string $description): void
    {
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ?, description = ? WHERE setting_key = ?');
        $u->execute([$value, $description, $key]);
        // MySQL's rowCount() counts CHANGED rows: re-writing the same value (signup_code_tries = '0' on a second
        // code request) reports 0 although the row exists. Only insert when the row is really missing.
        if ($u->rowCount() > 0) return;
        $chk = $this->db->prepare('SELECT 1 FROM ops_settings WHERE setting_key = ?');
        $chk->execute([$key]);
        if ($chk->fetchColumn() === false) $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $description]);
    }

    private function codeState(string $lock): array
    {
        $s = $this->db->query("SELECT setting_key, setting_value FROM ops_settings WHERE setting_key IN ('signup_code_hash','signup_code_expires_at','signup_code_tries')" . $lock);
        $v = $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        return ['hash' => (string)($v['signup_code_hash'] ?? ''), 'expires' => (string)($v['signup_code_expires_at'] ?? ''), 'tries' => (int)($v['signup_code_tries'] ?? 0)];
    }
}
