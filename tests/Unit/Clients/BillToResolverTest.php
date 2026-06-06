<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for BillToResolver pure resolution logic (no DB).
 *
 * Locks in the bill-to precedence so it can't silently drift as the
 * Client/Account model migrates through its phases.
 * See docs/architecture/client-account-model.md.
 */
class BillToResolverTest extends TestCase
{
    private function resolver(): BillToResolver
    {
        return new BillToResolver($this->createMock(PDO::class));
    }

    // ── composeBillToName ────────────────────────────────────────────────────

    public function testIndividualUsesContactName(): void
    {
        $name = $this->resolver()->composeBillToName([
            'contact_first' => 'Jane',
            'contact_last'  => 'Doe',
        ]);
        $this->assertSame('Jane Doe', $name);
    }

    public function testCompanyAloneUsesCompanyName(): void
    {
        $name = $this->resolver()->composeBillToName([
            'company_name'  => 'FirstService Residential',
            'contact_first' => 'Jane',
            'contact_last'  => 'Doe',
        ]);
        $this->assertSame('FirstService Residential', $name);
    }

    public function testPropertyEntityAloneWins(): void
    {
        $name = $this->resolver()->composeBillToName([
            'property_billing_entity' => 'Strata Plan VR15/40',
        ]);
        $this->assertSame('Strata Plan VR15/40', $name);
    }

    public function testEntityWithCompanyRendersCareOf(): void
    {
        $name = $this->resolver()->composeBillToName([
            'property_billing_entity' => 'Strata Plan VR15/40',
            'company_name'            => 'FirstService Residential',
        ]);
        $this->assertSame('Strata Plan VR15/40 C/O FirstService Residential', $name);
    }

    public function testContractTitleActsAsEntityOnlyWhenCompanyPresent(): void
    {
        // With a company → contract title becomes the entity, rendered C/O.
        $withCompany = $this->resolver()->composeBillToName([
            'contract_title' => 'Annual Grounds Maintenance',
            'company_name'   => 'FirstService Residential',
        ]);
        $this->assertSame('Annual Grounds Maintenance C/O FirstService Residential', $withCompany);

        // Without a company → contract title is ignored, falls back to contact.
        $noCompany = $this->resolver()->composeBillToName([
            'contract_title' => 'Annual Grounds Maintenance',
            'contact_first'  => 'Jane',
            'contact_last'   => 'Doe',
        ]);
        $this->assertSame('Jane Doe', $noCompany);
    }

    public function testEmptyRowResolvesToNull(): void
    {
        $this->assertNull($this->resolver()->composeBillToName([]));
    }

    // ── normalize ────────────────────────────────────────────────────────────

    public function testNormalizeStrataShape(): void
    {
        $out = $this->resolver()->normalize([
            'property_billing_entity' => 'Strata Plan VR15/40',
            'company_name'            => 'FirstService Residential',
            'company_type'            => 'strata',
            'company_id'              => '7',
            'property_id'             => '12',
            'site_contact_id'         => '3',
            'address'                 => '123 Main St',
            'city'                    => 'Vancouver',
            'province'                => 'BC',
            'postal_code'             => 'V6B 1A1',
            'contact_first'           => 'Jane',
            'contact_last'            => 'Doe',
            'contact_email'           => 'jane@example.com',
            'contact_mobile'          => '7788469273',
            'contact_receive_sms'     => 1,
        ]);

        $this->assertSame('Strata Plan VR15/40 C/O FirstService Residential', $out['bill_to_name']);
        $this->assertSame('strata', $out['account_type']);
        $this->assertSame(7, $out['company_id']);
        $this->assertSame(12, $out['property_id']);
        $this->assertSame(3, $out['contact_id']);
        $this->assertSame('123 Main St', $out['billing_address']);
        $this->assertSame('Jane Doe', $out['primary_contact']['name']);
        $this->assertTrue($out['primary_contact']['receive_sms']);
    }

    public function testNormalizeIndividualDefaults(): void
    {
        $out = $this->resolver()->normalize([
            'contact_first' => 'Jane',
            'contact_last'  => 'Doe',
        ]);

        $this->assertSame('Jane Doe', $out['bill_to_name']);
        $this->assertSame('individual', $out['account_type']);
        $this->assertNull($out['company_id']);
        $this->assertNull($out['property_id']);
        $this->assertSame('BC', $out['billing_province']);
        $this->assertFalse($out['primary_contact']['receive_sms']);
    }
}
