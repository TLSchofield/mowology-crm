<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Modules/Invoices/Services/PaymentIntentGuard.php';
require_once __DIR__ . '/../../app/Modules/Accounting/Services/StripeEventLedgerService.php';

final class StripeMoneySafetyTest extends TestCase
{
    public function testPaymentIntentNamesAreStableAndAttemptSpecific(): void
    {
        self::assertSame('invoice_pi_42', PaymentIntentGuard::lockName(42));
        self::assertSame('inv-42-12345-0', PaymentIntentGuard::idempotencyKey(42, 12345, 0));
        self::assertSame('inv-42-12345-1', PaymentIntentGuard::idempotencyKey(42, 12345, 1));
    }

    public function testSucceededAndProcessingIntentsBlockCreation(): void
    {
        self::assertTrue(PaymentIntentGuard::blocksCreate('succeeded'));
        self::assertTrue(PaymentIntentGuard::blocksCreate('processing'));
        self::assertFalse(PaymentIntentGuard::blocksCreate('canceled'));
        self::assertFalse(PaymentIntentGuard::blocksCreate('requires_payment_method'));

        foreach ([
            'app/Modules/Invoices/Services/InvoiceService.php',
            'public/crm/api/stripe/create-payment-intent.php',
            'public/customer/api/invoice-payment-intent.php',
        ] as $path) {
            $source = file_get_contents(__DIR__ . '/../../' . $path);
            self::assertStringContainsString('PaymentIntentGuard::lockName', $source, $path);
            self::assertStringContainsString('PaymentIntentGuard::idempotencyKey', $source, $path);
            self::assertStringContainsString('PaymentIntentGuard::blocksCreate', $source, $path);
        }
    }

    public function testStripeEventDateUsesVancouverAtDayBoundary(): void
    {
        self::assertSame('2023-12-31', StripeEventLedgerService::entryDate(1704094200));
    }

    public function testWebhookTurnsLedgerFailureIntoHttp500(): void
    {
        $source = file_get_contents(__DIR__ . '/../../public/api/stripe/webhook.php');
        self::assertStringContainsString("if (\$ok === false)", $source);
        self::assertMatchesRegularExpression('/if \(\$ok === false\).*?http_response_code\(500\)/s', $source);
        self::assertStringContainsString("'event_time'", $source);
    }

    public function testRefundAndPayoutLocksAndFailureRulesArePresent(): void
    {
        $events = file_get_contents(__DIR__ . '/../../app/Modules/Accounting/Services/StripeEventLedgerService.php');
        self::assertStringContainsString("'stripe_refund_' . md5", $events);
        self::assertStringContainsString('SELECT RELEASE_LOCK(?)', $events);
        self::assertStringContainsString('charge.dispute.won_early', $events);
        self::assertStringContainsString('throw $e;', $events);

        $payout = file_get_contents(__DIR__ . '/../../app/Modules/Accounting/Services/StripePayoutService.php');
        self::assertStringContainsString("'stripe_payout_' . md5", $payout);
        self::assertStringContainsString('stripe_payout_reversal_failed', $payout);
        self::assertStringContainsString('reversal needs a look', $payout);
    }

    public function testRefundRetryAndLockedPeriodRulesArePresent(): void
    {
        $source = file_get_contents(__DIR__ . '/../../app/Modules/Invoices/Services/InvoiceRefundService.php');
        self::assertStringContainsString('ORDER BY id DESC LIMIT 1 FOR UPDATE', $source);
        self::assertStringContainsString("'mw-refund-' . \$refundRowId", $source);
        self::assertStringContainsString('refund_in_locked_period', $source);
        self::assertStringContainsString('isLocked', $source);
    }

    public function testStaleRefundRowIsReusedOnlyForTheSameAmountWithinTheIdempotencyWindow(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Invoices/Services/InvoiceRefundService.php');
        self::assertStringContainsString('INTERVAL 23 HOUR', $src);
        self::assertStringContainsString('$staleCents !== $cents', $src);
        self::assertStringContainsString('was never confirmed. Check Stripe', $src);
    }

    public function testEventDateFallsBackToTodayWhenItsMonthIsClosed(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Accounting/Services/StripeEventLedgerService.php');
        self::assertStringContainsString('private function bookingDate', $src);
        self::assertStringContainsString("isLocked(\$d) ? date('Y-m-d') : \$d", $src);
    }

    public function testIntentLocksAreTakenTokenFirstThenInvoice(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/public/crm/api/stripe/create-payment-intent.php');
        self::assertLessThan(strpos($src, 'PaymentIntentGuard::lockName($invoiceId)'), strpos($src, "'mw_inv_pay_'"));
    }
}
