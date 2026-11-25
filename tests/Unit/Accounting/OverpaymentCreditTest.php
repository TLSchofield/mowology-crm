<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An overpayment kept as client credit (2026-10-14, Alberta 3025 Strata: $165.38 for "Invs 2026-0251 and
 * 0383", 0251 already paid). Tim: "leave his account in credit and it can pay the next invoice".
 */
class OverpaymentCreditTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE client_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INT, type TEXT, amount REAL, invoice_id INT,
                   etransfer_notification_id INT, bank_transaction_id INT, source_note TEXT, created_by INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->exec("CREATE TABLE etransfer_notifications (id INTEGER PRIMARY KEY, sender_name TEXT, amount REAL, allocated_amount REAL DEFAULT 0,
                   reference_number TEXT, email_date TEXT, status TEXT, recorded_invoice_id INT, recorded_by INT, processed_at TEXT)");
        $db->exec("CREATE TABLE invoice_payment_allocations (id INTEGER PRIMARY KEY, invoice_id INT, etransfer_notification_id INT, amount REAL, transaction_id INT)");
        $db->exec("INSERT INTO etransfer_notifications VALUES (31, 'Alberta 3025 Strata', 165.38, 82.69, 'CA6GjAAj', '2026-10-14 07:31:51', 'partially_recorded', 393, 1, NULL)");
        return $db;
    }

    public function test_the_rest_of_a_transfer_becomes_the_clients_credit_and_the_transfer_closes(): void
    {
        $db = $this->db();
        $svc = (new EtransferInboxService($db))->useCreditService((new ClientCreditService($db))->withoutLedger());
        $r = $svc->creditRest(31, 1, 2107);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertEqualsWithDelta(82.69, $r['credited'], 0.001);
        $c = $db->query("SELECT client_id, type, amount, etransfer_notification_id, bank_transaction_id FROM client_credits")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame([2107, 'deposit', 82.69, 31, null], [(int)$c['client_id'], $c['type'], round((float)$c['amount'], 2), (int)$c['etransfer_notification_id'], $c['bank_transaction_id']]);
        $n = $db->query("SELECT status, allocated_amount FROM etransfer_notifications WHERE id = 31")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('recorded', $n['status']);
        $this->assertEqualsWithDelta(165.38, (float)$n['allocated_amount'], 0.001);
        $this->assertEqualsWithDelta(82.69, (new ClientCreditService($db))->getBalance(2107), 0.001, 'it pays the next invoice');

        $again = $svc->creditRest(31, 1, 2107);
        $this->assertFalse($again['ok'], 'pressing twice keeps it once');
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM client_credits")->fetchColumn());
    }

    public function test_no_client_known_is_refused_not_guessed(): void
    {
        $db = $this->db();
        $db->exec("UPDATE etransfer_notifications SET recorded_invoice_id = NULL, allocated_amount = 0, status = 'pending'");
        $svc = (new EtransferInboxService($db))->useCreditService((new ClientCreditService($db))->withoutLedger());
        $r = $svc->creditRest(31, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('so I know whose credit it is', $r['message']);
    }

    public function test_a_deposit_going_away_lets_go_of_its_credit(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO client_credits (client_id, type, amount, etransfer_notification_id, bank_transaction_id) VALUES (2107, 'deposit', 82.69, 31, 9001)");
        ClientCreditService::unlinkDeposits($db, [9001]);
        $this->assertNull($db->query("SELECT bank_transaction_id FROM client_credits")->fetchColumn() ?: null);
        $this->assertEqualsWithDelta(82.69, (new ClientCreditService($db))->getBalance(2107), 0.001, 'still the client\'s');
    }

    public function test_pennys_words_for_an_overpayment(): void
    {
        $say = EtransferDeskService::sayOver('Alberta 3025 Strata', 165.38, ['invoice_number' => 'INV-2026-0383', 'apply_amount' => 82.69], 82.69, ['INV-2026-0251 (paid Aug 8)']);
        $this->assertSame('Alberta 3025 Strata sent $165.38: $82.69 pays INV-2026-0383 and $82.69 is extra — they named INV-2026-0251 (paid Aug 8), already paid. '
                        . 'Record it and keep $82.69 as credit for their next invoice? (A refund is yours to send.)', $say);
    }

    public function test_every_chaser_pauses_for_any_invoice_the_memo_names(): void
    {
        $sql = PaymentOnTheWay::sql('i');
        $this->assertStringContainsString("en.status IN ('pending', 'partially_recorded')", $sql);
        $this->assertStringContainsString('en.matched_invoice_id = i.id', $sql);
        $this->assertStringContainsString('FIND_IN_SET(CONVERT(i.invoice_number USING utf8mb4) COLLATE utf8mb4_general_ci', $sql, 'one collation: production mixes two');
        $this->assertStringNotContainsString('invoice_hints', PaymentOnTheWay::sql('i', false), 'before migration 1363');
        foreach (['app/Modules/Marketing/Cron/invoice_reminders.php', 'app/Modules/Marketing/Cron/invoice_overdue.php',
                  'app/Modules/Marketing/Cron/automation_runner.php', 'app/Modules/Invoices/Cron/autopay_charge.php'] as $f) {
            $src = file_get_contents(dirname(__DIR__, 3) . '/' . $f);
            $this->assertStringContainsString('PaymentOnTheWay::sql(', $src, $f);
            $this->assertStringNotContainsString('WHERE en.matched_invoice_id = i.id', $src, $f . ' has its own copy again');
        }
    }
}
