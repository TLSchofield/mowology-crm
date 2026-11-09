<?php
/**
 * ContactRoster — who is the contact NOW for an account (Tim, 2026-11-07: "Getting this right is fundamental to
 * automating the business"). Reads client_contacts, which ContactRosterSync keeps complete.
 *
 * A person with ended_on today or earlier has left that account. They stay on record for history, and only that
 * account is affected: someone who left one strata may still be the contact for another.
 *
 * Until migration 1443 has run there are no left dates, so nobody counts as former and every answer is what it was
 * before this class existed. Plan: docs/plans/2026-11-07-contact-left-the-building.md.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ContactRoster
{
    private const PURPOSE_FILTER = [
        'invoice' => 'cc.receives_invoices = 1',
        'thanks'  => '(cc.receives_invoices = 1 OR cc.receives_notifications = 1)',
    ];

    private PDO $db;
    private ?bool $hasDates = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** True when this person's every role on this account has ended. False if they hold any role still running. */
    public function isFormer(int $contactId, int $clientId, DateTimeImmutable $today): bool
    {
        if ($contactId <= 0 || $clientId <= 0 || !$this->hasDates()) return false;
        $day = $today->format('Y-m-d');
        $s = $this->db->prepare("
            SELECT SUM(CASE WHEN ended_on IS NOT NULL AND ended_on <= ? THEN 1 ELSE 0 END) AS ended,
                   SUM(CASE WHEN ended_on IS NULL OR ended_on > ? THEN 1 ELSE 0 END) AS running
            FROM client_contacts WHERE client_id = ? AND contact_id = ?");
        $s->execute([$day, $day, $clientId, $contactId]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return (int)($r['ended'] ?? 0) > 0 && (int)($r['running'] ?? 0) === 0;
    }

    /**
     * Today's people on this account for a purpose, primary first, one row per person with a usable email.
     * @return array<int,array{contact_id:int,email_address:string,first_name:string,last_name:string,role:string}>
     */
    public function current(int $clientId, string $purpose, DateTimeImmutable $today): array
    {
        if (!isset(self::PURPOSE_FILTER[$purpose])) throw new InvalidArgumentException('Unknown roster purpose: ' . $purpose);
        if ($clientId <= 0) return [];
        $running = $this->hasDates() ? 'AND (cc.ended_on IS NULL OR cc.ended_on > ?)' : '';
        $s = $this->db->prepare("
            SELECT cc.contact_id, c.email AS email_address, c.first_name, c.last_name, cc.role
            FROM client_contacts cc JOIN contacts c ON c.id = cc.contact_id
            WHERE cc.client_id = ? AND c.is_active = 1 AND c.merged_into_id IS NULL
              AND " . self::PURPOSE_FILTER[$purpose] . " $running
            ORDER BY cc.is_primary DESC, cc.receives_invoices DESC, cc.id");
        $s->execute($running !== '' ? [$clientId, $today->format('Y-m-d')] : [$clientId]);

        $people = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $id = (int)$p['contact_id'];
            if (isset($people[$id]) || !filter_var(trim((string)$p['email_address']), FILTER_VALIDATE_EMAIL)) continue;
            $people[$id] = ['contact_id' => $id, 'email_address' => trim((string)$p['email_address']),
                'first_name' => (string)$p['first_name'], 'last_name' => (string)$p['last_name'], 'role' => (string)$p['role']];
        }
        return array_values($people);
    }

    /**
     * The first recipient on the list who hasn't left the account. If people were passed over because they left, the
     * account's current person takes their place, marked 'replaced'; null when nobody is left to write to. A row with no contact
     * (a management firm's inbox) stands for $fallbackContactId, the person the message would greet.
     * @param array<int,array<string,mixed>> $recipients rows with email_address and optional contact_id
     * @return array<string,mixed>|null
     */
    public function firstCurrent(array $recipients, int $fallbackContactId, int $clientId, string $purpose, DateTimeImmutable $today): ?array
    {
        $passedOver = false;
        foreach ($recipients as $r) {
            if (!filter_var(trim((string)($r['email_address'] ?? '')), FILTER_VALIDATE_EMAIL)) continue;
            $who = (int)($r['contact_id'] ?? 0) ?: $fallbackContactId;
            if ($this->isFormer($who, $clientId, $today)) {
                $passedOver = true;
                continue;
            }
            return $r;
        }
        $next = $passedOver ? ($this->current($clientId, $purpose, $today)[0] ?? null) : null;
        return $next ? $next + ['replaced' => true] : null;
    }

    private function hasDates(): bool
    {
        if ($this->hasDates !== null) return $this->hasDates;
        try {
            $this->hasDates = $this->db->query('SELECT ended_on FROM client_contacts LIMIT 1') !== false;
        } catch (Throwable $e) {
            $this->hasDates = false;
        }
        return $this->hasDates;
    }
}
