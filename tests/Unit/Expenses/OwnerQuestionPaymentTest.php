<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** "How do you pay?" then "which one most?" as owner questions, after the first filed receipt. */
class OwnerQuestionPaymentTest extends TestCase
{
    private function db(string $status = 'approved'): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY)");
        $db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT)");
        $db->exec("INSERT INTO expenses (id, status) VALUES (1, '$status')");
        return $db;
    }

    public function test_not_asked_before_a_receipt_is_filed(): void
    {
        foreach (['draft', 'rejected'] as $st) {
            $this->assertSame([], (new OwnerQuestionService($this->db($st)))->open());
        }
    }

    public function test_a_receipt_waiting_for_approval_counts_as_filed(): void
    {
        $q = (new OwnerQuestionService($this->db('pending_approval')))->open();
        $this->assertSame(['payment_methods'], array_column($q, 'key'));
    }

    public function test_asked_after_the_first_filed_receipt_as_a_multi_select_with_owner_paid(): void
    {
        $q = (new OwnerQuestionService($this->db('forwarded')))->open();
        $this->assertCount(1, $q);
        $this->assertSame('payment_methods', $q[0]['key']);
        $this->assertTrue($q[0]['multi']);
        $this->assertContains('owner_paid', array_column($q[0]['choices'], 'key'));
        $this->assertSame(['mode' => 'question_answer', 'key' => 'payment_methods'], $q[0]['answer']);
    }

    public function test_one_method_is_the_usual_and_nothing_more_is_asked(): void
    {
        $db = $this->db(); $s = new OwnerQuestionService($db);
        $this->assertTrue($s->answer('payment_methods', ['debit'], 1)['ok']);
        $this->assertSame(['used' => ['debit'], 'usual' => 'debit'], ReceiptPaymentHabits::load($db));
        $this->assertSame([], $s->open());
    }

    public function test_several_methods_then_which_is_usual(): void
    {
        $db = $this->db(); $s = new OwnerQuestionService($db);
        $this->assertTrue($s->answer('payment_methods', ['debit', 'cash', 'etransfer'], 1)['ok']);
        $q = $s->open();
        $this->assertCount(1, $q);
        $this->assertSame('payment_usual', $q[0]['key']);
        $this->assertFalse($q[0]['multi']);
        $this->assertSame(['debit', 'cash', 'etransfer'], array_column($q[0]['choices'], 'key'));
        $this->assertFalse($s->answer('payment_usual', ['company_card'], 1)['ok']);   // not one they named
        $this->assertTrue($s->answer('payment_usual', ['cash'], 1)['ok']);
        $this->assertSame(['used' => ['debit', 'cash', 'etransfer'], 'usual' => 'cash'], ReceiptPaymentHabits::load($db));
        $this->assertSame([], $s->open());
    }

    public function test_bad_choices_are_refused_and_an_answered_question_cannot_be_answered_again(): void
    {
        $db = $this->db(); $s = new OwnerQuestionService($db);
        $this->assertFalse($s->answer('payment_methods', [], 1)['ok']);
        $this->assertFalse($s->answer('payment_methods', ['bitcoin'], 1)['ok']);
        $this->assertTrue($s->answer('payment_methods', ['cash'], 1)['ok']);
        $this->assertFalse($s->answer('payment_methods', ['debit'], 1)['ok']);
        $this->assertFalse($s->answer('payment_usual', ['cash'], 1)['ok']);   // only one named: not open
    }
}
