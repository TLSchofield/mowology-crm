<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Penny asks the owner a question: generic shape, due only after the first invoice, one answer action. */
class OwnerQuestionServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT, description TEXT)");
        $db->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY)");
        return $db;
    }

    public function test_nothing_is_asked_before_the_first_invoice(): void
    {
        $this->assertSame([], (new OwnerQuestionService($this->db()))->open());
    }

    public function test_asked_after_the_first_invoice_in_the_generic_shape(): void
    {
        $db = $this->db(); $db->exec('INSERT INTO invoices (id) VALUES (1)');
        $q = (new OwnerQuestionService($db))->open();
        $this->assertCount(1, $q);
        $this->assertSame(['key', 'title', 'body', 'multi', 'choices', 'answer'], array_keys($q[0]));
        $this->assertSame('accounting_software', $q[0]['key']);
        $this->assertFalse($q[0]['multi']);
        $this->assertSame(['ittai', 'quickbooks'], array_column($q[0]['choices'], 'key'));
        $this->assertSame(['I want to use Ittai', 'QuickBooks'], array_column($q[0]['choices'], 'label'));
        $this->assertSame('Penny keeps your books in Ittai. You can connect QuickBooks later.', $q[0]['body']);
        $this->assertSame(['mode' => 'question_answer', 'key' => 'accounting_software'], $q[0]['answer']);
    }

    public function test_choosing_ittai_saves_no_and_the_question_goes_away(): void
    {
        $db = $this->db(); $db->exec('INSERT INTO invoices (id) VALUES (1)');
        $s = new OwnerQuestionService($db);
        $r = $s->answer('accounting_software', ['ittai'], 1);
        $this->assertTrue($r['ok']);
        $this->assertArrayNotHasKey('next', $r);
        $this->assertSame('no', $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'onboarding_uses_qbo'")->fetchColumn());
        $this->assertSame([], $s->open());
    }

    public function test_choosing_quickbooks_saves_yes_and_points_at_the_connect_flow(): void
    {
        $db = $this->db(); $db->exec('INSERT INTO invoices (id) VALUES (1)');
        $s = new OwnerQuestionService($db);
        $r = $s->answer('accounting_software', ['quickbooks'], 1);
        $this->assertSame('connect_quickbooks', $r['next']);
        $this->assertSame('yes', $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'onboarding_uses_qbo'")->fetchColumn());
        $s->answer('accounting_software', ['ittai'], 1);
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM ops_settings")->fetchColumn(), 'updated in place, one row');
    }

    public function test_bad_answers_are_refused_and_save_nothing(): void
    {
        $db = $this->db();
        $s = new OwnerQuestionService($db);
        foreach ([[], ['nope'], ['ittai', 'quickbooks']] as $choices) $this->assertFalse($s->answer('accounting_software', $choices, 1)['ok']);
        $this->assertFalse($s->answer('payment_method', ['x'], 1)['ok']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM ops_settings")->fetchColumn());
    }

    public function test_an_answer_from_setup_keeps_it_closed(): void
    {
        $db = $this->db(); $db->exec('INSERT INTO invoices (id) VALUES (1)');
        $db->exec("INSERT INTO ops_settings VALUES ('onboarding_uses_qbo', 'yes', '')");
        $this->assertSame([], (new OwnerQuestionService($db))->open());
    }
}
