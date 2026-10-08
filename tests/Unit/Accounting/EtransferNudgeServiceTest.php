<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's one-time "please e-Transfer to info@" thank-you (migration 1301).
 * SQLite in-memory; the mailer and the invoice→recipient resolver are injected.
 */
class EtransferNudgeServiceTest extends TestCase
{
    private PDO $db;
    /** @var array<int,array{to:string,subject:string,html:string}> */
    private array $sent = [];
    private bool $mailOk = true;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $now = fn() => date('Y-m-d H:i:s');
        if (method_exists($db, 'createFunction')) $db->createFunction('NOW', $now, 0); else $db->sqliteCreateFunction('NOW', $now, 0);

        $db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
        $db->exec("INSERT INTO ops_settings VALUES ('penny_etransfer_nudge_enabled', '1')");
        $db->exec("CREATE TABLE etransfer_notifications (id INTEGER PRIMARY KEY, source TEXT DEFAULT 'interac', sender_name TEXT, amount REAL,
                   memo TEXT, invoice_hint TEXT, transfer_type TEXT, email_date TEXT, matched_invoice_id INT, match_confidence TEXT,
                   status TEXT DEFAULT 'pending', recorded_invoice_id INT)");
        $db->exec("CREATE TABLE etransfer_address_nudges (id INTEGER PRIMARY KEY AUTOINCREMENT, notification_id INT, contact_id INT, email TEXT,
                   email_key TEXT UNIQUE, sender_key TEXT, sender_name TEXT, invoice_id INT, invoice_number TEXT, amount REAL,
                   trigger_source TEXT, status TEXT, sent_at TEXT, created_at TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY, invoice_number TEXT)");
        $db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT)");
        $db->exec("CREATE TABLE communication_log (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INT, type TEXT, direction TEXT, subject TEXT,
                   message TEXT, to_email TEXT, status TEXT, created_by INT, created_at TEXT)");

        $db->exec("INSERT INTO invoices VALUES (425, 'INV-2026-0425'), (500, 'INV-2026-0500')");
        $db->exec("INSERT INTO contacts VALUES (7, 'Dana'), (8, '')");
        $this->db = $db;
        $this->sent = [];
        $this->mailOk = true;
    }

    /** Invoice 425 → Dana (contact 7); invoice 500 → strata inbox contact 8 with no first name. */
    private function svc(): EtransferNudgeService
    {
        $recipients = [
            425 => [['contact_id' => 7, 'email_address' => 'dana@example.com']],
            500 => [['contact_id' => 8, 'email_address' => 'accounts@strata4079.example']],
        ];
        return new EtransferNudgeService(
            $this->db,
            function (string $to, string $subject, string $html): bool {
                $this->sent[] = compact('to', 'subject', 'html');
                return $this->mailOk;
            },
            fn(int $invoiceId) => $recipients[$invoiceId] ?? []
        );
    }

    private function note(int $id, array $f = []): int
    {
        $row = $f + ['sender_name' => 'STRATA BCS4079', 'amount' => 262.50, 'memo' => 'invoice 2026 0425', 'invoice_hint' => 'INV-2026-0425',
                     'transfer_type' => 'claim', 'email_date' => date('Y-m-d H:i:s'), 'matched_invoice_id' => 425,
                     'match_confidence' => 'high', 'status' => 'pending', 'recorded_invoice_id' => null, 'source' => 'interac'];
        $this->db->prepare("INSERT INTO etransfer_notifications (id, source, sender_name, amount, memo, invoice_hint, transfer_type, email_date,
                            matched_invoice_id, match_confidence, status, recorded_invoice_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$id, $row['source'], $row['sender_name'], $row['amount'], $row['memo'], $row['invoice_hint'], $row['transfer_type'],
                       $row['email_date'], $row['matched_invoice_id'], $row['match_confidence'], $row['status'], $row['recorded_invoice_id']]);
        return $id;
    }

    public function testAutodepositNeverSends(): void
    {
        $r = $this->svc()->onNotification($this->note(1, ['transfer_type' => 'autodeposit']));
        $this->assertFalse($r['sent']);
        $this->assertSame('not_manual', $r['reason']);
        $this->assertSame([], $this->sent);
    }

    public function testUnknownTypeAndYardiNeverSend(): void
    {
        $this->assertSame('not_manual', $this->svc()->onNotification($this->note(1, ['transfer_type' => 'unknown']))['reason']);
        $this->assertSame('not_manual', $this->svc()->onNotification($this->note(2, ['source' => 'yardi_eft']))['reason']);
        $this->assertSame([], $this->sent);
    }

    public function testManualKnownPayerSendsOnceAndLogs(): void
    {
        $r = $this->svc()->onNotification($this->note(1));
        $this->assertTrue($r['sent']);
        $this->assertCount(1, $this->sent);
        $this->assertSame('dana@example.com', $this->sent[0]['to']);
        $this->assertSame('Thank you for your payment — INV-2026-0425', $this->sent[0]['subject']);
        $this->assertStringContainsString('Hi Dana,', $this->sent[0]['html']);
        $this->assertStringContainsString('as you did this time', $this->sent[0]['html']);

        $log = $this->db->query("SELECT * FROM etransfer_address_nudges")->fetchAll();
        $this->assertCount(1, $log);
        $this->assertSame('sent', $log[0]['status']);
        $this->assertSame(1, (int)$log[0]['notification_id']);
        $this->assertSame(7, (int)$log[0]['contact_id']);
        $this->assertNotEmpty($log[0]['sent_at']);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM communication_log WHERE contact_id = 7")->fetchColumn());
        $this->assertSame(1, $this->svc()->countSent());
    }

    public function testSecondManualTransferSamePayerDoesNotSend(): void
    {
        $this->svc()->onNotification($this->note(1));
        $r = $this->svc()->onNotification($this->note(2, ['amount' => 100.00, 'memo' => 'thanks', 'invoice_hint' => null]));
        $this->assertFalse($r['sent']);
        $this->assertSame('already_asked', $r['reason']);
        $this->assertCount(1, $this->sent);
    }

    public function testUnknownPayerIsDeferredUntilRecorded(): void
    {
        $id = $this->note(1, ['memo' => 'lawn', 'invoice_hint' => null, 'matched_invoice_id' => null, 'match_confidence' => 'none']);
        $r = $this->svc()->onNotification($id, 'ingest');
        $this->assertFalse($r['sent']);
        $this->assertSame('payer_unknown', $r['reason']);
        $this->assertSame([], $this->sent);

        // The owner records it against INV-2026-0425 → now Penny knows who paid.
        $this->db->exec("UPDATE etransfer_notifications SET status = 'recorded', recorded_invoice_id = 425 WHERE id = 1");
        $r = $this->svc()->onNotification($id, 'recorded');
        $this->assertTrue($r['sent']);
        $this->assertCount(1, $this->sent);
        // Memo had no invoice number → ask for it rather than "as you did this time".
        $this->assertStringNotContainsString('as you did this time', $this->sent[0]['html']);
        $this->assertStringContainsString('If you can add the invoice number', $this->sent[0]['html']);
        $this->assertSame('recorded', $this->db->query("SELECT trigger_source FROM etransfer_address_nudges")->fetchColumn());
    }

    public function testMediumAmountOnlyMatchIsNotEnoughToIdentify(): void
    {
        $r = $this->svc()->onNotification($this->note(1, ['invoice_hint' => null, 'memo' => '', 'match_confidence' => 'medium']));
        $this->assertSame('payer_unknown', $r['reason']);
    }

    public function testLearnedSenderUsesNoInvoiceVariant(): void
    {
        // An earlier transfer from this sender was recorded against invoice 500.
        $this->note(1, ['transfer_type' => 'autodeposit', 'status' => 'recorded', 'recorded_invoice_id' => 500,
                        'email_date' => date('Y-m-d H:i:s', time() - 86400 * 10)]);
        $r = $this->svc()->onNotification($this->note(2, ['memo' => 'fall cleanup', 'invoice_hint' => null,
                                                          'matched_invoice_id' => null, 'match_confidence' => 'none']));
        $this->assertTrue($r['sent']);
        $this->assertSame('Thank you for your payment', $this->sent[0]['subject']);
        $this->assertStringContainsString('Hi there,', $this->sent[0]['html']);
    }

    public function testSenderWhoAlreadySwitchedToAutodepositIsLeftAlone(): void
    {
        $this->note(1, ['transfer_type' => 'autodeposit', 'email_date' => date('Y-m-d H:i:s', time() + 60)]);
        $this->assertSame('already_uses_autodeposit', $this->svc()->onNotification($this->note(2))['reason']);
    }

    public function testKillSwitch(): void
    {
        $this->db->exec("UPDATE ops_settings SET setting_value = '0'");
        $r = $this->svc()->onNotification($this->note(1));
        $this->assertSame('switched_off', $r['reason']);
        $this->assertSame([], $this->sent);
    }

    public function testNoEmailOnFileAndBusinessItself(): void
    {
        $this->db->exec("INSERT INTO invoices VALUES (600, 'INV-2026-0600')");
        $this->assertSame('no_email', $this->svc()->onNotification($this->note(1, ['matched_invoice_id' => 600]))['reason']);
        $this->assertSame('business_itself', $this->svc()->onNotification($this->note(2, ['sender_name' => 'MOWOLOGY LAWNS']))['reason']);
        $this->assertTrue(EtransferNudgeService::isBusinessEmail('info@mowology.ca'));
        $this->assertFalse(EtransferNudgeService::isBusinessEmail('dana@example.com'));
    }

    public function testFailedSendReleasesTheClaimSoItCanRetry(): void
    {
        $this->mailOk = false;
        $this->assertSame('send_failed', $this->svc()->onNotification($this->note(1))['reason']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM etransfer_address_nudges")->fetchColumn());
        $this->mailOk = true;
        $this->assertTrue($this->svc()->onNotification(1)['sent']);
    }

    public function testOldTransferIsNotThanked(): void
    {
        $r = $this->svc()->onNotification($this->note(1, ['email_date' => date('Y-m-d H:i:s', time() - 86400 * 45)]));
        $this->assertSame('too_old', $r['reason']);
    }

    public function testNoLogTableMeansNoSend(): void
    {
        $this->db->exec("DROP TABLE etransfer_address_nudges");
        $r = $this->svc()->onNotification($this->note(1));
        $this->assertFalse($r['sent']);
        $this->assertSame([], $this->sent);
    }

    // ── Copy ──────────────────────────────────────────────────────────────

    public function testRenderWithInvoiceExactCopy(): void
    {
        $m = EtransferNudgeService::render('Dana', 262.5, 'INV-2026-0425', true);
        $this->assertSame('Thank you for your payment — INV-2026-0425', $m['subject']);
        $this->assertSame(
            "Hi Dana,\n\nThank you for your e-Transfer of \$262.50 for INV-2026-0425. It's received.\n\n"
            . "One small favour for next time. Could you update the recipient in your banking to info@mowology.ca? That address has Auto-deposit turned on, so there's no security question or password to set. Your payment lands straight away and we can match it to your invoice faster.\n\n"
            . "Please keep the invoice number in the message, as you did this time. It's the quickest way for us to match it.\n\n"
            . "Thank you,\nPenny\nMowology Lawns & Landscapes\n(778) 846-9273",
            $m['text']
        );
    }

    public function testRenderWithoutInvoiceExactCopy(): void
    {
        $m = EtransferNudgeService::render('Dana', 1234.5, null, false);
        $this->assertSame('Thank you for your payment', $m['subject']);
        $this->assertSame(
            "Hi Dana,\n\nThank you for your e-Transfer of \$1,234.50. It's received.\n\n"
            . "One small favour for next time. Could you update the recipient in your banking to info@mowology.ca? That address has Auto-deposit turned on, so there's no security question or password to set, and your payment lands straight away.\n\n"
            . "If you can add the invoice number in the message, we'll match it to the right invoice faster.\n\n"
            . "Thank you,\nPenny\nMowology Lawns & Landscapes\n(778) 846-9273",
            $m['text']
        );
    }

    public function testRenderNoFirstNameFallsBackToThere(): void
    {
        $this->assertStringStartsWith('Hi there,', EtransferNudgeService::render('', 10, 'INV-2026-0001', true)['text']);
        $this->assertStringStartsWith('Hi there,', EtransferNudgeService::render('  ', 10, null, false)['text']);
    }

    public function testHtmlEscapesAndKeepsParagraphs(): void
    {
        $html = EtransferNudgeService::toHtml("Hi <b>x</b>,\n\nLine one\nLine two");
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertSame(2, substr_count($html, '<p '));
        $this->assertStringContainsString('Line one<br />', $html);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->note(1);
        $this->note(2, ['transfer_type' => 'autodeposit', 'sender_name' => 'SOMEONE ELSE']);
        // SQLite has no DATE_SUB — the dry-run's date window is MySQL; exercise plan() instead.
        $plan = $this->svc()->plan($this->db->query("SELECT * FROM etransfer_notifications WHERE id = 1")->fetch());
        $this->assertTrue($plan['send']);
        $this->assertSame('dana@example.com', $plan['to']);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM etransfer_address_nudges")->fetchColumn());
    }
}
