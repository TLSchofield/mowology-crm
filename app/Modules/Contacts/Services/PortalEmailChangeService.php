<?php
/**
 * PortalEmailChangeService — a client's email changed through the portal is not applied until the
 * NEW address confirms it (security review #14, 2026-10-12).
 *
 * The portal link travels in emails and could be forwarded; whoever held it could change the email
 * and have every future invoice (with its payment link) rerouted. Now profile-update.php calls
 * request(): the change waits in contact_email_changes (migration 1341), a confirmation link goes
 * to the NEW address and a notice to the OLD one — both through sendCrmEmail() (MessagingService),
 * so they only exist once this is deployed. confirm() applies it when the link is used (POST from
 * /customer/confirm-email.php — a mail scanner fetching the link does not confirm it). Links last
 * 48 hours and are stored hashed.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/Services/Messaging/IdentityMail.php';

final class PortalEmailChangeService
{
    public const TTL_HOURS = 48;

    private PDO $db;
    /** @var callable(string $to, string $subject, string $html): bool */
    private $send;

    public function __construct(PDO $db, ?callable $send = null)
    {
        $this->db = $db;
        $this->send = $send ?? static function (string $to, string $subject, string $html): bool {
            require_once APP_ROOT . '/Services/Messaging/EmailWrapper.php';
            require_once APP_ROOT . '/Services/Messaging/MessagingService.php';
            return sendCrmEmail($to, $subject, EmailWrapper::wrap($html, null, null, EmailWrapper::getCompanyInfo()));
        };
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT id FROM contact_email_changes LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Does this change need confirming? Any different, non-empty new address does. PURE. */
    public static function needsConfirmation(?string $old, string $new): bool
    {
        $new = strtolower(trim($new));
        return $new !== '' && $new !== strtolower(trim((string)$old));
    }

    /**
     * Hold a change and send the two emails. Returns true when the change is waiting for confirmation.
     * Pending changes for the same contact are replaced (only the newest link works).
     */
    public function request(int $contactId, ?string $oldEmail, string $newEmail, string $firstName, string $ip, string $baseUrl): bool
    {
        if (!$this->ready() || !self::needsConfirmation($oldEmail, $newEmail)) return false;
        $token = bin2hex(random_bytes(32));
        $this->db->prepare("DELETE FROM contact_email_changes WHERE contact_id = ? AND confirmed_at IS NULL")->execute([$contactId]);
        $this->db->prepare("INSERT INTO contact_email_changes (contact_id, old_email, new_email, token_hash, expires_at, ip_address, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())")
                 ->execute([$contactId, $oldEmail ?: null, trim($newEmail), hash('sha256', $token),
                            date('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600), substr($ip, 0, 45)]);
        $id = (int)$this->db->lastInsertId();

        $name = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);
        $link = rtrim($baseUrl, '/') . '/customer/confirm-email.php?t=' . $token;
        $sentNew = ($this->send)(trim($newEmail), 'Confirm your new email address',
            "<p>Hi {$name},</p><p>Someone asked to send your " . htmlspecialchars(_idFromName(), ENT_QUOTES) . " invoices and updates to this address. "
            . "If that was you, confirm it here (the link works for " . self::TTL_HOURS . " hours):</p>"
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Confirm my new email address</a></p>'
            . '<p>If it wasn\'t you, ignore this email and nothing changes. Questions?' . (_idPhone() !== '' ? ' Call ' . htmlspecialchars(_idPhone(), ENT_QUOTES) . '.' : '') . '</p>');
        if ($oldEmail !== null && trim($oldEmail) !== '') {
            ($this->send)(trim($oldEmail), 'Your ' . _idFromName() . ' email is being changed',
                "<p>Hi {$name},</p><p>Someone asked to change the email on your " . htmlspecialchars(_idFromName(), ENT_QUOTES) . " account from this address to "
                . htmlspecialchars(self::mask($newEmail), ENT_QUOTES) . ". It only changes once the new address confirms it.</p>"
                . '<p>If that wasn\'t you, please ' . (_idPhone() !== '' ? 'call us at ' . htmlspecialchars(_idPhone(), ENT_QUOTES) : 'contact us') . ' — we\'ll stop it and send you a new portal link.</p>');
        }
        $this->log($contactId, 'Portal email change requested', "Waiting for {$newEmail} to confirm (request #{$id}); "
                   . ($sentNew ? 'confirmation sent' : 'confirmation email FAILED') . '; notice to the old address.', $ip);
        return true;
    }

    /** The pending change a link points to, if still valid. */
    public function pending(string $token): ?array
    {
        if (!$this->ready() || !preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $s = $this->db->prepare("SELECT id, contact_id, old_email, new_email, expires_at FROM contact_email_changes
                                 WHERE token_hash = ? AND confirmed_at IS NULL LIMIT 1");
        $s->execute([hash('sha256', $token)]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r || strtotime((string)$r['expires_at']) < time()) return null;
        return $r;
    }

    /** Apply the change. Returns the new email, or null when the link is invalid / used / expired. */
    public function confirm(string $token, string $ip): ?string
    {
        $r = $this->pending($token);
        if (!$r) return null;
        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare("UPDATE contact_email_changes SET confirmed_at = NOW() WHERE id = ? AND confirmed_at IS NULL");
            $u->execute([(int)$r['id']]);
            if ($u->rowCount() !== 1) { $this->db->rollBack(); return null; }
            $this->db->prepare("UPDATE contacts SET email = ? WHERE id = ?")->execute([$r['new_email'], (int)$r['contact_id']]);
            $this->log((int)$r['contact_id'], 'Client updated their details (portal)',
                       'email: ' . ($r['old_email'] ?: '(blank)') . ' → ' . $r['new_email'] . ' (confirmed by the new address)', $ip);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        return (string)$r['new_email'];
    }

    /** "j***@example.com" — the old address is told where it's going without spelling it out. PURE. */
    public static function mask(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) return '***';
        return $email[0] . str_repeat('*', max(3, $at - 1)) . substr($email, $at);
    }

    private function log(int $contactId, string $action, string $details, string $ip): void
    {
        try {
            $this->db->prepare("INSERT INTO activity_log (user_id, contact_id, action, details, ip_address) VALUES (NULL, ?, ?, ?, ?)")
                     ->execute([$contactId, $action, $details, substr($ip, 0, 45)]);
        } catch (Throwable $e) {
            error_log('PortalEmailChangeService log: ' . $e->getMessage());
        }
    }
}
