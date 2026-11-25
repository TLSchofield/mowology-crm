<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * SpecialRequestMatcher — pure matching of a client's message to their scheduled visits.
 * Fixture = Pacific Spirit United Church (Michelle Henry), 2026-10-08.
 */
final class SpecialRequestMatcherTest extends TestCase
{
    public const MICHELLE = "Hi Tim,\n\nCould you please run the mower over the front lawns of the Memorial Centre, Church lawns "
        . "and the two rental houses 2267 & 2279 W 45th to remove the dandelions, and rake up the leaves from the NW corner "
        . "of the Memorial Centre on the boulevard into our green bins.\n\nThanks,\nMichelle";

    public static function properties(): array
    {
        return [
            ['id' => 1, 'address' => '2195 West 45th Avenue', 'city' => 'Vancouver', 'property_name' => 'Pacific Spirit United Church'],
            ['id' => 2, 'address' => '2205 W 45th Ave', 'city' => 'Vancouver', 'property_name' => 'Memorial Centre'],
            ['id' => 3, 'address' => '2267 W 45th Ave', 'city' => 'Vancouver', 'property_name' => null],
            ['id' => 4, 'address' => '5390 Granville St', 'city' => 'Vancouver', 'property_name' => 'Parsonage'],
        ];
    }

    public static function plans(): array
    {
        return [
            ['id' => 10, 'property_id' => 1, 'plan_number' => 'PLN-2026-0101', 'title' => 'WEEKLY MOWING — Church', 'description' => '', 'service_type' => 'Lawn Mowing'],
            ['id' => 11, 'property_id' => 2, 'plan_number' => 'PLN-2026-0102', 'title' => 'WEEKLY MOWING — Memorial Centre', 'description' => '', 'service_type' => 'Lawn Mowing'],
            ['id' => 12, 'property_id' => 3, 'plan_number' => 'PLN-2026-0120', 'title' => 'BI-WEEKLY MOWING — 2267 & 2279 W 45th', 'description' => '', 'service_type' => 'Lawn Mowing'],
            ['id' => 13, 'property_id' => 4, 'plan_number' => 'PLN-2026-0130', 'title' => 'Hedge trimming', 'description' => '', 'service_type' => 'Hedge'],
        ];
    }

    public static function visits(): array
    {
        return [
            ['visit_id' => 100, 'property_id' => 1, 'plan_id' => 10, 'scheduled_date' => '2026-10-08', 'status' => 'scheduled'],
            ['visit_id' => 101, 'property_id' => 2, 'plan_id' => 11, 'scheduled_date' => '2026-10-08', 'status' => 'scheduled'],
            ['visit_id' => 102, 'property_id' => 3, 'plan_id' => 12, 'scheduled_date' => '2026-10-08', 'status' => 'scheduled'],
            ['visit_id' => 103, 'property_id' => 3, 'plan_id' => 12, 'scheduled_date' => '2026-10-22', 'status' => 'scheduled'],
            ['visit_id' => 104, 'property_id' => 4, 'plan_id' => 13, 'scheduled_date' => '2026-10-09', 'status' => 'scheduled'],
            ['visit_id' => 99,  'property_id' => 2, 'plan_id' => 11, 'scheduled_date' => '2026-10-01', 'status' => 'completed'],
        ];
    }

    public function testMichelleAttachesToTodaysThreeVisitsAndResolves2279ViaPlan(): void
    {
        $p = SpecialRequestMatcher::propose(self::MICHELLE, self::properties(), self::plans(), self::visits(), '2026-10-08');

        $this->assertTrue($p['is_work_request']);
        $this->assertSame([100, 101, 102], array_map(fn($v) => (int)$v['visit_id'], $p['visits']));
        foreach ($p['visits'] as $v) {
            $this->assertSame('2026-10-08', $v['scheduled_date']);
        }
        $this->assertSame([1, 2, 3], array_column($p['properties'], 'property_id'), 'Parsonage (not mentioned) is not matched');
        $reasons3 = implode(' | ', $p['properties'][2]['reasons']);
        $this->assertStringContainsString('2267', $reasons3);
        $this->assertStringContainsString('2279 W 45th via plan PLN-2026-0120', $reasons3);
        $this->assertSame([], $p['unmatched'], '2279 resolves through the plan title');
    }

