<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ContactRoster: someone who left an account isn't written to on that account, and nothing changes without left dates. */
final class ContactRosterTest extends TestCase
{
    private PDO $db;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-11-08');
        $this->db = $this->database(true);
    }

    public function testSomeoneWhoLeftIsFormerOnThatAccountOnly(): void
    {
        $r = new ContactRoster($this->db);
        $this->assertTrue($r->isFormer(1, 10, $this->today));
        $this->assertFalse($r->isFormer(1, 20, $this->today), 'still the contact for another account');
        $this->assertFalse($r->isFormer(2, 10, $this->today));
        $this->assertFalse($r->isFormer(99, 10, $this->today), 'nobody on the roster is not former');
    }

    public function testALeavingDateInTheFutureOrAnotherRunningRoleMeansStillCurrent(): void
    {
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, receives_invoices, ended_on) VALUES (30, 3, 'owner', 1, '2026-12-01')");
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, receives_invoices, ended_on) VALUES (40, 3, 'billing', 1, '2026-01-01'), (40, 3, 'owner', 0, NULL)");
        $r = new ContactRoster($this->db);
        $this->assertFalse($r->isFormer(3, 30, $this->today));
        $this->assertFalse($r->isFormer(3, 40, $this->today));
    }

    public function testCurrentLeavesOutFormerMergedInactiveAndNoEmail(): void
    {
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, receives_invoices) VALUES (10, 4, 'owner', 1), (10, 5, 'owner', 1), (10, 6, 'owner', 1)");
        $ids = array_column((new ContactRoster($this->db))->current(10, 'invoice', $this->today), 'contact_id');
        $this->assertSame([2], $ids);
    }

    public function testThanksAlsoReachesPeopleOnNotificationsAndAnUnknownPurposeIsRefused(): void
    {
        $this->db->exec("INSERT INTO client_contacts (client_id, contact_id, role, receives_notifications) VALUES (50, 2, 'owner', 1)");
        $r = new ContactRoster($this->db);
        $this->assertSame([], $r->current(50, 'invoice', $this->today));
        $this->assertSame([2], array_column($r->current(50, 'thanks', $this->today), 'contact_id'));
        $this->expectException(InvalidArgumentException::class);
        $r->current(10, 'parade', $this->today);
    }

    public function testFirstCurrentReplacesSomeoneWhoLeftWithTheCurrentPerson(): void
    {
        $got = (new ContactRoster($this->db))->firstCurrent([['contact_id' => 1, 'email_address' => 'old@example.test']], 1, 10, 'thanks', $this->today);
        $this->assertSame(2, $got['contact_id']);
        $this->assertSame('new@example.test', $got['email_address']);
        $this->assertTrue($got['replaced']);
    }

    public function testAnInboxWithNoContactIsPassedOverWhenThePersonItWouldGreetLeft(): void
    {
        $got = (new ContactRoster($this->db))->firstCurrent([['contact_id' => 0, 'email_address' => 'accounts@example.test']], 1, 10, 'thanks', $this->today);
        $this->assertSame(2, $got['contact_id']);
    }

    public function testFirstCurrentKeepsTheListWhenNobodyLeft(): void
    {
        $row = ['contact_id' => 2, 'email_address' => 'other@example.test'];
        $this->assertSame($row, (new ContactRoster($this->db))->firstCurrent([['email_address' => 'not an address'], $row], 2, 10, 'thanks', $this->today));
    }

    public function testNobodyToWriteToWhenTheOnlyPersonLeft(): void
    {
        $this->db->exec("UPDATE client_contacts SET ended_on = '2026-10-16' WHERE contact_id = 2");
        $this->assertNull((new ContactRoster($this->db))->firstCurrent([['contact_id' => 1, 'email_address' => 'old@example.test']], 1, 10, 'thanks', $this->today));
    }

    public function testBeforeTheMigrationNothingChanges(): void
    {
        $r = new ContactRoster($this->database(false));
        $row = ['contact_id' => 1, 'email_address' => 'old@example.test'];
        $this->assertFalse($r->isFormer(1, 10, $this->today));
        $this->assertSame($row, $r->firstCurrent([$row], 1, 10, 'thanks', $this->today));
        $this->assertSame([1, 2], array_column($r->current(10, 'invoice', $this->today), 'contact_id'));
    }

    private function database(bool $withDates): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, is_active INT DEFAULT 1, merged_into_id INT)");
        $db->exec("CREATE TABLE client_contacts (id INTEGER PRIMARY KEY, client_id INT, contact_id INT, role TEXT, is_primary INT DEFAULT 0,
            receives_invoices INT DEFAULT 0, receives_notifications INT DEFAULT 0" . ($withDates ? ', ended_on TEXT' : '') . ")");
        $db->exec("INSERT INTO contacts (id, first_name, last_name, email, is_active, merged_into_id) VALUES
            (1, 'Old', 'Rep', 'old@example.test', 1, NULL), (2, 'New', 'Rep', 'new@example.test', 1, NULL), (3, 'Third', 'Person', 'third@example.test', 1, NULL),
            (4, 'Merged', 'Copy', 'merged@example.test', 1, 2), (5, 'Not', 'Active', 'inactive@example.test', 0, NULL), (6, 'No', 'Email', '', 1, NULL)");
        $db->exec("INSERT INTO client_contacts (client_id, contact_id, role, is_primary, receives_invoices" . ($withDates ? ', ended_on' : '') . ") VALUES
            (10, 1, 'owner', 1, 1" . ($withDates ? ", '2026-10-16'" : '') . "), (10, 2, 'owner', 0, 1" . ($withDates ? ', NULL' : '') . "), (20, 1, 'owner', 1, 1" . ($withDates ? ', NULL' : '') . ")");
        return $db;
    }
}
