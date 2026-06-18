<?php
declare(strict_types=1);

/**
 * BillToResolver — Phase 0 of the Client/Account model.
 *
 * The single source of truth for "who is billed for work here, and what does
 * the invoice header say." Today it reads the CURRENT schema (the COALESCE
 * ladder of contacts/companies/properties + properties.billing_entity_name).
 * When the unified `clients` table lands (Phase 1+), ONLY this class changes —
 * every caller keeps the same normalized shape.
 *
 * See docs/architecture/client-account-model.md.
 *
 * No namespace — loaded via bootstrap.php require_once, consistent with the
 * rest of app/Modules/[Module]/Services/.
 *
 * Normalized bill-to shape returned by every resolve* method:
 *   [
 *     'bill_to_name'    => string,        // invoice header, e.g. "Strata Plan VR15/40 C/O FirstService"
 *     'account_type'    => string,        // individual|business|strata|property_manager
 *     'company_id'      => int|null,
 *     'contact_id'      => int|null,
 *     'property_id'     => int|null,
 *     'billing_address' => string,
 *     'billing_city'    => string,
 *     'billing_province'=> string,
 *     'billing_postal'  => string,
 *     'primary_contact' => [ 'name', 'email', 'mobile', 'receive_sms' ],
 *   ]
 */
class BillToResolver
{
    public function __construct(private PDO $db) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC — DB-backed entry points
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the bill-to for a completed (or any) job visit.
     * Mirrors the resolution that currently lives inline in
     * public/crm/invoices/create.php so that logic has one home.
     */
    public function resolveForVisit(int $visitId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jv.scheduled_date,
                   jp.property_id,
                   COALESCE(jp.company_id, cc.id) AS company_id,
                   COALESCE(c.company_name, cc.company_name) AS company_name,
                   COALESCE(c.company_type, cc.company_type) AS company_type,
                   mgr.company_name AS pm_firm_name,
                   NULLIF(p.billing_entity_name, '') AS property_billing_entity,
                   ctr.title AS contract_title,
                   p.address, p.city, p.province, p.postal_code,
                   p.site_contact_id,
                   COALESCE(con.first_name, '') AS contact_first,
                   COALESCE(con.last_name, '')  AS contact_last,
                   con.email  AS contact_email,
                   con.mobile AS contact_mobile,
                   con.receive_sms AS contact_receive_sms
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            LEFT JOIN contracts ctr ON jp.contract_id = ctr.id
            LEFT JOIN companies c   ON jp.company_id = c.id
            LEFT JOIN properties p  ON jp.property_id = p.id
            LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
            LEFT JOIN contacts con  ON p.site_contact_id = con.id
            LEFT JOIN companies cc  ON (cc.primary_contact_id = con.id OR cc.billing_contact_id = con.id)
            WHERE jv.id = ?
        ");
        $stmt->execute([$visitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalize($row) : null;
    }

    /**
     * Resolve the bill-to for a property directly (no visit/plan context).
     */
    public function resolveForProperty(int $propertyId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.id AS property_id,
                   cc.id AS company_id,
                   cc.company_name AS company_name,
                   cc.company_type AS company_type,
                   mgr.company_name AS pm_firm_name,
                   NULLIF(p.billing_entity_name, '') AS property_billing_entity,
                   NULL AS contract_title,
                   p.address, p.city, p.province, p.postal_code,
                   p.site_contact_id,
                   COALESCE(con.first_name, '') AS contact_first,
                   COALESCE(con.last_name, '')  AS contact_last,
                   con.email  AS contact_email,
                   con.mobile AS contact_mobile,
                   con.receive_sms AS contact_receive_sms
            FROM properties p
            LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
            LEFT JOIN contacts con  ON p.site_contact_id = con.id
            LEFT JOIN companies cc  ON (cc.primary_contact_id = con.id OR cc.billing_contact_id = con.id)
            WHERE p.id = ?
        ");
        $stmt->execute([$propertyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalize($row) : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PURE — testable resolution (no DB)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compose the invoice header bill-to name from a resolved row.
     *
     * Precedence:
     *   property billing entity → contract title (when a company/pm firm is present)
     *   → company name → contact name.
     *
     * When both an entity and a management context exist, renders:
     *   "Entity C/O PM Firm"  (PM firm takes precedence over generic company)
     *   "Entity C/O Company"  (fallback when no PM firm)
     *
     * Accepts keys: property_billing_entity, pm_firm_name, company_name,
     *               contract_title, contact_first, contact_last.
     */
    public function composeBillToName(array $row): ?string
    {
        $entity        = $this->nz($row['property_billing_entity'] ?? null);
        $contractTitle = trim((string)($row['contract_title'] ?? ''));
        $pmFirm        = $this->nz($row['pm_firm_name'] ?? null);
        $company       = $this->nz($row['company_name'] ?? null);
        $contactName   = trim(($row['contact_first'] ?? '') . ' ' . ($row['contact_last'] ?? ''));
        $contactName   = $contactName !== '' ? $contactName : null;

        // Contract title only acts as the entity when there IS a management context
        $managedBy = $pmFirm ?: $company;
        $entity    = $entity ?: ($managedBy ? ($contractTitle ?: null) : null);

        if ($entity && $managedBy) {
            return $entity . ' C/O ' . $managedBy;
        }
        if ($entity)  { return $entity; }
        if ($company) { return $company; }
        return $contactName;
    }

    /**
     * Normalize a joined row into the canonical bill-to shape.
     */
    public function normalize(array $row): array
    {
        $contactName = trim(($row['contact_first'] ?? '') . ' ' . ($row['contact_last'] ?? ''));

        return [
            'bill_to_name'     => $this->composeBillToName($row),
            'account_type'     => $this->nz($row['company_type'] ?? null)
                                    ?: ($this->nz($row['company_name'] ?? null) ? 'business' : 'individual'),
            'company_id'       => isset($row['company_id']) ? ($row['company_id'] !== null ? (int)$row['company_id'] : null) : null,
            'contact_id'       => isset($row['site_contact_id']) && $row['site_contact_id'] !== null ? (int)$row['site_contact_id'] : null,
            'property_id'      => isset($row['property_id']) && $row['property_id'] !== null ? (int)$row['property_id'] : null,
            'billing_address'  => (string)($row['address'] ?? ''),
            'billing_city'     => (string)($row['city'] ?? ''),
            'billing_province' => (string)($row['province'] ?? 'BC'),
            'billing_postal'   => (string)($row['postal_code'] ?? ''),
            'primary_contact'  => [
                'name'        => $contactName !== '' ? $contactName : null,
                'email'       => $row['contact_email'] ?? null,
                'mobile'      => $row['contact_mobile'] ?? null,
                'receive_sms' => !empty($row['contact_receive_sms']),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL
    // ─────────────────────────────────────────────────────────────────────────

    /** Null-if-empty: trims a value and returns null when blank. */
    private function nz($v): ?string
    {
        $v = trim((string)($v ?? ''));
        return $v !== '' ? $v : null;
    }
}
