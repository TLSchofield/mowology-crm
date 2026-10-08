<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The read-only discovery report, summarised from a Canadian-shaped company file.
 */
class QboDiscoveryServiceTest extends TestCase
{
    private function raw(array $over = []): array
    {
        $raw = [
            'company' => ['CompanyName' => 'Mowology Landscaping', 'LegalName' => 'Mowology Ltd', 'Country' => 'CA', 'FiscalYearStartMonth' => 'January',
                          'CompanyStartDate' => '2024-03-01', 'NameValue' => [['Name' => 'OfferingSku', 'Value' => 'QuickBooks Online Plus'], ['Name' => 'SubscriptionStatus', 'Value' => 'PAID']]],
            'preferences' => ['CurrencyPrefs' => ['HomeCurrency' => ['value' => 'CAD'], 'MultiCurrencyEnabled' => false],
                              'TaxPrefs' => ['UsingSalesTax' => true], 'AccountingInfoPrefs' => ['BookCloseDate' => '2025-12-31', 'UseAccountNumbers' => true, 'FirstMonthOfFiscalYear' => 'January'],
                              'SalesFormsPrefs' => ['CustomTxnNumbers' => false], 'ReportPrefs' => ['ReportBasis' => 'Accrual']],
            'accounts' => [
                ['Id' => '35', 'Name' => 'Chequing', 'AcctNum' => '1010', 'Classification' => 'Asset', 'AccountType' => 'Bank', 'AccountSubType' => 'Checking', 'CurrentBalance' => 13425.61, 'Active' => true],
                ['Id' => '60', 'Name' => 'Job Materials', 'AcctNum' => '5200', 'Classification' => 'Expense', 'AccountType' => 'Expense', 'AccountSubType' => 'SuppliesMaterials', 'Active' => true],
                ['Id' => '61', 'Name' => 'Old Account', 'Classification' => 'Expense', 'AccountType' => 'Expense', 'Active' => false],
            ],
            'tax_codes' => [
                ['Id' => '2', 'Name' => 'GST', 'Active' => true, 'Taxable' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']]]], 'PurchaseTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '4']]]]],
                ['Id' => '5', 'Name' => 'GST/PST BC', 'Active' => true, 'Taxable' => true, 'SalesTaxRateList' => ['TaxRateDetail' => [['TaxRateRef' => ['value' => '3']], ['TaxRateRef' => ['value' => '7']]]], 'PurchaseTaxRateList' => ['TaxRateDetail' => []]],
            ],
            'tax_rates' => [['Id' => '3', 'Name' => 'GST', 'RateValue' => 5], ['Id' => '4', 'Name' => 'GST (ITC)', 'RateValue' => 5], ['Id' => '7', 'Name' => 'PST (BC)', 'RateValue' => 7]],
            'items' => [['Id' => '11', 'Name' => 'Lawn care', 'Type' => 'Service', 'IncomeAccountRef' => ['name' => 'Sales']]],
            'counts' => ['Invoice' => ['before' => 0, '2025' => 40, '2026' => 0], 'Purchase' => ['before' => 0, '2025' => 120, '2026' => 0], 'Customer' => ['total' => 55]],
            'first' => ['Invoice' => '2025-01-04', 'Purchase' => '2025-01-02'], 'last' => ['Invoice' => '2025-12-20', 'Purchase' => '2025-12-29'],
            'errors' => [],
        ];
        return array_replace_recursive($raw, $over);
    }

    public function test_company_facts_are_lifted_from_companyinfo_and_preferences(): void
    {
        $r = QboDiscoveryService::summarise($this->raw(), '2026-10-07');
        $c = $r['company'];
        $this->assertSame('Mowology Landscaping', $c['name']);
        $this->assertSame('CA', $c['country']);
        $this->assertSame('CAD', $c['home_currency']);
        $this->assertSame('2025-12-31', $c['book_close_date']);
        $this->assertTrue($c['sales_tax_enabled']);
        $this->assertTrue($c['use_account_numbers']);
        $this->assertFalse($c['custom_txn_numbers']);
        $this->assertSame('QuickBooks Online Plus', $c['sku']);
        $this->assertSame('2024-03-01', $c['company_start_date']);
    }

    public function test_counts_years_and_dates_are_totalled(): void
    {
        $r = QboDiscoveryService::summarise($this->raw(), '2026-10-07');
        $this->assertSame(['2025', '2026'], $r['years']);
        $this->assertSame(160, $r['txn_total']);
        $this->assertSame(160, $r['per_year']['2025']);
        $this->assertSame('2025-01-02', $r['first_txn']);
        $this->assertSame('2025-12-29', $r['last_txn']);
        $this->assertSame(55, $r['counts']['Customer']['total']);
    }

    public function test_tax_codes_show_their_rates_and_items_are_listed(): void
    {
        $r = QboDiscoveryService::summarise($this->raw(), '2026-10-07');
        $this->assertSame(['GST 5%'], $r['tax_codes'][0]['sales']);
        $this->assertSame(['GST (ITC) 5%'], $r['tax_codes'][0]['purchase']);
        $this->assertSame(['GST 5%', 'PST (BC) 7%'], $r['tax_codes'][1]['sales']);
        $this->assertSame('Lawn care', $r['items'][0]['name']);
        $this->assertSame(['Asset' => 1, 'Expense' => 2], $r['accounts_by_class']);
    }

    public function test_a_clean_canadian_file_gets_only_the_informational_notes(): void
    {
        $r = QboDiscoveryService::summarise($this->raw(), '2026-10-07');
        $levels = array_column($r['warnings'], 'level');
        $this->assertNotContains('danger', $levels);
        $texts = implode(' | ', array_column($r['warnings'], 'text'));
        $this->assertStringContainsString('Custom transaction numbers are off', $texts);
        $this->assertStringContainsString('2026-01-01 will ever be pushed', $texts, 'book close before FY2026 start = fine');
    }

    public function test_a_us_file_with_sales_tax_off_and_no_pst_is_called_out(): void
    {
        $raw = $this->raw(['company' => ['Country' => 'US'], 'preferences' => ['TaxPrefs' => ['UsingSalesTax' => false], 'CurrencyPrefs' => ['HomeCurrency' => ['value' => 'USD']]]]);
        $raw['tax_codes'] = [];
        $r = QboDiscoveryService::summarise($raw, '2026-10-07');
        $texts = implode(' | ', array_column($r['warnings'], 'text'));
        $this->assertStringContainsString('not Canada', $texts);
        $this->assertStringContainsString('Sales tax is OFF', $texts);
        $this->assertStringContainsString('Home currency is USD', $texts);
        $this->assertSame(3, count(array_filter($r['warnings'], static fn($w) => $w['level'] === 'danger')));
    }

    public function test_existing_current_year_activity_warns_about_double_entry(): void
    {
        $raw = $this->raw(['counts' => ['Invoice' => ['2026' => 12]]]);
        $r = QboDiscoveryService::summarise($raw, '2026-10-07');
        $texts = implode(' | ', array_column($r['warnings'], 'text'));
        $this->assertStringContainsString('12 transactions dated 2026', $texts);
    }

    public function test_an_empty_file_and_read_errors_are_reported_not_hidden(): void
    {
        $raw = $this->raw(['counts' => ['Invoice' => ['before' => 0, '2025' => 0, '2026' => 0], 'Purchase' => ['before' => 0, '2025' => 0, '2026' => 0]], 'errors' => ['TaxRate: HTTP 500']]);
        $raw['first'] = []; $raw['last'] = [];
        $r = QboDiscoveryService::summarise($raw, '2026-10-07');
        $texts = implode(' | ', array_column($r['warnings'], 'text'));
        $this->assertStringContainsString('no transactions at all', $texts);
        $this->assertStringContainsString('Could not read: TaxRate', $texts);
        $this->assertNull($r['first_txn']);
    }
}
