<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for QuoteService pure-logic methods.
 *
 * Tests that don't require a database (resolveContact, calculateTotals)
 * are fully unit-tested with no mocking.
 *
 * Tests that touch the DB (create, update, ensureAccessToken) use a mocked PDO.
 */
class QuoteServiceTest extends TestCase
{
    private function service(): QuoteService
    {
        return new QuoteService($this->createMock(PDO::class));
    }

    // ── resolveContact() ─────────────────────────────────────────────────────

    /** @test */
    public function resolveContact_prefers_quote_request_contact(): void
    {
        $quote = [
            'qr_email'            => 'qr@example.com',
            'contact_email'       => 'company@example.com',
            'prop_contact_email'  => 'prop@example.com',
            'billing_email'       => 'billing@example.com',
            'qr_phone'            => '604-111-1111',
            'contact_phone'       => null,
            'prop_contact_phone'  => null,
            'billing_phone'       => null,
            'qr_first_name'       => 'Alice',
            'qr_last_name'        => 'Smith',
            'contact_first'       => 'Bob',
            'contact_last'        => 'Jones',
            'prop_contact_first'  => null,
            'prop_contact_last'   => null,
            'company_name'        => 'ACME Corp',
            'qr_contact_id'       => 7,
            'contact_id'          => 2,
            'prop_contact_id'     => 3,
        ];

        $result = $this->service()->resolveContact($quote);

        $this->assertSame('qr@example.com', $result['email']);
        $this->assertSame('604-111-1111',   $result['phone']);
        $this->assertSame('Alice Smith',    $result['name']);
        $this->assertSame('Alice',          $result['first_name']);
        $this->assertSame(7,                $result['contact_id']);
    }

    /** @test */
    public function resolveContact_falls_back_to_company_contact(): void
    {
        $quote = [
            'qr_email'           => null,
            'contact_email'      => 'company@example.com',
            'prop_contact_email' => 'prop@example.com',
            'billing_email'      => null,
            'qr_phone'           => null,
            'contact_phone'      => '604-222-2222',
            'prop_contact_phone' => null,
            'billing_phone'      => null,
            'qr_first_name'      => null,
            'qr_last_name'       => null,
            'contact_first'      => 'Bob',
            'contact_last'       => 'Jones',
            'prop_contact_first' => null,
            'prop_contact_last'  => null,
            'company_name'       => 'ACME Corp',
            'qr_contact_id'      => null,
            'contact_id'         => 2,
            'prop_contact_id'    => 3,
        ];

        $result = $this->service()->resolveContact($quote);

        $this->assertSame('company@example.com', $result['email']);
        $this->assertSame('Bob Jones', $result['name']);
        $this->assertSame(2, $result['contact_id']);
    }

    /** @test */
    public function resolveContact_falls_back_to_property_contact(): void
    {
        $quote = [
            'qr_email'           => null,
            'contact_email'      => null,
            'prop_contact_email' => 'prop@example.com',
            'billing_email'      => null,
            'qr_phone'           => null,
            'contact_phone'      => null,
            'prop_contact_phone' => '604-333-3333',
            'billing_phone'      => null,
            'qr_first_name'      => null,
            'qr_last_name'       => null,
            'contact_first'      => null,
            'contact_last'       => null,
            'prop_contact_first' => 'Carol',
            'prop_contact_last'  => 'White',
            'company_name'       => null,
            'qr_contact_id'      => null,
            'contact_id'         => null,
            'prop_contact_id'    => 5,
        ];

        $result = $this->service()->resolveContact($quote);

        $this->assertSame('prop@example.com', $result['email']);
        $this->assertSame('Carol White', $result['name']);
        $this->assertSame(5, $result['contact_id']);
    }

    /** @test */
    public function resolveContact_falls_back_to_billing(): void
    {
        $quote = [
            'qr_email'           => null,
            'contact_email'      => null,
            'prop_contact_email' => null,
            'billing_email'      => 'billing@acme.com',
            'qr_phone'           => null,
            'contact_phone'      => null,
            'prop_contact_phone' => null,
            'billing_phone'      => '604-444-4444',
            'qr_first_name'      => null,
            'qr_last_name'       => null,
            'contact_first'      => null,
            'contact_last'       => null,
            'prop_contact_first' => null,
            'prop_contact_last'  => null,
            'company_name'       => 'ACME Corp',
            'qr_contact_id'      => null,
            'contact_id'         => null,
            'prop_contact_id'    => null,
        ];

        $result = $this->service()->resolveContact($quote);

        $this->assertSame('billing@acme.com', $result['email']);
        $this->assertSame('ACME Corp', $result['name']);
        $this->assertNull($result['contact_id']);
    }

