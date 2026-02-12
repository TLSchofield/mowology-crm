<?php
/**
 * Invoice Routing Logic
 *
 * Determines who should receive invoices based on property management relationships,
 * company configuration, and routing preferences.
 *
 * Scenarios:
 * - Direct Client: Send to owner company's primary/billing contact
 * - With Manager: Send to manager (primary), CC owner (optional)
 * - Strata: Send to strata manager + property manager + accounting
 *
 * All FREE SMS is sent to all customers who consent (receive_sms = 1)
 *
 * Migrated from: public/crm/includes/invoice-routing.php
 */

declare(strict_types=1);

/**
 * Determine invoice recipients based on property management relationships
 *
 * @param int $propertyId Property ID
 * @param int $companyId Company ID (invoice recipient)
 * @return array Recipients array with contact_id, contact_role, email_address, name
 */
function determineInvoiceRecipients(int $propertyId, int $companyId): array {
    $db = getDB();

    try {
        // 1. Get property with all relationships
        $property = getPropertyWithCompanies($propertyId);
        if (!$property) {
            error_log("Invoice routing: Property not found: {$propertyId}");
            return [];
        }

        // 2. Get company routing preferences
        $stmt = $db->prepare("
            SELECT * FROM companies WHERE id = ?
        ");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            error_log("Invoice routing: Company not found: {$companyId}");
            return [];
        }

        $recipients = [];

        // 3. Determine routing based on scenario
        if ($property['property_manager_id']) {
            // Scenario: Property has a manager
            $recipients = getRecipientListWithManager(
                $property,
                $company,
                $db
            );
        } else if ($company['company_type'] === 'strata') {
            // Scenario: Strata corporation
            $recipients = getRecipientListStrata(
                $property,
                $company,
                $db
            );
        } else {
            // Scenario: Direct client (no manager)
            $recipients = getRecipientListDirect(
                $property,
                $company,
                $db
            );
        }

        // 4. Validate and filter recipients
        $validRecipients = [];
        foreach ($recipients as $recipient) {
            if (validateInvoiceRecipient($recipient['contact_id'], $recipient['email_address'])) {
                $validRecipients[] = $recipient;
            } else {
                error_log("Invoice routing: Invalid recipient - {$recipient['email_address']}");
            }
        }

        if (empty($validRecipients)) {
            error_log("Invoice routing: No valid recipients found for property {$propertyId}, company {$companyId}");
        }

        return $validRecipients;

    } catch (Exception $e) {
        error_log("Invoice routing error: " . $e->getMessage());
        return [];
    }
}


/**
 * Get recipient list for direct client (no property manager)
 *
 * @param array $property Property data
 * @param array $company Company data
 * @param PDO $db Database connection
 * @return array Recipients
 */
