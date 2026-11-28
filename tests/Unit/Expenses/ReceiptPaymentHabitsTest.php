<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ReceiptPaymentHabitsTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)');
        return $db;
    }

    public function test_nothing_said_changes_nothing(): void
    {
        $this->assertSame(['payment_method' => 'cash', 'source' => 'read', 'ask' => false], ReceiptPaymentHabits::decide([], null, 'cash'));
        $this->assertSame(['payment_method' => null, 'source' => null, 'ask' => false], ReceiptPaymentHabits::decide([], null, null));
    }

    public function test_usual_fills_only_when_the_reader_found_none(): void
    {
        $this->assertSame(['payment_method' => 'debit', 'source' => 'usual', 'ask' => false],
            ReceiptPaymentHabits::decide(['debit', 'cash'], 'debit', null));
        $this->assertSame(['payment_method' => 'cash', 'source' => 'read', 'ask' => false],
            ReceiptPaymentHabits::decide(['debit', 'cash'], 'debit', 'cash'));
    }

    public function test_a_read_method_the_owner_did_not_name_asks_once_and_is_kept(): void
    {
        $this->assertSame(['payment_method' => 'cash', 'source' => 'read', 'ask' => true],
            ReceiptPaymentHabits::decide(['debit'], 'debit', 'cash'));
    }

    public function test_card_kinds_are_one_family(): void
    {
        $this->assertFalse(ReceiptPaymentHabits::decide(['company_card'], 'company_card', 'credit_card')['ask']);
        $this->assertTrue(ReceiptPaymentHabits::decide(['debit'], 'debit', 'credit_card')['ask']);
    }

    public function test_an_unknown_read_code_is_kept_not_replaced(): void
    {
        $r = ReceiptPaymentHabits::decide(['debit'], 'debit', 'gift_card');
        $this->assertSame('gift_card', $r['payment_method']);
        $this->assertSame('read', $r['source']);
    }

    public function test_owner_paid_is_a_choice(): void
    {
        $this->assertSame(['owner_paid'], ReceiptPaymentHabits::parseUsed('owner_paid'));
    }

    public function test_parse_drops_unknown_codes_and_repeats(): void
    {
        $this->assertSame(['debit', 'cash'], ReceiptPaymentHabits::parseUsed(' Debit,cash,debit,bitcoin,'));
        $this->assertSame([], ReceiptPaymentHabits::parseUsed(null));
    }

    public function test_save_then_load_and_usual_must_be_named(): void
    {
        $db = $this->db();
        $r = ReceiptPaymentHabits::save($db, ['debit', 'cash', 'nope'], 'etransfer');
        $this->assertSame(['used' => ['debit', 'cash'], 'usual' => 'debit'], $r);
        ReceiptPaymentHabits::save($db, ['debit', 'cash'], 'cash');
        $this->assertSame(['used' => ['debit', 'cash'], 'usual' => 'cash'], ReceiptPaymentHabits::load($db));
        $this->assertSame(2, (int)$db->query('SELECT COUNT(*) FROM ops_settings')->fetchColumn());
    }

    public function test_apply_fills_the_usual_and_leaves_a_read_method(): void
    {
        $db = $this->db();
        ReceiptPaymentHabits::save($db, ['debit', 'cash'], 'debit');
        $a = ReceiptPaymentHabits::apply($db, ['total' => '10.00', 'payment_method' => null]);
        $this->assertSame('debit', $a['parsed']['payment_method']);
        $this->assertSame(['source' => 'usual', 'ask' => false], $a['habit']);
        $b = ReceiptPaymentHabits::apply($db, ['payment_method' => 'etransfer']);
        $this->assertSame('etransfer', $b['parsed']['payment_method']);
        $this->assertTrue($b['habit']['ask']);
    }

    public function test_apply_with_no_answer_or_no_table_is_a_no_op(): void
    {
        $db = new PDO('sqlite::memory:');   // no ops_settings at all
        $this->assertSame(['parsed' => ['payment_method' => null], 'habit' => null], ReceiptPaymentHabits::apply($db, ['payment_method' => null]));
    }

    public function test_no_card_number_is_kept(): void
    {
        $db = $this->db();
        ReceiptPaymentHabits::save($db, ['credit_card'], 'credit_card');
        $all = json_encode($db->query('SELECT * FROM ops_settings')->fetchAll(PDO::FETCH_ASSOC));
        $this->assertDoesNotMatchRegularExpression('/\d{4}/', (string)$all);
    }
}
