<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** A vendor's details from what its receipts print (Tim, 2026-10-15). */
class VendorDetailsServiceTest extends TestCase
{
    private const CANADIAN_TIRE = "CANADIAN\nTIRE\nLOCALLY OWNED AND OPERATED\nPROUDLY CANADIAN SINCE 1922\nSTORE #605 - MARINE & ONTARIO\n"
        . "8277 Ontario Street, Vancouver 336-1086\nREG #:42 09/15/2026 08:50:05 TRANS #:5\nOPERATOR #: 444 Float: 001\n051-5065-4\n(COKE ZERO 500M \$\n2.69\n"
        . "SUBTOTAL\n\$\n34.85\nGST 5%\n\$\n1.74\nTOTAL\n\$\n39.02\nGST REG # 103041367RT0001\n";
    private const RONA = "RONA+ Vancouver - Grandview\n83704\n2727 East 12th Avenue\nVancouver, BC, V5N 4W3\n604-253-2822\nITEM\nQTY PRICE\nTOTAL\n"
        . "3295249290177 2 32.49EA\nEARMUFF GREY 28 DECIBEL\n64.98P\nSubtotal:\n\$64.98\nTotal:\n\$68.23\nRONA Inc.\nGST/HST # 103039624RT0001\n"
        . "www.rona.ca/en/returns-and-refunds\nCall 1-866-283-2239 for help\n";

    public function test_canadian_tire(): void
    {
        $r = VendorDetailsService::read(self::CANADIAN_TIRE);
        $this->assertSame('605', $r['store_number']);
        $this->assertSame('Marine & Ontario', $r['store_name']);
        $this->assertSame('8277 Ontario Street', $r['address']);
        $this->assertSame('103041367RT0001', $r['gst_number']);
        $this->assertNull($r['phone'], 'a seven-digit number is not a phone we can use');
    }

    public function test_rona(): void
    {
        $r = VendorDetailsService::read(self::RONA);
        $this->assertSame('2727 East 12th Avenue', $r['address']);
        $this->assertSame('Vancouver', $r['city']);
        $this->assertSame('(604) 253-2822', $r['phone']);
        $this->assertSame('rona.ca', $r['website']);
        $this->assertSame('103039624RT0001', $r['gst_number']);
        $this->assertNull($r['store_number'], '83704 alone is not labelled as a store number');
    }

    public function test_the_hotline_at_the_bottom_is_not_the_store_phone(): void
    {
        $r = VendorDetailsService::read("SOME STORE\n123 Main St\n" . str_repeat("ITEM 1.00\n", 15) . "Call 604-555-1234\n");
        $this->assertNull($r['phone']);
    }

    public function test_street_keys_match_spelled_and_short_forms(): void
    {
        $this->assertSame(VendorDetailsService::streetKey('8277 Ontario Street'), VendorDetailsService::streetKey('8277 ONTARIO ST.'));
        $this->assertNull(VendorDetailsService::streetKey(' '));
    }

    public function test_only_the_vendors_own_website(): void
    {
        $this->assertTrue(VendorDetailsService::ownSite('rona.ca', 'Rona'));
        $this->assertTrue(VendorDetailsService::ownSite('https://www.dunbarlumber.com', 'Dunbar Lumber'));
        $this->assertTrue(VendorDetailsService::ownSite('canadiantire.ca', 'Canadian Tire'));
        $this->assertFalse(VendorDetailsService::ownSite('triangle.com', 'Canadian Tire'), 'its loyalty programme');
        $this->assertFalse(VendorDetailsService::ownSite('journie.ca', 'CHEVRON'));
        $this->assertFalse(VendorDetailsService::ownSite('rona.ca', 'Home Depot'), 'a misfiled receipt');
        $this->assertFalse(VendorDetailsService::ownSite('point-of-rental.com', 'KERRISDALE'));
    }

    public function test_a_bare_number_is_not_a_store(): void
    {
        $r = VendorDetailsService::read("DATE:\n#22594 - 0 Phone 604-738-3880\n2801 West 16th Avenue\n");
        $this->assertNull($r['store_number']);
        $r = VendorDetailsService::read("THE HOME DEPOT\nStore #103 - 2368\n2700 Sweden Way\n");
        $this->assertSame('103', $r['store_number']);
        $this->assertNull($r['store_name'], 'no letters: not a name');
    }
}
