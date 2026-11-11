<?php
declare(strict_types=1);

/**
 * PortalProfileAudit — a trail for every change a client makes to their own details through the
 * portal (Fable review 2026-10-12). The portal token is permanent and travels in emails, so an
 * email or phone change made with it must leave a record: one activity_log row listing each field
 * old → new, and a consent_log row whenever marketing-email or SMS consent flips.
 *
 * No confirmation email is sent (that would be a new send — Tim's decision).
 * diff() is pure; tested in tests/Unit/Contacts/PortalProfileAuditTest.php.
 */
final class PortalProfileAudit
{
    public const FIELDS = ['first_name', 'last_name', 'email', 'phone', 'mobile',
                           'preferred_contact_method', 'receive_marketing', 'receive_sms'];

    /** Fields whose value changed: [field => [old, new]]. Empty/null are the same; compared as strings. */
    public static function diff(array $old, array $new): array
    {
        $out = [];
        foreach (self::FIELDS as $f) {
            if (!array_key_exists($f, $new)) continue;
            $a = trim((string)($old[$f] ?? ''));
            $b = trim((string)($new[$f] ?? ''));
            if ($a !== $b) $out[$f] = [$a, $b];
        }
        return $out;
    }

    /** The activity_log details line: "email: a@x → b@y; phone: … → …". */
    public static function describe(array $diff): string
    {
        $parts = [];
        foreach ($diff as $f => [$a, $b]) {
            $parts[] = $f . ': ' . ($a === '' ? '(blank)' : $a) . ' → ' . ($b === '' ? '(blank)' : $b);
        }
        return implode('; ', $parts);
    }

    /** Write the trail. Never throws — a failed log must not undo the client's save (it is logged). */
    public static function record(PDO $db, int $contactId, array $diff, string $ip, string $userAgent): void
    {
        if (!$diff) return;
        try {
            $db->prepare("INSERT INTO activity_log (user_id, contact_id, action, details, ip_address)
                          VALUES (NULL, ?, 'Client updated their details (portal)', ?, ?)")
               ->execute([$contactId, self::describe($diff), substr($ip, 0, 45)]);
        } catch (Throwable $e) {
            error_log('PortalProfileAudit activity_log: ' . $e->getMessage());
        }
        $consent = ['receive_marketing' => 'marketing_email', 'receive_sms' => 'sms'];
        foreach ($consent as $field => $type) {
            if (!isset($diff[$field])) continue;
            try {
                $db->prepare("INSERT INTO consent_log (contact_id, consent_type, consent_given, consent_text, consent_source, ip_address, user_agent)
                              VALUES (?, ?, ?, ?, 'customer_portal', ?, ?)")
                   ->execute([$contactId, $type, (int)$diff[$field][1] === 1 ? 1 : 0,
                              'Client portal "My details" checkbox', substr($ip, 0, 45), substr($userAgent, 0, 255)]);
            } catch (Throwable $e) {
                error_log('PortalProfileAudit consent_log: ' . $e->getMessage());
            }
        }
    }
}
