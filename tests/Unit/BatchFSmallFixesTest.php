<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BatchFSmallFixesTest extends TestCase
{
    private function source(string $path): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        self::assertNotFalse($source, 'Could not read ' . $path);
        return $source;
    }

    public function testInvoiceMutationsUseCheckedStatusAndDetectRaces(): void
    {
        $source = $this->source('public/crm/invoices/view.php');
        self::assertStringContainsString('DELETE FROM invoices WHERE id = ? AND status = ?', $source);
        self::assertStringContainsString("UPDATE invoices SET status = 'cancelled' WHERE id = ? AND status = ?", $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'Invoice changed meanwhile. Please reload and try again.'));
    }

    public function testMarkPaidUsesAppliedAmountAndRefreshesDisplayedPaidAmount(): void
    {
        $source = $this->source('public/crm/invoices/view.php');
        self::assertStringContainsString("\$appliedAmount = (float)\$alloc['applied'];", $source);
        self::assertStringContainsString("'{{amount_paid}}'         => formatCurrency(\$appliedAmount)", $source);
        self::assertStringContainsString("\$invoice['amount_paid'] = (float)\$invoice['amount_paid'] + \$appliedAmount;", $source);
    }

    public function testZeroTaxRateIsNotReplacedByDefault(): void
    {
        $source = $this->source('public/crm/invoices/view.php');
        self::assertStringContainsString("isset(\$invoice['tax_rate']) ? \$invoice['tax_rate'] : 0.05", $source);
        self::assertStringNotContainsString("\$invoice['tax_rate'] ?: 0.05", $source);
    }

    public function testTransactionSummaryIsExplicitlyPageScoped(): void
    {
        $source = $this->source('public/crm/accounting/transactions.php');
        self::assertSame(3, substr_count($source, '(this page)'));
    }

    public function testTaxReportFailureShowsNoticeAndClearsFigures(): void
    {
        $source = $this->source('public/crm/accounting/reports.php');
        self::assertStringContainsString("notice.classList.remove('d-none')", $source);
        self::assertStringContainsString("document.getElementById('tax-cra-rows').innerHTML = '';", $source);
        self::assertStringContainsString("document.getElementById('tax-itc-rows').innerHTML = '';", $source);
        self::assertStringContainsString("document.getElementById('tax-annual-rows').innerHTML = '';", $source);
        self::assertStringContainsString("document.getElementById('tax-period-label').textContent = 'Period: —';", $source);
    }

    public function testRecipientLookupDatabaseFailureReturnsJsonError(): void
    {
        $source = $this->source('app/Modules/Invoices/Api/api-get-recipients.php');
        self::assertMatchesRegularExpression('/catch \(PDOException \$e\).*?error_log\(get_class\(\$e\).*?http_response_code\(500\).*?exit;/s', $source);
    }

    public function testPasswordResetRequiresUpdatedUserRow(): void
    {
        $source = $this->source('public/loginAuth/reset_password.php');
        self::assertStringContainsString("if (\$stmt->rowCount() !== 1)", $source);
        self::assertStringContainsString("throw new RuntimeException('reset user no longer exists')", $source);
    }

    public function testDeviceTokenIsSanitisedBeforeLengthValidation(): void
    {
        $source = $this->source('public/api/auth/device-token.php');
        $sanitise = strpos($source, "\$deviceToken = preg_replace('/[^a-f0-9]/i', '', \$deviceToken);");
        $length = strpos($source, 'if (strlen($deviceToken) < 32)');
        self::assertNotFalse($sanitise);
        self::assertNotFalse($length);
        self::assertLessThan($length, $sanitise);
    }

    public function testRoleIdsAreValidatedBeforeTransactionalReplacement(): void
    {
        $source = $this->source('app/Modules/Team/Api/employees.php');
        $validation = strpos($source, 'SELECT COUNT(*) FROM roles WHERE id IN');
        $transaction = strpos($source, '$db->beginTransaction();', $validation ?: 0);
        $delete = strpos($source, 'DELETE FROM user_roles WHERE user_id = ?', $transaction ?: 0);
        self::assertNotFalse($validation);
        self::assertNotFalse($transaction);
        self::assertNotFalse($delete);
        self::assertLessThan($transaction, $validation);
        self::assertLessThan($delete, $transaction);
        self::assertStringContainsString('if ($db->inTransaction()) $db->rollBack();', $source);
    }

    public function testPortfolioDatabaseFailureIsLoggedAndJsonLdIsHexEncoded(): void
    {
        $source = $this->source('public/portfolio.php');
        self::assertStringContainsString("error_log(get_class(\$e) . ': ' . \$e->getMessage()", $source);
        self::assertStringNotContainsString('http_response_code(500);', $source); // the public page still renders without the table
        self::assertStringContainsString('JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', $source);
    }

    public function testNotificationReturnFailuresAreLoggedWithoutChangingSuccessFlow(): void
    {
        $source = $this->source('public/jobFlow/jobFlow-confirm.php');
        self::assertStringContainsString('sendQuoteRequestNotifications returned failure', $source);
        self::assertStringContainsString('sendQuoteConfirmationEmail returned failure', $source);
        self::assertStringContainsString("header('Location: jobFlow-success.php');", $source);
    }
}
