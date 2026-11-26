<?php
use PHPUnit\Framework\TestCase;

final class ReportsPermissionTest extends TestCase
{
    /** @dataProvider files */
    public function testFinancialReportsNeedLedgerView(string $rel): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        self::assertStringContainsString("requirePermission('ledger.view')", $src);
        self::assertStringNotContainsString("requirePermission('expenses.view')", $src); // crew hold expenses.view
    }

    public static function files(): array
    {
        return [
            ['public/crm/accounting/reports.php'],
            ['app/Modules/Accounting/Api/reports.php'],
        ];
    }
}
