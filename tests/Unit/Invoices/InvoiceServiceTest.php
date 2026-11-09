<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for InvoiceService — Phase 1a methods.
 *
 * No real Stripe calls. We inject a fake Stripe client via
 * setStripeClientFactory() that exposes ->customers->create() and
 * ->paymentIntents->retrieve()/create() with predictable behavior.
 *
 * The DB is mocked via PDO::createMock; we assert against prepared
 * statements + execute() params.
 */
class InvoiceServiceTest extends TestCase
{
    /**
     * Build a fake Stripe client with configurable behavior. Returns an
     * anonymous object with the same shape the SDK exposes (->customers,
     * ->paymentIntents) so duck-typing works in the service.
     *
     * @param array $opts
     *   - 'pi_create_id': PaymentIntent ID to return on ->create()
     *   - 'pi_create_secret': client_secret to return on ->create()
     *   - 'pi_retrieve': stdClass or null. If set, retrieve() returns it.
     *   - 'pi_retrieve_throws': exception to throw on retrieve()
     *   - 'cust_create_id': Customer ID to return on ->create()
     */
    private function fakeStripe(array $opts = []): object
    {
        $piCreateId     = $opts['pi_create_id']     ?? 'pi_TEST_NEW';
        $piCreateSecret = $opts['pi_create_secret'] ?? 'pi_TEST_NEW_secret_AAAA';
        $piRetrieve     = $opts['pi_retrieve']      ?? null;
        $piRetrieveErr  = $opts['pi_retrieve_throws'] ?? null;
        $custCreateId   = $opts['cust_create_id']   ?? 'cus_TEST_NEW';

        $paymentIntents = new class($piCreateId, $piCreateSecret, $piRetrieve, $piRetrieveErr) {
            public array $createCalls = [];
            public array $retrieveCalls = [];
            private $newId; private $newSecret; private $existingPi; private $retrieveErr;
            public function __construct($newId, $newSecret, $existingPi, $retrieveErr) {
                $this->newId = $newId; $this->newSecret = $newSecret;
                $this->existingPi = $existingPi; $this->retrieveErr = $retrieveErr;
            }
            public function create(array $params) {
                $this->createCalls[] = $params;
                return (object)[
                    'id'             => $this->newId,
                    'client_secret'  => $this->newSecret,
                    'amount'         => $params['amount'] ?? 0,
                    'status'         => 'requires_payment_method',
                ];
            }
            public function retrieve(string $id) {
                $this->retrieveCalls[] = $id;
                if ($this->retrieveErr) throw $this->retrieveErr;
                return $this->existingPi;
            }
        };

        $customers = new class($custCreateId) {
            public array $createCalls = [];
            private $newId;
            public function __construct($newId) { $this->newId = $newId; }
            public function create(array $params) {
                $this->createCalls[] = $params;
                return (object)['id' => $this->newId];
            }
        };

        return new class($paymentIntents, $customers) {
            public $paymentIntents; public $customers;
            public function __construct($pi, $c) { $this->paymentIntents = $pi; $this->customers = $c; }
        };
    }

    /**
     * Build a PDO mock that returns one statement per prepare() call,
     * with the specified fetch result.
     */
    private function mockDb(array $rowsByQueryFragment): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(false);

