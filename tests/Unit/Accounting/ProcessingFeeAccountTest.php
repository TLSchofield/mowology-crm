<?php
use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/Modules/Accounting/Services/StripePayoutService.php';
require_once APP_ROOT . '/Modules/Accounting/Services/BankImportService.php';

/**
 * Stripe / card processing fees go to 6800 Bank Charges & Fees. On production 6850 is Meals &
 * Entertainment (migration 1019), and both fee paths picked "code 6850" first — 82 Stripe fees
 * ($1,028.30, 2026) landed on Meals, which is only 50% deductible (found 2026-10-13).
 */
class ProcessingFeeAccountTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, code TEXT, name TEXT, type TEXT, sub_type TEXT, normal_balance TEXT, is_active INTEGER DEFAULT 1, display_order INTEGER)");
        $db->exec("INSERT INTO chart_of_accounts (id, code, name, type) VALUES (84, '6850', 'Meals & Entertainment', 'expense'), (80, '6800', 'Bank Charges & Fees', 'expense'), (60, '6900', 'Miscellaneous', 'expense')");
        return $db;
    }

    private function call(object $svc, string $method): int
    {
        $m = new ReflectionMethod($svc, $method);
        $m->setAccessible(true);
        return $m->invoke($svc);
    }

    public function testStripePayoutFeesNeverLandOnMeals(): void
    {
        $this->assertSame(80, $this->call(new StripePayoutService($this->db()), 'feeAccountId'));
    }

    public function testImportProcessingFeesNeverLandOnMeals(): void
    {
        $this->assertSame(80, $this->call(new BankImportService($this->db()), 'getProcessingFeesAccountId'));
    }
}
