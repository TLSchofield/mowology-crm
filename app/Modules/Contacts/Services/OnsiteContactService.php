<?php
/**
 * OnsiteContactService — the person crew actually call when they're standing at the gate.
 *
 * `properties.site_contact_id` is NOT this: clients_appstack.php writes it with the client
 * who owns the property (the billing side), and invoice routing keys off it. The on-site
 * contact — caretaker, building manager, tenant — lives in `property_contacts` with
 * contact_role = 'site_supervisor'. One primary per property; this service owns that row.
 *
 * Reads degrade to "none" when the table is missing (it predates the migration runner —
 * migration 1122 re-creates it idempotently), so the schedule query never breaks on it.
 *
 * Global namespace on purpose: production has no autoloader for app/ classes.
 */
class OnsiteContactService
{
    public const ROLE = 'site_supervisor';

    /** @var array<int,bool> table-exists probe, cached per PDO instance id */
    private static array $tableProbe = [];

    public function __construct(private PDO $db) {}

    // ── Availability ─────────────────────────────────────────────────────────

    /** True when property_contacts exists on this database. Cached per connection. */
    public function isAvailable(): bool
    {
        $key = spl_object_id($this->db);
        if (!array_key_exists($key, self::$tableProbe)) {
            try {
                $stmt = $this->db->query("SHOW TABLES LIKE 'property_contacts'");
                self::$tableProbe[$key] = $stmt !== false && $stmt->fetchColumn() !== false;
            } catch (Throwable $e) {
                self::$tableProbe[$key] = false;
            }
        }
        return self::$tableProbe[$key];
    }

    /** Test seam — forget the probe result. */
    public static function resetProbeCache(): void
    {
        self::$tableProbe = [];
    }

    // ── SQL fragments for the schedule query ─────────────────────────────────

    /**
     * Correlated subquery resolving the primary on-site contact id for a property alias.
     * Used as `LEFT JOIN contacts osc ON osc.id = (<this>)` so the stop row count never
     * multiplies, whatever is in property_contacts.
     */
    public static function primaryIdSubquery(string $propertyAlias = 'p'): string
    {
        return "(SELECT pc.contact_id FROM property_contacts pc
                  WHERE pc.property_id = {$propertyAlias}.id AND pc.contact_role = '" . self::ROLE . "'
                  ORDER BY pc.is_primary DESC, pc.id ASC LIMIT 1)";
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * The primary on-site contact for a property, or null.
     * @return array{contact_id:int,name:string,phone:?string,email:?string}|null
     */
    public function getForProperty(int $propertyId): ?array
    {
        if ($propertyId <= 0 || !$this->isAvailable()) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT c.id, c.first_name, c.last_name, c.phone, c.mobile, c.email
            FROM property_contacts pc
            JOIN contacts c ON c.id = pc.contact_id
            WHERE pc.property_id = ? AND pc.contact_role = ?
            ORDER BY pc.is_primary DESC, pc.id ASC
            LIMIT 1
        ");
        $stmt->execute([$propertyId, self::ROLE]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::shape($row) : null;
    }

    /**
     * Normalise a contacts row into what the phone shows. Mobile beats landline — crew
     * text and call from the truck, and the landline is usually the office.
     */
    public static function shape(array $row): array
    {
        $name  = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $phone = trim((string)($row['mobile'] ?? '')) ?: trim((string)($row['phone'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));
        return [
            'contact_id' => (int)($row['id'] ?? $row['contact_id'] ?? 0),
            'name'       => $name,
            'phone'      => $phone !== '' ? $phone : null,
            'email'      => $email !== '' ? $email : null,
        ];
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * Make $contactId the (only) primary on-site contact for the property.
     * Idempotent; replaces any previous site_supervisor rows.
     */
    public function setForProperty(int $propertyId, int $contactId): void
    {
        if ($propertyId <= 0 || $contactId <= 0) {
            throw new InvalidArgumentException('propertyId and contactId must be positive');
        }
        if (!$this->isAvailable()) {
            throw new RuntimeException('property_contacts table is missing — run migration 1122');
        }
        $this->clearForProperty($propertyId);
        $this->db->prepare("
            INSERT INTO property_contacts (property_id, contact_id, contact_role, is_primary)
            VALUES (?, ?, ?, 1)
        ")->execute([$propertyId, $contactId, self::ROLE]);
    }

    /** Remove the on-site contact assignment (the contact record itself is kept). */
    public function clearForProperty(int $propertyId): void
    {
        if ($propertyId <= 0 || !$this->isAvailable()) {
            return;
        }
        $this->db->prepare("DELETE FROM property_contacts WHERE property_id = ? AND contact_role = ?")
                 ->execute([$propertyId, self::ROLE]);
    }

    /**
     * Create a minimal contact for a caretaker the office only knows by name/phone and
     * assign it. Returns the new contact id. Deliberately not a "prospect": this person
     * is never quoted or marketed to.
     */
    public function quickAddAndAssign(int $propertyId, string $fullName, ?string $phone, ?string $email): int
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName));
        if ($fullName === '') {
            throw new InvalidArgumentException('A name is required for the on-site contact');
        }
        [$first, $last] = self::splitName($fullName);
        $phone = trim((string)$phone) ?: null;
        $email = trim((string)$email) ?: null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That email address does not look valid');
        }

        $this->db->prepare("
            INSERT INTO contacts (first_name, last_name, mobile, email, consent_source, prospect_status, notes, is_active)
            VALUES (?, ?, ?, ?, 'crm_manual', 'inactive', 'On-site contact (added from property page)', 1)
        ")->execute([$first, $last, $phone, $email]);
        $contactId = (int)$this->db->lastInsertId();

        $this->setForProperty($propertyId, $contactId);
        return $contactId;
    }

    /** "Maria de la Cruz" → ["Maria", "de la Cruz"]; single word → [word, null]. */
    public static function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];
        return [$parts[0] ?? '', isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null];
    }
}
