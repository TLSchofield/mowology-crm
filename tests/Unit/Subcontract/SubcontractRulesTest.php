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

    private function current(array $stages): string
    {
        foreach ($stages['stages'] as $s) if ($s['current']) return $s['label'];
        return '';
    }

    public function testANewJobWalksItsTrack(): void
    {
        $job = ['work_status' => 'sent', 'bill_status' => 'none', 'open_count' => 0];
        $this->assertSame('Sent', $this->current(SubcontractRules::stages($job, ['sent'])));
        $this->assertSame('Opened', $this->current(SubcontractRules::stages(['open_count' => 1] + $job, ['sent', 'opened'])));
        $this->assertSame('Day set', $this->current(SubcontractRules::stages(['work_status' => 'scheduled'] + $job, [])));
        $this->assertSame('Started', $this->current(SubcontractRules::stages(['work_status' => 'started'] + $job, [])), 'skipping On the way still shows Started');
        $all = SubcontractRules::stages(['work_status' => 'finished', 'work_accepted_at' => 'x', 'bill_status' => 'paid'] + $job, []);
        $this->assertSame('Paid', $this->current($all));
        $this->assertSame('new', $all['kind']);
        $this->assertCount(10, $all['stages']);
    }

    public function testWorkAlreadyDoneHasTheShortTracks(): void
    {
        $done = SubcontractRules::stages(['work_status' => 'started', 'after_the_fact' => 1, 'bill_status' => 'none'], []);
        $this->assertSame('done', $done['kind']);
        $this->assertSame(['Asked', 'Opened', 'Proof in', 'You accepted', 'Bill in', 'Paid'], array_column($done['stages'], 'label'));
        $photos = SubcontractRules::stages(['work_status' => 'finished', 'after_the_fact' => 1, 'bill_status' => 'sent'], []);
        $this->assertSame('photos', $photos['kind']);
        $this->assertSame('Photos in', $this->current($photos));
        $paid = SubcontractRules::stages(['work_status' => 'finished', 'after_the_fact' => 1, 'bill_status' => 'paid', 'work_accepted_at' => 'x'], []);
        $this->assertSame('photos', $paid['kind'], 'stays on the photos track once paid');
    }

    public function testWhatTheOwnerDoesNext(): void
    {
        $this->assertSame('accept', SubcontractRules::ownerAction(['work_status' => 'finished']));
        $this->assertSame('penny', SubcontractRules::ownerAction(['work_status' => 'finished', 'work_accepted_at' => 'x', 'bill_status' => 'sent', 'bill_expense_id' => 4]));
        $this->assertSame('mark_paid', SubcontractRules::ownerAction(['work_status' => 'finished', 'work_accepted_at' => 'x', 'bill_status' => 'approved']));
        $this->assertSame('mark_paid', SubcontractRules::ownerAction(['work_status' => 'finished', 'work_accepted_at' => 'x', 'bill_status' => 'sent', 'after_the_fact' => 1]));
        $this->assertSame('dismiss', SubcontractRules::ownerAction(['work_status' => 'declined']));
        $this->assertNull(SubcontractRules::ownerAction(['work_status' => 'sent', 'bill_status' => 'none']));
        $this->assertNull(SubcontractRules::ownerAction(['work_status' => 'finished', 'work_accepted_at' => 'x', 'bill_status' => 'paid']));
    }

    public function testBoardKeepsPaidJobsTwoWeeksAndOrdersWaitingFirst(): void
    {
        $this->assertTrue(SubcontractRules::onBoard(['bill_status' => 'paid', 'bill_paid_at' => '2026-11-01 12:00:00'], [], self::NOW));
        $this->assertFalse(SubcontractRules::onBoard(['bill_status' => 'paid', 'bill_paid_at' => '2026-10-20 12:00:00'], [], self::NOW));
        $this->assertFalse(SubcontractRules::onBoard(['work_status' => 'declined'], ['declined', 'dismissed'], self::NOW));
        $rows = SubcontractRules::boardOrder([
            ['job_id' => 1, 'action' => null, 'last_at' => '2026-11-10 11:00:00'],
            ['job_id' => 2, 'action' => 'accept', 'last_at' => '2026-11-09 08:00:00'],
            ['job_id' => 3, 'action' => 'penny', 'last_at' => '2026-11-10 11:30:00'],
        ]);
        $this->assertSame([2, 3, 1], array_column($rows, 'job_id'));
    }

    public function testKanameHearsWhatNeedsYouFirstThenTheDaysNews(): void
    {
        $row = ['job_id' => 1, 'company' => 'Fern Tree Gardens', 'title' => 'Fall clean up', 'address' => '3757 Puget Drive', 'url' => '/v', 'action' => null,
                'at' => 'Took it', 'last' => 'Took the job', 'last_at' => '2026-11-10 11:29:00'];
        $news = SubcontractRules::briefItems([$row], self::NOW);
        $this->assertSame('Fern Tree Gardens: took the job, Fall clean up at 3757 Puget Drive.', $news[0]['text']);
        $this->assertSame(3, $news[0]['priority']);
        $this->assertSame([], SubcontractRules::briefItems([['last_at' => '2026-11-09 10:00:00'] + $row], self::NOW), 'news is a day old at most');
        $this->assertSame([], SubcontractRules::briefItems([['last' => 'Link sent'] + $row], self::NOW), 'your own clicks are not news');

        $finished = ['job_id' => 2, 'action' => 'accept', 'company' => 'University Landscapers inc.', 'title' => 'Sprinkler blowout', 'address' => '70 West 18th Avenue',
                     'at' => 'Photos in', 'last' => 'Finished', 'last_at' => '2026-11-08 09:00:00'] + $row;
        $both = SubcontractRules::briefItems([$row, $finished], self::NOW);
        $this->assertSame(1, $both[1]['priority']);
        $this->assertSame('otto:sub:2:accept', $both[1]['key']);
        $this->assertSame('University Landscapers inc. finished Sprinkler blowout at 70 West 18th Avenue. Accept the work?', SubcontractRules::spaceLine([$row, $finished], self::NOW)['text']);
        $this->assertNull(SubcontractRules::spaceLine([], self::NOW));
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
