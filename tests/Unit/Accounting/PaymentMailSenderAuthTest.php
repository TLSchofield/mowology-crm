<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Mail/MailAuth.php';
require_once __DIR__ . '/../../../app/Modules/Comms/Services/SupplierInboxService.php';

/** Bank deposit lookup without MySQL date functions: always "the one deposit is #77". */
class YardiBankedDouble extends YardiEftInboxService
{
    public function bankDeposit(float $amount, string $date): ?int
    {
        return 77;
    }
}

/**
 * Security review #4/#5/#20 (2026-10-12): a forged Yardi remittance or Interac notice must never be
 * recorded without a person, and an unproven supplier email never imports a price list.
 */
class PaymentMailSenderAuthTest extends TestCase
{
    private function db(bool $withColumn = true): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $now = static fn() => '2026-10-12 09:00:00';
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);
        $db->exec("CREATE TABLE etransfer_notifications (id INTEGER PRIMARY KEY, mailbox TEXT, source TEXT, dedup_key TEXT UNIQUE,
                   reference_number TEXT, message_id TEXT, sender_name TEXT, amount REAL, allocated_amount REAL, memo TEXT,
                   invoice_hint TEXT, transfer_type TEXT, email_subject TEXT, email_date TEXT, matched_invoice_id INTEGER,
                   match_method TEXT, match_confidence TEXT, status TEXT, recorded_invoice_id INTEGER, recorded_by INTEGER,
                   bank_transaction_id INTEGER, bank_match_confidence INTEGER, created_at TEXT, processed_at TEXT"
                   . ($withColumn ? ", sender_verified INTEGER" : "") . ")");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT, balance_due REAL, total REAL, status TEXT)");
        $db->exec("INSERT INTO invoices VALUES (5, 'INV-2026-0005', 420.00, 420.00, 'sent')");
        return $db;
    }

    private function remittance(): array
    {
        return ['transaction_reference' => 'EFT-991', 'transaction_date' => '2026-10-10', 'total' => 420.00,
                'lines' => [['property' => 'Dorset', 'invoice_number' => 'INV-2026-0005', 'invoice_date' => null, 'amount' => 420.00]]];
    }

    public function test_forged_yardi_remittance_is_never_recorded_even_with_a_banked_deposit(): void
    {
        $db = $this->db();
        $svc = new YardiBankedDouble($db);
        $r = $svc->ingest($this->remittance(), 'office@', '<m1>', 'EFT Payment', '2026-10-10', 1, false);
        $this->assertSame(0, $r['auto_recorded']);
        $this->assertSame(1, $r['pending']);
        $row = $db->query("SELECT match_confidence, sender_verified, memo FROM etransfer_notifications")->fetch();
        $this->assertSame('medium', $row['match_confidence']);
        $this->assertSame(0, (int)$row['sender_verified']);
        $this->assertStringContainsString('not verified', $row['memo']);
        $this->assertSame(0, $svc->recordWhenBanked(1), 'an unproven line never records when its deposit lands');
    }

    public function test_proven_remittance_waiting_for_its_deposit_is_high_and_flagged(): void
    {
        $db = $this->db();
        $svc = new YardiEftInboxService($db);   // real bankDeposit is never reached: no deposit query needed when it waits
        $svc2 = new class($db) extends YardiEftInboxService { public function bankDeposit(float $a, string $d): ?int { return null; } };
        $r = $svc2->ingest($this->remittance(), 'office@', '<m2>', 'EFT Payment', '2026-10-10', 1, true);
        $this->assertSame(1, $r['pending']);
        $row = $db->query("SELECT match_confidence, sender_verified FROM etransfer_notifications")->fetch();
        $this->assertSame('high', $row['match_confidence']);
        $this->assertSame(1, (int)$row['sender_verified']);
        unset($svc);
    }

    public function test_legacy_high_rows_without_proof_are_not_recorded_when_banked(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO etransfer_notifications (source, dedup_key, reference_number, amount, email_date, matched_invoice_id,
                   match_confidence, status, sender_verified) VALUES ('yardi_eft', 'yardi:X:INV-2026-0005', 'X', 420, '2026-10-10', 5, 'high', 'pending', NULL)");
        $this->assertSame(0, (new YardiBankedDouble($db))->recordWhenBanked(1));
    }

    public function test_before_migration_1340_nothing_auto_records(): void
    {
        $db = $this->db(false);
        $svc = new YardiBankedDouble($db);
        $r = $svc->ingest($this->remittance(), 'office@', '<m3>', 'EFT Payment', '2026-10-10', 1, false);
        $this->assertSame(0, $r['auto_recorded']);
        $this->assertSame(0, $svc->recordWhenBanked(1));
        $this->assertSame(0, (new EtransferInboxService($db))->autoRecordFullyCertain(1)['checked']);
    }

    public function test_interac_confidence_is_capped_unless_the_sender_is_proven(): void
    {
        $this->assertSame('high', EtransferInboxService::cappedConfidence('high', 'interac', true));
        $this->assertSame('medium', EtransferInboxService::cappedConfidence('high', 'interac', false), 'From header alone');
        $this->assertSame('medium', EtransferInboxService::cappedConfidence('high', 'forwarded', true), 'forwards are capped at medium');
        $this->assertSame('medium', EtransferInboxService::cappedConfidence('high', 'untrusted', false));
        $this->assertSame('low', EtransferInboxService::cappedConfidence('low', 'untrusted', false));
    }

    public function test_auto_record_only_takes_proven_notices(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO invoices VALUES (6, 'INV-2026-0006', 100.00, 100.00, 'sent')");
        $db->exec("INSERT INTO etransfer_notifications (dedup_key, amount, matched_invoice_id, match_method, match_confidence, status,
                   bank_transaction_id, bank_match_confidence, sender_verified) VALUES
                   ('a', 100, 6, 'invoice_number', 'high', 'pending', 9, 95, 0),
                   ('b', 100, 6, 'invoice_number', 'high', 'pending', 9, 95, NULL)");
        $svc = $this->getMockBuilder(EtransferInboxService::class)->setConstructorArgs([$db])->onlyMethods(['recordPayment'])->getMock();
        $svc->expects($this->never())->method('recordPayment');
        $this->assertSame(0, $svc->autoRecordFullyCertain(1)['checked']);
    }

    public function test_price_list_import_fails_closed_without_proof(): void
    {
        // No Authentication-Results and no DKIM signature: forms still read it (authFailed('') is false),
        // a price list does not.
        $this->assertFalse(SupplierInboxService::authFailed(''));
        $raw = "From: Inline Nurseries <news@inlinenurseries.com>\r\nSubject: Availability\r\n\r\n";
        $auth = MailAuth::verify($raw, "list\r\n", 'news@inlinenurseries.com', fn($n) => [], []);
        $this->assertFalse(SupplierInboxService::listImportAllowed($auth));
        $this->assertTrue(SupplierInboxService::listImportAllowed(['ok' => true]));
    }
}
