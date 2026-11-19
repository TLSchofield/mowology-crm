<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The statement says whose it is; the upload's account choice can't overrule it (2026-10-14). */
class StatementAccountMismatchTest extends TestCase
{
    private function svc(): BankImportService
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, sub_type TEXT, account_number TEXT)");
        $db->exec("CREATE TABLE bank_statement_accounts (account_id INTEGER, bank_name_match TEXT, kind TEXT)");
        $db->exec("INSERT INTO chart_of_accounts VALUES (2, '1010', 'Chequing Account', 'bank', NULL), (86, '1025', 'GST Reserves', 'bank', NULL)");
        $db->exec("INSERT INTO bank_statement_accounts VALUES (2, 'Vancity ••4290', 'bank'), (86, 'Vancity ••6827', 'bank')");
        $db->exec("CREATE TABLE bank_statement_sections (session_id INTEGER, account_suffix TEXT, bank_account_id INTEGER)");
        $db->exec("INSERT INTO bank_statement_sections VALUES (64, '6801', 2), (78, '6801', 2), (70, '1111', 2), (71, '1111', 86)");
        return new BankImportService($db);
    }

    public function test_digits_learned_from_past_statements_when_they_agree(): void
    {
        $s = $this->svc();
        $this->assertStringStartsWith('This statement is for 1010 Chequing Account (••6801)', (string)$s->statementAccountMismatch([['suffix' => '6801', 'kind' => 'primary']], 86));
        $this->assertNull($s->statementAccountMismatch([['suffix' => '1111', 'kind' => 'primary']], 86), 'past imports disagree: no verdict');
    }

    public function test_wrong_account_is_refused_by_name(): void
    {
        $why = $this->svc()->statementAccountMismatch([['suffix' => '4290', 'kind' => 'primary'], ['suffix' => '6827', 'kind' => 'savings']], 86);
        $this->assertSame('This statement is for 1010 Chequing Account (••4290), but the import was set to 1025 GST Reserves. Pick 1010 Chequing Account and import again.', $why);
    }

    public function test_agreeing_or_silent_statements_pass(): void
    {
        $s = $this->svc();
        $this->assertNull($s->statementAccountMismatch([['suffix' => '4290', 'kind' => 'primary']], 2));
        $this->assertNull($s->statementAccountMismatch([['suffix' => '9999', 'kind' => 'primary']], 86), 'digits that name no account');
        $this->assertNull($s->statementAccountMismatch([], 86), 'CSV / card statements carry no sections');
        $this->assertNull($s->statementAccountMismatch([['suffix' => '6827', 'kind' => 'savings']], 2), 'only the primary section decides');
    }
}
