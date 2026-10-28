<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The facts behind "has Pat paid?" are worked out in code, not guessed by the model. The first fixture mirrors a real
 * account (names changed): two big invoices paid by e-transfer, five small ones overdue (four have used all three
 * reminders), three not yet due, a card on file but no autopay, small invoices historically paid within hours of a
 * reminder. The old Ask Charlie only saw the 8 newest invoices and reported $302.40; the true total is $453.60.
 */
class CharliePaymentStoryTest extends TestCase
{
    private const TODAY = '2026-10-28';

    private static function inv(string $no, float $bal, string $due, int $rem, int $views = 0, ?string $lastRem = null, array $more = []): array
    {
        return $more + ['id' => (int)substr($no, -4), 'invoice_number' => $no, 'total' => $bal, 'balance' => $bal, 'due_date' => $due,
                        'reminder_count' => $rem, 'last_reminder_sent_at' => $lastRem, 'view_count' => $views, 'email_opened_at' => '2026-09-01 10:00:00'];
    }

    private static function patRivera(array $over = []): array
    {
        return array_replace_recursive([
            'name' => 'Pat Rivera', 'first' => 'Pat', 'today' => self::TODAY,
            'unpaid' => [
                self::inv('INV-0274', 50.40, '2026-08-22', 3, 0, '2026-10-01 06:00:23'),
                self::inv('INV-0293', 50.40, '2026-08-30', 3, 0, '2026-10-01 06:00:27'),
                self::inv('INV-0338', 50.40, '2026-09-20', 3, 0, '2026-10-01 06:00:49'),
                self::inv('INV-0397', 50.40, '2026-10-17', 3, 1, '2026-10-22 06:00:17'),
                self::inv('INV-0408', 50.40, '2026-10-24', 2, 1, '2026-10-25 06:00:16'),
                self::inv('INV-0444', 50.40, '2026-11-07', 0, 2),
                self::inv('INV-0460', 100.80, '2026-11-15', 0),
                self::inv('INV-0463', 50.40, '2026-11-21', 0, 1),
            ],
            'paid' => [
                ['invoice_number' => 'INV-0474', 'total' => 1853.25, 'paid_at' => '2026-10-23 12:00:00', 'method' => 'e_transfer', 'last_reminder_sent_at' => null],
                ['invoice_number' => 'INV-0465', 'total' => 1890.00, 'paid_at' => '2026-10-23 12:00:00', 'method' => 'e_transfer', 'last_reminder_sent_at' => null],
                ['invoice_number' => 'INV-0391', 'total' => 50.40, 'paid_at' => '2026-10-08 08:21:50', 'method' => 'stripe', 'last_reminder_sent_at' => '2026-10-08 06:00:14'],
                ['invoice_number' => 'INV-0325', 'total' => 50.40, 'paid_at' => '2026-09-23 08:00:39', 'method' => 'stripe', 'last_reminder_sent_at' => '2026-09-23 06:01:13'],
            ],
            'card' => ['brand' => 'visa', 'last4' => '5760', 'has_pm' => true, 'autopay_enabled' => false], 'live_mode' => true,
            'messages' => [], 'recipients' => ['emails' => ['pat@example.test'], 'bounced' => []],
        ], $over);
    }

    private static function text(array $story): string
    {
        return implode("\n", $story['signals']);
    }

    public function testTotalsAreRightEvenWithMoreThanEightInvoices(): void
    {
        $s = CharliePaymentStory::build(self::patRivera());
        $this->assertSame(8, $s['owing']['count']);
        $this->assertSame(453.60, $s['owing']['total']);
        $this->assertSame(5, $s['owing']['overdue_count']);
        $this->assertSame(252.0, $s['owing']['overdue_total']);
        $this->assertSame(3, $s['owing']['not_due_count']);
        $this->assertSame(201.6, $s['owing']['not_due_total']);
        $this->assertSame('2026-08-22', $s['owing']['oldest_due']);
        $this->assertCount(5, $s['overdue']);
        $this->assertStringContainsString('$453.60 across 8 invoices', self::text($s));
    }

    public function testExplainsWhyThroughSignals(): void
    {
        $t = self::text(CharliePaymentStory::build(self::patRivera(['messages' => ['last_in_at' => null, 'last_in_says_paid' => false]])));
        $this->assertStringContainsString('5 of the 5 overdue invoices have had a reminder', $t);
        $this->assertStringContainsString('4 have used all 3 reminders, so nothing more goes out automatically', $t);
        $this->assertStringContainsString('3 of the overdue invoices were never opened on the invoice page', $t);
        $this->assertStringContainsString('within a day of a reminder going out', $t);
        $this->assertStringContainsString('did pay the larger invoices ($1,853.25 and $1,890) by e-transfer on Oct 23', $t);
        $this->assertStringContainsString('Visa ending 5760 is on file, but Pat is not enrolled in autopay', $t);
        $this->assertStringContainsString('has not written to us', $t);
    }

