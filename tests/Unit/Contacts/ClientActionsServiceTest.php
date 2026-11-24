<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Contacts/Services/ClientActionsService.php';

class ClientActionsServiceTest extends TestCase
{
    /** @var PDO */
    private $db;

    /** @var ClientActionsService */
    private $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('NOW', function (): string {
            return '2026-11-22 12:00:00';
        });

        $this->db->exec('CREATE TABLE contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            first_name TEXT,
            last_name TEXT,
            email TEXT
        )');
        $this->db->exec('CREATE TABLE companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_name TEXT,
            company_type TEXT,
            primary_contact_id INTEGER,
            billing_contact_id INTEGER,
            billing_email TEXT,
            billing_address TEXT,
            billing_city TEXT,
            billing_province TEXT,
            account_status TEXT,
            pref_attach_pdf INTEGER
        )');
        $this->db->exec('CREATE TABLE properties (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            property_name TEXT,
            address TEXT,
            city TEXT,
            province TEXT,
            postal_code TEXT,
            latitude REAL,
            longitude REAL,
            site_contact_id INTEGER,
            status TEXT
        )');
        $this->db->exec('CREATE TABLE quote_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER,
            property_id INTEGER,
            company_id INTEGER,
            created_at TEXT
        )');
        $this->db->exec('CREATE TABLE client_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER,
            note_type TEXT,
            content TEXT,
            created_by INTEGER,
            created_at TEXT
        )');

        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, email)
            VALUES (1, 'Ada', 'Lovelace', 'ada@example.test'), (2, 'Grace', 'Hopper', 'grace@example.test')");
        $this->service = new ClientActionsService($this->db, false);
    }

    public function testConvertToProspectSuccess(): void
    {
        $this->db->exec("INSERT INTO properties (id, address, city) VALUES (1, '10 Main St', 'Burnaby')");
        $this->db->exec("INSERT INTO quote_requests (id, contact_id, property_id, created_at)
            VALUES (1, 1, 1, '2026-11-20 10:00:00')");

        $result = $this->service->convertToProspect(1);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);
        $this->assertNotEmpty($result['data']['company_id']);
        $this->assertSame('Ada Lovelace', $this->db->query('SELECT company_name FROM companies')->fetchColumn());
        $this->assertSame(
            (string)$result['data']['company_id'],
            (string)$this->db->query('SELECT company_id FROM quote_requests')->fetchColumn()
        );
    }

    public function testConvertToProspectRejectsInvalidRequestId(): void
    {
        $this->assertFailure($this->service->convertToProspect(0), 400, 'Invalid request ID');
    }

    public function testAddPropertyToContactSuccess(): void
    {
        $result = $this->service->addPropertyToContact(1, '20 Oak Ave', 'Vancouver', 'V1V 1V1', '');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['data']['linked_existing']);
        $row = $this->db->query('SELECT property_name, site_contact_id FROM properties')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Ada Lovelace', $row['property_name']);
        $this->assertSame('1', (string)$row['site_contact_id']);
    }

    public function testAddPropertyToContactRequiresContactAndAddress(): void
    {
        $this->assertFailure(
            $this->service->addPropertyToContact(0, '', 'Vancouver', '', ''),
            400,
            'Contact ID and address are required'
        );
    }

    public function testAddPropertyToCompanySuccess(): void
    {
        $this->db->exec("INSERT INTO companies (id, company_name, primary_contact_id) VALUES (1, 'North Shore', 1)");

        $result = $this->service->addPropertyToCompany(
            1,
            '30 Pine Rd',
            'North Vancouver',
            'V2V 2V2',
            '',
            49.3,
            -123.1
        );

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['data']['linked_existing']);
        $row = $this->db->query('SELECT property_name, site_contact_id FROM properties')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('North Shore', $row['property_name']);
        $this->assertSame('1', (string)$row['site_contact_id']);
    }

    public function testAddPropertyToCompanyReturnsNotFound(): void
    {
        $this->assertFailure(
            $this->service->addPropertyToCompany(99, '30 Pine Rd', 'Vancouver', '', '', null, null),
            404,
            'Company not found'
        );
    }

    public function testLinkCompanyToContactSuccess(): void
    {
        $this->db->exec("INSERT INTO companies (id, company_name) VALUES (1, 'Example')");

        $result = $this->service->linkCompanyToContact(2, 1);

        $this->assertTrue($result['ok']);
        $row = $this->db->query('SELECT primary_contact_id, billing_contact_id FROM companies WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2', (string)$row['primary_contact_id']);
        $this->assertSame('2', (string)$row['billing_contact_id']);
    }

    public function testLinkCompanyToContactRejectsInvalidIds(): void
    {
        $this->assertFailure($this->service->linkCompanyToContact(0, 1), 400, 'Invalid contact or company');
    }

    public function testSavePropertyCoordsSuccess(): void
    {
        $this->db->exec("INSERT INTO properties (id, address) VALUES (1, '40 Cedar St')");

        $result = $this->service->savePropertyCoords(1, 49.2827, -123.1207);

        $this->assertTrue($result['ok']);
        $row = $this->db->query('SELECT latitude, longitude FROM properties WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(49.2827, $row['latitude']);
        $this->assertEquals(-123.1207, $row['longitude']);
    }

    public function testSavePropertyCoordsRejectsInvalidCoordinates(): void
    {
        $this->assertFailure($this->service->savePropertyCoords(1, 0.0, -123.1), 400, 'Invalid property or coordinates');
    }

    public function testEditPropertySuccess(): void
    {
        $this->db->exec("INSERT INTO properties (id, address, latitude, longitude) VALUES (1, 'Old', 49, -123)");

        $result = $this->service->editProperty(1, '50 Birch St', 'Surrey', 'BC', 'V3V 3V3', 'Birch');

        $this->assertTrue($result['ok']);
        $this->assertSame('50 Birch St', $result['data']['address']);
        $row = $this->db->query('SELECT address, latitude, longitude FROM properties WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('50 Birch St', $row['address']);
        $this->assertNull($row['latitude']);
        $this->assertNull($row['longitude']);
    }

    public function testEditPropertyRequiresIdAndAddress(): void
    {
        $this->assertFailure(
            $this->service->editProperty(0, '', '', '', '', ''),
            400,
            'Property ID and address are required'
        );
    }

    public function testUnlinkPropertySuccess(): void
    {
        $this->db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (1, '60 Fir St', 1)");

        $result = $this->service->unlinkProperty(1, 1, 2);

        $this->assertTrue($result['ok']);
        $this->assertSame('reassigned', $result['data']['action']);
        $this->assertSame('2', (string)$this->db->query('SELECT site_contact_id FROM properties WHERE id = 1')->fetchColumn());
    }

    public function testUnlinkPropertyRejectsPropertyOwnedByAnotherContact(): void
    {
        $this->db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (1, '60 Fir St', 2)");

        $this->assertFailure(
            $this->service->unlinkProperty(1, 1, 0),
            400,
            'Property not found or not linked to this contact'
        );
    }

    public function testAddClientNoteSuccessAndNormalizesInvalidType(): void
    {
        $result = $this->service->addClientNote(1, 'invalid', 'Call tomorrow', 7);

        $this->assertTrue($result['ok']);
        $row = $this->db->query('SELECT contact_id, note_type, content, created_by FROM client_notes')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('1', (string)$row['contact_id']);
        $this->assertSame('general', $row['note_type']);
        $this->assertSame('Call tomorrow', $row['content']);
        $this->assertSame('7', (string)$row['created_by']);
    }

    public function testAddClientNoteUsesTheSharedHelperWhenGiven(): void
    {
        $calls = [];
        $svc = new ClientActionsService($this->db, function ($contactId, $content, $type, $userId) use (&$calls) {
            $calls[] = [$contactId, $content, $type, $userId];
        });

        $this->assertTrue($svc->addClientNote(1, 'issue', ' Gate stuck ', 7)['ok']);
        $this->assertSame([[1, 'Gate stuck', 'issue', 7]], $calls);
        $this->assertSame('0', (string)$this->db->query('SELECT COUNT(*) FROM client_notes')->fetchColumn());
    }

    public function testAddClientNoteRequiresContactAndContent(): void
    {
        $this->assertFailure(
            $this->service->addClientNote(0, 'general', '', 7),
            400,
            'Contact ID and content are required'
        );
    }

    private function assertFailure(array $result, int $status, string $error): void
    {
        $this->assertFalse($result['ok']);
        $this->assertSame($status, $result['status']);
        $this->assertSame([], $result['data']);
        $this->assertSame($error, $result['error']);
    }
}
