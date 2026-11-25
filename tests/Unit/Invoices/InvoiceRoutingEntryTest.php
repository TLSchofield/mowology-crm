<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/lib/legacy-recipient-cascade.php';

/**
 * Characterisation of the invoice-recipient cascade (the logic behind
 * api-get-recipients.php): client_contacts -> company live routing ->
 * determineInvoiceRecipients() -> site-contact fallback -> PM-managed precedence.
 *
 * Every case runs against BOTH the frozen pre-extraction copy
 * (scripts/lib/legacy-recipient-cascade.php) and InvoiceRouting::resolveInvoiceRecipients(),
 * and both must give exactly the pinned rows. Pinned 2026-11-24 from the behaviour
 * that produced the Dorset fix (PM-managed firm first and ticked, on-site contact unticked).
 *
 * In-memory SQLite with MySQL's CONCAT() shimmed.
 */
class InvoiceRoutingEntryTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->sqliteCreateFunction('CONCAT', static fn(...$a) => implode('', array_map('strval', $a)));

        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT,
                         email TEXT, mobile TEXT, receive_sms INT DEFAULT 0, is_active INT DEFAULT 1)");
        $this->db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, company_type TEXT,
                         primary_contact_id INT, billing_contact_id INT, billing_email TEXT, invoice_routing_method TEXT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, client_id INT, owner_company_id INT,
                         property_manager_id INT, site_contact_id INT)");
        $this->db->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY, legacy_company_id INT, managed_by_client_id INT)");
        $this->db->exec("CREATE TABLE client_contacts (client_id INT, contact_id INT, role TEXT,
                         is_primary INT DEFAULT 0, receives_invoices INT DEFAULT 0)");
    }

    private function contact(int $id, string $first, string $last, ?string $email, int $sms = 0, ?string $mobile = null): void
    {
        $this->db->prepare("INSERT INTO contacts (id, first_name, last_name, email, mobile, receive_sms) VALUES (?,?,?,?,?,?)")
                 ->execute([$id, $first, $last, $email, $mobile, $sms]);
    }

    private function company(int $id, string $name, string $type, ?int $primary, ?int $billing, ?string $billingEmail, ?string $method): void
    {
        $this->db->prepare("INSERT INTO companies VALUES (?,?,?,?,?,?,?)")
                 ->execute([$id, $name, $type, $primary, $billing, $billingEmail, $method]);
    }

    private function property(int $id, ?int $clientId, ?int $owner, ?int $manager, ?int $site): void
    {
        $this->db->prepare("INSERT INTO properties VALUES (?,?,?,?,?)")->execute([$id, $clientId, $owner, $manager, $site]);
    }

    private function client(int $id, ?int $legacyCompany, ?int $managedBy = null): void
    {
        $this->db->prepare("INSERT INTO clients VALUES (?,?,?)")->execute([$id, $legacyCompany, $managedBy]);
    }

    private function link(int $clientId, int $contactId, ?string $role, int $primary, int $receives): void
    {
        $this->db->prepare("INSERT INTO client_contacts VALUES (?,?,?,?,?)")->execute([$clientId, $contactId, $role, $primary, $receives]);
    }

    /** @return array<string,callable> */
    public function implementations(): array
    {
        return [
            'frozen legacy cascade' => [static fn(PDO $db, int $p, int $cl, int $co, int $ct) => legacyRecipientCascade($db, $p, $cl, $co, $ct)],
            'InvoiceRouting::resolveInvoiceRecipients' => [static fn(PDO $db, int $p, int $cl, int $co, int $ct) => InvoiceRouting::resolveInvoiceRecipients($p, $cl, $co, $ct, $db)],
        ];
    }

    private function skipIfMissing(callable $impl): void
    {
        $probe = $impl;
        if (!class_exists('InvoiceRouting') && $probe instanceof Closure) {
            // new entry point not extracted yet — the frozen copy still pins the behaviour
            try { $probe($this->db, 0, 0, 0, 0); } catch (Error $e) { $this->markTestSkipped('InvoiceRouting not extracted yet'); }
        }
    }

    /** @param array<int,array> $rows  @return array<int,array> */
    private function slim(array $rows): array
    {
        return array_map(static fn($r) => [
            (int)$r['contact_id'], $r['contact_name'] ?? null, $r['contact_role'], $r['email_address'],
            array_key_exists('preselect', $r) ? $r['preselect'] : 'n/a',
        ], $rows);
    }

    /** Dorset-style: firm routed by billing_email only; on-site manager stays in the list, unticked. */
    private function dorset(): void
    {
        $this->contact(5, 'Joann', 'Hamelin', 'joann@dorset.example', 1, '6045550105');
        $this->company(10, 'Dorset Realty', 'property_management', null, null, 'invoices@dorsetrealty.com', 'email_address');
        $this->client(100, 10);
        $this->property(1, 100, null, 10, 5);
        $this->link(100, 5, 'site_contact', 1, 1);
    }

    /** @dataProvider implementations */
    public function testPmManagedBuildingBillsTheFirmAndDemotesTheOnSiteContact(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->dorset();
        $rows = $impl($this->db, 1, 100, 10, 0);
        $this->assertSame([
            [0, 'Dorset Realty (Accounts)', 'billing_contact', 'invoices@dorsetrealty.com', true],
            [5, 'Joann Hamelin', 'site_contact', 'joann@dorset.example', false],
        ], $this->slim($rows));
        $this->assertNull($rows[0]['phone']);
        $this->assertFalse($rows[0]['receive_sms']);
        $this->assertSame('6045550105', $rows[1]['phone']);
        $this->assertTrue($rows[1]['receive_sms']);
    }

    /** @dataProvider implementations */
    public function testPmManagedBuildingWithNoClientIdStillBillsTheFirm(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->dorset();
        // No client: manager has no contacts so legacy routing is empty, site-contact fallback fills in,
        // then the PM precedence puts the firm first.
        $rows = $impl($this->db, 1, 0, 10, 0);
        $this->assertSame([
            [0, 'Dorset Realty (Accounts)', 'billing_contact', 'invoices@dorsetrealty.com', true],
            [5, 'Joann Hamelin', 'primary_recipient', 'joann@dorset.example', false],
        ], $this->slim($rows));
    }

    /** @dataProvider implementations */
    public function testStrataCompanyBillsItsOwnerContactViaLegacyRouting(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(6, 'Sue', 'Strata', 'sue@strata.example', 0);
        $this->company(20, 'Strata Plan VR15', 'strata', 6, null, null, null);
        $this->property(2, null, 20, null, null);
        $rows = $impl($this->db, 2, 0, 20, 0);
        $this->assertSame([
            [6, null, 'strata_manager', 'sue@strata.example', 'n/a'],
        ], $this->slim($rows));
        $this->assertSame('Sue', $rows[0]['first_name']);
    }

    /** @dataProvider implementations */
    public function testDirectCompanyRoutesItsBillingContactFirstAndKeepsAccountPeopleUnticked(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(7, 'Bea', 'Billing', 'bea@acme.example');
        $this->contact(8, 'Pat', 'Owner', 'pat@acme.example');
        $this->company(30, 'Acme Ltd', 'company', 8, 7, null, 'billing_contact');
        $this->client(300, 30);
        $this->property(3, 300, 30, null, null);
        $this->link(300, 8, 'primary_recipient', 1, 1);
        $rows = $impl($this->db, 3, 300, 30, 0);
        $this->assertSame([
            [7, 'Bea Billing', 'billing_contact', 'bea@acme.example', true],
            [8, 'Pat Owner', 'primary_recipient', 'pat@acme.example', false],
        ], $this->slim($rows));
    }

    /** @dataProvider implementations */
    public function testPlainIndividualClientUsesItsClientContactsOnly(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(9, 'Ivy', 'Home', 'ivy@home.example', 1, '6045550109');
        $this->contact(13, 'Noemail', 'Person', null);
        $this->client(400, null);
        $this->property(4, 400, null, null, null);
        $this->link(400, 9, null, 1, 1);
        $this->link(400, 13, 'cc', 0, 0);   // no email: skipped
        $rows = $impl($this->db, 4, 400, 0, 0);
        $this->assertSame([
            [9, 'Ivy Home', 'primary_recipient', 'ivy@home.example', true],
        ], $this->slim($rows));
    }

    /** A managed account (strata run by a PM firm) lists the manager's people first, tagged "(Manager)", and follows the manager's routing. */
    /** @dataProvider implementations */
    public function testManagedAccountFollowsItsManagersRouting(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(14, 'Mo', 'Manager', 'mo@pm.example');
        $this->contact(15, 'Rep', 'Council', 'rep@strata.example');
        $this->company(40, 'PM Firm', 'property_management', 14, 14, null, 'billing_contact');
        $this->client(500, null);               // the PM firm's client
        $this->client(501, null, 500);          // the strata, managed by 500
        $this->db->exec("UPDATE clients SET legacy_company_id = 40 WHERE id = 500");
        $this->link(500, 14, 'billing_contact', 1, 1);
        $this->link(501, 15, 'primary_recipient', 1, 1);
        $rows = $impl($this->db, 0, 501, 0, 0);
        $this->assertSame([
            [14, 'Mo Manager', 'billing_contact', 'mo@pm.example', true],
            [15, 'Rep Council', 'primary_recipient', 'rep@strata.example', false],
        ], $this->slim($rows));
    }

    /** @dataProvider implementations */
    public function testPropertyWithNoClientIdFallsBackToItsSiteContact(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(11, 'Sid', 'Site', 'sid@site.example', 1, '6045550111');
        $this->property(5, null, null, null, 11);
        $rows = $impl($this->db, 5, 0, 0, 0);
        $this->assertSame([
            [11, 'Sid Site', 'primary_recipient', 'sid@site.example', 'n/a'],
        ], $this->slim($rows));
        $this->assertSame('6045550111', $rows[0]['phone']);
        $this->assertTrue($rows[0]['receive_sms']);
    }

    /** @dataProvider implementations */
    public function testPassedContactIsUsedWhenThePropertyHasNoSiteContact(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->contact(12, 'Pass', 'Ed', 'passed@x.example');
        $this->property(6, null, null, null, null);
        $this->assertSame([[12, 'Pass Ed', 'primary_recipient', 'passed@x.example', 'n/a']],
            $this->slim($impl($this->db, 6, 0, 0, 12)));
    }

    /** @dataProvider implementations */
    public function testNothingResolvableGivesAnEmptyList(callable $impl): void
    {
        $this->skipIfMissing($impl);
        $this->property(7, null, null, null, null);
        $this->assertSame([], $impl($this->db, 7, 0, 0, 0));
    }
}
