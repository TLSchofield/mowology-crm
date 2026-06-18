<?php
/**
 * QuoteService — Business logic for the quotes module.
 *
 * Extracted from the monolithic create.php / view.php pages.
 * Pages should call these methods instead of issuing raw SQL directly.
 *
 * Responsibilities:
 *  - Fetch a quote (with full contact resolution join)
 *  - Create / update a quote + line items (transactional)
 *  - Manage access tokens (generate / refresh)
 *  - Apply status transitions (sent, accepted, declined, expired)
 *  - Resolve the customer contact chain for email/SMS delivery
 *
 * NOT responsible for:
 *  - Email/SMS delivery   → use messaging.php helpers (sendEmail, sendSms)
 *  - PDF generation       → use PdfGenerator
 *  - Attribution tracking → AttributionService
 *  - Activity logging     → logActivityExtended()
 *
 * All callers must requireLogin() + verifyCSRFToken() before calling mutations.
 */
declare(strict_types=1);

class QuoteService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // FETCHING
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Fetch a quote with all related contact/property/company data.
     *
     * Contact resolution priority:
     *   1. quote_requests contact (the person who submitted the online form)
     *   2. Company primary contact (from company_properties junction)
     *   3. Property site contact
     *   4. Company billing fields
     *
     * Returns null if the quote does not exist.
     */
    public function getWithContact(int $quoteId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                q.*,
                p.address       AS property_address,
                p.city          AS property_city,
                p.postal_code   AS property_postal,
                p.property_type,
                p.site_contact_id,
                NULLIF(p.billing_entity_name, '') AS property_billing_entity,
                c.company_name,
                c.billing_email,
                c.billing_phone,
                ct.id           AS contact_id,
                ct.first_name   AS contact_first,
                ct.last_name    AS contact_last,
                ct.email        AS contact_email,
                ct.phone        AS contact_phone,
                qr.contact_id   AS qr_contact_id,
                qrc.first_name  AS qr_first_name,
                qrc.last_name   AS qr_last_name,
                qrc.email       AS qr_email,
                qrc.phone       AS qr_phone,
                pc.id           AS prop_contact_id,
                pc.first_name   AS prop_contact_first,
                pc.last_name    AS prop_contact_last,
                pc.email        AS prop_contact_email,
                pc.phone        AS prop_contact_phone,
                u.full_name     AS created_by_name
            FROM quotes q
            LEFT JOIN properties p           ON q.property_id = p.id
            LEFT JOIN company_properties cp  ON p.id = cp.property_id AND cp.is_primary = 1
            LEFT JOIN companies c            ON q.company_id = c.id
            LEFT JOIN contacts ct            ON c.primary_contact_id = ct.id
            LEFT JOIN quote_requests qr      ON qr.quote_id = q.id
            LEFT JOIN contacts qrc           ON qr.contact_id = qrc.id
            LEFT JOIN contacts pc            ON p.site_contact_id = pc.id
            LEFT JOIN users u               ON q.created_by = u.id
            WHERE q.id = ?
        ");
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        return $quote ?: null;
    }

    /**
     * Fetch line items for a quote, ordered by sort_order.
     */
    public function getLineItems(int $quoteId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM quote_line_items WHERE quote_id = ? ORDER BY sort_order"
        );
        $stmt->execute([$quoteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch quote + line items for the edit form.
     * Returns ['quote' => array|null, 'line_items' => array].
     */
    public function getForEdit(int $quoteId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM quotes WHERE id = ?");
        $stmt->execute([$quoteId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $lineItems = $quote ? $this->getLineItems($quoteId) : [];

        return ['quote' => $quote, 'line_items' => $lineItems];
    }

    /**
     * Fetch quote request data for form prefill (when creating from an online request).
     */
    public function getQuoteRequest(int $quoteRequestId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT qr.*, c.first_name, c.last_name, p.id AS property_id, p.address, p.city
            FROM quote_requests qr
            LEFT JOIN contacts c ON qr.contact_id = c.id
            LEFT JOIN properties p ON qr.property_id = p.id
            WHERE qr.id = ?
        ");
        $stmt->execute([$quoteRequestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Fetch a contact and their active properties for form prefill.
     * Returns ['contact' => array|null, 'properties' => array].
     */
    public function getContactPrefill(int $contactId): array
    {
        $cStmt = $this->db->prepare("SELECT id, first_name, last_name FROM contacts WHERE id = ?");
        $cStmt->execute([$contactId]);
        $contact = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $properties = [];
        if ($contact) {
            $pStmt = $this->db->prepare("
                SELECT id, address, city, province, postal_code, property_type, status, latitude, longitude
                FROM properties
                WHERE site_contact_id = ? AND status = 'active'
                ORDER BY address
            ");
            $pStmt->execute([$contactId]);
            $properties = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return ['contact' => $contact, 'properties' => $properties];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MUTATIONS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Create a new quote with line items.
     *
     * @param array    $data          Validated form fields (property_id, title, service_type, etc.)
     * @param array    $lineItems     Decoded line items array
     * @param int      $userId        Created-by user
     * @param int|null $quoteRequestId  If creating from a quote request, link it on success
     * @return int     New quote ID
     * @throws PDOException|\RuntimeException
     */
    public function create(array $data, array $lineItems, int $userId, ?int $quoteRequestId = null): int
    {
        $propertyId = (int)$data['property_id'];
        $totals     = $this->calculateTotals($lineItems);

        [$companyId, $siteContactId] = $this->lookupPropertyRelations($propertyId);

        $quoteNumber = $this->generateQuoteNumber();
        $accessToken = $this->generateAccessToken();

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO quotes (
                    quote_number, property_id, company_id, contact_id, title, service_type,
                    amount, subtotal, tax_rate, tax_amount, valid_until, terms,
                    notes_customer, notes_internal, description,
                    access_token, token_expires_at, created_by, status, is_contract
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, DATE_ADD(NOW(), INTERVAL 30 DAY), ?, 'draft', ?
                )
            ");
            $stmt->execute([
                $quoteNumber, $propertyId, $companyId, $siteContactId,
                $data['title'], $data['service_type'] ?? 'landscaping',
                $totals['total'], $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                $data['valid_until'] ?: null, $data['terms'] ?? '',
                $data['notes_customer'] ?? '', $data['notes_internal'] ?? '', $data['description'] ?? '',
                $accessToken, $userId, (int)($data['is_contract'] ?? 0),
            ]);

            $quoteId = (int)$this->db->lastInsertId();

            $this->insertLineItems($quoteId, $lineItems);

            if ($quoteRequestId) {
                $this->db->prepare(
                    "UPDATE quote_requests SET quote_id = ?, status = 'quoted' WHERE id = ?"
                )->execute([$quoteId, $quoteRequestId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $quoteId;
    }

    /**
     * Update an existing quote + replace its line items.
     *
     * @param int   $quoteId
     * @param array $data      Validated form fields
     * @param array $lineItems New line items (replaces existing)
     * @throws PDOException|\RuntimeException
     */
    public function update(int $quoteId, array $data, array $lineItems): void
    {
        $propertyId = (int)$data['property_id'];
        $totals     = $this->calculateTotals($lineItems);

        [$companyId] = $this->lookupPropertyRelations($propertyId);

        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE quotes SET
                    property_id     = ?,
                    company_id      = ?,
                    title           = ?,
                    service_type    = ?,
                    amount          = ?,
                    subtotal        = ?,
                    tax_rate        = ?,
                    tax_amount      = ?,
                    valid_until     = ?,
                    terms           = ?,
                    notes_customer  = ?,
                    notes_internal  = ?,
                    description     = ?,
                    is_contract     = ?,
                    pdf_path        = NULL,
                    pdf_version     = 0,
                    pdf_generated_at = NULL,
                    updated_at      = NOW()
                WHERE id = ?
            ")->execute([
                $propertyId, $companyId,
                $data['title'], $data['service_type'] ?? 'landscaping',
                $totals['total'], $totals['subtotal'], $totals['tax_rate'], $totals['tax_amount'],
                $data['valid_until'] ?: null, $data['terms'] ?? '',
                $data['notes_customer'] ?? '', $data['notes_internal'] ?? '', $data['description'] ?? '',
                (int)($data['is_contract'] ?? 0),
                $quoteId,
            ]);

            $this->db->prepare("DELETE FROM quote_line_items WHERE quote_id = ?")->execute([$quoteId]);
            $this->insertLineItems($quoteId, $lineItems);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ACCESS TOKEN MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Ensure the quote has a valid access token with a fresh 30-day window.
     *
     * Creates one if absent; refreshes expiry on every call (so resent links
     * always get a full new window). Returns the token string.
     */
    public function ensureAccessToken(int $quoteId, array &$quote): string
    {
        if (empty($quote['access_token'])) {
            $token = $this->generateAccessToken();
            $this->db->prepare(
                "UPDATE quotes SET access_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?"
            )->execute([$token, $quoteId]);
            $quote['access_token'] = $token;
        } else {
            $this->db->prepare(
                "UPDATE quotes SET token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?"
            )->execute([$quoteId]);
            $token = $quote['access_token'];
        }
        return $token;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // STATUS TRANSITIONS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Mark a quote as sent. Updates status, sent_at, and sent_via.
     */
    public function markSent(int $quoteId, string $sentVia): void
    {
        $this->db->prepare(
            "UPDATE quotes SET status = 'sent', sent_at = NOW(), sent_via = ? WHERE id = ?"
        )->execute([$sentVia, $quoteId]);
    }

    /**
     * Record a verbal approval (admin acting on behalf of customer).
     */
    public function approveVerbal(int $quoteId, string $approverName, string $adminName, string $remoteAddr): void
    {
        $this->db->prepare("
            UPDATE quotes SET
                status            = 'accepted',
                accepted_at       = NOW(),
                accepted_by_name  = ?,
                accepted_ip_address = ?
            WHERE id = ?
        ")->execute([
            "{$approverName} (verbal approval by {$adminName})",
            $remoteAddr,
            $quoteId,
        ]);
    }

    /**
     * Generic status update (decline, expire, revert to draft, etc.)
     */
    public function updateStatus(int $quoteId, string $status): void
    {
        $this->db->prepare("UPDATE quotes SET status = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$status, $quoteId]);
    }

    /**
     * Increment the follow-up counter (gracefully ignores pre-migration tables).
     */
    public function recordFollowUp(int $quoteId): void
    {
        try {
            $this->db->prepare(
                "UPDATE quotes SET follow_up_sent_at = NOW(), follow_up_count = COALESCE(follow_up_count, 0) + 1 WHERE id = ?"
            )->execute([$quoteId]);
        } catch (\PDOException $e) {
            error_log('[QuoteService] follow_up column update skipped (pre-migration?): ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CONTACT RESOLUTION
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Resolve the customer contact chain from a quote row (as returned by getWithContact()).
     *
     * Priority order:
     *   1. quote_request contact  (qr_* fields)
     *   2. Company primary contact (contact_* fields)
     *   3. Property site contact  (prop_contact_* fields)
     *   4. Company billing fields  (billing_*)
     *
     * Returns:
     *   email       string|null
     *   phone       string|null
     *   name        string       (full name or company name or 'Valued Customer')
     *   first_name  string
     *   contact_id  int|null     (for SMS consent check)
     */
    public function resolveContact(array $quote): array
    {
        $email = $quote['qr_email']
              ?? $quote['contact_email']
              ?? $quote['prop_contact_email']
              ?? $quote['billing_email']
              ?? null;

        $phone = $quote['qr_phone']
              ?? $quote['contact_phone']
              ?? $quote['prop_contact_phone']
              ?? $quote['billing_phone']
              ?? null;

        $firstName = $quote['qr_first_name']
                  ?? $quote['contact_first']
                  ?? $quote['prop_contact_first']
                  ?? '';
        $lastName  = $quote['qr_last_name']
                  ?? $quote['contact_last']
                  ?? $quote['prop_contact_last']
                  ?? '';

        $name = trim($firstName . ' ' . $lastName)
             ?: ($quote['company_name'] ?? 'Valued Customer');

        // The contact ID used for SMS consent lookup
        $contactId = (int)($quote['qr_contact_id'] ?? $quote['contact_id'] ?? $quote['prop_contact_id'] ?? 0) ?: null;

        return [
            'email'      => $email ?: null,
            'phone'      => $phone ?: null,
            'name'       => $name,
            'first_name' => $firstName ?: explode(' ', $name)[0],
            'contact_id' => $contactId,
        ];
    }

    /**
     * Resolve the display name shown on the quote ("who is this for").
     *
     * Precedence:
     *   1. Strata / managed property — the property billing entity (e.g.
     *      "Strata Plan VR15/40") IS the customer, so it's shown ALONE and ahead
     *      of any on-site individual. No "C/O company" suffix.
     *   2. Individual person — quote-request contact → company primary contact
     *      → property site contact (residential quotes resolve here).
     *   3. Company name (commercial, no strata entity).
     *   4. Nothing resolvable → 'N/A'.
     *
     * Requires the quote row from getWithContact() (provides property_billing_entity).
     */
    public function resolveDisplayName(array $quote): string
    {
        $entity = trim((string)($quote['property_billing_entity'] ?? ''));
        if ($entity !== '') {
            return $entity;
        }

        $person = trim(($quote['qr_first_name'] ?? '') . ' ' . ($quote['qr_last_name'] ?? ''))
            ?: trim(($quote['contact_first'] ?? '') . ' ' . ($quote['contact_last'] ?? ''))
            ?: trim(($quote['prop_contact_first'] ?? '') . ' ' . ($quote['prop_contact_last'] ?? ''));
        if ($person !== '') {
            return $person;
        }

        return trim((string)($quote['company_name'] ?? '')) ?: 'N/A';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TOTALS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Calculate subtotal, tax, and total from an array of line items.
     *
     * Mirrors calculateQuoteTotals() in functions.php but lives here so
     * it's testable in isolation and available to the service layer.
     *
     * @param array $lineItems  Each item must have 'line_total', optionally 'tax_rate'.
     * @return array [subtotal, tax_rate, tax_amount, total]
     */
    public function calculateTotals(array $lineItems): array
    {
        // If the legacy helper is loaded, delegate to it for consistency
        if (function_exists('calculateQuoteTotals')) {
            return calculateQuoteTotals($lineItems);
        }

        $subtotal = 0.0;
        $taxRate  = 0.0;
        foreach ($lineItems as $item) {
            $subtotal += (float)($item['line_total'] ?? 0);
            if (!empty($item['tax_rate'])) {
                $taxRate = max($taxRate, (float)$item['tax_rate']);
            }
        }
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total     = round($subtotal + $taxAmount, 2);

        return [
            'subtotal'   => round($subtotal, 2),
            'tax_rate'   => $taxRate,
            'tax_amount' => $taxAmount,
            'total'      => $total,
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Insert line items for a quote. Replaces existing only if called after a DELETE.
     */
    private function insertLineItems(int $quoteId, array $lineItems): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO quote_line_items (
                quote_id, product_id, pricing_rule_id, measurement_group_key,
                service_type, description, quantity, unit_type,
                unit_price, line_total, sort_order, is_optional,
                units_used, price_per_unit, minimum_applied, included_units,
                pricing_snapshot, bundle_id, is_upsell, section_name
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($lineItems as $i => $item) {
            $sectionName = isset($item['section_name']) && trim($item['section_name']) !== ''
                ? trim($item['section_name'])
                : null;

            $stmt->execute([
                $quoteId,
                !empty($item['product_id'])       ? (int)$item['product_id']      : null,
                !empty($item['pricing_rule_id'])   ? (int)$item['pricing_rule_id'] : null,
                $item['measurement_group_key']     ?? null,
                $item['service_type']              ?? 'Service',
                $item['description']               ?? '',
                (float)($item['quantity']          ?? 1),
                $item['unit_type']                 ?? 'each',
                (float)($item['unit_price']        ?? 0),
                (float)($item['line_total']        ?? 0),
                $i,
                (int)($item['is_optional']         ?? 0),
                isset($item['units_used'])         ? (float)$item['units_used']      : null,
                isset($item['price_per_unit'])     ? (float)$item['price_per_unit']  : null,
                (int)($item['minimum_applied']     ?? 0),
                isset($item['included_units'])     ? (float)$item['included_units']  : null,
                $item['pricing_snapshot']          ?? null,
                !empty($item['bundle_id'])         ? (int)$item['bundle_id']         : null,
                (int)($item['is_upsell']           ?? 0),
                $sectionName,
            ]);
        }
    }

    /**
     * Look up company_id and site_contact_id for a property.
     * Returns [company_id|null, site_contact_id|null].
     */
    private function lookupPropertyRelations(int $propertyId): array
    {
        $stmt = $this->db->prepare("
            SELECT c.id AS company_id, p.site_contact_id
            FROM properties p
            LEFT JOIN company_properties cp ON p.id = cp.property_id AND cp.is_primary = 1
            LEFT JOIN companies c           ON cp.company_id = c.id
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [$row['company_id'] ?? null, $row['site_contact_id'] ?? null];
    }

    /**
     * Generate a unique quote number (QUO-YYYY-NNNN).
     * Delegates to the legacy helper if available.
     */
    private function generateQuoteNumber(): string
    {
        if (function_exists('generateQuoteNumber')) {
            return generateQuoteNumber();
        }

        $year = date('Y');
        $stmt = $this->db->query(
            "SELECT MAX(CAST(SUBSTRING_INDEX(quote_number, '-', -1) AS UNSIGNED)) AS max_n FROM quotes WHERE quote_number LIKE 'QUO-{$year}-%'"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $n   = ((int)($row['max_n'] ?? 0)) + 1;
        return sprintf('QUO-%s-%04d', $year, $n);
    }

    /**
     * Generate a cryptographically secure access token.
     * Delegates to the legacy helper if available.
     */
    private function generateAccessToken(): string
    {
        if (function_exists('generateAccessToken')) {
            return generateAccessToken();
        }
        return bin2hex(random_bytes(32));
    }
}
