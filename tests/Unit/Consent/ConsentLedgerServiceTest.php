<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The consent ledger's decision: unsubscribes win, withdrawals end everything before them,
 * express beats implied, implied lasts 2 years and never covers texts.
 */
class ConsentLedgerServiceTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-05 12:00:00');
    }

    private function row(string $type, string $granted, ?string $expires = null, string $source = 'test', string $key = 'k'): array
    {
        return ['consent_type' => $type, 'granted_at' => $granted, 'expires_at' => $expires, 'source' => $source, 'proof_key' => $key, 'proof_text' => "proof $key"];
    }

    public function test_implied_from_a_job_lasts_two_years(): void
    {
        $r = ConsentLedgerService::evaluate([$this->row('implied', '2025-06-01', '2027-06-01', 'completed_visit', 'visit:9')], false, 'email', $this->now);
        $this->assertTrue($r['ok']);
        $this->assertSame('implied', $r['type']);
        $this->assertSame('completed_visit', $r['source']);
        $this->assertSame('proof visit:9', $r['proof']);
        $expired = ConsentLedgerService::evaluate([$this->row('implied', '2024-06-01', '2026-06-01')], false, 'email', $this->now);
        $this->assertFalse($expired['ok']);
        $this->assertSame('implied consent expired', $expired['reason']);
    }

    public function test_express_beats_implied_and_does_not_expire(): void
    {
        $r = ConsentLedgerService::evaluate([
            $this->row('implied', '2025-06-01', '2027-06-01'),
            $this->row('express', '2019-03-01', null, 'website_form', 'consent_log:4'),
        ], false, 'email', $this->now);
        $this->assertSame('express', $r['type']);
        $this->assertNull($r['expires_at']);
    }

    public function test_unsubscribed_always_blocks(): void
    {
        $r = ConsentLedgerService::evaluate([$this->row('express', '2026-01-01')], true, 'email', $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('unsubscribed', $r['reason']);
    }

    public function test_a_withdrawal_ends_every_consent_before_it_but_not_a_later_opt_in(): void
    {
        $rows = [
            $this->row('express', '2024-01-01'),
            $this->row('implied', '2026-01-01', '2028-01-01'),
            $this->row('withdrawn', '2026-03-01'),
        ];
        $this->assertSame('withdrew consent', ConsentLedgerService::evaluate($rows, false, 'email', $this->now)['reason']);
        $rows[] = $this->row('express', '2026-05-01', null, 'optin_email', 'optin:3');
        $this->assertSame('express', ConsentLedgerService::evaluate($rows, false, 'email', $this->now)['type']);
    }

    public function test_texts_need_express_consent(): void
    {
        $this->assertFalse(ConsentLedgerService::evaluate([$this->row('implied', '2026-01-01', '2028-01-01')], false, 'sms', $this->now)['ok']);
        $this->assertTrue(ConsentLedgerService::evaluate([$this->row('express', '2026-01-01')], false, 'sms', $this->now)['ok']);
    }

    public function test_nothing_on_record_is_a_no(): void
    {
        $r = ConsentLedgerService::evaluate([], false, 'email', $this->now);
        $this->assertFalse($r['ok']);
        $this->assertSame('no consent on record', $r['reason']);
    }

    public function test_bulk_and_expiring_soon_read_the_ledger(): void
    {
        $db = new class extends PDO {
            public function __construct() { parent::__construct('sqlite::memory:'); $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
        };
        $db->exec("CREATE TABLE consent_ledger (contact_id INT, channel TEXT, consent_type TEXT, source TEXT, proof_key TEXT, proof_text TEXT, granted_at TEXT, expires_at TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, email TEXT)");
        $db->exec("CREATE TABLE marketing_unsubscribes (email TEXT)");
        $db->exec("INSERT INTO contacts VALUES (1, 'a@x.com'), (2, ' B@x.com '), (3, 'c@x.com')");
        $db->exec("INSERT INTO marketing_unsubscribes VALUES ('b@x.com')");
        $db->exec("INSERT INTO consent_ledger VALUES
            (1, 'email', 'implied', 'completed_visit', 'visit:1', 'p', '2025-09-10 00:00:00', '2027-09-10 00:00:00'),
            (2, 'email', 'express', 'website_form', 'consent_log:2', 'p', '2025-01-10 00:00:00', NULL),
            (3, 'email', 'implied', 'paid_invoice', 'invoice:3', 'p', '2024-12-01 00:00:00', '2026-12-01 00:00:00')");
        $l = new ConsentLedgerService($db);
        $b = $l->bulk([1, 2, 3, 4], 'email', $this->now);
        $this->assertTrue($b[1]['ok']);
        $this->assertSame('unsubscribed', $b[2]['reason'], 'matched case- and space-insensitively');
        $this->assertTrue($b[3]['ok']);
        $this->assertFalse($b[4]['ok']);
        $this->assertSame([3], array_keys($l->expiringSoon($this->now, 6)), 'only the one running out by April 2027');
    }
}