    public function testNoPronounsAreEverGuessed(): void
    {
        foreach ([self::patRivera(), self::patRivera(['messages' => ['last_in_at' => '2026-10-03 09:00:00', 'last_in_says_paid' => true]])] as $in) {
            $this->assertDoesNotMatchRegularExpression('/\b(he|she|his|her|him|hers)\b/i', self::text(CharliePaymentStory::build($in)));
        }
    }

    public function testNoReminderYet(): void
    {
        $in = self::patRivera();
        $in['unpaid'] = [self::inv('INV-0500', 80.0, '2026-10-20', 0)];
        $this->assertStringContainsString('No reminder has gone out on any of the overdue invoices yet.', self::text(CharliePaymentStory::build($in)));
    }

    public function testNoCardAndBouncedEmailAndClaim(): void
    {
        $in = self::patRivera();
        $in['card'] = [];
        $in['recipients'] = ['emails' => ['old@example.test'], 'bounced' => ['old@example.test']];
        $in['messages'] = ['last_in_at' => '2026-10-03 09:00:00', 'last_in_says_paid' => true];
        $t = self::text(CharliePaymentStory::build($in));
        $this->assertStringContainsString('no card on file and autopay is off', $t);
        $this->assertStringContainsString('Invoice emails to old@example.test bounced', $t);
        $this->assertStringContainsString('wrote on Oct 3 saying they had paid; no matching payment is recorded', $t);
    }

    public function testAutopayOnButChargingSwitchedOff(): void
    {
        $in = self::patRivera(['card' => ['autopay_enabled' => true], 'live_mode' => false]);
        $this->assertStringContainsString('Autopay is on with Visa ending 5760 on file, but automatic charging is switched off', self::text(CharliePaymentStory::build($in)));
    }

    public function testPendingEtransferDisputeAndFailedAutopay(): void
    {
        $in = self::patRivera();
        $in['unpaid'] = [self::inv('INV-0600', 90.0, '2026-10-10', 1, 0, '2026-10-12 06:00:00', ['pending_etransfer' => true, 'dispute_status' => 'open', 'autopay_status' => 'failed', 'autopay_failure' => 'card_declined'])];
        $t = self::text(CharliePaymentStory::build($in));
        $this->assertStringContainsString('e-transfer for 1 of the invoices is waiting to be recorded', $t);
        $this->assertStringContainsString('disputed (open)', $t);
        $this->assertStringContainsString('automatic charge failed (card_declined)', $t);
    }

    public function testNothingOutstanding(): void
    {
        $s = CharliePaymentStory::build(['name' => 'Sam Lee', 'today' => self::TODAY, 'unpaid' => []]);
        $this->assertSame(0, $s['owing']['count']);
        $this->assertSame(['Sam Lee has nothing outstanding.'], $s['signals']);
    }

    // ── DB loader ───────────────────────────────────────────────────────────

