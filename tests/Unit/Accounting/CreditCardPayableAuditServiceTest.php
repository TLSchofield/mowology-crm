<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2400 Credit Card Payable showed a $39,110.95 DEBIT (2026-10-07). The audit (report only)
 * splits what posts there by source and month, finds lines that aren't card payments, and
 * says whether card statements were ever imported.
 */
class CreditCardPayableAuditServiceTest extends TestCase
{
    private const CARD = 7;

    public function test_breakdown_on_fixtures(): void
    {
        $journal = [
            ['source_type' => 'bank_import', 'month' => '2026-03', 'debit' => 3000.00, 'credit' => 0, 'n' => 2],
            ['source_type' => 'expense',     'month' => '2026-03', 'debit' => 0, 'credit' => 800.00, 'n' => 4],
            ['source_type' => 'bank_import', 'month' => '2026-04', 'debit' => 2500.00, 'credit' => 0, 'n' => 2],
            ['source_type' => 'expense',     'month' => '2026-04', 'debit' => 0, 'credit' => 450.00, 'n' => 3],
        ];
        $rows = [
            $this->row(1, '2026-03-05', 'transfer', 2000.00, 'TD VISA PAYMENT', 10),
            $this->row(2, '2026-03-20', 'transfer', 1000.00, 'WAVE PYRL 0012345', 10),
            $this->row(3, '2026-04-05', 'transfer', 1250.00, 'PREAUTHORIZED DEBIT 4455', 10),
            $this->row(4, '2026-04-05', 'transfer', 1250.00, 'PREAUTHORIZED DEBIT 4455', 11),
            $this->row(5, '2026-04-10', 'expense', 0, 'manual adj', null, 'manual'),
        ];
        $sessions = [['id' => 10, 'filename' => 'td-chequing.csv', 'bank_name' => 'TD Bank', 'account_name' => 'Chequing', 'bank_account_id' => 1,
                      'date_from' => '2026-03-01', 'date_to' => '2026-04-30', 'status' => 'imported']];

        $r = CreditCardPayableAuditService::analyse($journal, $rows, $sessions, self::CARD);

        $this->assertEqualsWithDelta(4250.00, $r['balance'], 0.001, '5,500 of payments − 1,250 of card purchases');
        $this->assertSame('debit', $r['balance_side']);
        $src = [];
        foreach ($r['by_source'] as $s) $src[$s['source']] = $s;
        $this->assertEqualsWithDelta(5500.00, $src['bank_import']['debit'], 0.001);
        $this->assertEqualsWithDelta(1250.00, $src['expense']['credit'], 0.001);
        $this->assertEqualsWithDelta(2200.00, $r['by_month'][0]['running'], 0.001);
        $this->assertEqualsWithDelta(4250.00, $r['by_month'][1]['running'], 0.001);

        $this->assertFalse($r['card_statements']['imported'], 'only a chequing statement was imported');
        $this->assertStringContainsString('No credit-card statement', $r['say']);

        $why = array_column($r['suspects'], 'why', 'id');
        $this->assertArrayHasKey(2, $why, 'Wave payroll on 2400 is misfiled');
        $this->assertStringContainsString('PYRL', $why[2]);
        $this->assertArrayHasKey(3, $why, 'a pre-authorised debit that never says "card"');
        $this->assertArrayNotHasKey(1, $why, 'TD VISA PAYMENT is a card payment');
        $this->assertCount(1, $r['duplicates'], 'same date + amount twice = a possible double payoff');
        $this->assertSame([3, 4], $r['duplicates'][0]['ids']);
        $kinds = array_column($r['bank_rows']['by_kind'], 'n', 'kind');
        $this->assertSame(4, $kinds['card_payment_from_bank']);
        $this->assertSame(1, $kinds['manual']);
    }

    public function test_card_statement_sessions_are_recognised(): void
    {
        $this->assertTrue(CreditCardPayableAuditService::isCardSession(['bank_name' => 'TD Credit Card'], self::CARD));
        $this->assertTrue(CreditCardPayableAuditService::isCardSession(['bank_name' => 'Generic', 'bank_account_id' => self::CARD], self::CARD));
        $this->assertFalse(CreditCardPayableAuditService::isCardSession(['bank_name' => 'Vancity (Bank)', 'account_name' => 'Chequing'], self::CARD));
        $this->assertSame('card_statement_charge', CreditCardPayableAuditService::kindOf(['reference_type' => 'bank_import', 'type' => 'expense', 'import_session_id' => 3], [3 => []]));
    }

    public function test_report_reads_the_database(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (7, '2400', 'Credit Card Payable')");
        $db->exec("CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, entry_date TEXT, source_type TEXT, source_id INTEGER)");
        $db->exec("CREATE TABLE journal_lines (id INTEGER PRIMARY KEY, entry_id INTEGER, account_id INTEGER, debit REAL, credit REAL)");
        $db->exec("INSERT INTO journal_entries VALUES (1, '2026-03-05', 'bank_import', 1), (2, '2026-03-09', 'expense', 9)");
        $db->exec("INSERT INTO journal_lines VALUES (1, 1, 7, 2000, 0), (2, 1, 1, 0, 2000), (3, 2, 7, 0, 300)");
        $db->exec("CREATE TABLE accounting_transactions (id INTEGER PRIMARY KEY, transaction_date TEXT, type TEXT, amount REAL, description TEXT,
                   reference_type TEXT, status TEXT, bank_account_id INTEGER, import_session_id INTEGER, account_id INTEGER)");
        $db->exec("INSERT INTO accounting_transactions VALUES (1, '2026-03-05', 'transfer', 2000, 'TD VISA PAYMENT', 'bank_import', 'cleared', 1, NULL, 7)");
        $r = (new CreditCardPayableAuditService($db))->report();
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(1700.00, $r['balance'], 0.001);
        $this->assertNull($r['card_expenses'], 'no expenses table: skipped, not fatal');
    }

    private function row(int $id, string $date, string $type, float $amount, string $desc, ?int $session, string $ref = 'bank_import'): array
    {
        return ['id' => $id, 'transaction_date' => $date, 'type' => $type, 'amount' => $amount, 'description' => $desc,
                'reference_type' => $ref, 'status' => 'cleared', 'bank_account_id' => 1, 'import_session_id' => $session];
    }
}
