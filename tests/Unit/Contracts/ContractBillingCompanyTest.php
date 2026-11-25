<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ContractBillingCompanyTest extends TestCase
{
    public function test_property_billing_company_wins(): void
    {
        $this->assertSame(3, ContractBillingCompany::resolve(3, 15, 9));
    }

    public function test_managing_firm_beats_the_signatorys_company(): void
    {
        $this->assertSame(15, ContractBillingCompany::resolve(null, 15, 9));
    }

    /** Laburnum Heights: no billing company, signatory in no company, firm 15 -> firm. */
    public function test_laburnum_gets_the_firm(): void
    {
        $this->assertSame(15, ContractBillingCompany::resolve(null, '15', null));
    }

    public function test_signatorys_company_only_when_not_managed(): void
    {
        $this->assertSame(9, ContractBillingCompany::resolve(0, null, 9));
        $this->assertNull(ContractBillingCompany::resolve(null, 0, null));
    }
}