function getRecipientListDirect(array $property, array $company, PDO $db): array {
    $recipients = [];

    // Primary recipient: company's primary contact
    if ($company['primary_contact_id']) {
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, receive_sms
            FROM contacts WHERE id = ?
        ");
        $stmt->execute([$company['primary_contact_id']]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact) {
            $recipients[] = [
                'contact_id' => $contact['id'],
                'contact_role' => 'primary_recipient',
                'email_address' => $contact['email'],
                'first_name' => $contact['first_name'],
                'last_name' => $contact['last_name'],
                'receive_sms' => $contact['receive_sms']
            ];
        }
    }

    // Billing contact (if different from primary)
    if ($company['billing_contact_id'] && $company['billing_contact_id'] !== $company['primary_contact_id']) {
        $stmt = $db->prepare("
            SELECT id, first_name, last_name, email, receive_sms
            FROM contacts WHERE id = ?
        ");
        $stmt->execute([$company['billing_contact_id']]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact) {
            $recipients[] = [
                'contact_id' => $contact['id'],
                'contact_role' => 'billing_contact',
                'email_address' => $contact['email'],
                'first_name' => $contact['first_name'],
                'last_name' => $contact['last_name'],
                'receive_sms' => $contact['receive_sms']
            ];
        }
    }

    return $recipients;
}


/**
 * Get recipient list for property with manager
 * Primary: Property manager, CC: Owner
 *
 * @param array $property Property data (with owner, manager relationships)
 * @param array $company Company data
 * @param PDO $db Database connection
 * @return array Recipients
 */
function getRecipientListWithManager(array $property, array $company, PDO $db): array {
    $recipients = [];

    // Primary: Property manager's primary contact
    if ($property['property_manager_id'] && $property['manager']) {
        if ($property['manager']['primary_contact_id']) {
            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, receive_sms
                FROM contacts WHERE id = ?
            ");
            $stmt->execute([$property['manager']['primary_contact_id']]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact) {
                $recipients[] = [
                    'contact_id' => $contact['id'],
                    'contact_role' => 'property_manager',
                    'email_address' => $contact['email'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'receive_sms' => $contact['receive_sms']
                ];
            }
        }
    }

    // CC: Owner's primary contact
    if ($property['owner_company_id'] && $property['owner']) {
        if ($property['owner']['primary_contact_id']) {
            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, receive_sms
                FROM contacts WHERE id = ?
            ");
            $stmt->execute([$property['owner']['primary_contact_id']]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact) {
                $recipients[] = [
                    'contact_id' => $contact['id'],
                    'contact_role' => 'owner_contact',
                    'email_address' => $contact['email'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'receive_sms' => $contact['receive_sms']
                ];
            }
        }
    }

    return $recipients;
}


/**
 * Get recipient list for strata building
 * Primary: Strata manager, Secondary: Property manager, CC: Accounting
 *
 * @param array $property Property data
 * @param array $company Company data
 * @param PDO $db Database connection
 * @return array Recipients
 */
function getRecipientListStrata(array $property, array $company, PDO $db): array {
    $recipients = [];

    // Primary: Strata manager's primary contact
    if ($property['owner_company_id'] && $property['owner']) {
        if ($property['owner']['primary_contact_id']) {
            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, receive_sms
                FROM contacts WHERE id = ?
            ");
            $stmt->execute([$property['owner']['primary_contact_id']]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact) {
                $recipients[] = [
                    'contact_id' => $contact['id'],
                    'contact_role' => 'strata_manager',
                    'email_address' => $contact['email'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'receive_sms' => $contact['receive_sms']
                ];
            }
        }
    }

    // Secondary: Property manager's primary contact
    if ($property['property_manager_id'] && $property['manager']) {
        if ($property['manager']['primary_contact_id']) {
            $stmt = $db->prepare("
                SELECT id, first_name, last_name, email, receive_sms
                FROM contacts WHERE id = ?
            ");
            $stmt->execute([$property['manager']['primary_contact_id']]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact) {
                $recipients[] = [
                    'contact_id' => $contact['id'],
                    'contact_role' => 'property_manager',
                    'email_address' => $contact['email'],
                    'first_name' => $contact['first_name'],
                    'last_name' => $contact['last_name'],
                    'receive_sms' => $contact['receive_sms']
                ];
            }
        }
    }

    return $recipients;
}


/**
 * Get property with all linked companies and contacts
 *
 * @param int $propertyId Property ID
 * @return array|null Property data with owner and manager relationships
 */
function getPropertyWithCompanies(int $propertyId): ?array {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT
            p.*,
            owner.id as owner_company_id,
            owner.company_name as owner_name,
            owner.primary_contact_id as owner_primary_contact_id,
            owner.billing_contact_id as owner_billing_contact_id,
            mgr.id as manager_company_id,
            mgr.company_name as manager_name,
            mgr.primary_contact_id as manager_primary_contact_id,
            mgr.invoice_routing_method,
            site.first_name as site_contact_first,
            site.last_name as site_contact_last,
            site.email as site_contact_email
        FROM properties p
        LEFT JOIN companies owner ON p.owner_company_id = owner.id
        LEFT JOIN companies mgr ON p.property_manager_id = mgr.id
        LEFT JOIN contacts site ON p.site_contact_id = site.id
        WHERE p.id = ?
    ");
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$property) {
        return null;
    }

    // Add structured owner and manager data
    if ($property['owner_company_id']) {
        $property['owner'] = [
            'id' => $property['owner_company_id'],
            'company_name' => $property['owner_name'],
            'primary_contact_id' => $property['owner_primary_contact_id'],
            'billing_contact_id' => $property['owner_billing_contact_id']
        ];
    }

    if ($property['manager_company_id']) {
        $property['manager'] = [
            'id' => $property['manager_company_id'],
            'company_name' => $property['manager_name'],
            'primary_contact_id' => $property['manager_primary_contact_id'],
            'invoice_routing_method' => $property['invoice_routing_method']
        ];
    }

    return $property;
}


/**
 * Validate invoice recipient has email and is active
 *
 * @param int|null $contactId Contact ID
 * @param string $email Email address
 * @return bool True if valid
 */
function validateInvoiceRecipient(?int $contactId, string $email): bool {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if ($contactId) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT is_active FROM contacts WHERE id = ?
        ");
        $stmt->execute([$contactId]);
        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        return $contact && $contact['is_active'];
    }

    return true;
}


/**
 * Log invoice routing decision for audit purposes
 *
 * @param int $invoiceId Invoice ID
 * @param int $propertyId Property ID
 * @param int $companyId Company ID
 * @param array $recipients Recipients array
 * @param string $decisionNotes Notes about routing decision
 * @return bool Success
 */
function logInvoiceRoutingDecision(
    int $invoiceId,
    int $propertyId,
    int $companyId,
    array $recipients,
    string $decisionNotes = ''
): bool {
    try {
        $db = getDB();

        $recipientList = implode(', ', array_map(
            fn($r) => "{$r['first_name']} {$r['last_name']} ({$r['contact_role']})",
            $recipients
        ));

        $details = "Invoice recipients: {$recipientList}";
        if ($decisionNotes) {
            $details .= ". Notes: {$decisionNotes}";
        }

        $stmt = $db->prepare("
            INSERT INTO activity_log (
                user_id, company_id, property_id, invoice_id,
                action, details, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $userId = getCurrentUser()['id'] ?? 0;

        return $stmt->execute([
            $userId,
            $companyId,
            $propertyId,
            $invoiceId,
            'Invoice routing determined',
            $details
        ]);

    } catch (Exception $e) {
        error_log("Log invoice routing error: " . $e->getMessage());
        return false;
    }
}
?>