    private function db(bool $full = true): PDO
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, contact_id INT, company_id INT, invoice_number TEXT, total REAL, total_amount REAL, balance_due REAL, due_date TEXT, status TEXT,
                   reminder_count INT DEFAULT 0, first_reminder_sent_at TEXT, last_reminder_sent_at TEXT, view_count INT DEFAULT 0, email_opened_at TEXT, dispute_status TEXT,
                   paid_at TEXT, payment_method TEXT)");
        if ($full) {
            $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, stripe_card_brand TEXT, stripe_card_last4 TEXT, stripe_payment_method_id TEXT, autopay_enabled INT DEFAULT 0)");
            $db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, stripe_card_brand TEXT, stripe_card_last4 TEXT, stripe_payment_method_id TEXT, autopay_enabled INT DEFAULT 0)");
            $db->exec("CREATE TABLE invoice_contacts (invoice_id INT, email_address TEXT, bounced INT DEFAULT 0, email_bounced INT DEFAULT 0)");
            $db->exec("CREATE TABLE etransfer_notifications (matched_invoice_id INT, status TEXT)");
            $db->exec("CREATE TABLE autopay_attempts (id INTEGER PRIMARY KEY, invoice_id INT, status TEXT, failure_code TEXT)");
            $db->exec("CREATE TABLE business_settings (autopay_live_mode INT)");
            $db->exec("CREATE TABLE sales_messages (contact_id INT, direction TEXT, sent_at TEXT, snippet TEXT)");
        }
        return $db;
    }

    public function testLoaderBuildsTheStoryFromTables(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO contacts VALUES (7, 'visa', '5760', 'pm_x', 0)");
        $db->exec("INSERT INTO business_settings VALUES (1)");
        $ins = $db->prepare("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status, reminder_count, last_reminder_sent_at, view_count) VALUES (?, 7, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([1, 'INV-1', 50.4, 50.4, '2026-10-17', 'overdue', 3, '2026-10-22 06:00:00', 0]);
        $ins->execute([2, 'INV-2', 50.4, 50.4, '2026-11-21', 'sent', 0, null, 0]);
        $db->exec("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status, paid_at, payment_method) VALUES (3, 7, 'INV-3', 1890, 0, '2026-11-21', 'paid', '2026-10-23 12:00:00', 'e_transfer')");
        $db->exec("INSERT INTO invoice_contacts VALUES (1, 'pat@example.test', 0, 0), (2, 'pat@example.test', 0, 0)");
        $db->exec("INSERT INTO sales_messages VALUES (7, 'inbound', '2026-10-20 10:00:00', 'Thanks for the update')");

        $s = CharliePaymentStory::load($db, ['kind' => 'contact', 'id' => 7, 'name' => 'Pat Rivera', 'first' => 'Pat'], self::TODAY);
        $this->assertSame(2, $s['owing']['count']);
        $this->assertSame(100.8, $s['owing']['total']);
        $t = self::text($s);
        $this->assertStringContainsString('used all 3 reminders', $t);
        $this->assertStringContainsString('Visa ending 5760 is on file, but Pat is not enrolled in autopay', $t);
        $this->assertStringContainsString('The last message from Pat was on Oct 20', $t);
    }

    public function testMissingTablesJustLeaveFactsOut(): void
    {
        $db = $this->db(false);
        $db->exec("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status, reminder_count) VALUES (1, 7, 'INV-1', 80, 80, '2026-10-10', 'overdue', 1)");
        $s = CharliePaymentStory::load($db, ['kind' => 'contact', 'id' => 7, 'name' => 'Pat Rivera', 'first' => 'Pat'], self::TODAY);
        $this->assertSame(80.0, $s['owing']['total']);
        $this->assertNotEmpty($s['signals']);
    }

    public function testForMatchesPicksAccountsWithBalancesLargestFirst(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status) VALUES (1, 1, 'A', 10, 10, '2026-10-10', 'overdue'), (2, 2, 'B', 500, 500, '2026-10-10', 'overdue')");
        $ctx = ['contacts' => [['id' => 1, 'first_name' => 'Ann', 'last_name' => 'A'], ['id' => 2, 'first_name' => 'Bo', 'last_name' => 'B'], ['id' => 3, 'first_name' => 'Cy', 'last_name' => 'C']]];
        $stories = CharliePaymentStory::forMatches($db, $ctx, self::TODAY);
        $this->assertSame(['Bo B', 'Ann A'], array_column($stories, 'account'), 'Cy has nothing owing and is left out; the largest balance comes first');
    }

    public function testNoClaimAboutMessagesWhenNoneWereLooked(): void
    {
        $t = self::text(CharliePaymentStory::build(self::patRivera()));   // messages => [] (a company, or no messages table)
        $this->assertStringNotContainsString('written to us', $t);
        $this->assertStringNotContainsString('last message', $t);
    }

    public function testAPaidInvoiceWithAStaleBalanceIsNotOwed(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status) VALUES (1, 7, 'A', 50, 50, '2026-10-01', 'paid'), (2, 7, 'B', 20, 20, '2026-10-01', 'overdue')");
        $s = CharliePaymentStory::load($db, ['kind' => 'contact', 'id' => 7, 'name' => 'Pat Rivera', 'first' => 'Pat'], self::TODAY);
        $this->assertSame(20.0, $s['owing']['total']);
    }

    public function testMoreThanSixtyInvoicesAreStillCountedAndSaid(): void
    {
        $db = $this->db();
        $ins = $db->prepare("INSERT INTO invoices (id, contact_id, invoice_number, total, balance_due, due_date, status) VALUES (?, 7, ?, 10, 10, '2026-10-01', 'overdue')");
        for ($i = 1; $i <= 70; $i++) $ins->execute([$i, "INV-$i"]);
        $s = CharliePaymentStory::load($db, ['kind' => 'contact', 'id' => 7, 'name' => 'Pat Rivera', 'first' => 'Pat'], self::TODAY);
        $this->assertSame(70, $s['owing']['count'], 'no silent cap at 60');
        $this->assertSame(700.0, $s['owing']['total']);
    }

    public function testAContactAndTheirCompanyAreNotTwoDebtors(): void
    {
        $db = $this->db();
        // The same invoice carries both ids; a second, company-only invoice makes the company the bigger story.
        $db->exec("INSERT INTO invoices (id, contact_id, company_id, invoice_number, total, balance_due, due_date, status) VALUES (1, 1, 9, 'A', 100, 100, '2026-10-01', 'overdue'), (2, NULL, 9, 'B', 400, 400, '2026-10-01', 'overdue')");
        $ctx = ['contacts' => [['id' => 1, 'first_name' => 'Ann', 'last_name' => 'A']], 'companies' => [['id' => 9, 'company_name' => 'Acme Strata']]];
        $stories = CharliePaymentStory::forMatches($db, $ctx, self::TODAY);
        $this->assertSame(['Acme Strata'], array_column($stories, 'account'), "Ann's only invoice is already inside Acme's, so it is not reported again");
        $this->assertArrayNotHasKey('_invoice_ids', $stories[0]);
    }
}
