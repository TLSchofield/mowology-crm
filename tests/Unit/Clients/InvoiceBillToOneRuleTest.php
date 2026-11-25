<?php

use PHPUnit\Framework\TestCase;

/**
 * One "Bill to" company rule for every invoice header (2026-10-11): 23 of 368 invoices since
 * Feb 2026 showed the company on the PDF but not in the CRM view or the customer portal, because
 * each had its own COALESCE ladder. All three now use BillToResolver's constants.
 */
class InvoiceBillToOneRuleTest extends TestCase
{
    /** @dataProvider headers */
    public function test_every_invoice_header_uses_the_shared_rule(string $file): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertStringContainsString('BillToResolver::INVOICE_COMPANY_NAME', $src, "$file must take company_name from BillToResolver");
        $this->assertStringContainsString('BillToResolver::INVOICE_COMPANY_JOINS', $src, "$file must join BillToResolver::INVOICE_COMPANY_JOINS");
        $this->assertStringContainsString('BillToResolver.php', $src, "$file must require BillToResolver (no autoloader in production)");
    }

    public static function headers(): array
    {
        return [['app/Services/Pdf/PdfGenerator.php'], ['public/crm/invoices/view.php'], ['public/customer/invoice.php']];
    }

    public function test_the_rule_reads_property_billing_company_and_manager(): void
    {
        $sql = BillToResolver::INVOICE_COMPANY_JOINS . BillToResolver::INVOICE_COMPANY_NAME;
        foreach (['bt_cb.company_name', 'bt_pm.company_name', 'bt_p.billing_company_id', 'bt_p.property_manager_id'] as $needle) {
            $this->assertStringContainsString($needle, $sql);
        }
    }
}