    public function testMowingIsIncludedAndLeafRakingIsExtraOnlyAtTheMemorialCentre(): void
    {
        $p = SpecialRequestMatcher::propose(self::MICHELLE, self::properties(), self::plans(), self::visits(), '2026-10-08');

        $this->assertCount(1, $p['included']);
        $this->assertStringStartsWith('Run the mower', $p['included'][0]);
        $this->assertCount(1, $p['extra']);
        $this->assertStringStartsWith('Rake up the leaves', $p['extra'][0]);

        $byVisit = [];
        foreach ($p['visits'] as $v) $byVisit[(int)$v['visit_id']] = $v;
        $this->assertSame([], $byVisit[100]['extra'], 'church: mowing only');
        $this->assertCount(1, $byVisit[101]['extra'], 'Memorial Centre carries the leaf raking');
        $this->assertSame([], $byVisit[102]['extra'], 'rentals: mowing only');
        $this->assertCount(1, $byVisit[102]['included']);
    }

    public function testAddressListExpandsSharedStreet(): void
    {
        $a = SpecialRequestMatcher::addresses('the two rental houses 2267 & 2279 W 45th please');
        $this->assertSame(['2267', '2279'], array_column($a, 'number'));
        $this->assertSame(['45', '45'], array_column($a, 'street'));
        $this->assertSame(['w', 'w'], array_column($a, 'dir'));
    }

    public function testDirectionMustAgreeOnlyWhenBothGiveOne(): void
    {
        $w = SpecialRequestMatcher::addresses('2267 W 45th')[0];
        $e = SpecialRequestMatcher::addresses('2267 East 45th Ave')[0];
        $none = SpecialRequestMatcher::addresses('2267 45th Avenue')[0];
        $this->assertFalse(SpecialRequestMatcher::sameAddress($w, $e));
        $this->assertTrue(SpecialRequestMatcher::sameAddress($w, $none));
    }

    public function testUnknownAddressIsReportedNotGuessed(): void
    {
        $p = SpecialRequestMatcher::propose('Please mow 9999 W 12th Ave on Friday', self::properties(), self::plans(), self::visits(), '2026-10-08');
        $this->assertSame([], $p['visits']);
        $this->assertSame(['9999 W 12th'], $p['unmatched']);
    }

    public function testOnlyPropertyWithAVisitIsUsedWhenNothingIsNamed(): void
    {
        $props = [self::properties()[3]];
        $plans = [self::plans()[3]];
        $visits = [self::visits()[4]];
        $p = SpecialRequestMatcher::propose('Can you also trim the hedge by the gate a bit lower this time?', $props, $plans, $visits, '2026-10-08');
        $this->assertSame([104], array_map(fn($v) => (int)$v['visit_id'], $p['visits']));
        $this->assertSame(['Trim the hedge by the gate a bit lower this time'], $p['included']);
    }

    public function testBillingQuestionIsNotAWorkRequest(): void
    {
        $this->assertFalse(SpecialRequestMatcher::isWorkRequest('Hi, can you resend the invoice for September? Thanks'));
        $this->assertTrue(SpecialRequestMatcher::isWorkRequest('Please rake the leaves by the side door.'));
    }

    public function testNextVisitIsUsedWhenNoneToday(): void
    {
        $p = SpecialRequestMatcher::propose('Please mow 2267 W 45th extra short', self::properties(), self::plans(), self::visits(), '2026-10-09');
        $this->assertSame([103], array_map(fn($v) => (int)$v['visit_id'], $p['visits']), 'next visit at 2267 is the 22nd');
    }
}
