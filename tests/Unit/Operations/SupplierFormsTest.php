<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Supplier directory + account forms (migration 1320): the pure rules. */
final class SupplierFormsTest extends TestCase
{
    public function testFieldMatching(): void
    {
        $cases = [
            'company_name' => 'company', 'Business Name' => 'company', 'address' => 'street', 'Street Address' => 'street',
            'city' => 'city', 'postal_code' => 'postal', 'Zip' => 'postal', 'phone' => 'phone', 'Telephone' => 'phone',
            'cell' => 'cell', 'Mobile' => 'cell', 'fax' => 'fax', 'gst_number' => 'gst', 'GST#' => 'gst', 'Business No.' => 'gst',
            'contact_person' => 'contact', 'email' => 'email', 'Contact Email' => 'email', 'website' => 'website',
            'email_newsletter_optin' => 'newsletter', 'Business Licence #' => 'licence_no', 'companyPhone' => 'phone',
            // never Otto's to answer
            'Bank Name' => 'yours', 'Transit Number' => 'yours', 'Account Number' => 'yours', 'Trade References' => 'yours',
            'Personal Guarantee' => 'yours', 'Signature' => 'yours', 'Date' => 'yours', 'SIN' => 'yours', 'Credit Limit Requested' => 'yours',
            'Favourite colour' => null,
        ];
        foreach ($cases as $field => $want) {
            $this->assertSame($want, CompanyProfileService::matchField($field), $field);
        }
    }

    public function testParseAddress(): void
    {
        $this->assertSame(['2845 West 15th Ave', 'Vancouver', 'BC', 'V6K 3A1'], CompanyProfileService::parseAddress('2845 West 15th Ave Vancouver V6K 3A1', 'BC'));
        $this->assertSame(['7024 Glover Road', 'Langley', 'BC', 'V2Y 2R1'], CompanyProfileService::parseAddress('7024 Glover Road, Langley, BC V2Y 2R1'));
        $this->assertSame(['100 Main St E', 'North Vancouver', '', 'V7L 1A1'], CompanyProfileService::parseAddress('100 Main St E North Vancouver v7l1a1'));
        $this->assertSame('(778) 846-9273', CompanyProfileService::phone('778 846 9273'));
        $this->assertSame('(604) 888-4491', CompanyProfileService::phone('1-604-888-4491'));
    }

    public function testAnswerUsesProfileAndHoldsBack(): void
    {
        $fields = ['company_name' => ['type' => 'text'], 'fax' => ['type' => 'text'], 'email_newsletter_optin' => ['type' => 'checkbox'],
                   'Bank Name' => ['type' => 'text'], 'Sign here' => ['type' => 'signature'], 'agree_terms' => ['type' => 'checkbox'],
                   'City/Province' => ['type' => 'text']];
        $a = CompanyProfileService::answer($fields, ['company' => 'Mowology Lawns & Landscapes Ltd (Mowology)', 'fax' => '', 'city_prov' => 'Vancouver, BC']);
        $this->assertSame(['company_name' => 'Mowology Lawns & Landscapes Ltd (Mowology)', 'email_newsletter_optin' => true, 'City/Province' => 'Vancouver, BC'], $a['answers']);
        $this->assertSame(['fax', 'agree_terms'], $a['blanks']);
        $this->assertSame(['Bank Name', 'Sign here'], $a['yours']);
    }

    public function testParseLicence(): void
    {
        $t = "2026 LICENCE OFFICE MOWOLOGY Licence # 26-105147 May 30, 2026 December 31, 2026 515 West 10th Avenue Vancouver";
        $this->assertSame(['number' => '26-105147', 'issued' => '2026-05-30', 'expires' => '2026-12-31'], CompanyDocumentService::parseLicence($t));
        $this->assertSame(['number' => null, 'issued' => null, 'expires' => null], CompanyDocumentService::parseLicence('Your receipt'));
        $this->assertSame('soon', CompanyDocumentService::expiryState('2026-12-31', '2026-12-10'));
        $this->assertSame('ok', CompanyDocumentService::expiryState('2026-12-31', '2026-10-10'));
        $this->assertSame('expired', CompanyDocumentService::expiryState('2025-12-31', '2026-01-02'));
        $this->assertSame('unknown', CompanyDocumentService::expiryState(null, '2026-01-02'));
    }

