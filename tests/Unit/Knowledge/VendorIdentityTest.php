<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VendorIdentityTest extends TestCase
{
    public function testNameKeyDropsLegalSuffixesStoreNumbersAndPunctuation(): void
    {
        $this->assertSame('home depot', VendorIdentity::nameKey('THE HOME DEPOT #7128'));
        $this->assertSame('home depot', VendorIdentity::nameKey('Home Depot, Store 7128'));
        $this->assertSame('home depot', VendorIdentity::nameKey('Home Depot Canada Inc.'));
        $this->assertSame('lawnboy', VendorIdentity::nameKey('LAWNBOY'));
        $this->assertSame('lawnboy equipment', VendorIdentity::nameKey('Lawnboy Equipment Ltd'));
        $this->assertSame('city vancouver', VendorIdentity::nameKey('City of Vancouver'));
        $this->assertNull(VendorIdentity::nameKey('  '));
        $this->assertNull(VendorIdentity::nameKey(null));
    }

    public function testNameKeyIsTradeNeutralAndStableAcrossSpellings(): void
    {
        $a = VendorIdentity::nameKey('Chevron Canada Limited');
        $b = VendorIdentity::nameKey('CHEVRON');
        $this->assertSame($a, $b, 'the same vendor must key the same way for a plumber and a landscaper');
    }

    public function testPhoneDigitsKeepsTheLastTen(): void
    {
        $this->assertSame('6043228771', VendorIdentity::phoneDigits('(604) 322-8771'));
        $this->assertSame('6043228771', VendorIdentity::phoneDigits('+1 604.322.8771'));
        $this->assertSame('6043228771', VendorIdentity::phoneDigits('Tel: 1-604-322-8771'));
        $this->assertNull(VendorIdentity::phoneDigits('322-8771'));
        $this->assertNull(VendorIdentity::phoneDigits(null));
    }

    public function testDomainOfNormalisesUrlsAndEmails(): void
    {
        $this->assertSame('lawnboy.ca', VendorIdentity::domainOf('https://www.lawnboy.ca/shop?x=1'));
        $this->assertSame('lawnboy.ca', VendorIdentity::domainOf('WWW.LAWNBOY.CA'));
        $this->assertSame('lawnboy.ca', VendorIdentity::domainOf('parts@lawnboy.ca'));
        $this->assertSame('homedepot.ca', VendorIdentity::domainOf('homedepot.ca'));
        $this->assertNull(VendorIdentity::domainOf('not a domain'));
    }

    /** Shapes seen on BC receipts. */
    public function testGstNumberReadsReceiptFormats(): void
    {
        $this->assertSame('123456789RT0001', VendorIdentity::gstNumber("GST # 123456789 RT0001\nTOTAL 12.40"));
        $this->assertSame('123456789RT0001', VendorIdentity::gstNumber('GST/HST REG 123456789RT0001'));
        $this->assertSame('123456789RT0001', VendorIdentity::gstNumber('123456789 rt 0001'));
        $this->assertSame('123456789', VendorIdentity::gstNumber('GST# 123456789'));
        $this->assertSame('123456789', VendorIdentity::gstNumber('Business Number: 123456789'));
        $this->assertNull(VendorIdentity::gstNumber('Invoice 123456789'), 'a bare 9-digit number without a tax label is not a GST number');
        $this->assertNull(VendorIdentity::gstNumber(''));
    }

    public function testFromReceiptCombinesContactAndText(): void
    {
        $ocr = "LAWNBOY EQUIPMENT LTD\n8655 Cambie St\n(604) 322-8771\nwww.lawnboy.ca\nGST# 123456789 RT0001\nTOTAL 84.00";
        $id = VendorIdentity::fromReceipt($ocr, 'Lawnboy Equipment Ltd', []);
        $this->assertSame('123456789RT0001', $id['gst_number']);
        $this->assertSame('6043228771', $id['phone_digits']);
        $this->assertSame('lawnboy.ca', $id['domain']);
        $this->assertSame('lawnboy equipment', $id['name_key']);
    }

    public function testValueHashIsOrderAndCaseInsensitive(): void
    {
        $this->assertSame(VendorIdentity::valueHash(['b' => 1, 'a' => 2]), VendorIdentity::valueHash(['a' => 2, 'b' => 1]));
        $this->assertSame(VendorIdentity::valueHash('Fuel'), VendorIdentity::valueHash('fuel '));
        $this->assertSame(40, strlen(VendorIdentity::valueHash('x')));
    }

    public function testTenantHashNeverRevealsTheTenant(): void
    {
        $h = VendorIdentity::tenantHash('https://mowology.ca');
        $this->assertSame(40, strlen($h));
        $this->assertStringNotContainsString('mowology', $h);
        $this->assertNotSame($h, VendorIdentity::tenantHash('https://other.example'));
    }
}