    /** @test */
    public function resolveContact_no_data_returns_valued_customer(): void
    {
        $quote = array_fill_keys([
            'qr_email', 'contact_email', 'prop_contact_email', 'billing_email',
            'qr_phone', 'contact_phone', 'prop_contact_phone', 'billing_phone',
            'qr_first_name', 'qr_last_name', 'contact_first', 'contact_last',
            'prop_contact_first', 'prop_contact_last', 'company_name',
            'qr_contact_id', 'contact_id', 'prop_contact_id',
        ], null);

        $result = $this->service()->resolveContact($quote);

        $this->assertNull($result['email']);
        $this->assertNull($result['phone']);
        $this->assertSame('Valued Customer', $result['name']);
        $this->assertNull($result['contact_id']);
    }

    // ── resolveDisplayName() ──────────────────────────────────────────────────

    /** @test */
    public function resolveDisplayName_prefers_an_individual_person(): void
    {
        $quote = [
            'qr_first_name' => 'Alice', 'qr_last_name' => 'Smith',
            'contact_first' => 'Bob', 'contact_last' => 'Jones',
            'prop_contact_first' => null, 'prop_contact_last' => null,
            'company_name' => 'ACME Strata Mgmt',
            'property_billing_entity' => 'Strata Plan VR15/40',
        ];

        // A real person wins over the company/strata entity — residential unaffected.
        $this->assertSame('Alice Smith', $this->service()->resolveDisplayName($quote));
    }

    /** @test */
    public function resolveDisplayName_composes_strata_C_O_company_when_no_person(): void
    {
        $quote = [
            'qr_first_name' => null, 'qr_last_name' => null,
            'contact_first' => null, 'contact_last' => null,
            'prop_contact_first' => null, 'prop_contact_last' => null,
            'company_name' => 'FirstService Residential',
            'property_billing_entity' => 'Strata Plan VR15/40',
        ];

        $this->assertSame(
            'Strata Plan VR15/40 C/O FirstService Residential',
            $this->service()->resolveDisplayName($quote)
        );
    }

    /** @test */
    public function resolveDisplayName_falls_back_to_company_name(): void
    {
        $quote = [
            'qr_first_name' => null, 'qr_last_name' => null,
            'contact_first' => null, 'contact_last' => null,
            'prop_contact_first' => null, 'prop_contact_last' => null,
            'company_name' => 'ACME Corp',
            'property_billing_entity' => null,
        ];

        $this->assertSame('ACME Corp', $this->service()->resolveDisplayName($quote));
    }

    /** @test */
    public function resolveDisplayName_returns_na_when_nothing_resolvable(): void
    {
        $quote = array_fill_keys([
            'qr_first_name', 'qr_last_name', 'contact_first', 'contact_last',
            'prop_contact_first', 'prop_contact_last', 'company_name',
            'property_billing_entity',
        ], null);

        $this->assertSame('N/A', $this->service()->resolveDisplayName($quote));
    }

    // ── calculateTotals() ─────────────────────────────────────────────────────

    /** @test */
    public function calculateTotals_sums_line_totals_no_tax(): void
    {
        // When calculateQuoteTotals() isn't loaded, the service uses its own fallback
        $items = [
            ['line_total' => 100.00],
            ['line_total' => 50.00],
            ['line_total' => 25.50],
        ];

        $result = $this->service()->calculateTotals($items);

        $this->assertEqualsWithDelta(175.50, $result['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.00,   $result['tax_amount'], 0.001);
        $this->assertEqualsWithDelta(175.50, $result['total'], 0.001);
    }

    /** @test */
    public function calculateTotals_empty_items_returns_zeroes(): void
    {
        $result = $this->service()->calculateTotals([]);

        $this->assertEqualsWithDelta(0.00, $result['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.00, $result['total'], 0.001);
    }

    /** @test */
    public function calculateTotals_result_has_required_keys(): void
    {
        $result = $this->service()->calculateTotals([['line_total' => 100]]);

        $this->assertArrayHasKey('subtotal',   $result);
        $this->assertArrayHasKey('tax_rate',   $result);
        $this->assertArrayHasKey('tax_amount', $result);
        $this->assertArrayHasKey('total',      $result);
    }
}
