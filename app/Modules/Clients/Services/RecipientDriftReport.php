<?php
declare(strict_types=1);

/**
 * RecipientDriftReport — read-only count of where the customer links disagree (2026-11-24).
 *
 * "Who gets billed" is read from several legacy columns (site_contact_id, client_id, property_manager_id,
 * billing_company_id) plus the client roster. The plan "one entry point for invoice recipients" keeps them
 * as they are; this report says how far they have drifted, so the bigger clients-only account migration only
 * starts once every number here is zero. It NEVER writes.
 *
 * Each check is its own query, so one missing column on an older database loses one line, not the report.
 */
class RecipientDriftReport
{
    /** @return array<string,array{label:string,count:int,sample:array<int,int>}|array{label:string,error:string}> */
    public static function run(PDO $db, int $sampleSize = 5): array
    {
        $checks = [
            'orphan_properties'    => ['Properties with no client_id',
                "SELECT id FROM properties WHERE client_id IS NULL"],
            'orphan_invoices'      => ['Invoices with no client_id',
                "SELECT id FROM invoices WHERE client_id IS NULL"],
            'orphan_quotes'        => ['Quotes with no client_id',
                "SELECT id FROM quotes WHERE client_id IS NULL"],
            'orphan_job_plans'     => ['Job plans with no client_id',
                "SELECT id FROM job_plans WHERE client_id IS NULL"],
            'site_contact_off_client' => ['Properties whose billing person (site_contact_id) is not one of their client\'s people',
                "SELECT p.id FROM properties p
                 JOIN clients cl ON cl.id = p.client_id
                 WHERE p.site_contact_id IS NOT NULL
                   AND COALESCE(cl.legacy_contact_id, 0) <> p.site_contact_id
                   AND NOT EXISTS (SELECT 1 FROM client_contacts cc WHERE cc.client_id = p.client_id AND cc.contact_id = p.site_contact_id)"],
            'manager_not_on_client' => ['Properties with a property manager but a client not managed by that firm',
                "SELECT p.id FROM properties p
                 JOIN clients cl ON cl.id = p.client_id
                 JOIN clients mg ON mg.legacy_company_id = p.property_manager_id
                 WHERE p.property_manager_id IS NOT NULL
                   AND COALESCE(cl.managed_by_client_id, 0) <> mg.id
                   AND cl.id <> mg.id"],
            'billing_company_off_client' => ['Properties whose billing_company_id maps to a different client than client_id',
                "SELECT p.id FROM properties p
                 JOIN clients bc ON bc.legacy_company_id = p.billing_company_id
                 WHERE p.billing_company_id IS NOT NULL AND p.client_id IS NOT NULL AND bc.id <> p.client_id
                   AND COALESCE((SELECT managed_by_client_id FROM clients WHERE id = p.client_id), 0) <> bc.id"],
            'invoice_company_off_client' => ['Invoices whose company maps to a different client than client_id',
                "SELECT i.id FROM invoices i
                 JOIN clients cc ON cc.legacy_company_id = i.company_id
                 WHERE i.company_id IS NOT NULL AND i.client_id IS NOT NULL AND cc.id <> i.client_id"],
            'client_on_inactive_contact' => ['Clients whose identity contact was merged away or deactivated',
                "SELECT cl.id FROM clients cl JOIN contacts c ON c.id = cl.legacy_contact_id WHERE c.is_active = 0"],
            'recipient_on_inactive_contact' => ['Client recipients (client_contacts) that are inactive contacts',
                "SELECT cc.client_id FROM client_contacts cc JOIN contacts c ON c.id = cc.contact_id WHERE c.is_active = 0"],
        ];
        $out = [];
        foreach ($checks as $key => [$label, $sql]) {
            try {
                $ids = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
                $out[$key] = ['label' => $label, 'count' => count($ids), 'sample' => array_map('intval', array_slice($ids, 0, $sampleSize))];
            } catch (Throwable $e) {
                $out[$key] = ['label' => $label, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    /** One line for cron_runs: nonzero checks with counts and a few ids, zero when clean. */
    public static function summary(array $report): string
    {
        $parts = [];
        $total = 0;
        foreach ($report as $key => $r) {
            if (isset($r['error'])) { $parts[] = $key . ': ERROR'; continue; }
            if ($r['count'] > 0) {
                $total += $r['count'];
                $parts[] = $key . '=' . $r['count'] . ($r['sample'] ? ' [' . implode(',', $r['sample']) . ']' : '');
            }
        }
        return $parts ? 'Drift ' . $total . ': ' . implode('; ', $parts) : 'Drift 0 — every link agrees';
    }
}
