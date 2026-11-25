<?php
/**
 * ContactRosterSync — keeps the client roster (clients + client_contacts) complete, nightly.
 *
 * Why (2026-11-07): the roster was filled once in June (migration 1049) and never again — on production none of
 * the 29 contacts added since 7 June had a roster row, 7 companies had no client, and 303 invoices from the last
 * year had no client_id. Contacts are created in 9 places and companies in 4, so hooking each insert would miss
 * the next one; one idempotent sync that runs nightly (and once by hand) cannot. "Who is the contact now"
 * (ContactRoster, plan docs/plans/2026-11-07-contact-left-the-building.md) can only be as good as this list.
 *
 * Same steps as 1049, in portable SQL (MySQL + the SQLite unit tests), every step "only what's missing":
 *   A  an organisation client for every company
 *   B  an individual client for every named contact (merged contacts are left out — 1049 didn't)
 *   C  each individual client linked to its own contact
 *   D  each organisation linked to its primary and billing contacts (routing flags from invoice_routing_method),
 *      and to people whose contacts.company_id is that company (council_member for a strata, else owner;
 *      not invoiced, not notified — membership only)
 *   E  client_id stamped on properties, invoices, quotes and job plans that have none
 * It never changes or removes an existing row: a former contact is marked with ended_on, never deleted.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class ContactRosterSync
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<string,int> rows added / stamped per step */
    public function sync(): array
    {
        $name = $this->fullName();
        $n = [];
        $n['org_clients'] = $this->exec("
            INSERT INTO clients (site_id, client_type, display_name, billing_address, billing_city, billing_province,
                                 billing_postal_code, billing_email, billing_phone, payment_terms, payment_method,
                                 lifecycle_stage, status, legacy_company_id)
            SELECT 1, COALESCE(co.company_type, 'business'), co.company_name, co.billing_address, co.billing_city,
                   co.billing_province, co.billing_postal_code, co.billing_email, co.billing_phone,
                   COALESCE(co.payment_terms, 'Net 30'), COALESCE(co.payment_method, 'invoice'),
                   COALESCE(co.lifecycle_stage, 'prospect'),
                   CASE co.account_status WHEN 'inactive' THEN 'inactive' WHEN 'suspended' THEN 'suspended' ELSE 'active' END,
                   co.id
            FROM companies co
            WHERE TRIM(COALESCE(co.company_name, '')) <> ''
              AND NOT EXISTS (SELECT 1 FROM clients cl WHERE cl.legacy_company_id = co.id)");

        $n['person_clients'] = $this->exec("
            INSERT INTO clients (site_id, client_type, display_name, billing_email, billing_phone, lifecycle_stage,
                                 status, legacy_contact_id)
            SELECT 1, 'individual', {$name}, c.email,
                   COALESCE(NULLIF(c.mobile, ''), c.phone), COALESCE(c.lifecycle_stage, 'lead'),
                   CASE WHEN c.is_active = 1 THEN 'active' ELSE 'inactive' END, c.id
            FROM contacts c
            WHERE c.merged_into_id IS NULL
              AND {$name} <> ''
              AND NOT EXISTS (SELECT 1 FROM clients cl WHERE cl.legacy_contact_id = c.id)");

        $n['own_links'] = $this->link("
            SELECT cl.id, cl.legacy_contact_id, 'owner', 1, 1, 1
            FROM clients cl JOIN contacts c ON c.id = cl.legacy_contact_id
            WHERE c.merged_into_id IS NULL", 'owner');

        $n['primary_links'] = $this->link("
            SELECT cl.id, co.primary_contact_id, 'owner', 1,
                   CASE WHEN co.invoice_routing_method IN ('primary_contact', 'both_contacts') OR co.invoice_routing_method IS NULL THEN 1 ELSE 0 END,
                   1
            FROM clients cl JOIN companies co ON co.id = cl.legacy_company_id
            JOIN contacts c ON c.id = co.primary_contact_id AND c.merged_into_id IS NULL", 'owner');

        $n['billing_links'] = $this->link("
            SELECT cl.id, co.billing_contact_id, 'billing', 0,
                   CASE WHEN co.invoice_routing_method IN ('billing_contact', 'both_contacts') THEN 1 ELSE 0 END, 0
            FROM clients cl JOIN companies co ON co.id = cl.legacy_company_id
            JOIN contacts c ON c.id = co.billing_contact_id AND c.merged_into_id IS NULL
            WHERE co.billing_contact_id <> COALESCE(co.primary_contact_id, 0)", 'billing');

        $n['member_links'] = $this->link("
            SELECT cl.id, c.id, CASE WHEN co.company_type = 'strata' THEN 'council_member' ELSE 'owner' END, 0, 0, 0
            FROM contacts c JOIN companies co ON co.id = c.company_id
            JOIN clients cl ON cl.legacy_company_id = co.id
            WHERE c.merged_into_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM client_contacts x WHERE x.client_id = cl.id AND x.contact_id = c.id)", null);

        $n['properties'] = $this->exec("
            UPDATE properties SET client_id = COALESCE(
                (SELECT id FROM clients WHERE legacy_company_id = properties.company_id),
                (SELECT id FROM clients WHERE legacy_contact_id = properties.site_contact_id))
            WHERE client_id IS NULL
              AND COALESCE((SELECT id FROM clients WHERE legacy_company_id = properties.company_id),
                           (SELECT id FROM clients WHERE legacy_contact_id = properties.site_contact_id)) IS NOT NULL");

        $n['invoices'] = $this->exec("
            UPDATE invoices SET client_id = COALESCE(
                (SELECT id FROM clients WHERE legacy_company_id = invoices.company_id),
                (SELECT id FROM clients WHERE legacy_contact_id = invoices.contact_id),
                (SELECT client_id FROM properties WHERE id = invoices.property_id))
            WHERE client_id IS NULL
              AND COALESCE((SELECT id FROM clients WHERE legacy_company_id = invoices.company_id),
                           (SELECT id FROM clients WHERE legacy_contact_id = invoices.contact_id),
                           (SELECT client_id FROM properties WHERE id = invoices.property_id)) IS NOT NULL");

        foreach (['quotes', 'job_plans'] as $t) {
            $n[$t] = $this->exec("
                UPDATE $t SET client_id = COALESCE(
                    (SELECT id FROM clients WHERE legacy_company_id = $t.company_id),
                    (SELECT client_id FROM properties WHERE id = $t.property_id))
                WHERE client_id IS NULL
                  AND COALESCE((SELECT id FROM clients WHERE legacy_company_id = $t.company_id),
                               (SELECT client_id FROM properties WHERE id = $t.property_id)) IS NOT NULL");
        }
        return $n;
    }

    /** Insert client_contacts rows from $select (client, contact, role, primary, invoices, notifications) that aren't there yet. */
    private function link(string $select, ?string $role): int
    {
        $added = 0;
        $ins = $this->db->prepare("INSERT INTO client_contacts (client_id, contact_id, role, is_primary, receives_invoices, receives_notifications) VALUES (?, ?, ?, ?, ?, ?)");
        $has = $this->db->prepare("SELECT 1 FROM client_contacts WHERE client_id = ? AND contact_id = ? AND role = ?");
        foreach ($this->db->query($select)->fetchAll(PDO::FETCH_NUM) as $r) {
            if (empty($r[0]) || empty($r[1])) continue;
            $has->execute([$r[0], $r[1], $r[2]]);
            if ($has->fetchColumn()) continue;
            $ins->execute($r);
            $added++;
        }
        return $added;
    }

    private function exec(string $sql): int
    {
        return (int)$this->db->exec($sql);
    }

    /** "First Last" in this database's dialect: MySQL CONCAT(), SQLite (unit tests) ||. */
    private function fullName(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')))"
            : "TRIM(COALESCE(c.first_name, '') || ' ' || COALESCE(c.last_name, ''))";
    }
}
