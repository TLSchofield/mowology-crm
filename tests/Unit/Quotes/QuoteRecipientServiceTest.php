<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * QuoteRecipientService — who a quote goes to and who is copied (migration 1186).
 *
 * The case this pins: a Vancouver Management building's billing (site) contact is the
 * accountant, Jodi; quotes must go to Alena (the company's quote contact), and the
 * building's property manager (Darren) is copied. Runs against in-memory SQLite.
 */
class QuoteRecipientServiceTest extends TestCase
{
    private PDO $db;

    private const JODI = 10, ALENA = 1535, DARREN = 20, OTHER = 30, VML = 5, PROP = 100;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT,
                         phone TEXT, contact_role TEXT, employer_company_id INT)");
        $this->db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT, primary_contact_id INT,
                         billing_contact_id INT, quote_contact_id INT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, site_contact_id INT,
                         property_manager_id INT, quote_contact_id INT)");
        $this->db->exec("CREATE TABLE company_properties (id INTEGER PRIMARY KEY, company_id INT, property_id INT, is_primary INT)");
        $this->db->exec("CREATE TABLE property_contacts (id INTEGER PRIMARY KEY, property_id INT, contact_id INT,
                         contact_role TEXT, is_primary INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE quotes (id INTEGER PRIMARY KEY, property_id INT, company_id INT, contact_id INT,
                         recipient_chosen INT NOT NULL DEFAULT 0, cc_property_manager INT NOT NULL DEFAULT 1)");

        $this->contact(self::JODI, 'Jodi', 'Peacock', 'invoices@vml.example', 'billing_contact', self::VML);
        $this->contact(self::ALENA, 'Alena', 'Radosovska', 'alena@vml.example', null, self::VML);
        $this->contact(self::DARREN, 'Darren', 'Brattston', 'darren@vml.example', 'property_manager', self::VML);
        $this->contact(self::OTHER, 'Pat', 'Owner', 'pat@example.com', null, null);

        $this->db->exec("INSERT INTO companies (id, company_name, primary_contact_id, billing_contact_id) VALUES (" . self::VML . ", 'Vancouver Management Ltd', " . self::JODI . ", " . self::JODI . ")");
        $this->db->exec("INSERT INTO properties (id, address, site_contact_id) VALUES (" . self::PROP . ", '1450 Brandon', " . self::JODI . ")");
    }

    private function contact(int $id, string $f, string $l, ?string $email, ?string $role, ?int $employer): void
    {
        $this->db->prepare("INSERT INTO contacts (id, first_name, last_name, email, contact_role, employer_company_id) VALUES (?,?,?,?,?,?)")
                 ->execute([$id, $f, $l, $email, $role, $employer]);
    }

    private function svc(): QuoteRecipientService
    {
        return new QuoteRecipientService($this->db);
    }

    // ── Resolution order ────────────────────────────────────────────────

    public function test_falls_back_to_the_billing_contact_when_nothing_is_set(): void
    {
        $r = $this->svc()->forProperty(self::PROP);
        $this->assertSame(self::JODI, $r['contact_id']);
        $this->assertSame('site_contact', $r['source']);
    }

    public function test_company_quote_contact_beats_the_billing_contact_via_the_inferred_link(): void
    {
        // No property_manager_id: VML is found because Jodi is its billing contact.
        $this->db->exec("UPDATE companies SET quote_contact_id = " . self::ALENA);
        $r = $this->svc()->forProperty(self::PROP);
        $this->assertSame(self::ALENA, $r['contact_id']);
        $this->assertSame('company', $r['source']);
        $this->assertSame(self::VML, $r['company_id']);
    }

    public function test_managing_company_is_found_through_property_manager_id(): void
    {
        $this->db->exec("UPDATE properties SET site_contact_id = " . self::OTHER . ", property_manager_id = " . self::VML);
        $this->db->exec("UPDATE companies SET quote_contact_id = " . self::ALENA);
        $this->assertSame(self::ALENA, $this->svc()->forProperty(self::PROP)['contact_id']);
    }

    public function test_building_override_beats_the_company(): void
    {
        $this->db->exec("UPDATE companies SET quote_contact_id = " . self::ALENA);
        $this->db->exec("UPDATE properties SET quote_contact_id = " . self::OTHER);
        $r = $this->svc()->forProperty(self::PROP);
        $this->assertSame(self::OTHER, $r['contact_id']);
        $this->assertSame('property', $r['source']);
    }

    public function test_works_before_the_migration(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, site_contact_id INT)");
        $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, primary_contact_id INT, billing_contact_id INT)");
        $db->exec("INSERT INTO properties VALUES (1, 7)");
        $db->exec("INSERT INTO companies VALUES (2, 7, 7)");
        $svc = new QuoteRecipientService($db);
        $this->assertFalse($svc->isAvailable());
        $this->assertSame(['contact_id' => 7, 'source' => 'site_contact', 'company_id' => null], $svc->forProperty(1));
        $this->assertFalse($svc->setCompanyQuoteContact(2, 9));
        $this->assertFalse($svc->setPropertyQuoteContact(1, 9));
    }

    // ── CC ──────────────────────────────────────────────────────────────

    public function test_cc_is_the_building_manager_row(): void
    {
        $this->db->exec("INSERT INTO property_contacts (property_id, contact_id, contact_role, is_primary) VALUES (" . self::PROP . ", " . self::DARREN . ", 'manager', 1)");
        $cc = $this->svc()->ccForProperty(self::PROP, self::ALENA, 'alena@vml.example');
        $this->assertSame(self::DARREN, $cc['contact_id']);
        $this->assertSame('darren@vml.example', $cc['email']);
    }

    public function test_on_site_contact_is_copied_only_when_they_are_a_property_manager(): void
    {
        $this->db->exec("INSERT INTO property_contacts (property_id, contact_id, contact_role) VALUES (" . self::PROP . ", " . self::OTHER . ", 'site_supervisor')");
        $this->assertNull($this->svc()->ccForProperty(self::PROP, self::ALENA, 'alena@vml.example'));

        $this->db->exec("UPDATE property_contacts SET contact_id = " . self::DARREN);
        $this->assertSame(self::DARREN, $this->svc()->ccForProperty(self::PROP, self::ALENA, 'alena@vml.example')['contact_id']);
    }

    public function test_firm_primary_contact_is_never_copied_unless_a_property_manager(): void
    {
        // VML's primary contact is Jodi (accounts) — must not be copied on quotes.
        $this->db->exec("UPDATE properties SET property_manager_id = " . self::VML);
        $this->assertNull($this->svc()->ccForProperty(self::PROP, self::ALENA, 'alena@vml.example'));
    }

    public function test_cc_skips_the_to_person_and_people_without_email(): void
    {
        $this->db->exec("INSERT INTO property_contacts (property_id, contact_id, contact_role) VALUES (" . self::PROP . ", " . self::DARREN . ", 'manager')");
        $this->assertNull($this->svc()->ccForProperty(self::PROP, self::DARREN, null), 'same contact as To');
        $this->assertNull($this->svc()->ccForProperty(self::PROP, null, 'DARREN@vml.example'), 'same email as To');
        $this->db->exec("UPDATE contacts SET email = '' WHERE id = " . self::DARREN);
        $this->assertNull($this->svc()->ccForProperty(self::PROP, self::ALENA, 'alena@vml.example'), 'no email');
    }

    public function test_per_quote_untick_switches_cc_off(): void
    {
        $this->db->exec("INSERT INTO property_contacts (property_id, contact_id, contact_role) VALUES (" . self::PROP . ", " . self::DARREN . ", 'manager')");
        $this->db->exec("INSERT INTO quotes (id, property_id, contact_id) VALUES (1, " . self::PROP . ", " . self::ALENA . ")");
        $this->assertSame(['darren@vml.example'], $this->svc()->ccEmailsForQuotes([1], self::ALENA, 'alena@vml.example'));

        $this->assertTrue($this->svc()->setCcEnabled(1, false, 1));
        $this->assertSame([], $this->svc()->ccEmailsForQuotes([1], self::ALENA, 'alena@vml.example'));
    }

    // ── set_recipient never touches contacts ────────────────────────────

    public function test_set_recipient_changes_the_quote_and_never_a_contact(): void
    {
        $this->db->exec("INSERT INTO quotes (id, property_id, contact_id) VALUES (1, " . self::PROP . ", " . self::JODI . ")");
        $before = $this->db->query("SELECT * FROM contacts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        $r = $this->svc()->setRecipient(1, self::ALENA, 1);

        $this->assertTrue($r['ok']);
        $this->assertSame(self::JODI, $r['old_contact_id']);
        $q = $this->db->query("SELECT contact_id, recipient_chosen FROM quotes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(self::ALENA, (int)$q['contact_id']);
        $this->assertSame(1, (int)$q['recipient_chosen']);
        $this->assertSame($before, $this->db->query("SELECT * FROM contacts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_set_recipient_rejects_unknown_contacts_without_writing(): void
    {
        $this->db->exec("INSERT INTO quotes (id, property_id, contact_id) VALUES (1, " . self::PROP . ", " . self::JODI . ")");
        $this->assertFalse($this->svc()->setRecipient(1, 99999, 1)['ok']);
        $this->assertFalse($this->svc()->setRecipient(1, 0, 1)['ok']);
        $this->assertSame(self::JODI, (int)$this->db->query("SELECT contact_id FROM quotes WHERE id = 1")->fetchColumn());
    }

    public function test_picker_offers_company_people_and_the_current_recipient(): void
    {
        $this->db->exec("INSERT INTO quotes (id, property_id, contact_id) VALUES (1, " . self::PROP . ", " . self::JODI . ")");
        $ids = array_column($this->svc()->pickerOptions(['property_id' => self::PROP, 'quote_contact_id' => self::JODI]), 'id');
        $this->assertContains(self::JODI, $ids);
        $this->assertContains(self::ALENA, $ids);
        $this->assertContains(self::DARREN, $ids);
        $this->assertNotContains(self::OTHER, $ids);
    }

    // ── QuoteService::applyChosenRecipient ──────────────────────────────

    private function fetched(array $over): array
    {
        return $over + [
            'quote_contact_id' => self::ALENA, 'site_contact_id' => self::JODI,
            'rc_first_name' => 'Alena', 'rc_last_name' => 'Radosovska', 'rc_email' => 'alena@vml.example', 'rc_phone' => null,
            'contact_id' => self::JODI, 'contact_first' => 'Jodi', 'contact_last' => 'Peacock', 'contact_email' => 'invoices@vml.example', 'contact_phone' => null,
            'qr_contact_id' => null, 'qr_first_name' => null, 'qr_last_name' => null, 'qr_email' => null, 'qr_phone' => null,
        ];
    }

    public function test_a_chosen_recipient_drives_send(): void
    {
        $q = QuoteService::applyChosenRecipient($this->fetched(['recipient_chosen' => 1, 'qr_email' => 'webform@example.com']));
        $svc = new QuoteService($this->db);
        $c = $svc->resolveContact($q);
        $this->assertSame('alena@vml.example', $c['email']);
        $this->assertSame(self::ALENA, $c['contact_id']);
    }

    public function test_legacy_quotes_keep_the_old_ladder(): void
    {
        // After 1186: not chosen → unchanged.
        $q = $this->fetched(['recipient_chosen' => 0]);
        $this->assertSame($q, QuoteService::applyChosenRecipient($q));
        // Before 1186: contact_id equal to the billing contact → unchanged.
        $q = $this->fetched(['quote_contact_id' => self::JODI]);
        $this->assertSame($q, QuoteService::applyChosenRecipient($q));
        // Before 1186: contact_id that differs from the billing contact → it wins.
        $this->assertSame('alena@vml.example', QuoteService::applyChosenRecipient($this->fetched([]))['contact_email']);
    }
}
