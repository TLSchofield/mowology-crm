<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/VendorIdentity.php';

/** A phone / domain on the paper is a vendor's only when the paper names that vendor (Lordco filed as Costco, 2026-11-06). */
final class VendorNamedOnPaperTest extends TestCase
{
    private const LORDCO = "lordco\nauto parts\nvancouver #66\n210 sw marine drive\n(604) 324-8000\nwww.lordco.com";

    public function testTheVendorNamedOnThePaper(): void
    {
        $this->assertTrue(VendorIdentity::namedOnPaper(self::LORDCO, 'Lordco Auto Parts', 'lordco auto parts,lordco'));
        $this->assertTrue(VendorIdentity::namedOnPaper(self::LORDCO, 'LORDCO'));
        // First real word: the landfill prints "CITY OF / VANCOUVER", not its whole vendor name.
        $this->assertTrue(VendorIdentity::namedOnPaper("city of\nvancouver\nlandfill ticket", 'City of Vancouver Vancouver Landfill'));
        $this->assertTrue(VendorIdentity::namedOnPaper("home depot #7042", 'The Home Depot'));
    }

    public function testAnotherVendorIsNotNamed(): void
    {
        $this->assertFalse(VendorIdentity::namedOnPaper(self::LORDCO, 'Costco', 'Costco, COSTCO WHOLESALE'));
        $this->assertFalse(VendorIdentity::namedOnPaper("costcoe parts", 'Costco'));   // whole words only
    }
}
