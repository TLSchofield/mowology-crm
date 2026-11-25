<?php
/**
 * FROZEN copy of the invoice-recipient cascade exactly as it stood inline in
 * app/Modules/Invoices/Api/api-get-recipients.php on 2026-11-24 (before it moved
 * into InvoiceRouting::resolveInvoiceRecipients()).
 *
 * DO NOT EDIT and DO NOT call from product code. It exists only so the characterisation
 * test and scripts/recipient-parity.php can prove the extraction changed nothing.
 * Delete it once the single entry point has run clean in production for a while.
 *
 * Only differences from the original: the PDO is passed in, and the client_contacts
 * PDOException (which the API turned into a 500) is rethrown as-is.
 */
declare(strict_types=1);

function legacyRecipientCascade(PDO $db, int $propertyId, int $clientId, int $companyId, int $contactId): array
{
    $recipients = [];

    if ($clientId) {
        $ccStmt = $db->prepare("
            SELECT con.id                                     AS contact_id,
                   CONCAT(con.first_name, ' ', con.last_name) AS contact_name,
                   con.email, con.mobile, con.receive_sms,
                   cc.role         AS contact_role,
                   cc.is_primary, cc.receives_invoices,
                   (cc.client_id <> ?) AS via_manager
            FROM client_contacts cc
            JOIN contacts con ON con.id = cc.contact_id
            WHERE cc.client_id = ?
               OR cc.client_id = (SELECT managed_by_client_id FROM clients WHERE id = ?)
            ORDER BY via_manager DESC, cc.receives_invoices DESC, cc.is_primary DESC, con.first_name
        ");
        $ccStmt->execute([$clientId, $clientId, $clientId]);
        $seenContact = [];
        foreach ($ccStmt->fetchAll(PDO::FETCH_ASSOC) as $cc) {
            if (empty($cc['email'])) {
                continue;
            }
            if (isset($seenContact[(int)$cc['contact_id']])) {
                continue;
            }
            $seenContact[(int)$cc['contact_id']] = true;
            $recipients[] = [
                'contact_id'    => (int)$cc['contact_id'],
                'contact_name'  => $cc['contact_name'] . (!empty($cc['via_manager']) ? ' (Manager)' : ''),
                'contact_role'  => $cc['contact_role'] ?: 'primary_recipient',
                'email_address' => $cc['email'],
                'receive_sms'   => (bool)$cc['receive_sms'],
                'phone'         => $cc['mobile'] ?? null,
                'preselect'     => (bool)$cc['receives_invoices'],
            ];
        }
    }

    if ($clientId && !empty($recipients)) {
        $lc = $db->prepare("SELECT COALESCE(m.legacy_company_id, cl.legacy_company_id)
                            FROM clients cl LEFT JOIN clients m ON m.id = cl.managed_by_client_id
                            WHERE cl.id = ?");
        $lc->execute([$clientId]);
        $legacyCompany = (int)$lc->fetchColumn();
        if ($legacyCompany > 0) {
            $routed = resolveCompanyBillingRecipient($legacyCompany, $db);
            if ($routed) $recipients = preferRecipients($routed, $recipients);
        }
    }

    if (empty($recipients) && $companyId && $propertyId) {
        $recipients = determineInvoiceRecipients($propertyId, $companyId, $db);
    }

    if (empty($recipients) && $propertyId) {
        $fallbackStmt = $db->prepare("
            SELECT con.id as contact_id,
                   CONCAT(con.first_name, ' ', con.last_name) as contact_name,
                   con.email,
                   con.mobile,
                   con.receive_sms
            FROM properties p
            JOIN contacts con ON con.id = COALESCE(p.site_contact_id, ?)
            WHERE p.id = ?
            LIMIT 1
        ");
        $fallbackStmt->execute([$contactId ?: 0, $propertyId]);
        $contact = $fallbackStmt->fetch(PDO::FETCH_ASSOC);

        if ($contact && $contact['email']) {
            $recipients = [[
                'contact_id'   => $contact['contact_id'],
                'contact_name' => $contact['contact_name'],
                'contact_role' => 'primary_recipient',
                'email_address'=> $contact['email'],
                'receive_sms'  => (bool)$contact['receive_sms'],
                'phone'        => $contact['mobile'] ?? null,
            ]];
        }
    }

    if ($propertyId) {
        $pmRecipients = resolveManagementBillingRecipient($propertyId, $db);
        if (!empty($pmRecipients)) {
            $pmContactIds = array_column($pmRecipients, 'contact_id');
            $ccRecipients = [];
            foreach ($recipients as $r) {
                if (in_array((int)($r['contact_id'] ?? 0), array_map('intval', $pmContactIds), true)) {
                    continue;
                }
                $r['preselect'] = false;
                $ccRecipients[] = $r;
            }
            $recipients = array_merge($pmRecipients, $ccRecipients);
        }
    }

    return $recipients;
}
