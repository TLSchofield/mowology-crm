<?php
declare(strict_types=1);

/**
 * Business logic for the JSON actions on the Clients AppStack page.
 */
class ClientActionsService
{
    /** @var PDO */
    private $db;

    /** @var ContactService */
    private $contacts;

    /** @var callable|false|null null = use the shared addClientNote() helper when loaded; false = always insert directly (tests) */
    private $noteWriter;

    public function __construct(PDO $db, $noteWriter = null)
    {
        $this->db = $db;
        $this->noteWriter = $noteWriter;
        $this->contacts = new ContactService($db);
    }

    public function convertToProspect(int $requestId): array
    {
        if (!$requestId) {
            return $this->failure(400, 'Invalid request ID');
        }

        try {
            $stmt = $this->db->prepare("
                SELECT qr.*, c.first_name, c.last_name, c.email, p.address, p.city
                FROM quote_requests qr
                LEFT JOIN contacts c ON qr.contact_id = c.id
                LEFT JOIN properties p ON qr.property_id = p.id
                WHERE qr.id = ?
            ");
            $stmt->execute([$requestId]);
            $qr = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$qr) {
                throw new Exception('Quote request not found');
            }

            $companyName = trim(($qr['first_name'] ?? '') . ' ' . ($qr['last_name'] ?? ''));
            if ($companyName === '') {
                $companyName = 'Prospect from ' . $this->formatDate($qr['created_at']);
            }

            $stmt = $this->db->prepare("
                INSERT INTO companies (
                    company_name, company_type, billing_email, billing_address, billing_city,
                    billing_province, account_status, pref_attach_pdf
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $companyName,
                'individual',
                $qr['email'] ?? null,
                $qr['address'] ?? null,
                $qr['city'] ?? 'Vancouver',
                'BC',
                'active',
                1,
            ]);

            $companyId = $this->db->lastInsertId();
            $stmt = $this->db->prepare("UPDATE quote_requests SET company_id = ? WHERE id = ?");
            $stmt->execute([$companyId, $requestId]);

            return $this->success(['company_id' => $companyId]);
        } catch (Exception $e) {
            if (isset($GLOBALS['crm_error_handler'])) {
                $GLOBALS['crm_error_handler']->logError(
                    'Failed to convert quote request to prospect',
                    $e,
                    ['request_id' => $requestId]
                );
            }
            return $this->failure(400, 'Couldn\'t convert this request. Try again.');
        }
    }

    public function addPropertyToContact(
        int $contactId,
        string $address,
        string $city,
        string $postalCode,
        string $propertyName
    ): array {
        $address = trim($address);
        $city = trim($city);
        $postalCode = trim($postalCode);
        $propertyName = trim($propertyName);

        if (!$contactId || $address === '') {
            return $this->failure(400, 'Contact ID and address are required');
        }

        try {
            $checkStmt = $this->db->prepare("SELECT id, site_contact_id FROM properties WHERE address = ? LIMIT 1");
            $checkStmt->execute([$address]);
            $existingProp = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProp) {
                if (empty($existingProp['site_contact_id'])) {
                    $this->db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                        ->execute([$contactId, $existingProp['id']]);
                }
                return $this->success([
                    'property_id' => (int)$existingProp['id'],
                    'linked_existing' => true,
                ]);
            }

            if ($propertyName === '') {
                $contactStmt = $this->db->prepare("SELECT first_name, last_name FROM contacts WHERE id = ?");
                $contactStmt->execute([$contactId]);
                $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
                $propertyName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
            }

            $stmt = $this->db->prepare("
                INSERT INTO properties (property_name, address, city, province, postal_code, site_contact_id, status)
                VALUES (?, ?, ?, 'BC', ?, ?, 'active')
            ");
            $stmt->execute([$propertyName, $address, $city, $postalCode, $contactId]);

            return $this->success([
                'property_id' => (int)$this->db->lastInsertId(),
                'linked_existing' => false,
            ]);
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t add the property. Try again.');
        }
    }

    public function addPropertyToCompany(
        int $companyId,
        string $address,
        string $city,
        string $postalCode,
        string $propertyName,
        ?float $lat,
        ?float $lng
    ): array {
        $address = trim($address);
        $city = trim($city);
        $postalCode = trim($postalCode);
        $propertyName = trim($propertyName);

        if (!$companyId || $address === '') {
            return $this->failure(400, 'Company ID and address are required');
        }

        try {
            $compStmt = $this->db->prepare(
                "SELECT primary_contact_id, billing_contact_id, company_name FROM companies WHERE id = ?"
            );
            $compStmt->execute([$companyId]);
            $company = $compStmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) {
                return $this->failure(404, 'Company not found');
            }

            $contactId = (int)($company['primary_contact_id'] ?? 0) ?: (int)($company['billing_contact_id'] ?? 0);
            if (!$contactId) {
                return $this->failure(400, 'Company has no linked contact. Add a contact first.');
            }

            $checkStmt = $this->db->prepare("SELECT id, site_contact_id FROM properties WHERE address = ? LIMIT 1");
            $checkStmt->execute([$address]);
            $existingProp = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingProp) {
                if (empty($existingProp['site_contact_id'])) {
                    $this->db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                        ->execute([$contactId, $existingProp['id']]);
                }
                return $this->success([
                    'property_id' => (int)$existingProp['id'],
                    'linked_existing' => true,
                ]);
            }

