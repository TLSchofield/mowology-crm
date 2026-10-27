<?php
declare(strict_types=1);

require_once __DIR__ . '/OnboardingService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/BusinessIdentityService.php';
require_once dirname(__DIR__, 2) . '/Settings/Services/Registry/OrgBookBcProvider.php';

final class SignUpService
{
    public const TRADES = ['gardening', 'landscaping', 'lawn_care', 'snow', 'cleaning', 'other'];

    private PDO $db;
    private string $dbName;

    public function __construct(PDO $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    public static function validate(array $in): array
    {
        $errors = [];
        $businessName = trim((string)($in['business_name'] ?? ''));
        $first = trim((string)($in['owner_first'] ?? ''));
        $last = trim((string)($in['owner_last'] ?? ''));
        $length = static function (string $value): int {
            return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        };
        // 2–60: BusinessIdentityService::manual() keeps the web's two-character minimum for a legal name.
        if ($length($businessName) < 2 || $length($businessName) > 60) $errors['business_name'] = 'Enter a business name (2–60 characters).';
        if (!in_array((string)($in['trade'] ?? ''), self::TRADES, true)) $errors['trade'] = 'Choose a trade.';
        if ($length($first) < 1 || $length($first) > 40) $errors['owner_first'] = "Enter the owner's first name (1–40 characters).";
        if ($length($last) < 1 || $length($last) > 40) $errors['owner_last'] = "Enter the owner's last name (1–40 characters).";
        if (filter_var(trim((string)($in['email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) $errors['email'] = 'Enter a valid email address.';
        if (strlen((string)($in['password'] ?? '')) < 10) $errors['password'] = 'Use at least 10 characters.';
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

    /** Claim the provisioned owner and business. */
    public function claim(array $in, string $ip, ?callable $issueToken = null): array
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->db->query("SELECT id, email, password_hash, full_name, role, last_login FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1" . $lock);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($owner === null) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 404, 'error' => 'not_available'];
            }
            $claimed = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?' . $lock);
            $claimed->execute(['tenant_claimed_at']);
            $claimedAt = $claimed->fetchColumn();
            if (self::claimState($owner, $claimedAt === false ? null : (string)$claimedAt) === 'claimed') {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 409, 'error' => 'taken'];
            }
            // The host is guessable before claim; the email given to the operator is the shared secret.
            if (!self::ownerEmailMatches($owner, (string)($in['email'] ?? ''))) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 400, 'error' => 'validation', 'fields' => ['email' => 'Use the email address this business was set up with.']];
            }

            $ownerId = (int)$owner['id'];
            $hash = password_hash((string)$in['password'], PASSWORD_DEFAULT);
            $this->db->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?')->execute([$hash, $ownerId]);
            $context = new OnboardingContext($this->db, $ownerId);
            $nameResult = (new OnboardingNameModule())->save($context, (string)$in['owner_first'], (string)$in['owner_last'], '');
            if (!$nameResult['ok']) throw new RuntimeException($nameResult['message']);
            $businessId = $this->db->query('SELECT id FROM business_settings ORDER BY id ASC LIMIT 1')->fetchColumn();
            $this->db->prepare('UPDATE business_settings SET company_name = ? WHERE id = ?')
                ->execute([trim((string)$in['business_name']), (int)$businessId]);
            $identity = new BusinessIdentityService($this->db, new OrgBookBcProvider($this->dbName), $ownerId);
            $identityResult = $identity->manual(['legal_name' => trim((string)$in['business_name'])]);
            if (($identityResult['ok'] ?? false) !== true) throw new RuntimeException((string)($identityResult['error'] ?? 'Business identity could not be saved'));
            $context->saveSetting('business_trade', (string)$in['trade'], 'Business: trade selected by the owner (mobile sign-up)');
            $context->saveSetting('owner_user_id', (string)$ownerId, 'Business: owner user id (mobile sign-up)');
            $context->saveSetting('tenant_claimed_at', date('Y-m-d H:i:s'), 'Business: tenant claimed at (mobile sign-up)');

            $owner['password_hash'] = $hash;
            $owner['full_name'] = trim((string)$in['owner_first'] . ' ' . (string)$in['owner_last']);
            $owner['role'] = 'admin';
            $next = (new OnboardingService(new OnboardingContext($this->db, $ownerId)))->next();
            $token = $issueToken !== null ? $issueToken($owner) : null;
            try {
                $this->db->prepare("INSERT INTO activity_log (user_id, client_id, action, details, ip_address) VALUES (?, NULL, 'Sign-up (mobile)', 'Business claimed, JWT issued', ?)")
                    ->execute([$ownerId, $ip]);
            } catch (Throwable $logError) {
                error_log('[signup] Activity log failed: ' . get_class($logError));
            }
            $this->db->commit();

            return ['ok' => true, 'status' => 201, 'user' => $owner, 'token' => $token, 'next' => $next];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('[signup] Claim failed: ' . get_class($e));
            return ['ok' => false, 'status' => 500, 'error' => 'server'];
        }
    }
}
