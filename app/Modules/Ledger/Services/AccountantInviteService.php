<?php
declare(strict_types=1);

/**
 * AccountantInviteService — "Invite your accountant" (Accountant's Ledger step 1, 2026-10-13).
 *
 * Creates a login with users.role = 'accountant' and the RBAC role 'accountant', and a set-password
 * link through the existing reset flow (password_reset_tokens + /loginAuth/reset_password.php).
 * It SENDS NOTHING: the owner copies the link and passes it on. The link lasts LINK_HOURS; asking
 * again replaces it. Revoking deactivates the login (is_active = 0 — signs out open sessions within a
 * minute, AuthFreshness) and deletes any unused link.
 *
 * Every invite / new link / revoke is written to activity_log ('Invited accountant …').
 * No namespace / no autoloader in production: require_once and `new`.
 */
class AccountantInviteService
{
    public const LINK_HOURS = 72;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Pure: is this a usable invite (name + email)? Returns the problem, or null. */
    public static function invalid(string $name, string $email): ?string
    {
        if (trim($name) === '') return 'Enter the accountant\'s name.';
        if (mb_strlen($name) > 120) return 'That name is too long.';
        if (!filter_var(trim($email), FILTER_VALIDATE_EMAIL)) return 'Enter a valid email address.';
        return null;
    }

    /**
     * Invite (or re-issue a link for) an accountant.
     * @return array{user_id: int, link: string, expires_at: string, created: bool}
     * @throws InvalidArgumentException when the email belongs to another kind of CRM user
     */
    public function invite(string $name, string $email, int $invitedBy, string $siteUrl): array
    {
        $name = trim($name); $email = strtolower(trim($email));
        if ($why = self::invalid($name, $email)) throw new InvalidArgumentException($why);

        $own = !$this->db->inTransaction();
        if ($own) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT id, role FROM users WHERE LOWER(email) = ? LIMIT 1");
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            $created = false;
            if ($u && strtolower((string)$u['role']) !== 'accountant') {
                throw new InvalidArgumentException('That email already belongs to a CRM user who is not an accountant. Use a different address.');
            }
            if ($u) {
                $userId = (int)$u['id'];
                $this->db->prepare("UPDATE users SET is_active = 1, full_name = ? WHERE id = ?")->execute([$name, $userId]);
            } else {
                $parts = preg_split('/\s+/', $name, 2) ?: [$name];
                // An unusable random password: the accountant sets their own through the link.
                $this->db->prepare("INSERT INTO users (email, full_name, first_name, last_name, role, is_active, is_driver, receive_weather_sms, password_hash)
                                    VALUES (?, ?, ?, ?, 'accountant', 1, 0, 0, ?)")
                    ->execute([$email, $name, $parts[0], $parts[1] ?? '', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
                $userId = (int)$this->db->lastInsertId();
                $created = true;
            }
            $roleId = $this->db->query("SELECT id FROM roles WHERE name = 'accountant'")->fetchColumn();
            if (!$roleId) throw new RuntimeException('The accountant role is missing — run migration 1355 first.');
            // The accountant role only: drop any other RBAC role this login might carry.
            $this->db->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id <> ?")->execute([$userId, (int)$roleId]);
            $this->db->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$userId, (int)$roleId]);

            $token = bin2hex(random_bytes(32));
            $this->db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
            $this->db->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . self::LINK_HOURS . " HOUR))")
                ->execute([$userId, hash('sha256', $token)]);
            $exp = $this->db->prepare("SELECT expires_at FROM password_reset_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $exp->execute([$userId]);
            $expiresAt = (string)$exp->fetchColumn();

            if (function_exists('logActivity')) {
                logActivity($invitedBy, null, ($created ? 'Invited accountant #' : 'New set-password link for accountant #') . $userId,
                            'Accountant login ' . $email . ' (nothing sent; link shown to the inviter, valid ' . self::LINK_HOURS . ' h)');
            }
            if ($own) $this->db->commit();
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return ['user_id' => $userId, 'link' => rtrim($siteUrl, '/') . '/loginAuth/reset_password.php?token=' . $token,
                'expires_at' => $expiresAt, 'created' => $created];
    }

    /** Accountant logins with their last sign-in and last Ledger activity. */
    public function accountants(): array
    {
        return $this->db->query("
            SELECT u.id, u.full_name, u.email, u.is_active, u.last_login, u.created_at,
                   (SELECT MAX(a.created_at) FROM activity_log a WHERE a.user_id = u.id AND a.action IN ('ledger.view', 'ledger.export')) AS last_ledger,
                   (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id AND a.action = 'ledger.export') AS exports
            FROM users u WHERE u.role = 'accountant' ORDER BY u.is_active DESC, u.full_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Owner-revocable: deactivate the login and drop any unused link. */
    public function revoke(int $userId, int $by): bool
    {
        $st = $this->db->prepare("UPDATE users SET is_active = 0 WHERE id = ? AND role = 'accountant'");
        $st->execute([$userId]);
        if ($st->rowCount() === 0) return false;
        $this->db->prepare("DELETE FROM password_reset_tokens WHERE user_id = ?")->execute([$userId]);
        if (function_exists('logActivity')) logActivity($by, null, 'Revoked accountant #' . $userId, 'Accountant login deactivated');
        return true;
    }
}
