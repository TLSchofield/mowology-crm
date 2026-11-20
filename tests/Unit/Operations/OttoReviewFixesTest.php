<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Comms/Services/SupplierInboxService.php';

/** 2026-10-12 review of Otto: supplier senders, licence expiry, special-request crew, SMS, bylaw moves. */
final class OttoReviewFixesTest extends TestCase
{
    private const CEDAR = ['id' => 4, 'name' => 'Cedar Rim', 'domain' => 'cedarrim.com', 'email' => 'jon@cedarrim.com'];

    public function testTheFromAddressIsTheAngleBracketNotTheDisplayName(): void
    {
        $this->assertSame('evil@x.net', SupplierDirectoryService::addressOf('"jon@cedarrim.com" <evil@x.net>'));
        $this->assertSame('jon@cedarrim.com', SupplierDirectoryService::addressOf('jon@cedarrim.com'));
        $this->assertSame('', SupplierDirectoryService::addressOf('Jon Smith'));
    }

    public function testASubstringOfTheSupplierDomainIsNotTheSupplier(): void
    {
        $this->assertTrue(SupplierDirectoryService::senderMatches('accounts@cedarrim.com', self::CEDAR));
        $this->assertTrue(SupplierDirectoryService::senderMatches('a@billing.cedarrim.com', self::CEDAR));
        $this->assertFalse(SupplierDirectoryService::senderMatches('a@cedarrim.com.evil.net', self::CEDAR));
        $this->assertFalse(SupplierDirectoryService::senderMatches('a@notcedarrim.com', self::CEDAR));
        $gmail = ['domain' => null, 'email' => 'bob.supply@gmail.com'];
        $this->assertTrue(SupplierDirectoryService::senderMatches('bob.supply@gmail.com', $gmail));
        $this->assertFalse(SupplierDirectoryService::senderMatches('someone@gmail.com', $gmail), 'a shared domain never identifies a supplier');
    }

    public function testForgedOrFailedSendersAreNotTheSupplier(): void
    {
        $this->assertTrue(SupplierInboxService::fromIs('Jon <jon@cedarrim.com>', 'mx.icloud.com; spf=pass; dkim=pass; dmarc=pass', self::CEDAR));
        $this->assertTrue(SupplierInboxService::fromIs('Jon <jon@cedarrim.com>', '', self::CEDAR), 'no verdict is not a failure');
        $this->assertFalse(SupplierInboxService::fromIs('Jon <jon@cedarrim.com>', 'mx; dmarc=fail (p=none)', self::CEDAR));
        $this->assertFalse(SupplierInboxService::fromIs('Jon <jon@cedarrim.com>', 'mx; spf=fail; dkim=none', self::CEDAR));
        $this->assertTrue(SupplierInboxService::fromIs('Jon <jon@cedarrim.com>', 'mx; spf=softfail; dkim=pass', self::CEDAR));
        $this->assertFalse(SupplierInboxService::fromIs('"cedarrim.com" <x@cedarrim-co.com>', '', self::CEDAR));
    }

    public function testRepliesGoOnlyToTheSupplier(): void
    {
        $ours = ['@mowology.ca', 'mowology@icloud.com'];
        $r = SupplierFormService::recipients('Jon <jon@cedarrim.com>', 'office@mowology.ca, Acct <accounting@cedarrim.com>, friend@other.com', $ours, self::CEDAR);
        $this->assertSame(['to' => 'jon@cedarrim.com', 'cc' => ['accounting@cedarrim.com']], $r);

        $forged = SupplierFormService::recipients('Jon <jon@cedarrim.com.evil.net>', 'accounting@cedarrim.com', $ours, self::CEDAR);
        $this->assertSame(['to' => '', 'cc' => []], $forged, 'a forged sender gets nothing');
    }

