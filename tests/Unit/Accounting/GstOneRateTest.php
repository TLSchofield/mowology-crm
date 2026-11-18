<?php

use PHPUnit\Framework\TestCase;

/**
 * GST has one home (2026-10-11): the Settings page rate (business_settings.gst_rate, a percent).
 * Before, the ledger and GST report read tax_rates while invoices charged the Settings rate —
 * a change in Settings would have split charged GST from reported GST.
 */
class GstOneRateTest extends TestCase
{
    private function db(?string $settingsPct, ?string $tableRate): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE business_settings (id INTEGER, gst_rate TEXT)");
        if ($settingsPct !== null) $db->exec("INSERT INTO business_settings VALUES (1, '$settingsPct')");
        $db->exec("CREATE TABLE tax_rates (id INTEGER, code TEXT, rate TEXT, is_active INTEGER)");
        $db->exec("INSERT INTO tax_rates VALUES (2, 'PST', '0.07', 1)");
        if ($tableRate !== null) $db->exec("INSERT INTO tax_rates VALUES (1, 'GST', '$tableRate', 1)");
        return $db;
    }

    public function test_the_settings_rate_wins_everywhere(): void
    {
        $db = $this->db('6.00', '0.05');
        $this->assertSame(0.06, TaxEngine::gstRate($db));
        $rates = (new TaxEngine($db))->getRates();
        $this->assertSame(0.06, $rates['GST']);
        $this->assertSame(0.07, $rates['PST']);
    }

    public function test_falls_back_to_the_table_then_five_percent(): void
    {
        $this->assertSame(0.05, TaxEngine::gstRate($this->db(null, '0.05')));
        $this->assertSame(0.05, TaxEngine::gstRate($this->db(null, null)));
    }

    public function test_the_two_screens_no_longer_hard_code_five_percent(): void
    {
        foreach (['public/crm/accounting/transactions.php', 'public/crm/products/area-measurement.php'] as $f) {
            $src = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $f);
            $this->assertStringNotContainsString('* 0.05', $src, $f);
            $this->assertStringContainsString('TaxEngine::gstRate', $src, $f);
        }
    }
}
