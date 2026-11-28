<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/app/Modules/Knowledge/Services/VendorIdentity.php';
require_once dirname(__DIR__, 3) . '/app/Services/Receipts/ReceiptParser.php';

/**
 * A new business sees a known vendor for the first time (samtest, 2026-11-27): the landfill ticket prints no GST number,
 * so only its phone reached the commons, and the tenant was given the first logo line ("CITY OF") as a bare vendor.
 * The lines below are the ticket's header and totals as printed (no amounts of a real receipt are kept beyond the fixture).
 */
final class CommonsCreationGateTest extends TestCase
{
    private const TICKET = "city of\nvancouver\n320-507 west broadway\nvancouver bc v5z 0b4\nphone: 311 (inside vancouver)\n604-873-7000 (outside vancouver)\ndate: 04/07/25\ngreen waste\ntotal fee: \$41.00\ndebit card";

    private function landfill(array $over = []): array
    {
        return array_merge([
            'id' => 2, 'display_name' => 'City of Vancouver Vancouver Landfill', 'name_key' => 'city vancouver vancouver landfill',
            'gst_number' => null, 'phone_digits' => '6048737000', 'status' => 'curated',
        ], $over);
    }

    public function testThePhoneReadFromTheTicketIsTheCommonsPhone(): void
    {
        $this->assertSame('6048737000', extractPhoneNumbers(self::TICKET)[0]);
    }

    public function testPhoneAndNameOnPaperMayCreateTheVendor(): void
    {
        $this->assertTrue(VendorIdentity::commonsMayNameVendor($this->landfill(), self::TICKET, null));
    }

    public function testAPhoneAloneNeverCreates(): void
    {
        $this->assertFalse(VendorIdentity::commonsMayNameVendor($this->landfill(), "costco wholesale\n604-873-7000", null));
    }

    public function testACandidateVendorIsNotTrusted(): void
    {
        $this->assertFalse(VendorIdentity::commonsMayNameVendor($this->landfill(['status' => 'candidate']), self::TICKET, null));
    }

    public function testGstNumberStillCreatesWithoutTheNameOnPaper(): void
    {
        $v = $this->landfill(['gst_number' => '123456789RT0001', 'display_name' => 'Acme Supply', 'phone_digits' => null]);
        $this->assertTrue(VendorIdentity::commonsMayNameVendor($v, "receipt\ngst 123456789 rt0001", '123456789RT0001'));
        $this->assertFalse(VendorIdentity::commonsMayNameVendor($v, "receipt\ngst 999999999 rt0001", '999999999RT0001'));
    }

    public function testAnUnusableCommonsNameIsRefused(): void
    {
        $this->assertFalse(VendorIdentity::commonsMayNameVendor($this->landfill(['display_name' => 'City of']), self::TICKET, null));
    }
}
