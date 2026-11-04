<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Codex nightly 2026-10-24 (money-13/16): a login with billing.view only could POST send / mark_sent / mark_paid
 * on an invoice and create a payable Stripe intent; the buttons were hidden but the server did not check.
 * Source scan, same style as the other guard tests.
 */
class BillingEditGuardTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
    }

    public function test_invoice_view_blocks_send_and_payment_actions_without_billing_edit(): void
    {
        $s = self::src('public/crm/invoices/view.php');
        $this->assertMatchesRegularExpression(
            "/in_array\(\\\$action, \['send', 'mark_sent', 'mark_paid'\], true\)\s*&&\s*!userHasPermission\('billing\.edit'\)/",
            $s
        );
        // the guard must sit before the first action runs
        $this->assertLessThan(strpos($s, "if (\$action === 'delete_invoice')"), strpos($s, "'mark_paid'], true)"));
    }

    public function test_payment_intent_endpoint_needs_billing_edit(): void
    {
        $s = self::src('public/crm/api/stripe/create-payment-intent.php');
        $this->assertStringContainsString("requirePermission('billing.edit')", $s);
        $this->assertStringNotContainsString("requirePermission('billing.view')", $s);
    }

    public function test_link_product_checks_the_expense_is_editable(): void
    {
        $s = self::src('app/Modules/Expenses/Api/expenses.php');
        $i = strpos($s, 'function handleLinkProduct');
        $this->assertNotFalse($i);
        $this->assertStringContainsString('assertCanEditExpense', substr($s, $i, 900));
    }
}
