<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RecurringScheduleServiceTest extends TestCase
{
    private function v(int $id, string $date, array $x = []): array
    {
        return array_merge(['id' => $id, 'scheduled_date' => $date, 'status' => 'scheduled', 'locked' => false, 'invoiced' => false,
            'timer' => false, 'photos' => 0, 'notes' => 0, 'moved' => false, 'stop_id' => $id], $x);
    }

    private function change(array $x = []): array
    {
        return array_merge(['time' => null, 'crew_ids' => null, 'repeat' => null, 'stop_after' => false, 'include_moved' => false], $x);
    }

    // ── normaliseChange ───────────────────────────────────────────────

    public function testNothingToChangeIsRefused(): void
    {
        $r = RecurringScheduleService::normaliseChange([]);
        $this->assertSame(['Nothing to change.'], $r['errors']);
    }

    public function testTimeNeedsBothEndsInOrder(): void
    {
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['time_start' => '09:00'])['errors']);
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['time_start' => '10:00', 'time_end' => '09:00'])['errors']);
        $ok = RecurringScheduleService::normaliseChange(['time_start' => '9:00', 'time_end' => '10:30']);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(['09:00:00', '10:30:00'], $ok['change']['time']);
    }

    public function testCrewNeedsAtLeastOnePerson(): void
    {
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['crew_ids' => []])['errors']);
        $r = RecurringScheduleService::normaliseChange(['crew_ids' => ['5', 5, 'x', 0, 7]]);
        $this->assertSame([5, 7], $r['change']['crew_ids']);
    }

    public function testRepeatRules(): void
    {
        $ok = RecurringScheduleService::normaliseChange(['repeat' => ['every' => 2, 'unit' => 'weeks', 'days' => [3, 1, 3, 9]]]);
        $this->assertSame([], $ok['errors']);
        $this->assertSame(['pattern' => 'weekly', 'every' => 2, 'unit' => 'weeks', 'days' => [1, 3]], $ok['change']['repeat']);
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['repeat' => ['every' => 2, 'unit' => 'weeks', 'days' => []]])['errors']);
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['repeat' => ['every' => 0, 'unit' => 'weeks', 'days' => [1]]])['errors']);
        $this->assertNotSame([], RecurringScheduleService::normaliseChange(['repeat' => ['every' => 13, 'unit' => 'months']])['errors']);
        $m = RecurringScheduleService::normaliseChange(['repeat' => ['every' => 1, 'unit' => 'months']]);
        $this->assertSame('monthly', $m['change']['repeat']['pattern']);
    }

    public function testStopAfterCannotMixWithRepeat(): void
    {
        $r = RecurringScheduleService::normaliseChange(['stop_after' => true, 'repeat' => ['every' => 1, 'unit' => 'weeks', 'days' => [1]]]);
        $this->assertNotSame([], $r['errors']);
    }

    // ── planChange ────────────────────────────────────────────────────

    public function testTimeChangeTouchesOnlyScheduledVisitsAndKeepsHandMovedOnes(): void
    {
        $visits = [$this->v(1, '2026-12-07'), $this->v(2, '2026-12-14', ['moved' => true]), $this->v(3, '2026-12-21', ['locked' => true]),
            $this->v(4, '2026-12-28', ['status' => 'completed']), $this->v(5, '2027-01-04')];
        $p = RecurringScheduleService::planChange($visits, $this->change(['time' => ['09:00:00', '10:00:00']]), '2026-12-07', []);
        $this->assertSame([1, 5], $p['update_time']);
        $this->assertSame([2, 3], array_column($p['keep'], 'id'));
        $this->assertSame([], $p['cancel']);
    }

    public function testIncludeMovedLetsHandMovedVisitsChange(): void
    {
        $p = RecurringScheduleService::planChange([$this->v(2, '2026-12-14', ['moved' => true])], $this->change(['time' => ['09:00:00', '10:00:00'], 'include_moved' => true]), '2026-12-07', []);
        $this->assertSame([2], $p['update_time']);
    }

    public function testCrewChangeSkipsLockedInvoicedRunningAndHandMovedVisits(): void
    {
        $visits = [$this->v(1, '2026-12-07'), $this->v(2, '2026-12-14', ['locked' => true]), $this->v(3, '2026-12-21', ['invoiced' => true]),
            $this->v(4, '2026-12-28', ['timer' => true]), $this->v(5, '2027-01-04', ['moved' => true])];
        $p = RecurringScheduleService::planChange($visits, $this->change(['crew_ids' => [5]]), '2026-12-07', []);
        $this->assertSame([1], $p['update_crew']);
        $this->assertSame([2, 3, 4, 5], array_column($p['keep'], 'id'));
        $this->assertSame([], $p['update_time']);
    }

    public function testEditWhy(): void
    {
        $this->assertNull(RecurringScheduleService::editWhy($this->v(1, '2026-12-07', ['moved' => true]), true));
        $this->assertSame('you moved it by hand', RecurringScheduleService::editWhy($this->v(1, '2026-12-07', ['moved' => true]), false));
        $this->assertSame('someone is working on it', RecurringScheduleService::editWhy($this->v(1, '2026-12-07', ['timer' => true]), true));
    }

    public function testNewRepeatKeepsMatchingVisitsCancelsTheRestAndAddsMissingDates(): void
    {
        $visits = [$this->v(1, '2026-12-07'), $this->v(2, '2026-12-14'), $this->v(3, '2026-12-21'), $this->v(4, '2026-12-28')];
        // new pattern: every 2 weeks from Dec 7 -> Dec 7, Dec 21, Jan 4
        $p = RecurringScheduleService::planChange($visits, $this->change(['repeat' => ['pattern' => 'weekly', 'every' => 2, 'unit' => 'weeks', 'days' => [1]]]),
            '2026-12-07', ['2026-12-07', '2026-12-21', '2027-01-04']);
        $this->assertSame([2, 4], $p['cancel']);
        $this->assertSame(['2027-01-04'], $p['add']);
        $this->assertSame([], $p['keep']);
    }

    public function testRepeatChangeNeverCancelsVisitsWithWorkOnThem(): void
    {
        $visits = [$this->v(1, '2026-12-14', ['photos' => 2]), $this->v(2, '2026-12-21', ['notes' => 1]), $this->v(3, '2026-12-28', ['invoiced' => true]),
            $this->v(4, '2027-01-04', ['timer' => true]), $this->v(5, '2027-01-11', ['moved' => true]), $this->v(6, '2027-01-18', ['locked' => true]), $this->v(7, '2027-01-25')];
        $p = RecurringScheduleService::planChange($visits, $this->change(['repeat' => ['pattern' => 'weekly', 'every' => 1, 'unit' => 'weeks', 'days' => [2]]]), '2026-12-14', ['2026-12-15']);
        $this->assertSame([7], $p['cancel']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($p['keep'], 'id'));
        $this->assertSame(['2026-12-15'], $p['add']);
    }

    public function testAKeptVisitsDateIsNotAddedTwice(): void
    {
        $visits = [$this->v(1, '2026-12-15', ['photos' => 1])];
        $p = RecurringScheduleService::planChange($visits, $this->change(['repeat' => ['pattern' => 'weekly', 'every' => 1, 'unit' => 'weeks', 'days' => [2]]]), '2026-12-15', ['2026-12-15']);
        $this->assertSame([], $p['add']);
    }

    public function testDoneVisitsOccupyTheirDateButAreNeverCancelled(): void
    {
        $visits = [$this->v(1, '2026-12-07', ['status' => 'completed'])];
        $p = RecurringScheduleService::planChange($visits, $this->change(['repeat' => ['pattern' => 'weekly', 'every' => 1, 'unit' => 'weeks', 'days' => [1]]]), '2026-12-07', ['2026-12-07', '2026-12-14']);
        $this->assertSame(['2026-12-14'], $p['add']);
        $this->assertSame([], $p['cancel']);
    }

    public function testStopAfterThisVisitCancelsOnlyLaterEligibleVisits(): void
    {
        $visits = [$this->v(1, '2026-12-07'), $this->v(2, '2026-12-14'), $this->v(3, '2026-12-21', ['photos' => 1]), $this->v(4, '2026-12-28')];
        $p = RecurringScheduleService::planChange($visits, $this->change(['stop_after' => true]), '2026-12-07', []);
        $this->assertSame([2, 4], $p['cancel']);
        $this->assertSame([3], array_column($p['keep'], 'id'));
    }

    public function testCancelWhyOrder(): void
    {
        $this->assertNull(RecurringScheduleService::cancelWhy($this->v(1, '2026-12-07')));
        $this->assertSame('it is locked', RecurringScheduleService::cancelWhy($this->v(1, '2026-12-07', ['locked' => true, 'photos' => 3])));
    }
}
