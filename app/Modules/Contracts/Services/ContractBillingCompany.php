<?php
declare(strict_types=1);

/**
 * Which company a contract invoice is billed to (invoices.company_id; InvoiceRouting::stampClientId
 * then derives client_id from it).
 *
 * Order: the property's explicit billing company, then the managing firm (properties.property_manager_id,
 * a companies.id), then the company the signatory is primary/billing contact of. The firm must come
 * BEFORE the signatory's company and must not be skipped: the cron already emailed the firm
 * (InvoiceRouting::managedFirmRecipients) but left company_id NULL for Laburnum Heights (2026-11-24),
 * so stampClientId fell back to the signatory's own client and the invoices left the firm's page.
 */
class ContractBillingCompany
{
    /** @return int|null company id, or null when none resolves */
    public static function resolve($billingCompanyId, $propertyManagerId, $contactCompanyId): ?int
    {
        foreach ([$billingCompanyId, $propertyManagerId, $contactCompanyId] as $id) {
            if ((int)$id > 0) {
                return (int)$id;
            }
        }
        return null;
    }
}
