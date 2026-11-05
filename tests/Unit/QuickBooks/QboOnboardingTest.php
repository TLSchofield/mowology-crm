<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class QboOnboardingContactServiceStub
{
    public function __construct(PDO $db) {}
    public function addPropertyToContact(int $contactId, array $data): array { return ['created' => false]; }
}

/**
 * Penny's QuickBooks onboarding (2026-10-20): the snapshot moves between sites, the previews read it the
 * way the sandbox stores things (sub-customers are jobs, Category items are folders, the GST number is on
 * the tax agency), and nothing is written on the live site.
 */
class QboOnboardingTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            "CREATE TABLE qbo_onboarding_snapshot (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, qbo_id TEXT, data TEXT, pulled_at TEXT)",
            "CREATE TABLE qbo_onboarding_parts (id INTEGER PRIMARY KEY, site_id INT, part TEXT, summary TEXT, applied_by INT, applied_at TEXT)",
            "CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)",
            "CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, mobile TEXT, preferred_contact_method TEXT, receive_sms INT, receive_marketing INT, consent_quote_followup INT, consent_marketing_email INT, consent_sms INT, consent_source TEXT, prospect_status TEXT, notes TEXT, is_active INT, company_id INT)",
            "CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, company_type TEXT, primary_contact_id INT, billing_contact_id INT, billing_email TEXT, billing_phone TEXT, billing_address TEXT, billing_city TEXT, billing_province TEXT, billing_postal_code TEXT, account_status TEXT, payment_terms TEXT, pref_attach_pdf INT)",
            "CREATE TABLE clients (id INTEGER PRIMARY KEY, site_id INT NOT NULL DEFAULT 1, client_type TEXT, display_name TEXT, billing_address TEXT, billing_city TEXT, billing_province TEXT, billing_postal_code TEXT, billing_email TEXT, billing_phone TEXT, payment_terms TEXT, legacy_contact_id INT, contact_id INT, UNIQUE(site_id, legacy_contact_id))",
            "CREATE TABLE qbo_links (id INTEGER PRIMARY KEY, site_id INT, realm_id TEXT, entity_type TEXT, entity_id TEXT, qbo_id TEXT, sync_token TEXT, request_id TEXT, status TEXT, qbo_total REAL, message TEXT, sent_at TEXT)",
            "CREATE TABLE properties (id INTEGER PRIMARY KEY, property_name TEXT, address TEXT, city TEXT, province TEXT, postal_code TEXT, latitude REAL, longitude REAL, site_contact_id INT, status TEXT, billing_entity_name TEXT)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INT, name TEXT, description TEXT, unit_type_id INT, base_price REAL, taxable INT, active INT, created_by INT)",
            "CREATE TABLE product_categories (id INTEGER PRIMARY KEY, name TEXT, description TEXT, active INT)",
            "CREATE TABLE unit_types (id INTEGER PRIMARY KEY, name TEXT)",
            "CREATE TABLE qbo_mappings (id INTEGER PRIMARY KEY, site_id INT, kind TEXT, ittai_key TEXT, qbo_id TEXT, qbo_name TEXT, set_by INT)",
            "CREATE TABLE vendors (id INTEGER PRIMARY KEY, name TEXT, phone TEXT, notes TEXT, is_active INT)",
        ] as $sql) $db->exec($sql);
        $db->exec("INSERT INTO unit_types (id,name) VALUES (1,'Each')");
        return $db;
    }

    private function snapshot(): string
    {
        $rows = [
            ['company', '1', ['CompanyName' => 'Sandbox CA', 'LegalName' => 'Sandbox CA Ltd', 'CompanyAddr' => ['Line1' => '2845 West 15th', 'City' => 'Vancouver', 'CountrySubDivisionCode' => 'BC'], 'FiscalYearStartMonth' => 'January']],
            ['agency', '1', ['Id' => '1', 'DisplayName' => 'Canada Revenue Agency', 'TaxRegistrationNumber' => '99-1234567']],
            ['term', '3', ['Id' => '3', 'Name' => 'Net 30', 'DueDays' => 30]],
            ['customer', '1', ['Id' => '1', 'DisplayName' => 'Adwin Ko', 'GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'Ko International Ltd', 'BillAddr' => ['Line1' => '6412 av des Erables', 'City' => 'Vancouver']]],
            ['customer', '2', ['Id' => '2', 'DisplayName' => 'Oxon Insurance Agency', 'CompanyName' => 'Oxon Insurance Agency', 'PrimaryEmailAddr' => ['Address' => 'Oxon@Example.ca']]],
            ['customer', '3', ['Id' => '3', 'DisplayName' => 'Oxon - Retreat', 'ParentRef' => ['value' => '2'], 'Job' => true, 'ShipAddr' => ['Line1' => '348 Worthington St. E.']]],
            ['customer', '4', ['Id' => '4', 'DisplayName' => 'Gone Ltd', 'Active' => false]],
            ['item', '1', ['Id' => '1', 'Name' => 'General services', 'Type' => 'Category']],
            ['item', '2', ['Id' => '2', 'Name' => 'Catering', 'FullyQualifiedName' => 'General services:Catering', 'Type' => 'Service', 'UnitPrice' => 0]],
            ['item', '3', ['Id' => '3', 'Name' => 'Lawn Cut', 'Type' => 'Service', 'UnitPrice' => 45]],
            ['vendor', '1', ['Id' => '1', 'DisplayName' => 'Supply Depot']],
            ['vendor', '2', ['Id' => '2', 'DisplayName' => 'Old Vendor', 'Active' => false]],
        ];
        return json_encode(['v' => 1, 'rows' => array_map(fn($r) => ['kind' => $r[0], 'qbo_id' => $r[1], 'data' => json_encode($r[2])], $rows)]);
    }

    private function loaded(PDO $db, bool $local = true): QboOnboardingService
    {
        $ob = new QboOnboardingService($db, null, 1, fn() => 1792540800, true);
        $ob->import($this->snapshot());
        return $local ? $ob : new QboOnboardingService($db, null, 1, fn() => 1792540800, false);
    }

    private function withCustomers(PDO $db, array $customers, ?QboConnectionService $conn = null): QboOnboardingService
    {
        $rows = array_map(fn($c) => ['kind' => 'customer', 'qbo_id' => (string)$c['Id'], 'data' => json_encode($c)], $customers);
        $ob = new QboOnboardingService($db, $conn, 1, fn() => 1792540800, true);
        $ob->import((string)json_encode(['v' => 1, 'rows' => $rows]));
        return $ob;
    }

    public function test_a_snapshot_moves_between_sites_and_the_live_site_never_loads_one(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $this->assertTrue($ob->hasSnapshot());
        $copy = $this->db();
        $this->assertTrue((new QboOnboardingService($copy, null, 1, null, true))->import($ob->export())['ok']);
        $this->assertSame(12, (int)$copy->query('SELECT COUNT(*) FROM qbo_onboarding_snapshot')->fetchColumn());
        $this->assertFalse((new QboOnboardingService($this->db(), null, 1, null, false))->import($ob->export())['ok'], 'live: refused');
        $this->assertFalse((new QboOnboardingService($this->db(), null, 1, null, true))->import('{"x":1}')['ok']);
    }

    public function test_business_details_come_from_the_company_and_the_gst_number_from_the_tax_agency(): void
    {
        $b = $this->loaded($this->db())->business();
        $this->assertSame('Sandbox CA', $b['name']);
        $this->assertSame('99-1234567', $b['gst_number']);
        $this->assertSame('January', $b['fiscal_start']);
        $this->assertSame('Net 30', $b['terms']);
        $this->assertSame('2845 West 15th, Vancouver BC', $b['address']);
    }

    public function test_clients_jobs_and_matches(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES (9, 'O', 'X', 'oxon@example.ca')");
        $c = $this->loaded($db)->clients();
        $this->assertSame(1, $c['new'], 'Adwin Ko');
        $this->assertSame(1, $c['match'], 'Oxon by email, case-insensitive');
        $this->assertSame(1, $c['properties'], 'the sub-customer is a job of Oxon, not a client');
        $this->assertSame('A job of Oxon Insurance Agency — kept with that client.', $c['rows'][2]['why']);
        $this->assertCount(3, $c['rows'], 'inactive customers left out');
    }

    public function test_shared_addresses_and_possible_building_groups_are_previewed_only(): void
    {
        $customers = [
            ['Id' => '1', 'DisplayName' => 'Acme Mgmt - 123 Main', 'ShipAddr' => ['Line1' => '350 West Georgia St']],
            ['Id' => '2', 'DisplayName' => 'Acme Mgmt – 456 Oak', 'ShipAddr' => ['Line1' => ' 350 WEST GEORGIA ST ']],
            ['Id' => '3', 'DisplayName' => 'Bob Smith', 'ShipAddr' => ['Line1' => '9 Alone Rd']],
        ];
        $ob = $this->withCustomers($this->db(), $customers);

        $clients = $ob->clients();

        $this->assertSame([['address' => '350 West Georgia St', 'count' => 2]], $clients['shared_addresses']);
        $this->assertSame('2 clients at 350 West Georgia St', $clients['rows'][0]['shared']);
        $this->assertSame('Acme Mgmt', $clients['rows'][1]['group_hint']);
        $this->assertSame([['prefix' => 'Acme Mgmt', 'names' => ['Acme Mgmt - 123 Main', 'Acme Mgmt – 456 Oak']]], $clients['groups']);
        $this->assertStringContainsString('1 shared addresses', $ob->partPreviews()['clients']);
        $this->assertStringContainsString('1 possible building groups', $ob->partPreviews()['clients']);
    }

    public function test_two_clients_at_one_street_get_separate_properties_and_rerun_is_idempotent(): void
    {
        $db = $this->db();
        $customers = [
            ['Id' => '1', 'DisplayName' => 'Alice One', 'GivenName' => 'Alice', 'FamilyName' => 'One', 'ShipAddr' => ['Line1' => '350 West Georgia St', 'CountrySubDivisionCode' => 'ON']],
            ['Id' => '2', 'DisplayName' => 'Bob Two', 'GivenName' => 'Bob', 'FamilyName' => 'Two', 'ShipAddr' => ['Line1' => '350 West Georgia St', 'CountrySubDivisionCode' => 'ON']],
        ];
        $ob = $this->withCustomers($db, $customers);

        $ob->apply('clients', 7);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM properties')->fetchColumn());
        $this->assertSame(2, (int)$db->query('SELECT COUNT(DISTINCT site_contact_id) FROM properties')->fetchColumn());
        $this->assertSame('ON', $db->query('SELECT province FROM properties LIMIT 1')->fetchColumn());
        $ob->apply('clients', 7);
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM properties')->fetchColumn());
    }

    public function test_an_unowned_property_at_the_address_is_reused_and_filled(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO properties (id,address,site_contact_id) VALUES (9,'350 West Georgia St',NULL)");
        $ob = $this->withCustomers($db, [['Id' => '1', 'DisplayName' => 'Alice One', 'ShipAddr' => ['Line1' => '350 West Georgia St']]]);

        $ob->apply('clients', 7);

        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM properties')->fetchColumn());
        $this->assertSame(1, (int)$db->query('SELECT site_contact_id FROM properties WHERE id=9')->fetchColumn());
    }

    public function test_a_persons_different_billing_address_is_kept_without_overwriting_it_on_rerun(): void
    {
        $db = $this->db();
        $customer = ['Id' => '1', 'DisplayName' => 'Alice One', 'GivenName' => 'Alice', 'FamilyName' => 'One',
            'PrimaryEmailAddr' => ['Address' => 'alice@example.ca'], 'PrimaryPhone' => ['FreeFormNumber' => '604-555-0101'],
            'ShipAddr' => ['Line1' => '1 Work Rd', 'PostalCode' => 'V1V 1V1'],
            'BillAddr' => ['Line1' => '9 Mail St', 'City' => 'Victoria', 'CountrySubDivisionCode' => 'BC', 'PostalCode' => 'V2V 2V2']];
        $ob = $this->withCustomers($db, [$customer]);

        $first = $ob->apply('clients', 7);
        $this->assertStringContainsString('1 billing addresses kept', $first['message']);
        $row = $db->query('SELECT * FROM clients')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['9 Mail St', 'Victoria', 'BC', 'V2V 2V2', 1], [$row['billing_address'], $row['billing_city'], $row['billing_province'], $row['billing_postal_code'], (int)$row['legacy_contact_id']]);
        $db->exec("UPDATE clients SET billing_city='Keep Me'");
        $ob->apply('clients', 7);
        $this->assertSame(1, (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        $this->assertSame('Keep Me', $db->query('SELECT billing_city FROM clients')->fetchColumn());
    }

    public function test_company_and_same_site_billing_addresses_do_not_make_client_rows(): void
    {
        $db = $this->db();
        $customers = [
            ['Id' => '1', 'DisplayName' => 'Alice One', 'CompanyName' => 'Alice Co', 'ShipAddr' => ['Line1' => '1 Work Rd'], 'BillAddr' => ['Line1' => '9 Mail St']],
            ['Id' => '2', 'DisplayName' => 'Bob Two', 'ShipAddr' => ['Line1' => '2 Same Rd', 'PostalCode' => 'A1A1A1'], 'BillAddr' => ['Line1' => '2 Same Rd', 'PostalCode' => 'A1A 1A1']],
        ];

        $this->withCustomers($db, $customers)->apply('clients', 7);

        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
    }

    public function test_every_addressed_sub_customer_becomes_a_named_property_link(): void
    {
        $db = $this->db();
        $conn = new class($db) extends QboConnectionService {
            public function company(): ?array { return ['realm_id' => 'realm']; }
        };
        $customers = [
            ['Id' => '1', 'DisplayName' => 'Acme Mgmt', 'CompanyName' => 'Acme Mgmt', 'PrimaryEmailAddr' => ['Address' => 'parent@example.ca']],
            ['Id' => '2', 'DisplayName' => 'Acme Mgmt - Harbour Tower', 'ParentRef' => ['value' => '1'], 'BillAddr' => ['Line1' => '123 Harbour Rd'],
             'BillWithParent' => false, 'PrimaryEmailAddr' => ['Address' => 'tower@example.ca']],
            ['Id' => '3', 'DisplayName' => 'Acme Mgmt: Missing Tower', 'ParentRef' => ['value' => '1']],
            ['Id' => '4', 'DisplayName' => 'Acme Mgmt - Shipping Tower', 'ParentRef' => ['value' => '1'], 'ShipAddr' => ['Line1' => '456 Shipping Rd']],
        ];
        $ob = $this->withCustomers($db, $customers, $conn);

        $result = $ob->apply('clients', 7);

        $property = $db->query("SELECT * FROM properties WHERE address='123 Harbour Rd'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Harbour Tower', $property['property_name']);
        $this->assertSame('Harbour Tower', $property['billing_entity_name']);
        $this->assertSame('2', $db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='property:" . (int)$property['id'] . "'")->fetchColumn());
        $shippingProperty = $db->query("SELECT * FROM properties WHERE address='456 Shipping Rd'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Shipping Tower', $shippingProperty['property_name']);
        $this->assertSame('4', $db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='property:" . (int)$shippingProperty['id'] . "'")->fetchColumn());
        $this->assertSame([['name' => 'Harbour Tower', 'email' => 'tower@example.ca']], $result['own_billing']);
        $this->assertStringContainsString('1 buildings billed on their own — check who gets their invoices', $result['message']);
        $this->assertStringContainsString('Missing Tower: no address', $result['message']);
        $this->assertSame('Building billed on its own — check who gets its invoices.', $ob->clients()['rows'][1]['why']);
    }

    public function test_two_units_at_one_street_get_their_own_buildings_and_a_rerun_makes_none(): void
    {
        $db = $this->db();
        $conn = new class($db) extends QboConnectionService {
            public function company(): ?array { return ['realm_id' => 'realm']; }
        };
        $ob = $this->withCustomers($db, [
            ['Id' => '1', 'DisplayName' => 'Acme Mgmt', 'CompanyName' => 'Acme Mgmt', 'PrimaryEmailAddr' => ['Address' => 'parent@example.ca']],
            ['Id' => '2', 'DisplayName' => 'Acme Mgmt - Unit A', 'ParentRef' => ['value' => '1'], 'ShipAddr' => ['Line1' => '9 Dock St']],
            ['Id' => '3', 'DisplayName' => 'Acme Mgmt - Unit B', 'ParentRef' => ['value' => '1'], 'ShipAddr' => ['Line1' => '9 Dock St']],
        ], $conn);

        $ob->apply('clients', 7);
        $ob->apply('clients', 7);

        $ids = $db->query("SELECT id FROM properties WHERE address='9 Dock St' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $ids, 'one building per unit, none added by the re-run');
        $this->assertSame('2', $db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='property:" . (int)$ids[0] . "'")->fetchColumn());
        $this->assertSame('3', $db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='property:" . (int)$ids[1] . "'")->fetchColumn());
    }

    public function test_a_property_that_already_existed_gets_no_sub_customer_link(): void
    {
        $db = $this->db();
        $conn = new class($db) extends QboConnectionService {
            public function company(): ?array { return ['realm_id' => 'realm']; }
        };
        $ob = $this->withCustomers($db, [
            ['Id' => '1', 'DisplayName' => 'Acme Mgmt', 'CompanyName' => 'Acme Mgmt', 'PrimaryEmailAddr' => ['Address' => 'parent@example.ca']],
            ['Id' => '2', 'DisplayName' => 'Acme Mgmt - Old Tower', 'ParentRef' => ['value' => '1'], 'ShipAddr' => ['Line1' => '5 Old Rd']],
        ], $conn);
        $db->exec("INSERT INTO properties (property_name, address, city, province, postal_code, site_contact_id, status) VALUES ('Old', '5 Old Rd', '', 'BC', '', NULL, 'active')");

        $ob->apply('clients', 7);

        $id = (int)$db->query("SELECT id FROM properties WHERE address='5 Old Rd'")->fetchColumn();
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM properties WHERE address='5 Old Rd'")->fetchColumn());
        $this->assertFalse($db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='property:$id'")->fetchColumn(),
            'its earlier invoices keep going to their own customer');
    }

    public function test_sync_prefers_an_imported_property_customer_link(): void
    {
        $sync = new class extends QboSyncService {
            public function __construct() {}
            protected function link(string $type, string $id): ?array
            {
                return $type === 'client' && $id === 'property:43' ? ['qbo_id' => '900'] : null;
            }
        };
        $method = new ReflectionMethod(QboSyncService::class, 'ensureCustomer');
        $method->setAccessible(true);

        $result = $method->invoke($sync, ['id' => 10, 'property_id' => 43]);

        $this->assertSame(['ok' => true, 'qbo_id' => '900'], $result);
    }

    public function test_sync_falls_back_to_the_existing_customer_identity_chain(): void
    {
        $sync = new class extends QboSyncService {
            public function __construct() {}
            protected function link(string $type, string $id): ?array
            {
                if ($id === 'property:43') return null;
                return $id === 'contact:7' ? ['qbo_id' => '800'] : null;
            }
        };
        $method = new ReflectionMethod(QboSyncService::class, 'ensureCustomer');
        $method->setAccessible(true);

        $result = $method->invoke($sync, ['id' => 10, 'property_id' => 43, 'contact_id' => 7, 'contact_name' => 'Alice One']);

        $this->assertSame(['ok' => true, 'qbo_id' => '800'], $result);
    }

    public function test_a_client_imported_before_is_recognised_even_with_no_email_or_address(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts (id, first_name, last_name, notes) VALUES (5, 'Adwin', 'Ko', '" . QboOnboardingService::importNote('1') . "')");
        $this->assertSame('Imported from QuickBooks already.', $this->loaded($db)->clients()['rows'][0]['why']);
    }

    public function test_imported_customer_links_cover_contact_company_and_client_identities(): void
    {
        $this->assertSame(['contact:4','company:7','client:9'], QboOnboardingService::customerKeys(4,7,9));
        $this->assertSame(['contact:4'], QboOnboardingService::customerKeys(4,null,null));
    }

    public function test_accepting_clients_links_every_schema_identity_lookup(): void
    {
        if (!class_exists('ContactService')) class_alias(QboOnboardingContactServiceStub::class, 'ContactService');
        $db=$this->db();
        $note=QboOnboardingService::importNote('1');
        $db->prepare("INSERT INTO contacts (id,first_name,last_name,notes) VALUES (8,'Adwin','Ko',?)")->execute([$note]);
        $db->exec("INSERT INTO contacts (id,first_name,last_name,email,company_id) VALUES (9,'O','X','oxon@example.ca',7)");
        $db->exec("INSERT INTO companies (id,primary_contact_id) VALUES (7,9)");
        $db->exec("INSERT INTO clients (id,legacy_contact_id,contact_id) VALUES (11,9,NULL)");
        $conn=new class($db) extends QboConnectionService {
            public function company(): ?array { return ['realm_id'=>'realm']; }
        };
        $ob=new QboOnboardingService($db,$conn,1,fn()=>1792540800,true);
        $ob->import($this->snapshot());
        $r=$ob->apply('clients',7);
        $this->assertTrue($r['ok']);
        $links=$db->query("SELECT entity_id,qbo_id FROM qbo_links WHERE entity_type='client' AND qbo_id='2' ORDER BY entity_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['client:11'=>'2','company:7'=>'2','contact:9'=>'2'],$links);
    }

    public function test_accepting_clients_writes_all_links_keeps_conflicts_and_is_idempotent(): void
    {
        if (!class_exists('ContactService')) class_alias(QboOnboardingContactServiceStub::class, 'ContactService');
        $db=$this->db();
        $note=QboOnboardingService::importNote('1');
        $db->prepare("INSERT INTO contacts (id,first_name,last_name,notes,company_id) VALUES (1,'Adwin','Ko',?,1)")->execute([$note]);
        $db->exec("INSERT INTO contacts (id,first_name,last_name,email) VALUES (2,'O','X','oxon@example.ca')");
        $db->exec('INSERT INTO companies (id,primary_contact_id) VALUES (1,1)');
        $db->exec('INSERT INTO clients (id,legacy_contact_id,contact_id) VALUES (1,1,1),(2,2,2)');
        $db->exec("INSERT INTO qbo_links (site_id,realm_id,entity_type,entity_id,qbo_id,status) VALUES (1,'realm','client','contact:1','other','sent')");
        $conn=new class($db) extends QboConnectionService {
            public function company(): ?array { return ['realm_id'=>'realm']; }
        };
        $ob=new QboOnboardingService($db,$conn,1,fn()=>1792540800,true);
        $ob->import($this->snapshot());

        $first=$ob->apply('clients',7);
        $this->assertStringContainsString('1 QuickBooks link conflicts kept unchanged',$first['message']);
        $this->assertSame('other',$db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='contact:1'")->fetchColumn());
        $this->assertSame(2,(int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn());
        $this->assertSame(2,(int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn());
        $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn());
        $this->assertSame(6,(int)$db->query("SELECT COUNT(*) FROM qbo_links WHERE entity_type='client'")->fetchColumn(),
                          'conflicting contact plus contact/company/client and sub-customer property identities are all represented');
        $this->assertSame('3',(string)$db->query("SELECT qbo_id FROM qbo_links WHERE entity_id LIKE 'property:%'")->fetchColumn());

        $ob->apply('clients',7);
        $this->assertSame([2,2,1,6],[(int)$db->query('SELECT COUNT(*) FROM contacts')->fetchColumn(),
                                    (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
                                    (int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn(),
                                    (int)$db->query("SELECT COUNT(*) FROM qbo_links WHERE entity_type='client'")->fetchColumn()]);
    }

    public function test_services_skip_folders_and_keep_their_group(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO products (name) VALUES ('LAWN CUT')");
        $s = $this->loaded($db)->services();
        $this->assertSame(1, $s['new']);
        $this->assertSame(1, $s['match'], 'Lawn Cut already in Ittai (by name)');
        $this->assertSame('Catering', $s['rows'][0]['name']);
        $this->assertSame('General services', $s['rows'][0]['group']);
    }

    public function test_accepting_services_maps_new_and_existing_products_idempotently(): void
    {
        $db=$this->db();
        $db->exec("INSERT INTO products (id,name) VALUES (9,'Lawn Cut')");
        $ob=$this->loaded($db);
        $this->assertStringContainsString('2 QuickBooks items linked',$ob->apply('services',7)['message']);
        $maps=$db->query("SELECT ittai_key,qbo_id FROM qbo_mappings WHERE kind='item' ORDER BY qbo_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('3',$maps['9']);
        $this->assertCount(2,$maps);
        $ob->apply('services',7);
        $this->assertSame(2,(int)$db->query("SELECT COUNT(*) FROM qbo_mappings WHERE kind='item'")->fetchColumn());
    }

    public function test_suppliers_and_inactive_ones(): void
    {
        $s = $this->loaded($this->db())->suppliers();
        $this->assertSame(1, $s['new']);
        $this->assertSame('Supply Depot', $s['rows'][0]['name']);
    }

    public function test_the_live_site_previews_but_never_imports(): void
    {
        $db = $this->db();
        $live = $this->loaded($db, false);
        $r = $live->apply('suppliers', 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('preview', $r['message']);
        $this->assertSame(0, (int)$db->query('SELECT COUNT(*) FROM vendors')->fetchColumn());
        $this->assertSame([], $live->partSummaries());
    }

    /** Claudia walk 1: every business but Mowology imports; Mowology's live site keeps the preview. */
    public function test_writes_are_allowed_on_any_business_but_the_default_one(): void
    {
        $this->assertTrue(QboOnboardingService::writesAllowed(true, true), 'local test site');
        $this->assertTrue(QboOnboardingService::writesAllowed(false, false), 'a founding business');
        $this->assertFalse(QboOnboardingService::writesAllowed(false, true), 'Mowology live: preview only');
    }

    public function test_rows_preview_what_accept_would_do(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $p = $ob->partPreviews();
        $this->assertSame('1 supplier to add', $p['suppliers']);
        $this->assertArrayHasKey('clients', $p); $this->assertArrayHasKey('services', $p);
        $this->assertArrayNotHasKey('accounts', $p, 'no chart in this fixture: that part is left out, the rest still preview');
        $this->assertNotFalse($db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'qbo_onboarding_previews'")->fetchColumn(), 'stored once per read');
        $ob->apply('suppliers', 1);
        $this->assertSame('1 supplier to add', $ob->partPreviews()['suppliers'], 'as of the read (the row itself now shows Done)');
        $ob->import($this->snapshot());
        $this->assertSame('Nothing new — 1 already here', $ob->partPreviews()['suppliers'], 'a new read works it out again');
        $this->assertSame([], (new QboOnboardingService($this->db(), null, 1, null, true))->partPreviews(), 'nothing before a read');
        $this->assertSame('None found', QboOnboardingService::countLine(0, 0, 'client', 'clients'));
        $this->assertSame('3 clients to add, 2 already here', QboOnboardingService::countLine(3, 2, 'client', 'clients'));
    }

    public function test_the_local_import_of_suppliers_runs_once(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $this->assertSame('1 suppliers added for Penny.', $ob->apply('suppliers', 1)['message']);
        $this->assertSame('0 suppliers added for Penny.', $ob->apply('suppliers', 1)['message'], 'a second press adds nothing');
        $this->assertSame(['suppliers' => '0 suppliers added for Penny.'], $ob->partSummaries());
    }

    public function test_a_new_read_is_reviewed_again(): void
    {
        $db = $this->db();
        $ob = $this->loaded($db);
        $ob->apply('suppliers', 1);
        $this->assertNotEmpty($ob->partSummaries());
        $ob->import($this->snapshot());
        $this->assertSame([], $ob->partSummaries(), 'old approvals belonged to the old data');
    }

    public function test_names_and_companies(): void
    {
        $this->assertSame(['Adwin', 'Ko'], QboOnboardingService::personName(['GivenName' => 'Adwin', 'FamilyName' => 'Ko']));
        $this->assertSame(['Oxon', 'Insurance Agency'], QboOnboardingService::personName(['DisplayName' => 'Oxon Insurance Agency']));
        $this->assertSame('Ko International Ltd', QboOnboardingService::companyOf(['GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'Ko International Ltd']));
        $this->assertNull(QboOnboardingService::companyOf(['GivenName' => 'Adwin', 'FamilyName' => 'Ko', 'CompanyName' => 'adwin ko']), 'a person\'s own name is not a company');
        $this->assertNull(QboOnboardingService::address(['City' => 'Vancouver']));
    }

    public function test_import_links_are_idempotent_and_never_replace_another_qbo_customer(): void
    {
        $db=$this->db();
        $args=[$db,1,'realm','client','contact:5','101',null,'imported','sent',null,'linked at import','2026-11-04 10:00:00',true];
        $this->assertSame('saved',QboLinkService::save(...$args));
        $this->assertSame('unchanged',QboLinkService::save(...$args));
        $args[5]='202';
        $this->assertSame('conflict',QboLinkService::save(...$args));
        $this->assertSame('101',$db->query("SELECT qbo_id FROM qbo_links WHERE entity_id='contact:5'")->fetchColumn());
        $this->assertSame(1,(int)$db->query('SELECT COUNT(*) FROM qbo_links')->fetchColumn());
    }
}
