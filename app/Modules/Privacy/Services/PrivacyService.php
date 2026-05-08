<?php
declare(strict_types=1);

/**
 * PrivacyService
 *
 * PIPEDA compliance business logic:
 *   - generateDataExport()       — collect all PII for a contact (DSAR right of access)
 *   - anonymizeContact()         — PIPEDA right to erasure (with legal-hold carve-outs)
 *   - purgeExpiredLocationData() — automated data minimisation per retention settings
 *
 * Financial records (quotes, invoices, stripe_payments, consent_log) are intentionally
 * excluded from erasure. Canadian tax law (ITA s.230) requires 7-year retention of
 * financial records. PIPEDA's right to erasure has an explicit legal-hold exception.
 *
 * No namespace — loaded via require_once (consistent with other services).
 */
class PrivacyService
{
    public function __construct(private PDO $db) {}

    // ─────────────────────────────────────────────────────────────────────────
    // DSAR — Right of Access (generate data export)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Collect all personal data held for a contact.
     * Returns a structured array suitable for JSON encoding and delivery to the subject.
     */
    public function generateDataExport(int $contactId): array
    {
        $export = [
            'generated_at'    => date('c'),
            'contact_id'      => $contactId,
            'contact'         => null,
            'properties'      => [],
            'quotes'          => [],
            'invoices'        => [],
            'job_plans'       => [],
            'consent_log'     => [],
            'stripe_payments' => [],
            'visit_photos'    => [],
            'privacy_requests' => [],
        ];

        // Contact record
        $stmt = $this->db->prepare("
            SELECT id, first_name, last_name, email, phone, mobile,
                   receive_sms, receive_marketing,
                   consent_sms, consent_marketing_email,
                   consent_timestamp, consent_source, consent_ip_address,
                   stripe_customer_id, stripe_card_brand, stripe_card_last4,
                   created_at, updated_at
            FROM contacts WHERE id = ?
        ");
        $stmt->execute([$contactId]);
        $export['contact'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$export['contact']) {
            return $export;
        }

        // Properties
        $stmt = $this->db->prepare("
            SELECT id, address, city, province, postal_code, property_type,
                   latitude, longitude, created_at
            FROM properties WHERE site_contact_id = ?
        ");
        $stmt->execute([$contactId]);
        $export['properties'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Quotes (via properties)
        $propIds = array_column($export['properties'], 'id');
        if ($propIds) {
            $in  = implode(',', array_fill(0, count($propIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, quote_number, status, amount, created_at
                FROM quotes WHERE property_id IN ({$in})
            ");
            $stmt->execute($propIds);
            $export['quotes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Invoices (via properties)
        if ($propIds) {
            $in  = implode(',', array_fill(0, count($propIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, invoice_number, status, amount, issued_date, due_date, created_at
                FROM invoices WHERE property_id IN ({$in})
            ");
            $stmt->execute($propIds);
            $export['invoices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Job plans (via properties)
        if ($propIds) {
            $in  = implode(',', array_fill(0, count($propIds), '?'));
            $stmt = $this->db->prepare("
                SELECT id, plan_number, service_type, status, start_date, created_at
                FROM job_plans WHERE property_id IN ({$in})
            ");
            $stmt->execute($propIds);
            $export['job_plans'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Consent log
        $stmt = $this->db->prepare("
            SELECT consent_type, consent_given, consent_text, consent_source,
                   ip_address, created_at
            FROM consent_log WHERE contact_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$contactId]);
        $export['consent_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Stripe payments (invoice IDs → payments) — tokens only, no raw card data
        $invoiceIds = array_column($export['invoices'], 'id');
        if ($invoiceIds) {
            $in  = implode(',', array_fill(0, count($invoiceIds), '?'));
            $stmt = $this->db->prepare("
                SELECT payment_intent_id, payment_method_type, amount_cents, currency,
                       status, stripe_customer_id, created_at
                FROM stripe_payments WHERE invoice_id IN ({$in})
            ");
            $stmt->execute($invoiceIds);
            $export['stripe_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Visit photos (filenames only — not file contents)
        $planIds = array_column($export['job_plans'], 'id');
        if ($planIds) {
            $in  = implode(',', array_fill(0, count($planIds), '?'));
            $stmt = $this->db->prepare("
                SELECT vp.id, vp.photo_type, vp.filename, vp.caption, vp.uploaded_at
                FROM visit_photos vp
                JOIN job_visits jv ON jv.id = vp.visit_id
                WHERE jv.plan_id IN ({$in}) AND vp.deleted_at IS NULL
            ");
            $stmt->execute($planIds);
            $export['visit_photos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Prior privacy requests about this contact
        $stmt = $this->db->prepare("
            SELECT request_type, status, requester_name, requester_email,
                   notes, created_at, completed_at
            FROM privacy_requests WHERE contact_id = ? ORDER BY created_at DESC
        ");
        $stmt->execute([$contactId]);
        $export['privacy_requests'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $export;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DSAR — Right to Erasure (anonymise contact)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Anonymise a contact under PIPEDA right to erasure.
     *
     * What IS anonymised: name, email, phone, GPS coordinates on visits.
     * What IS NOT anonymised (legal hold): invoices, quotes, stripe_payments,
     *   consent_log (PIPEDA itself requires keeping consent proof).
     *
     * Photos: soft-deleted in DB, then unlinked from disk (best-effort).
     *
     * @throws InvalidArgumentException if contact not found or already anonymised
     */
    public function anonymizeContact(int $contactId, int $userId, string $reason = ''): void
    {
        $stmt = $this->db->prepare(
            "SELECT id, first_name, email FROM contacts WHERE id = ?"
        );
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            throw new \InvalidArgumentException("Contact {$contactId} not found.");
        }
        if ($contact['first_name'] === '[Anonymized]') {
            throw new \InvalidArgumentException("Contact {$contactId} is already anonymised.");
        }

        // Collect photo filenames before transaction (read-only, safe outside TX)
        $photoFilenames = $this->getContactPhotoFilenames($contactId);

        $this->db->beginTransaction();
        try {
            // 1 — Anonymise contact PII
            $this->db->prepare("
                UPDATE contacts SET
                    first_name = '[Anonymized]',
                    last_name  = '[Anonymized]',
                    email      = CONCAT('anonymized-', id, '@deleted.invalid'),
                    phone      = '',
                    mobile     = '',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$contactId]);

            // 2 — Null out GPS coordinates on job_visits for this contact's plans
            $this->db->prepare("
                UPDATE job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                JOIN properties p  ON p.id = jp.property_id
                SET jv.gps_arrival_lat   = NULL,
                    jv.gps_arrival_lng   = NULL,
                    jv.gps_departure_lat = NULL,
                    jv.gps_departure_lng = NULL
                WHERE p.site_contact_id = ?
            ")->execute([$contactId]);

            // 3 — Soft-delete visit photos
            if ($photoFilenames) {
                $ids = array_column($photoFilenames, 'id');
                $in  = implode(',', array_fill(0, count($ids), '?'));
                $this->db->prepare(
                    "UPDATE visit_photos SET deleted_at = NOW() WHERE id IN ({$in})"
                )->execute($ids);
            }

            // 4 — Log the anonymisation in privacy_requests
            $this->db->prepare("
                INSERT INTO privacy_requests
                    (contact_id, request_type, status, requester_name, requester_email,
                     notes, handled_by, completed_at)
                VALUES (?, 'deletion', 'completed', '[System]', '[System]', ?, ?, NOW())
            ")->execute([$contactId, $reason ?: 'Contact anonymised per PIPEDA request', $userId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 5 — Unlink photo files from disk (best-effort, after commit)
        if (defined('PUBLIC_ROOT') && $photoFilenames) {
            foreach ($photoFilenames as $row) {
                $path = PUBLIC_ROOT . '/uploads/photos/' . $row['filename'];
                if (is_file($path)) {
                    @unlink($path);
                }
                // Remove variants (thumb, grid, view) if they exist
                foreach (['t', 'g', 'v'] as $variant) {
                    $ext      = pathinfo($row['filename'], PATHINFO_EXTENSION);
                    $base     = pathinfo($row['filename'], PATHINFO_FILENAME);
                    $varPath  = PUBLIC_ROOT . '/uploads/photos/' . $variant . '/' . $base . '.webp';
                    if (is_file($varPath)) {
                        @unlink($varPath);
                    }
                }
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Retention Purge
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Delete location data older than the configured retention period.
     * Reads retention days from data_retention_settings.
     * Returns counts of deleted rows per data type.
     */
    public function purgeExpiredLocationData(): array
    {
        $settings = $this->getRetentionSettings();

        $crewDays  = (int) ($settings['crew_location_history']['retention_days'] ?? 90);
        $gpsDays   = (int) ($settings['visit_gps_points']['retention_days']      ?? 180);
        $auditDays = (int) ($settings['visit_audit_log']['retention_days']        ?? 730);

        // crew_location_history
        $stmt = $this->db->prepare(
            "DELETE FROM crew_location_history WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$crewDays]);
        $crewDeleted = $stmt->rowCount();

        // visit_gps_points
        $stmt = $this->db->prepare(
            "DELETE FROM visit_gps_points WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$gpsDays]);
        $gpsDeleted = $stmt->rowCount();

        // visit_audit_log — only purge rows from completed visits older than retention period
        $stmt = $this->db->prepare("
            DELETE val FROM visit_audit_log val
            JOIN job_visits jv ON jv.id = val.visit_id
            WHERE jv.status = 'completed'
              AND val.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$auditDays]);
        $auditDeleted = $stmt->rowCount();

        return [
            'crew_location_deleted'  => $crewDeleted,
            'visit_gps_deleted'      => $gpsDeleted,
            'audit_log_deleted'      => $auditDeleted,
            'purged_at'              => date('c'),
            'retention_days'         => [
                'crew_location_history' => $crewDays,
                'visit_gps_points'      => $gpsDays,
                'visit_audit_log'       => $auditDays,
            ],
        ];
    }

    /**
     * Get all retention settings as [data_type => retention_days].
     */
    public function getRetentionSettings(): array
    {
        $stmt = $this->db->prepare(
            "SELECT data_type, retention_days, description, updated_at FROM data_retention_settings"
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['data_type']] = $row;
        }
        return $result;
    }

    /**
     * Update a single retention setting.
     */
    public function updateRetentionSetting(string $dataType, int $days, int $userId): void
    {
        if ($days < 1 || $days > 3650) {
            throw new \InvalidArgumentException('Retention days must be between 1 and 3650.');
        }
        $this->db->prepare("
            UPDATE data_retention_settings
            SET retention_days = ?, updated_by = ?, updated_at = NOW()
            WHERE data_type = ?
        ")->execute([$days, $userId, $dataType]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Privacy Requests CRUD
    // ─────────────────────────────────────────────────────────────────────────

    public function listRequests(string $status = '', int $limit = 50, int $offset = 0): array
    {
        $where  = $status ? "WHERE pr.status = ?" : '';
        $params = $status ? [$status] : [];
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare("
            SELECT pr.*,
                   CONCAT(c.first_name, ' ', c.last_name) AS contact_name,
                   u.full_name AS handler_name
            FROM privacy_requests pr
            LEFT JOIN contacts c ON c.id = pr.contact_id
            LEFT JOIN users u    ON u.id = pr.handled_by
            {$where}
            ORDER BY pr.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countRequests(string $status = ''): int
    {
        $where  = $status ? "WHERE status = ?" : '';
        $params = $status ? [$status] : [];
        $stmt   = $this->db->prepare("SELECT COUNT(*) FROM privacy_requests {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function createRequest(
        string $type,
        string $name,
        string $email,
        string $notes = '',
        ?int   $contactId = null
    ): int {
        $this->db->prepare("
            INSERT INTO privacy_requests
                (contact_id, request_type, status, requester_name, requester_email, notes)
            VALUES (?, ?, 'pending', ?, ?, ?)
        ")->execute([$contactId, $type, $name, $email, $notes]);
        return (int) $this->db->lastInsertId();
    }

    public function updateRequestStatus(int $id, string $status, int $userId, string $notes = ''): void
    {
        $validStatuses = ['pending', 'in_progress', 'completed', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $completedAt = ($status === 'completed') ? 'NOW()' : 'NULL';
        $this->db->prepare("
            UPDATE privacy_requests
            SET status       = ?,
                handled_by   = ?,
                notes        = CONCAT(COALESCE(notes,''), IF(? != '', CONCAT('\n', ?), '')),
                completed_at = {$completedAt},
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$status, $userId, $notes, $notes, $id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function getContactPhotoFilenames(int $contactId): array
    {
        $stmt = $this->db->prepare("
            SELECT vp.id, vp.filename
            FROM visit_photos vp
            JOIN job_visits jv ON jv.id = vp.visit_id
            JOIN job_plans  jp ON jp.id = jv.plan_id
            JOIN properties p  ON p.id  = jp.property_id
            WHERE p.site_contact_id = ? AND vp.deleted_at IS NULL
        ");
        $stmt->execute([$contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
