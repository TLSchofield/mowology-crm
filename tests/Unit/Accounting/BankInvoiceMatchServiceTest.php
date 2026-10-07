<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Penny's "Invoice payment" on a bank line: which already-recorded payments are this
 * deposit, which credits look like a client paying, and scoring a TD e-Transfer whose
 * memo carries only OUR name by the Interac email's sender instead.
 */
class BankInvoiceMatchServiceTest extends TestCase
{
    private function pay(array $o): array
    {
        return $o + ['id' => 1, 'invoice_id' => 10, 'amount' => 0, 'payment_date' => '2026-10-04', 'method' => 'e_transfer', 'reference' => null,
                     'etransfer_notification_id' => null, 'invoice_number' => 'INV-2026-0001', 'payer' => '', 'email_amount' => null,
                     'email_date' => null, 'sender_name' => null];
    }

    public function test_payment_recorded_from_the_interac_email_is_this_deposit(): void
    {
        $p = [$this->pay(['id' => 7, 'amount' => 824.25, 'etransfer_notification_id' => 30, 'email_amount' => 824.25,
                          'email_date' => '2026-10-04 18:57:00', 'invoice_number' => 'INV-2026-0433', 'payer' => 'Maureen Kirkbride', 'sender_name' => 'alaninglis'])];
        $r = BankInvoiceMatchService::pickRecorded($p, 824.25, '2026-10-04', null);
        $this->assertCount(1, $r);
        $this->assertSame([7], $r[0]['allocation_ids']);
        $this->assertSame(['INV-2026-0433'], $r[0]['invoice_numbers']);
        $this->assertStringContainsString('alaninglis', $r[0]['how']);
    }

    public function test_an_email_split_across_invoices_links_all_its_payments(): void
    {
        $base = ['etransfer_notification_id' => 24, 'email_amount' => 232.36, 'email_date' => '2026-09-23', 'payer' => 'Tove Pashkowski'];
        $p = [$this->pay($base + ['id' => 1, 'amount' => 137.18, 'invoice_number' => 'INV-2026-0318']),
              $this->pay($base + ['id' => 2, 'amount' => 63.68, 'invoice_number' => 'INV-2026-0378']),
              $this->pay($base + ['id' => 3, 'amount' => 31.50, 'invoice_number' => 'INV-2026-0403'])];
        $r = BankInvoiceMatchService::pickRecorded($p, 232.36, '2026-09-29', 24);
        $this->assertSame([1, 2, 3], $r[0]['allocation_ids']);
    }

    public function test_an_email_weeks_away_is_not_this_deposit(): void
    {
        $p = [$this->pay(['amount' => 364.65, 'etransfer_notification_id' => 20, 'email_amount' => 364.65, 'email_date' => '2026-09-07',
                          'payer' => 'Strata Plan BCS-2106', 'payment_date' => '2026-09-07'])];
        // falls through to the by-payer rule: one payer, exact → still offered, but as a hand-recorded payment
        $r = BankInvoiceMatchService::pickRecorded($p, 364.65, '2026-10-03', null);
        $this->assertStringStartsWith('recorded by hand', $r[0]['how']);
    }

    public function test_two_payers_with_the_same_amount_are_both_offered_not_guessed(): void
    {
        $p = [$this->pay(['id' => 1, 'amount' => 65.00, 'payer' => 'Ann Lee', 'method' => 'cheque']),
              $this->pay(['id' => 2, 'amount' => 65.00, 'payer' => 'Bob Ray', 'method' => 'cheque', 'invoice_id' => 11])];
        $r = BankInvoiceMatchService::pickRecorded($p, 65.00, '2026-09-17', null);
        $this->assertCount(2, $r);
        $this->assertStringContainsString('more than one', BankInvoiceMatchService::say(65.0, null, $r, [], [], []));
    }

    public function test_nothing_adds_up_means_nothing_recorded(): void
    {
        $p = [$this->pay(['amount' => 100.00, 'payer' => 'Ann Lee'])];
        $this->assertSame([], BankInvoiceMatchService::pickRecorded($p, 824.25, '2026-10-04', null));
    }

    public function test_which_credits_look_like_a_client_paying(): void
    {
        $yes = ['e-Transfer credit Ref 20261004185716817882 TimatMowology', 'ETRANSFERCREDIT(JOHNHUGHES)', 'Preauthorized credit DORSET REALTY GROUP CANADA LTD',
                'Cheque deposit-branch VANCOUVER MANAGEMENT LTD IN TRUST', 'EFT CREDIT YARDI'];
        foreach ($yes as $d) $this->assertTrue(BankInvoiceMatchService::looksLikeClientPayment($d), $d);
        $no = ['STRIPE TRANSFER ST-ABC', 'INTEREST PAID', 'GST REFUND CRA', 'TFR-FR 1234567'];
        foreach ($no as $d) $this->assertFalse(BankInvoiceMatchService::looksLikeClientPayment($d), $d);
    }

    public function test_already_recorded_says_it_counts_twice(): void
    {
        $say = BankInvoiceMatchService::say(824.25, 'alaninglis', [['allocation_ids' => [7], 'invoice_numbers' => ['INV-2026-0433'], 'payer' => 'Maureen Kirkbride', 'how' => 'recorded from alaninglis\'s e-Transfer', 'sure' => true]], [], [], []);
        $this->assertStringContainsString('INV-2026-0433', $say);
        $this->assertStringContainsString('counts twice', $say);
    }

