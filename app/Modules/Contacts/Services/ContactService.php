<?php
declare(strict_types=1);

/**
 * ContactService
 *
 * Extracted from clients_appstack.php. All contact/company/property
 * mutations that previously lived as inline SQL in the page file now
 * live here. The page file becomes a thin controller.
 *
 * No namespace — loaded via bootstrap.php require_once (consistent with
 * the rest of app/Modules/[Module]/Services/).
 */
class ContactService
{
    public function __construct(private PDO $db) {}

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a new contact and optionally a property + company.
     *
     * $data keys:
     *   first_name, last_name, email, phone, mobile,
     *   preferred_contact_method, receive_sms, receive_marketing,
     *   consent_quote_followup, notes,
     *   -- property (all optional) --
     *   property_address, property_city, property_postal_code,
     *   property_latitude, property_longitude,
     *   -- company (optional, only if link_company=true) --
     *   link_company (bool), company_mode ('new'|'existing'),
     *   company_id, company_name, company_type,
     *   billing_address, billing_city, billing_province, billing_postal_code,
     *   account_status, payment_terms, pref_attach_pdf
     *
     * Returns newly created contact ID.
     */
    public function createContact(array $data): int
    {
        // Run any safe DDL migrations outside the transaction
        $this->ensureContactColumns();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO contacts (
                    first_name, last_name, email, phone, mobile,
                    preferred_contact_method, receive_sms, receive_marketing,
                    consent_quote_followup, consent_marketing_email, consent_sms,
                    consent_source, prospect_status, notes, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'crm_manual', 'prospect', ?, 1)
            ");
            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['email']                  ?? '',
                $data['phone']                  ?? '',
                $data['mobile']                 ?? '',
                $data['preferred_contact_method'] ?? 'phone',
                $data['receive_sms']            ?? 0,
                $data['receive_marketing']      ?? 0,
                $data['consent_quote_followup'] ?? 0,
                $data['receive_marketing']      ?? 0,   // consent_marketing_email mirrors receive_marketing
                $data['receive_sms']            ?? 0,   // consent_sms mirrors receive_sms
                $data['notes']                  ?? '',
            ]);
            $contactId = (int) $this->db->lastInsertId();

            // Property
            $propertyAddress = trim($data['property_address'] ?? '');
            if ($propertyAddress !== '') {
                $this->attachPropertyToNewContact(
                    $contactId,
                    $data['first_name'] . ' ' . $data['last_name'],
                    $propertyAddress,
                    $data['property_city']        ?? 'Vancouver',
                    $data['property_postal_code'] ?? '',
                    $data['property_latitude']    ? (float) $data['property_latitude']  : null,
                    $data['property_longitude']   ? (float) $data['property_longitude'] : null,
                );
            }

            // Company
            if (!empty($data['link_company'])) {
                if (($data['company_mode'] ?? 'new') === 'new') {
                    $this->db->prepare("
                        INSERT INTO companies (
                            company_name, company_type, primary_contact_id, billing_contact_id,
                            billing_email, billing_phone, billing_address, billing_city,
                            billing_province, billing_postal_code, account_status, payment_terms,
                            pref_attach_pdf
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $data['company_name']        ?? '',
                        $data['company_type']         ?? 'individual',
                        $contactId,
                        $contactId,
                        $data['email']               ?? '',
                        $data['phone']               ?? '',
                        $data['billing_address']      ?? '',
                        $data['billing_city']         ?? 'Vancouver',
                        $data['billing_province']     ?? 'BC',
                        $data['billing_postal_code']  ?? '',
                        $data['account_status']       ?? 'active',
                        $data['payment_terms']        ?? 'Net 30',
                        $data['pref_attach_pdf']      ?? 0,
                    ]);
                } else {
                    $existingCompanyId = (int) ($data['company_id'] ?? 0);
                    if ($existingCompanyId) {
                        $this->db->prepare(
                            "UPDATE companies SET primary_contact_id = ?, billing_contact_id = ? WHERE id = ?"
                        )->execute([$contactId, $contactId, $existingCompanyId]);
                    }
                }
            }

            $this->db->commit();
            return $contactId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE — Contact
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Update contact fields. Returns the old values (for change tracking
     * by the caller via trackFieldChanges()).
     *
     * $data keys: first_name, last_name, email, phone, mobile,
     *   preferred_contact_method, receive_sms, receive_marketing,
     *   consent_quote_followup, notes
     *
     * @return array Old contact row (for change tracking)
     */
    public function updateContact(int $contactId, array $data): array
    {
        $oldStmt = $this->db->prepare(
            "SELECT first_name, last_name, email, phone, mobile,
                    preferred_contact_method, receive_sms, receive_marketing,
                    consent_quote_followup, notes
             FROM contacts WHERE id = ?"
        );
        $oldStmt->execute([$contactId]);
        $old = $oldStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $this->db->prepare("
            UPDATE contacts SET
                first_name = ?, last_name = ?, email = ?, phone = ?, mobile = ?,
                preferred_contact_method = ?, receive_sms = ?, receive_marketing = ?,
                consent_quote_followup = ?, notes = ?
            WHERE id = ?
        ")->execute([
            $data['first_name'],
            $data['last_name'],
            $data['email']                   ?? '',
            $data['phone']                   ?? '',
            $data['mobile']                  ?? '',
            $data['preferred_contact_method'] ?? 'phone',
            $data['receive_sms']             ?? 0,
            $data['receive_marketing']       ?? 0,
            $data['consent_quote_followup']  ?? 0,
            $data['notes']                   ?? '',
            $contactId,
        ]);

        return $old;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE — Company
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * $data keys: company_name, company_type, billing_email, billing_phone,
     *   billing_address, billing_city, billing_province, billing_postal_code,
     *   account_status, payment_terms, payment_method, notes
     */
    public function updateCompany(int $companyId, array $data): void
    {
        $this->db->prepare("
            UPDATE companies SET
                company_name = ?, company_type = ?, billing_email = ?,
                billing_phone = ?, billing_address = ?, billing_city = ?,
                billing_province = ?, billing_postal_code = ?,
                account_status = ?, payment_terms = ?, payment_method = ?,
                notes = ?
            WHERE id = ?
        ")->execute([
            $data['company_name']       ?? '',
            $data['company_type']       ?? 'individual',
            $data['billing_email']      ?? '',
            $data['billing_phone']      ?? '',
            $data['billing_address']    ?? '',
            $data['billing_city']       ?? 'Vancouver',
            $data['billing_province']   ?? 'BC',
            $data['billing_postal_code'] ?? '',
            $data['account_status']     ?? 'active',
            $data['payment_terms']      ?? 'Net 30',
            $data['payment_method']     ?? 'invoice',
            $data['notes']              ?? '',
            $companyId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE — Property
    // ─────────────────────────────────────────────────────────────────────────

    public function updatePropertyCoords(int $propertyId, float $lat, float $lng): void
    {
        $this->db->prepare(
            "UPDATE properties SET latitude = ?, longitude = ? WHERE id = ?"
        )->execute([$lat, $lng, $propertyId]);
    }

    /**
     * Link a company's primary and billing contact to a given contact.
     */
    public function linkCompanyToContact(int $companyId, int $contactId): void
    {
        $this->db->prepare(
            "UPDATE companies SET primary_contact_id = ?, billing_contact_id = ? WHERE id = ?"
        )->execute([$contactId, $contactId, $companyId]);
    }

    /**
     * Unlink a property from a contact (set site_contact_id = NULL).
     * Only unlinks if the property is actually linked to $contactId.
     *
     * @return bool true if a row was updated
     */
    public function unlinkProperty(int $propertyId, int $contactId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM properties WHERE id = ? AND site_contact_id = ?"
        );
        $stmt->execute([$propertyId, $contactId]);
        if (!$stmt->fetch()) {
            return false;
        }
        $this->db->prepare(
            "UPDATE properties SET site_contact_id = NULL WHERE id = ?"
        )->execute([$propertyId]);
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Property management (AJAX endpoints)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add (or link an existing) property to a contact.
     *
     * $addr keys: address, city, postal_code, province (optional, defaults BC),
     *   latitude (optional), longitude (optional)
     *
     * @return array ['property_id' => int, 'created' => bool]
     */
    public function addPropertyToContact(int $contactId, array $addr): array
    {
        $address = trim($addr['address'] ?? '');

        $check = $this->db->prepare(
            "SELECT id, site_contact_id FROM properties WHERE address = ? LIMIT 1"
        );
        $check->execute([$address]);
        $existing = $check->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            if (!$existing['site_contact_id']) {
                $this->db->prepare(
                    "UPDATE properties SET site_contact_id = ? WHERE id = ?"
                )->execute([$contactId, $existing['id']]);
            }
            return ['property_id' => (int) $existing['id'], 'created' => false];
        }

        $this->db->prepare("
            INSERT INTO properties
                (property_name, address, city, province, postal_code,
                 latitude, longitude, site_contact_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ")->execute([
            $addr['property_name']  ?? $address,
            $address,
            $addr['city']           ?? 'Vancouver',
            $addr['province']       ?? 'BC',
            $addr['postal_code']    ?? '',
            isset($addr['latitude'])  ? (float) $addr['latitude']  : null,
            isset($addr['longitude']) ? (float) $addr['longitude'] : null,
            $contactId,
        ]);

        return ['property_id' => (int) $this->db->lastInsertId(), 'created' => true];
    }

    /**
     * Add (or link an existing) property to a company.
     * Links via the company's primary_contact_id.
     *
     * @return array ['property_id' => int, 'created' => bool]
     */
    public function addPropertyToCompany(int $companyId, array $addr): array
    {
        $row = $this->db->prepare(
            "SELECT primary_contact_id FROM companies WHERE id = ?"
        );
        $row->execute([$companyId]);
        $company = $row->fetch(\PDO::FETCH_ASSOC);

        $contactId = $company ? (int) ($company['primary_contact_id'] ?? 0) : 0;

        return $this->addPropertyToContact($contactId ?: 0, $addr);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────

    public function deleteCompany(int $companyId): void
    {
        $this->db->prepare("DELETE FROM companies WHERE id = ?")->execute([$companyId]);
    }

    /**
     * Bulk delete companies. Skips any that have related jobs or invoices.
     *
     * @param int[] $ids
     * @return array ['deleted' => int, 'skipped' => int]
     */
    public function bulkDeleteCompanies(array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $id = (int) $id;

            $cntStmt = $this->db->prepare(
                "SELECT
                     (SELECT COUNT(*) FROM jobs     WHERE company_id = ?) +
                     (SELECT COUNT(*) FROM invoices WHERE company_id = ?) AS total"
            );
            $cntStmt->execute([$id, $id]);
            $related = (int) $cntStmt->fetchColumn();

            if ($related > 0) {
                $skipped++;
                continue;
            }

            $this->db->prepare("DELETE FROM activity_log WHERE entity_id = ? AND entity_type = 'company'")->execute([$id]);
            $this->db->prepare("DELETE FROM companies WHERE id = ?")->execute([$id]);
            $deleted++;
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Convert quote request → prospect
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Convert a quote_request record into a company prospect.
     *
     * @return int New company ID
     */
    public function convertQuoteRequestToProspect(int $quoteRequestId): int
    {
        $stmt = $this->db->prepare("SELECT * FROM quote_requests WHERE id = ?");
        $stmt->execute([$quoteRequestId]);
        $qr = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$qr) {
            throw new \InvalidArgumentException("Quote request $quoteRequestId not found");
        }

        $this->db->prepare("
            INSERT INTO companies (
                company_name, company_type, billing_email, billing_phone,
                billing_address, billing_city, billing_province,
                account_status, payment_terms
            ) VALUES (?, 'prospect', ?, ?, ?, ?, 'BC', 'prospect', 'Net 30')
        ")->execute([
            trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? '')),
            $qr['email']   ?? '',
            $qr['phone']   ?? '',
            $qr['address'] ?? '',
            $qr['city']    ?? 'Vancouver',
        ]);

        $companyId = (int) $this->db->lastInsertId();

        $this->db->prepare(
            "UPDATE quote_requests SET company_id = ? WHERE id = ?"
        )->execute([$companyId, $quoteRequestId]);

        return $companyId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Notes
    // ─────────────────────────────────────────────────────────────────────────

    public function addNote(int $contactId, string $content, string $noteType, int $userId): void
    {
        $validTypes = ['general', 'customer_request', 'issue', 'follow_up', 'internal'];
        if (!in_array($noteType, $validTypes, true)) {
            $noteType = 'general';
        }

        $this->db->prepare(
            "INSERT INTO client_notes (contact_id, note_type, content, created_by, created_at)
             VALUES (?, ?, ?, ?, NOW())"
        )->execute([$contactId, $noteType, $content, $userId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check for columns that were added in later migrations
     * and add them if missing. MUST run outside any transaction.
     */
    private function ensureContactColumns(): void
    {
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM contacts")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('mobile', $cols, true)) {
                $this->db->exec("ALTER TABLE contacts ADD COLUMN mobile VARCHAR(50) AFTER phone");
            }
            if (!in_array('prospect_status', $cols, true)) {
                $this->db->exec(
                    "ALTER TABLE contacts ADD COLUMN prospect_status
                     ENUM('prospect','client','inactive') DEFAULT 'prospect' AFTER is_active"
                );
            }
        } catch (\Throwable) {
            // Non-fatal — columns may already exist or DB may reject SHOW COLUMNS
        }
    }

    /**
     * Attach or link a property when creating a new contact.
     * Called inside an active transaction.
     */
    private function attachPropertyToNewContact(
        int     $contactId,
        string  $contactName,
        string  $address,
        string  $city,
        string  $postalCode,
        ?float  $lat,
        ?float  $lng,
    ): void {
        $check = $this->db->prepare("SELECT id FROM properties WHERE address = ? LIMIT 1");
        $check->execute([$address]);
        $existing = $check->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            $this->db->prepare(
                "UPDATE properties SET site_contact_id = COALESCE(site_contact_id, ?) WHERE id = ?"
            )->execute([$contactId, $existing['id']]);
        } else {
            $this->db->prepare("
                INSERT INTO properties
                    (property_name, address, city, province, postal_code,
                     latitude, longitude, site_contact_id, status)
                VALUES (?, ?, ?, 'BC', ?, ?, ?, ?, 'active')
            ")->execute([
                $contactName . ' Property',
                $address,
                $city,
                $postalCode,
                $lat,
                $lng,
                $contactId,
            ]);
        }
    }
}