            if ($propertyName === '') {
                $propertyName = trim($company['company_name']);
            }

            $stmt = $this->db->prepare("
                INSERT INTO properties (property_name, address, city, province, postal_code, latitude, longitude, site_contact_id, status)
                VALUES (?, ?, ?, 'BC', ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$propertyName, $address, $city, $postalCode, $lat, $lng, $contactId]);

            return $this->success([
                'property_id' => (int)$this->db->lastInsertId(),
                'linked_existing' => false,
            ]);
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t add the property. Try again.');
        }
    }

    public function linkCompanyToContact(int $contactId, int $companyId): array
    {
        if (!$contactId || !$companyId) {
            return $this->failure(400, 'Invalid contact or company');
        }

        try {
            $this->contacts->linkCompanyToContact($companyId, $contactId);
            return $this->success();
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t link the company. Try again.');
        }
    }

    public function savePropertyCoords(int $propertyId, float $lat, float $lng): array
    {
        if (!$propertyId || !$lat || !$lng) {
            return $this->failure(400, 'Invalid property or coordinates');
        }

        try {
            $this->contacts->updatePropertyCoords($propertyId, $lat, $lng);
            return $this->success();
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t save the location. Try again.');
        }
    }

    public function editProperty(
        int $propertyId,
        string $address,
        string $city,
        string $province,
        string $postalCode,
        string $propertyName
    ): array {
        $address = trim($address);
        $city = trim($city);
        $province = trim($province);
        $postalCode = trim($postalCode);
        $propertyName = trim($propertyName);

        if (!$propertyId || $address === '') {
            return $this->failure(400, 'Property ID and address are required');
        }

        try {
            $this->db->prepare("
                UPDATE properties
                SET address = ?, city = ?, province = ?, postal_code = ?,
                    property_name = NULLIF(?, ''),
                    latitude = NULL, longitude = NULL
                WHERE id = ?
            ")->execute([$address, $city, $province, $postalCode, $propertyName, $propertyId]);

            return $this->success([
                'address' => $address,
                'city' => $city,
                'province' => $province,
            ]);
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t update the property. Try again.');
        }
    }

    public function unlinkProperty(int $propertyId, int $currentContactId, int $newContactId): array
    {
        if (!$propertyId || !$currentContactId) {
            return $this->failure(400, 'Property ID and current contact ID are required');
        }

        try {
            $checkStmt = $this->db->prepare("SELECT id FROM properties WHERE id = ? AND site_contact_id = ?");
            $checkStmt->execute([$propertyId, $currentContactId]);
            if (!$checkStmt->fetch()) {
                return $this->failure(400, 'Property not found or not linked to this contact');
            }

            if ($newContactId > 0) {
                $this->db->prepare("UPDATE properties SET site_contact_id = ? WHERE id = ?")
                    ->execute([$newContactId, $propertyId]);
                return $this->success(['action' => 'reassigned']);
            }

            $this->db->prepare("UPDATE properties SET site_contact_id = NULL WHERE id = ?")
                ->execute([$propertyId]);
            return $this->success(['action' => 'unlinked']);
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t update the property. Try again.');
        }
    }

    public function addClientNote(int $contactId, string $noteType, string $content, int $userId): array
    {
        $noteType = trim($noteType);
        $content = trim($content);

        if (!$contactId || $content === '') {
            return $this->failure(400, 'Contact ID and content are required');
        }

        $validTypes = ['general', 'customer_request', 'issue', 'follow_up', 'internal'];
        if (!in_array($noteType, $validTypes, true)) {
            $noteType = 'general';
        }

        try {
            // The shared helper also writes the activity-log line; the direct insert is the fallback when it is not loaded.
            $writer = $this->noteWriter ?? (function_exists('addClientNote') ? 'addClientNote' : false);
            if ($writer) {
                $writer($contactId, $content, $noteType, $userId);
                return $this->success();
            }
            $stmt = $this->db->prepare(
                'INSERT INTO client_notes (contact_id, note_type, content, created_by, created_at) '
                . 'VALUES (?, ?, ?, ?, NOW())'
            );
            $stmt->execute([$contactId, $noteType, $content, $userId]);
            return $this->success();
        } catch (Exception $e) {
            return $this->failure(500, 'Couldn\'t save the note. Try again.');
        }
    }

    private function success(array $data = []): array
    {
        return ['ok' => true, 'status' => 200, 'data' => $data, 'error' => null];
    }

    private function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $error];
    }

    private function formatDate($date): string
    {
        if (function_exists('formatDate')) {
            return formatDate($date);
        }
        if (empty($date)) {
            return '-';
        }
        return date('M j, Y', strtotime($date));
    }
}