    public function test_an_amount_only_match_is_worded_as_a_question(): void
    {
        $say = BankInvoiceMatchService::say(66.15, null, [['allocation_ids' => [23], 'invoice_numbers' => ['INV-2026-0315'], 'payer' => 'Ann Lee', 'how' => 'recorded by hand on 2026-10-05', 'sure' => false]], [], [], []);
        $this->assertStringContainsString("If that's this money", $say);
        $this->assertStringNotContainsString('counts twice', $say);
        $r = BankInvoiceMatchService::pickRecorded([$this->pay(['amount' => 66.15, 'payer' => 'Ann Lee'])], 66.15, '2026-09-23', null);
        $this->assertFalse($r[0]['sure']);
    }

    public function test_i_think_sentence_keeps_lower_case(): void
    {
        $say = BankInvoiceMatchService::say(804.04, null, [], [], [['invoice_number' => 'INV-2026-0418', 'payer' => 'Dorset', 'confidence' => 70, 'reasons' => ['Memo names Dorset']]], []);
        $this->assertStringStartsWith('I think this $804.04 pays', $say);
    }

    // ── the scorer, fed the Interac sender because the TD memo names only us ──

    private function invoice(array $o = []): array
    {
        return $o + ['id' => 443, 'invoice_number' => 'INV-2026-0433', 'balance_due' => 824.25, 'total' => 824.25, 'invoice_date' => '2026-09-17',
                     'due_date' => '2026-10-01', 'contact_name' => 'Maureen Kirkbride', 'company_name' => null, 'property_name' => null, 'bill_to_name' => null,
                     'stripe_payment_intent_id' => null, 'stripe_charge_id' => null];
    }

    private function deposit(array $o = []): array
    {
        return $o + ['id' => 51293, 'transaction_date' => '2026-10-04', 'amount' => 824.25, 'description' => 'e-Transfer credit Ref 20261004185716817882 TimatMowology'];
    }

    public function test_td_memo_alone_scores_on_amount_and_date_only(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $s = $svc->scoreDeposit($this->deposit(), $this->invoice());
        $this->assertNotNull($s);
        $this->assertContains('Exact amount', $s['reasons']);
        $this->assertLessThan(80, $s['confidence']);
    }

    public function test_the_learned_payer_names_the_invoice(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $plain = $svc->scoreDeposit($this->deposit(), $this->invoice());
        $s = $svc->scoreDeposit($this->deposit(['payer_names' => ['You\'ve recorded alaninglis paying for' => 'Maureen Kirkbride']]), $this->invoice());
        $this->assertSame($plain['confidence'] + 25, $s['confidence']);
        $this->assertStringContainsString('Maureen Kirkbride', implode(' ', $s['reasons']));
    }

    public function test_a_run_together_sender_matches_first_and_last_name(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $s = $svc->scoreDeposit($this->deposit(['payer_names' => ['Interac email from' => 'ALAN INGLIS']]), $this->invoice(['contact_name' => 'Alan Inglis']));
        $this->assertStringContainsString('Interac email from ALAN INGLIS', implode(' ', $s['reasons']));
    }

    public function test_an_unrelated_sender_adds_nothing(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $plain = $svc->scoreDeposit($this->deposit(), $this->invoice());
        $s = $svc->scoreDeposit($this->deposit(['payer_names' => ['Interac email from' => 'STRATA PLAN BCS-2106']]), $this->invoice());
        $this->assertSame($plain['confidence'], $s['confidence']);
    }

    public function test_sender_names_agree_on_distinctive_words_only(): void
    {
        $this->assertTrue(InvoiceReconciliationService::namesAgree('STRATA PLAN BCS-2106', 'The Owners, Strata Plan BCS 2106'));
        $this->assertFalse(InvoiceReconciliationService::namesAgree('STRATA PLAN BCS-2106', 'The Owners, Strata Plan BCS 4079'));
        $this->assertFalse(InvoiceReconciliationService::namesAgree('STRATA PLAN BCS-2106', 'Strata 3775'));
        $this->assertTrue(InvoiceReconciliationService::namesAgree('alaninglis', 'Alan Inglis'));
        $this->assertTrue(InvoiceReconciliationService::namesAgree('alan inglis', 'Alan Inglis'));
        $this->assertTrue(InvoiceReconciliationService::namesAgree('TOVE MARIE PASHKOWSKI', 'Tove Marie Pashkowski'));
        $this->assertFalse(InvoiceReconciliationService::namesAgree('alan inglis', 'Maureen Kirkbride'));
        $this->assertFalse(InvoiceReconciliationService::namesAgree('ALAN SMITH', 'Alan Cook'), 'one shared first name is not the same payer');
    }

    public function test_a_shared_generic_word_never_ties_a_strata_to_another_strata(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $s = $svc->scoreDeposit($this->deposit(['amount' => 364.65, 'transaction_date' => '2026-10-03', 'payer_names' => ['Interac email from' => 'STRATA PLAN BCS-2106']]),
                                $this->invoice(['balance_due' => 262.50, 'total' => 262.50, 'invoice_date' => '2026-10-01', 'contact_name' => null,
                                                'company_name' => 'The Owners, Strata Plan BCS 4079']));
        $this->assertNull($s, 'a bigger deposit is only offered when a name ties it to the invoice');
    }

    public function test_invoice_total_match_when_partly_paid(): void
    {
        $svc = new InvoiceReconciliationService($this->createMock(PDO::class));
        $s = $svc->scoreDeposit($this->deposit(['transaction_date' => '2026-10-01', 'description' => 'ETRANSFERCREDIT(MAUREENKIRKBRIDE)']),
                                $this->invoice(['balance_due' => 400.00]));
        $this->assertContains('Matches the invoice total', $s['reasons']);
    }
}
