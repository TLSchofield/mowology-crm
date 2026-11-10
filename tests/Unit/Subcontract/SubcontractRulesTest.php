<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubcontractRulesTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';

    public function testTheWorkMovesOnlyForward(): void
    {
        $this->assertTrue(SubcontractRules::canMove('sent', 'accepted'));
        $this->assertTrue(SubcontractRules::canMove('accepted', 'started'));
        $this->assertTrue(SubcontractRules::canMove('on_way', 'started'));
        $this->assertTrue(SubcontractRules::canMove('started', 'finished'));
        $this->assertFalse(SubcontractRules::canMove('sent', 'finished'));
        $this->assertFalse(SubcontractRules::canMove('finished', 'started'));
        $this->assertFalse(SubcontractRules::canMove('declined', 'accepted'));
        $this->assertFalse(SubcontractRules::canMove('cancelled', 'started'));
    }

    /** @dataProvider steps */
    public function testOneNextStepForEachState(string $work, string $bill, string $expected): void
    {
        $this->assertSame($expected, SubcontractRules::nextStep(['work_status' => $work, 'bill_status' => $bill]));
    }

    public static function steps(): array
    {
        return [
            ['sent', 'none', 'respond'], ['accepted', 'none', 'pick_day'], ['scheduled', 'none', 'on_my_way'],
            ['on_way', 'none', 'start'], ['started', 'none', 'finish'], ['finished', 'none', 'send_bill'],
            ['finished', 'returned', 'send_bill'], ['finished', 'sent', 'bill_waiting'], ['finished', 'approved', 'payment_waiting'],
            ['finished', 'paid', 'done'], ['declined', 'none', 'closed'], ['cancelled', 'none', 'closed'],
        ];
    }

    public function testUndoIsShortAndStopsOnceTheOwnerHasAcceptedOrABillIsIn(): void
    {
        $job = ['work_status' => 'finished', 'status_changed_at' => '2026-11-10 11:55:00', 'bill_status' => 'none'];
        $this->assertSame('started', SubcontractRules::undoTarget($job, self::NOW));
        $this->assertNull(SubcontractRules::undoTarget(['status_changed_at' => '2026-11-10 11:49:00'] + $job, self::NOW));
        $this->assertNull(SubcontractRules::undoTarget(['work_accepted_at' => '2026-11-10 11:58:00'] + $job, self::NOW));
        $this->assertNull(SubcontractRules::undoTarget(['bill_status' => 'sent'] + $job, self::NOW));
        $this->assertNull(SubcontractRules::undoTarget(['work_status' => 'started'] + $job, self::NOW), 'starting has moved the visit');
    }

    public function testRevokedLinkReadsAsRevokedEvenWhenExpired(): void
    {
        $this->assertSame('ok', SubcontractRules::linkState(['expires_at' => '2026-11-11 00:00:00'], self::NOW));
        $this->assertSame('expired', SubcontractRules::linkState(['expires_at' => '2026-11-10 12:00:00'], self::NOW));
        $this->assertSame('revoked', SubcontractRules::linkState(['expires_at' => '2026-01-01 00:00:00', 'revoked_at' => '2026-02-01 00:00:00'], self::NOW));
        $this->assertSame('expired', SubcontractRules::linkState([], self::NOW), 'a job with no expiry does not open');
    }

    public function testPagePayloadCarriesOnlyWhatSheMaySee(): void
    {
        $job = ['work_status' => 'accepted', 'bill_status' => 'none', 'share_client_name' => 1, 'share_client_phone' => 1, 'agreed_amount' => '250.00',
                'note_to_sub' => 'Gate is on the left', 'price_per_visit' => '999.00', 'token_hash' => 'SECRET-HASH', 'bill_expense_id' => 77];
        $context = ['plan_title' => 'Fall cleanup', 'scheduled_date' => '2026-11-14', 'property_address' => '12 Oak St', 'property_city' => 'Vancouver',
                    'contact_first_name' => 'Pat', 'contact_last_name' => 'Lee', 'contact_phone' => '604-555-0100', 'contact_email' => 'client@example.com',
                    'price_per_visit' => '999.00', 'monthly_flat_price' => '888.00', 'billing_address' => '1 Money Rd', 'payment_method' => 'Visa', 'line_total' => '777.00'];
        $page = SubcontractRules::pagePayload($job, $context, ['Rake beds', ' ', 'Haul debris'], self::NOW);

        $this->assertSame('Pat Lee', $page['client_name']);
        $this->assertSame('604-555-0100', $page['client_phone']);
        $this->assertSame(['Rake beds', 'Haul debris'], $page['work_items']);
        $this->assertSame(250.0, $page['agreed_amount']);

        $flat = json_encode($page);
        foreach (['999', '888', '777', 'client@example.com', '1 Money Rd', 'Visa', 'SECRET-HASH'] as $secret) {
            $this->assertStringNotContainsString($secret, $flat);
        }
        $this->assertSame(['next_step', 'work_status', 'bill_status', 'can_undo', 'title', 'date', 'address', 'client_name', 'client_phone', 'after_the_fact', 'bill_received', 'work_items',
                           'note', 'agreed_amount', 'started_at', 'finished_at', 'work_accepted', 'paid_at'], array_keys($page),
                          'a new field on her page must be added here on purpose');
    }

    public function testClientDetailsObeyTheOwnersSwitches(): void
    {
        $job = ['work_status' => 'accepted', 'share_client_name' => 0, 'share_client_phone' => 0];
        $page = SubcontractRules::pagePayload($job, ['contact_first_name' => 'Pat', 'contact_last_name' => 'Lee', 'contact_phone' => '604-555-0100'], [], self::NOW);
        $this->assertSame('', $page['client_name']);
        $this->assertSame('', $page['client_phone']);
    }

    /** @dataProvider phoneByStatus */
    public function testShePhonesTheClientOnlyOnceSheHasTheJob(string $status, string $expected): void
    {
        $job = ['work_status' => $status, 'share_client_name' => 1, 'share_client_phone' => 1];
        $page = SubcontractRules::pagePayload($job, ['contact_phone' => '604-555-0100'], [], self::NOW);
        $this->assertSame($expected, $page['client_phone']);
    }

    public static function phoneByStatus(): array
    {
        return [
            ['sent', ''], ['declined', ''], ['cancelled', ''],
            ['accepted', '604-555-0100'], ['scheduled', '604-555-0100'], ['on_way', '604-555-0100'],
            ['started', '604-555-0100'], ['finished', '604-555-0100'],
        ];
    }

    public function testOnSiteAllowsForARoughReading(): void
    {
        $this->assertTrue(SubcontractRules::onSite(49.2827, -123.1207, 20, 49.2828, -123.1208));
        $this->assertFalse(SubcontractRules::onSite(49.30, -123.1207, 20, 49.2828, -123.1208));
        $this->assertNull(SubcontractRules::onSite(49.2850, -123.1207, 400, 49.2828, -123.1208), 'too rough to tell');
        $this->assertNull(SubcontractRules::onSite(null, null, null, 49.2828, -123.1208));
        $this->assertNull(SubcontractRules::onSite(49.2827, -123.1207, 20, null, null), 'property has no pin');
        $this->assertNull(SubcontractRules::onSite(49.2827, -123.1207, 20, 0.0, 0.0), 'an empty pin is no pin');
    }

    public function testBillProblems(): void
    {
        $this->assertNull(SubcontractRules::billProblem(250.0, 12.5, 'INV-7'));
        $this->assertNotNull(SubcontractRules::billProblem(0.0, 0.0, ''));
        $this->assertNotNull(SubcontractRules::billProblem(100.0, -1.0, ''));
        $this->assertNotNull(SubcontractRules::billProblem(100.0, 50.0, ''));
        $this->assertNotNull(SubcontractRules::billProblem(100.0, 5.0, str_repeat('x', 61)));
    }

    public function testPushNamesTheCompanyAndOnlyFiresOnRealSteps(): void
    {
        $this->assertSame(['Ready for your OK', 'Green Co finished at 12 Oak St'], SubcontractRules::pushFor('finished', 'Green Co', '12 Oak St'));
        $this->assertNull(SubcontractRules::pushFor('scheduled', 'Green Co', '12 Oak St'));
    }
}