    public function testOnlyAPhraseInTheBodyMakesAFormOfAFewFields(): void
    {
        $this->assertFalse(SupplierFormService::looksLikeForm('order.pdf', 'Your order', 'Applicable taxes extra. Pay by credit card on our platform.', 2));
        $this->assertTrue(SupplierFormService::looksLikeForm('doc.pdf', 'Hello', 'Please fill out the credit application attached.', 2));
        $this->assertTrue(SupplierFormService::looksLikeForm('New Account Form.pdf', '', '', 2));
        $this->assertFalse(SupplierFormService::looksLikeForm('Price List 2026.pdf', 'Account form', '', 9));
    }

    public function testAnExpiredLicenceIsNeverUsable(): void
    {
        $this->assertFalse(CompanyDocumentService::isUsable(['expires_on' => '2025-12-31'], '2026-10-12'));
        $this->assertTrue(CompanyDocumentService::isUsable(['expires_on' => '2026-12-31'], '2026-10-12'));
        $this->assertTrue(CompanyDocumentService::isUsable(['expires_on' => null], '2026-10-12'), 'unknown expiry still counts');
        $this->assertFalse(CompanyDocumentService::isUsable(null, '2026-10-12'));
    }

    public function testOnlyCrewOnTheVisitOrTheOfficeMayAnswerARequest(): void
    {
        $this->assertTrue(SpecialRequestService::mayAct([3, 7], 7, false));
        $this->assertFalse(SpecialRequestService::mayAct([3, 7], 9, false));
        $this->assertTrue(SpecialRequestService::mayAct([], 9, true));
        $this->assertFalse(SpecialRequestService::mayAct([0], 0, false));
    }

    public function testTheLeaderSmsAlwaysCarriesTheOfficeNumber(): void
    {
        $long = str_repeat('Please trim the very long cedar hedge along the north side of the lane ', 3);
        foreach ([
            ['2205 W 45th Ave, Vancouver', 'today', ['Trim the hedge'], []],
            ['12345 Some Extremely Long Named Boulevard East, Vancouver', 'Mon Oct 12', [$long], [$long]],
            [str_repeat('Long address words ', 12), 'tomorrow', [$long], [$long]],
        ] as [$addr, $when, $inc, $extra]) {
            $t = SpecialRequestService::smsText($addr, $when, $inc, $extra);
            $this->assertStringContainsString('(778) 846-9273', $t);
            $this->assertLessThanOrEqual(160, mb_strlen($t));
            $this->assertDoesNotMatchRegularExpression('~https?://|www\.|\.(com|ca)\b~i', $t);
        }
    }

    public function testMovesFitTheCityHoursAndSkipRestDays(): void
    {
        $rules = [[
            'id' => 1, 'kind' => 'hours', 'area' => null, 'day_type' => 'weekday', 'equipment_class' => 'all',
            'allowed_start' => '08:00:00', 'allowed_end' => '20:00:00', 'status' => 'verified', 'power_source' => 'any',
        ]];
        $this->assertSame(['start' => '08:00', 'end' => '10:00'], DispatchRules::fitSlot($rules, 'weekday', ['power_equipment'], [], '07:00', 120));
        $this->assertSame(['start' => '09:00', 'end' => '10:00'], DispatchRules::fitSlot($rules, 'weekday', ['power_equipment'], [], '09:00', 60));
        $this->assertNull(DispatchRules::fitSlot($rules, 'weekday', ['power_equipment'], [], '19:30', 60), 'would run past 8 pm');
        $this->assertNull(DispatchRules::fitSlot($rules, 'weekday', ['power_equipment'], [], '07:00', 120, '09:30'), 'past the move band');
        $ban = [['kind' => 'ban', 'area' => null, 'day_type' => 'sunday_holiday', 'equipment_class' => 'all', 'status' => 'verified',
                 'allowed_start' => null, 'allowed_end' => null, 'power_source' => 'gas']];
        $this->assertNull(DispatchRules::fitSlot($ban, 'sunday_holiday', ['power_equipment'], [], '09:00', 60));

        $this->assertTrue(DispatchRules::isRestDay('2026-10-11'), 'a Sunday');
        $this->assertTrue(DispatchRules::isRestDay('2026-10-12'), 'Thanksgiving');
        $this->assertFalse(DispatchRules::isRestDay('2026-10-13'));
    }
}
