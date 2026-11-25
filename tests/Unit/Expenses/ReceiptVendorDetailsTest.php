<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Store details from a receipt header — extractVendorLocationFromOcr() was called by
 * intake since the 2026-09 parity pass but never defined, so vendor phone/website/
 * location back-fill never ran and the phone match (strongest vendor signal) only
 * worked for hand-typed phones. extractPhoneNumbers() also fixes that match: it
 * compared digits against text that prints "604-555-1234".
 */
class ReceiptVendorDetailsTest extends TestCase
{
    private const HOME_DEPOT = "THE HOME DEPOT\n2388 CAMBIE ST\nVANCOUVER, BC V5Z 2T8\n(604) 675-4890\nSTORE 7012\n"
        . "2X4X8 SPF        3 @ 4.97   14.91\nSUBTOTAL 14.91\nGST 0.75\nPST 1.04\nTOTAL 16.70\nwww.homedepot.ca";

    public function test_phone_formats_normalise_to_ten_digits(): void
    {
        $this->assertSame(['6046754890'], extractPhoneNumbers('Tel: (604) 675-4890'));
        $this->assertSame(['6046754890'], extractPhoneNumbers('604-675-4890'));
        $this->assertSame(['6046754890'], extractPhoneNumbers('604.675.4890'));
        $this->assertSame(['8004663337'], extractPhoneNumbers('1-800-466-3337'));
        $this->assertSame(['6046754890'], extractPhoneNumbers("(604) 675-4890\nCall 604-675-4890"));
    }

    public function test_amounts_and_card_numbers_are_not_phones(): void
    {
        $this->assertSame([], extractPhoneNumbers("TOTAL 604.55\nSUBTOTAL 1234.56\nVISA ************4890"));
        $this->assertSame([], extractPhoneNumbers('AUTH 123456 REF 0001234567'));
    }

    public function test_home_depot_header(): void
    {
        $d = extractVendorLocationFromOcr(self::HOME_DEPOT);
        $this->assertSame('(604) 675-4890', $d['phone']);
        $this->assertSame('homedepot.ca', $d['website']);
        $this->assertSame('2388 CAMBIE ST', $d['address']);
        $this->assertSame('Vancouver', $d['city']);
    }

    public function test_address_and_city_on_one_line(): void
    {
        $d = extractVendorLocationFromOcr("CANADIAN TIRE #391\n1350 W 4TH AVE, VANCOUVER, BC\n604-732-2233");
        $this->assertSame('1350 W 4TH AVE', $d['address']);
        $this->assertSame('Vancouver', $d['city']);
        $this->assertSame('(604) 732-2233', $d['phone']);
    }

    public function test_empty_or_bare_receipt_returns_nulls(): void
    {
        $this->assertSame(['phone' => null, 'website' => null, 'address' => null, 'city' => null], extractVendorLocationFromOcr(''));
        $d = extractVendorLocationFromOcr("SUBWAY\nTOTAL 12.50");
        $this->assertNull($d['phone']);
        $this->assertNull($d['address']);
    }

    public function test_email_domain_is_not_a_website(): void
    {
        $this->assertNull(extractVendorLocationFromOcr("Questions? help@shell.ca")['website']);
    }
}