        $self = $this;
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($self, $rowsByQueryFragment) {
            $stmt = $self->createMock(PDOStatement::class);
            // Find the matching fixture row by SQL substring
            foreach ($rowsByQueryFragment as $fragment => $row) {
                if (strpos($sql, $fragment) !== false) {
                    if ($row === null) {
                        $stmt->method('fetch')->willReturn(false);
                    } else {
                        $stmt->method('fetch')->willReturn($row);
                    }
                    return $stmt;
                }
            }
            // Default: an UPDATE-style stmt that just succeeds
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });
        return $db;
    }

    // ── ensureAccessToken() ────────────────────────────────────────────────

    /** @test */
    public function ensureAccessToken_returns_existing_unexpired_token(): void
    {
        $existing = str_repeat('a', 64);
        $db = $this->mockDb([
            'SELECT access_token, token_expires_at FROM invoices' => [
                'access_token'     => $existing,
                'token_expires_at' => date('Y-m-d H:i:s', time() + 86400 * 30),
            ],
        ]);
        $svc = new InvoiceService($db);
        $this->assertSame($existing, $svc->ensureAccessToken(42));
    }

    /** @test */
    public function ensureAccessToken_generates_new_when_missing(): void
    {
        $db = $this->mockDb([
            'SELECT access_token, token_expires_at FROM invoices' => [
                'access_token'     => null,
                'token_expires_at' => null,
            ],
        ]);
        $svc = new InvoiceService($db);
        $token = $svc->ensureAccessToken(42);
        $this->assertSame(64, strlen($token), '32 random bytes hex-encoded = 64 chars');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    /** @test */
    public function ensureAccessToken_regenerates_when_expired(): void
    {
        $stale = str_repeat('b', 64);
        $db = $this->mockDb([
            'SELECT access_token, token_expires_at FROM invoices' => [
                'access_token'     => $stale,
                'token_expires_at' => date('Y-m-d H:i:s', time() - 86400),
            ],
        ]);
        $svc = new InvoiceService($db);
        $token = $svc->ensureAccessToken(42);
        $this->assertNotSame($stale, $token);
    }

    /** @test */
    public function ensureAccessToken_throws_when_invoice_missing(): void
    {
        $db = $this->mockDb([
            'SELECT access_token, token_expires_at FROM invoices' => null,
        ]);
        $svc = new InvoiceService($db);
        $this->expectException(InvalidArgumentException::class);
        $svc->ensureAccessToken(999);
    }

    // ── ensurePaymentIntent() ──────────────────────────────────────────────

    /** @test */
    public function ensurePaymentIntent_refuses_when_in_transaction(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('inTransaction')->willReturn(true);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $this->fakeStripe());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be called inside a DB transaction');
        $svc->ensurePaymentIntent(1);
    }

    /** @test */
    public function ensurePaymentIntent_refuses_zero_balance(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 1, 'invoice_number' => 'INV-001', 'balance_due' => '0.00',
                'contact_id' => 22, 'stripe_payment_intent_id' => null, 'stripe_client_secret' => null,
                'first_name' => 'Tim', 'last_name' => 'Schofield', 'email' => 'tim@example.com',
                'stripe_customer_id' => 'cus_existing',
            ],
        ]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $this->fakeStripe());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no outstanding balance');
        $svc->ensurePaymentIntent(1);
    }

    /** @test */
    public function ensurePaymentIntent_creates_new_pi_when_none_exists(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 21, 'invoice_number' => 'INV-2026-0015', 'balance_due' => '84.00',
                'contact_id' => 78, 'stripe_payment_intent_id' => null, 'stripe_client_secret' => null,
                'first_name' => 'Mariam', 'last_name' => 'Rassem', 'email' => 'mmmr90@gmail.com',
                'stripe_customer_id' => 'cus_existing',
            ],
        ]);
        $stripe = $this->fakeStripe([
            'pi_create_id'     => 'pi_NEW_FOR_84',
            'pi_create_secret' => 'pi_NEW_FOR_84_secret_xyz',
        ]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $secret = $svc->ensurePaymentIntent(21);

        $this->assertSame('pi_NEW_FOR_84_secret_xyz', $secret);
        $this->assertCount(1, $stripe->paymentIntents->createCalls);
        $createParams = $stripe->paymentIntents->createCalls[0];
        $this->assertSame(8400, $createParams['amount']);
        $this->assertSame('cad', $createParams['currency']);
        $this->assertSame(['card'], $createParams['payment_method_types']);
        $this->assertSame('cus_existing', $createParams['customer']);
        $this->assertSame('21', $createParams['metadata']['invoice_id']);
        $this->assertSame('INV-2026-0015', $createParams['metadata']['invoice_number']);
    }

    /** @test */
    public function ensurePaymentIntent_creates_stripe_customer_when_contact_has_none(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 5, 'invoice_number' => 'INV-X', 'balance_due' => '50.00',
                'contact_id' => 99, 'stripe_payment_intent_id' => null, 'stripe_client_secret' => null,
                'first_name' => 'New', 'last_name' => 'Customer', 'email' => 'new@example.com',
                'stripe_customer_id' => null,
            ],
        ]);
        $stripe = $this->fakeStripe(['cust_create_id' => 'cus_BRAND_NEW']);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $svc->ensurePaymentIntent(5);

        $this->assertCount(1, $stripe->customers->createCalls);
        $custParams = $stripe->customers->createCalls[0];
        $this->assertSame('new@example.com', $custParams['email']);
        $this->assertSame('New Customer', $custParams['name']);
        $this->assertSame('99', $custParams['metadata']['contact_id']);

        // The new customer ID is then passed to PI create
        $this->assertSame('cus_BRAND_NEW', $stripe->paymentIntents->createCalls[0]['customer']);
    }

    /** @test */
    public function ensurePaymentIntent_reuses_existing_card_only_pi_with_matching_amount(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 1, 'invoice_number' => 'INV-1', 'balance_due' => '100.00',
                'contact_id' => 1, 'stripe_payment_intent_id' => 'pi_EXISTING',
                'stripe_client_secret' => 'pi_EXISTING_secret_old',
                'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com',
                'stripe_customer_id' => 'cus_x',
            ],
        ]);
        $existing = (object)[
            'id'                   => 'pi_EXISTING',
            'client_secret'        => 'pi_EXISTING_secret_FRESH',
            'amount'               => 10000, // matches $100.00
            'status'               => 'requires_payment_method',
            'payment_method_types' => ['card'],
        ];
        $stripe = $this->fakeStripe(['pi_retrieve' => $existing]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $secret = $svc->ensurePaymentIntent(1);

        $this->assertSame('pi_EXISTING_secret_FRESH', $secret);
        $this->assertCount(0, $stripe->paymentIntents->createCalls,
            'must not create a new PI when an existing one is reusable');
        $this->assertCount(1, $stripe->paymentIntents->retrieveCalls);
    }

    /** @test */
    public function ensurePaymentIntent_creates_fresh_when_existing_pi_has_wrong_amount(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 1, 'invoice_number' => 'INV-1', 'balance_due' => '100.00',
                'contact_id' => 1, 'stripe_payment_intent_id' => 'pi_OLD',
                'stripe_client_secret' => null,
                'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com',
                'stripe_customer_id' => 'cus_x',
            ],
        ]);
        $existing = (object)[
            'id' => 'pi_OLD', 'client_secret' => 'pi_OLD_secret',
            'amount' => 5000,             // $50 — invoice now $100, mismatch
            'status' => 'requires_payment_method',
            'payment_method_types' => ['card'],
        ];
        $stripe = $this->fakeStripe([
            'pi_retrieve'      => $existing,
            'pi_create_id'     => 'pi_FRESH',
            'pi_create_secret' => 'pi_FRESH_secret',
        ]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $secret = $svc->ensurePaymentIntent(1);
        $this->assertSame('pi_FRESH_secret', $secret);
        $this->assertCount(1, $stripe->paymentIntents->createCalls);
    }

    /** @test */
    public function ensurePaymentIntent_creates_fresh_when_existing_pi_already_succeeded(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 1, 'invoice_number' => 'INV-1', 'balance_due' => '100.00',
                'contact_id' => 1, 'stripe_payment_intent_id' => 'pi_PAID',
                'stripe_client_secret' => null,
                'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com',
                'stripe_customer_id' => 'cus_x',
            ],
        ]);
        $existing = (object)[
            'id' => 'pi_PAID', 'client_secret' => 'pi_PAID_secret',
            'amount' => 10000, 'status' => 'succeeded',  // already paid → not reusable
            'payment_method_types' => ['card'],
        ];
        $stripe = $this->fakeStripe([
            'pi_retrieve'  => $existing,
            'pi_create_id' => 'pi_FRESH',
        ]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $svc->ensurePaymentIntent(1);
        $this->assertCount(1, $stripe->paymentIntents->createCalls,
            'a succeeded PI must not be reused for a new charge');
    }

    /** @test */
    public function ensurePaymentIntent_creates_fresh_when_retrieve_fails(): void
    {
        if (!class_exists(\Stripe\Exception\InvalidRequestException::class)) {
            $this->markTestSkipped('stripe-php SDK not autoloaded — requires public/vendor');
        }

        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 1, 'invoice_number' => 'INV-1', 'balance_due' => '100.00',
                'contact_id' => 1, 'stripe_payment_intent_id' => 'pi_GHOST',
                'stripe_client_secret' => null,
                'first_name' => 'A', 'last_name' => 'B', 'email' => 'a@b.com',
                'stripe_customer_id' => 'cus_x',
            ],
        ]);
        $stripe = $this->fakeStripe([
            'pi_retrieve_throws' => new \Stripe\Exception\InvalidRequestException('No such payment_intent: pi_GHOST'),
            'pi_create_id'       => 'pi_FRESH',
        ]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $svc->ensurePaymentIntent(1);
        $this->assertCount(1, $stripe->paymentIntents->createCalls);
    }

    /** @test */
    public function ensurePaymentIntent_throws_when_invoice_not_found(): void
    {
        $db = $this->mockDb(['FROM invoices i' => null]);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $this->fakeStripe());

        $this->expectException(InvalidArgumentException::class);
        $svc->ensurePaymentIntent(404);
    }

    // ── enableV2PaymentFlow() ──────────────────────────────────────────────

    /** @test */
    public function enableV2PaymentFlow_sets_flag_and_returns_secret(): void
    {
        $db = $this->mockDb([
            'FROM invoices i' => [
                'id' => 9, 'invoice_number' => 'INV-9', 'balance_due' => '25.00',
                'contact_id' => 22, 'stripe_payment_intent_id' => null,
                'stripe_client_secret' => null,
                'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.com',
                'stripe_customer_id' => 'cus_existing',
            ],
        ]);
        $stripe = $this->fakeStripe(['pi_create_secret' => 'pi_NEW_secret_v2']);
        $svc = new InvoiceService($db);
        $svc->setStripeClientFactory(fn() => $stripe);

        $secret = $svc->enableV2PaymentFlow(9);
        $this->assertSame('pi_NEW_secret_v2', $secret);
    }
}