    public function testLooksLikeForm(): void
    {
        $this->assertTrue(SupplierFormService::looksLikeForm('Wholesale AccAppCOD (Fillable).pdf', 'Re: Contractor wholesale program', '', 12));
        $this->assertTrue(SupplierFormService::looksLikeForm('Credit Application.pdf', '', '', 3));
        $this->assertFalse(SupplierFormService::looksLikeForm('Wholesale Availability.pdf', 'Re: x', 'stock list attached', 0), 'no fields = not a form');
        $this->assertFalse(SupplierFormService::looksLikeForm('Invoice 1234.pdf', 'Your invoice', '', 4));
        $this->assertTrue(SupplierFormService::looksLikeForm('doc.pdf', 'hello', '', 6), 'many fields reads as a form');
        $this->assertTrue(SupplierFormService::wantsLicence('Please attach copy of your Business License'));
        $this->assertFalse(SupplierFormService::wantsLicence('Thanks for your order'));
    }

    public function testFlatApplications(): void
    {
        $this->assertTrue(SupplierFormService::looksLikeApplication('Foreshore Equipment and Supply - Credit Application.pdf', 'Re: Commercial account'));
        $this->assertFalse(SupplierFormService::looksLikeApplication('Wholesale Availability.pdf', 'Re: Contractor wholesale program'));
        $this->assertFalse(SupplierFormService::looksLikeApplication('Credit memo invoice 12.pdf', ''));
        $text = 'CREDIT APPLICATION Name of Applicant: S.I.N. #: Trade References: Bank Reference: Bank: A/C #: Credit Card #: The applicant hereby guarantees';
        $this->assertSame(['your SIN', 'a bank reference', 'a credit card', 'trade references', 'a personal guarantee'], SupplierFormService::ownerAsks($text));
        $this->assertSame('a, b and c', SupplierFormService::listing(['a', 'b', 'c']));
        $this->assertSame('a', SupplierFormService::listing(['a']));
    }

    public function testRecipients(): void
    {
        $r = SupplierFormService::recipients('Jon <jon@cedarrim.com>', 'mowology@icloud.com, Accounting <accounting@cedarrim.com>, jon@cedarrim.com, office@mowology.ca',
                                             ['@mowology.ca', 'mowology@icloud.com']);
        $this->assertSame(['to' => 'jon@cedarrim.com', 'cc' => ['accounting@cedarrim.com']], $r);
    }

    public function testNoteAndDirectoryHelpers(): void
    {
        $n = SupplierFormService::note(true, true, 'office@mowology.ca', ['contact' => 'Tim Schofield', 'company' => 'Mowology', 'phone' => '(778) 846-9273']);
        $this->assertStringContainsString('a copy of our business licence', $n);
        $this->assertStringContainsString('office@mowology.ca for our account and your newsletter', $n);
        $this->assertSame('cedarrim.com', SupplierDirectoryService::domainOf('Jon <jon@CedarRim.com>'));
        $this->assertSame('westernturffarms.com', SupplierDirectoryService::domainOf('https://www.westernturffarms.com/sod'));
        $this->assertSame('linnaea@telus.net', SupplierDirectoryService::mailKey('linnaea@telus.net'), 'shared domains match the whole address');
        $this->assertSame('cedarrim.com', SupplierDirectoryService::mailKey('sales@cedarrim.com'));
        $this->assertEqualsWithDelta(111.19, SupplierDirectoryService::km(49.0, -123.0, 50.0, -123.0), 0.01);  // one degree of latitude
    }
}
