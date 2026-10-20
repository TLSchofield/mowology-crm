<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A receipt category never maps to a revenue account (4900 "Other Services" aliases the SERVICE type 'other'; 2026-10-20). */
class ReceiptCategoryNeverRevenueTest extends TestCase
{
    public function testTheAliasOfARevenueAccountIsNotAReceiptCategory(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, expense_category_alias TEXT, is_active INT DEFAULT 1)");
        $db->exec("INSERT INTO chart_of_accounts (code, name, type, expense_category_alias) VALUES
                   ('4900', 'Other Services', 'revenue', 'other'), ('6900', 'Miscellaneous Expenses', 'expense', 'other_expense'), ('6850', 'Meals', 'expense', 'meals')");
        $sync = new LedgerSyncService($db, new LedgerService($db));
        $m = (new ReflectionMethod($sync, 'categoryToCodeMap'));
        $m->setAccessible(true);
        $map = $m->invoke($sync);
        $this->assertArrayNotHasKey('other', $map, 'a revenue account is never a receipt\'s account');
        $this->assertSame('6850', $map['meals']);
    }
}
