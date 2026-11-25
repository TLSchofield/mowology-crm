<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Businesses other than the original take no cards; their clients pay on QuickBooks' page (2026-11-09). */
class QboClientPayTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenant::forTests(null);
    }

    public function testOnlyTheOriginalBusinessTakesCards(): void
    {
        Tenant::forTests(['key' => Tenant::DEFAULT, 'cfg' => []]);
        $this->assertTrue(Tenant::takesCards());
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $this->assertFalse(Tenant::takesCards());
    }

    public function testNoPayLinkWhileQuickBooksHasOnlinePaymentOff(): void
    {
        $this->assertNull(QboClientPayService::payUrl(['InvoiceLink' => 'https://connect.intuit.com/pay', 'AllowOnlineCreditCardPayment' => false, 'AllowOnlineACHPayment' => false]));
    }

    public function testPayLinkMustBeIntuitsOwnAndSecure(): void
    {
        $on = ['AllowOnlineCreditCardPayment' => true];
        $this->assertSame('https://connect.intuit.com/pay?x=1', QboClientPayService::payUrl($on + ['InvoiceLink' => 'https://connect.intuit.com/pay?x=1']));
        $this->assertNull(QboClientPayService::payUrl($on + ['InvoiceLink' => 'http://connect.intuit.com/pay']));
        $this->assertNull(QboClientPayService::payUrl($on + ['InvoiceLink' => 'https://evilintuit.com/pay']));
        $this->assertNull(QboClientPayService::payUrl($on));
    }

    public function testBankPaymentAloneCountsAsOnlinePayment(): void
    {
        $this->assertNotNull(QboClientPayService::payUrl(['AllowOnlineACHPayment' => true, 'InvoiceLink' => 'https://connect.intuit.com/p']));
    }

    public function testPaidInFullInQuickBooksIsRecordedOnce(): void
    {
        $this->assertSame(['do' => 'record', 'amount' => 327.6], QboClientPayService::step(327.6, 0.0, 0.0, false));
        $this->assertSame('none', QboClientPayService::step(327.6, 0.0, 327.6, false)['do']);
    }

    public function testOnlyTheNewPartIsRecordedAfterAPartPayment(): void
    {
        $this->assertSame(['do' => 'record', 'amount' => 100.0], QboClientPayService::step(300.0, 100.0, 100.0, false));
    }

    public function testWaitsWhileIttaiHasAPaymentNotYetInQuickBooks(): void
    {
        $this->assertSame('wait', QboClientPayService::step(300.0, 200.0, 0.0, true)['do']);
    }

    public function testABalanceGoingBackUpIsFlaggedNeverUnpaid(): void
    {
        $this->assertSame(['do' => 'flag', 'amount' => 50.0], QboClientPayService::step(300.0, 250.0, 100.0, false));
    }

    public function testPaymentIdComesFromTheInvoice(): void
    {
        $this->assertSame('77', QboClientPayService::lastPaymentId(['LinkedTxn' => [['TxnType' => 'Estimate', 'TxnId' => '5'], ['TxnType' => 'Payment', 'TxnId' => '77']]]));
        $this->assertSame('qbo', QboClientPayService::lastPaymentId([]));
    }

    public function testTheOriginalBusinessInvoiceBodyDoesNotChange(): void
    {
        $b = QboSyncService::invoiceBody(['client_email' => 'pay@example.ca', 'issue_date' => '2026-11-09'], '1', [], '9');
        $this->assertArrayNotHasKey('BillEmail', $b);
        $this->assertArrayNotHasKey('AllowOnlineCreditCardPayment', $b);
    }

    public function testInvoiceCarriesTheClientsEmailSoQuickBooksMakesALink(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $b = QboSyncService::invoiceBody(['client_email' => 'pay@example.ca', 'issue_date' => '2026-11-09'], '1', [], '9');
        $this->assertSame('pay@example.ca', $b['BillEmail']['Address']);
        $this->assertTrue($b['AllowOnlineCreditCardPayment']);
    }

    public function testNoEmailMeansNoOnlinePaymentAsked(): void
    {
        Tenant::forTests(['key' => 'greenleaf', 'cfg' => []]);
        $b = QboSyncService::invoiceBody(['client_email' => 'not an email', 'issue_date' => '2026-11-09'], '1', [], '9');
        $this->assertArrayNotHasKey('BillEmail', $b);
        $this->assertArrayNotHasKey('AllowOnlineCreditCardPayment', $b);
    }

    public function testErrorsKeepIntuitsReference(): void
    {
        $this->assertSame('QuickBooks: no (Intuit ref 1-abc)', QboClient::withTid('QuickBooks: no', ['tid' => '1-abc']));
        $this->assertSame('QuickBooks: no', QboClient::withTid('QuickBooks: no', []));
    }

    public function testDiscoveryOnlyTakesIntuitsOwnAddresses(): void
    {
        $doc = (string)json_encode(['authorization_endpoint' => 'https://appcenter.intuit.com/a', 'token_endpoint' => 'https://bad.example.com/t', 'revocation_endpoint' => 'http://x.intuit.com/r']);
        $this->assertSame(['authorization_endpoint' => 'https://appcenter.intuit.com/a'], QboOAuth::parseDiscovery($doc));
        $this->assertSame([], QboOAuth::parseDiscovery('not json'));
    }

    public function testAFailedDiscoveryLeavesTheFixedAddress(): void
    {
        QboOAuth::discover('sandbox', fn(string $u) => '', sys_get_temp_dir() . '/qbo-disc-test-' . uniqid() . '.json', 1000);
        $this->assertStringStartsWith(QboOAuth::AUTH_URL . '?', QboOAuth::authUrl('id', 'https://x', 's'));
    }

    public function testAFailedDiscoveryIsNotRetriedOnEveryPage(): void
    {
        $file = sys_get_temp_dir() . '/qbo-disc-test-' . uniqid() . '.json';
        $calls = 0;
        $fetch = function (string $u) use (&$calls) { $calls++; return ''; };
        QboOAuth::discover('sandbox', $fetch, $file, time());
        QboOAuth::discover('sandbox', $fetch, $file, time());
        $this->assertSame(1, $calls);
        @unlink($file);
    }

    /** A new way of charging a card must not appear without the rule that keeps it to the original business. */
    public function testEveryCardChargeIsGated(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        foreach (['app', 'public/crm', 'public/customer', 'public/api'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') continue;
                $src = (string)file_get_contents($f->getPathname());
                if (preg_match('/(PaymentIntent|SetupIntent)::(create|confirm)\(|(paymentIntents|setupIntents)->(create|confirm)\(|refunds->create\(/', preg_replace('#^\s*(\*|//).*$#m', '', $src))) {
                    $found[substr($f->getPathname(), strlen($root) + 1)] = strpos($src, 'takesCards') !== false || strpos($src, 'this->stripe()') !== false;
                }
            }
        }
        $this->assertNotEmpty($found);
        $this->assertSame([], array_keys(array_filter($found, fn($gated) => !$gated)), 'charges a card with no Tenant::takesCards() rule');
        $this->assertSame(1, substr_count((string)file_get_contents($root . '/app/Modules/Invoices/Services/InvoiceService.php'), "Tenant::takesCards()"));
    }
}
