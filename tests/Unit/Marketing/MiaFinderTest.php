<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Who Mia suggests, who she leaves alone, and how she scores what came of a message.
 */
class MiaFinderTest extends TestCase
{
    private function cand(string $kind, int $cid, array $contact = [], int $priority = 2, float $value = 100): array
    {
        $c = MiaFinder::row($kind, $contact + [
            'contact_id' => $cid, 'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => "p{$cid}@example.com",
            'receive_marketing' => 1, 'consent_email_express_at' => '2026-01-01', 'receive_sms' => 0, 'mobile' => '6045550000',
        ], ['last_done' => '2025-01-01'], $priority, $value);
        return $c;
    }

    // ── Seasonal window ──────────────────────────────────────────────────

    public function test_seasonal_window_is_around_today_last_year(): void
    {
        $this->assertSame(['2025-09-14', '2025-11-04', '2026-06-07'],
            MiaFinder::seasonalWindow(new DateTimeImmutable('2026-10-05'), 21, 30));
    }

    public function test_seasonal_window_inside_the_year_end_tail_crosses_the_boundary(): void
    {
        // 10 Jan: last year's window runs from mid-December the year before into February.
        $this->assertSame(['2025-12-20', '2026-02-09', '2026-09-12'],
            MiaFinder::seasonalWindow(new DateTimeImmutable('2027-01-10'), 21, 30));
    }

    public function test_seasonal_window_in_the_gap_between_seasons(): void
    {
        // Late summer: the window is late summer last year — never a calendar "season".
        [$from, $to] = MiaFinder::seasonalWindow(new DateTimeImmutable('2026-08-28'), 21, 30);
        $this->assertSame('2025-08-07', $from);
        $this->assertSame('2025-09-27', $to);
    }

    public function test_seasonal_window_survives_a_leap_day(): void
    {
        [$from, $to] = MiaFinder::seasonalWindow(new DateTimeImmutable('2028-02-29'), 21, 30);
        $this->assertSame('2027-02-08', $from);
        $this->assertSame('2027-03-31', $to);
    }

    // ── Quiet property managers ──────────────────────────────────────────

    public function test_a_property_manager_is_quiet_at_half_the_work_or_less(): void
    {
        $this->assertTrue(MiaFinder::isQuiet(14, 2));
        $this->assertTrue(MiaFinder::isQuiet(10, 5));
        $this->assertFalse(MiaFinder::isQuiet(10, 6));
        $this->assertFalse(MiaFinder::isQuiet(2, 0), 'too little last year to call it a drop');
    }

    /** Everyone consented, as the ledger would say, unless named. */
    private function consent(array $ids, array $without = []): array
    {
        $out = [];
        foreach ($ids as $id) $out[$id] = in_array($id, $without, true) ? ['ok' => false, 'reason' => 'no consent on record'] : ['ok' => true, 'reason' => 'implied consent until 2027-01-01'];
        return $out;
    }

    // ── Leave alone ──────────────────────────────────────────────────────

    public function test_sams_open_quotes_current_work_mutes_and_tests_are_left_alone(): void
    {
        $today = new DateTimeImmutable('2026-10-05');
        $cands = [
            $this->cand('reconnect', 1),                                  // keep
            $this->cand('reconnect', 2),                                  // Sam has an open quote
            $this->cand('reconnect', 3),                                  // active plan
            $this->cand('referral', 3, [], 3),                            // …but a referral ask is fine
            $this->cand('reconnect', 4),                                  // muted
            $this->cand('reconnect', 5, ['first_name' => 'ZZTEST']),       // test record
            $this->cand('reconnect', 6),                                  // no consent in the ledger
        ];
        $f = MiaFinder::filter($cands, [2 => true], [3 => true], ['mia:contact:4' => true], $this->consent([1, 2, 3, 4, 5, 6], [6]));
        $kept = array_map(fn($c) => [$c['kind'], $c['contact_id']], $f['keep']);
        $this->assertSame([['reconnect', 1], ['referral', 3]], $kept);
        $this->assertSame([6 => true], $f['no_consent']);
    }

    public function test_one_suggestion_per_person_most_timely_first(): void
    {
        $today = new DateTimeImmutable('2026-10-05');
        $f = MiaFinder::filter([
            $this->cand('reconnect', 7, [], 2, 900),
            $this->cand('seasonal', 7, [], 1, 50),
            $this->cand('reconnect', 8, [], 2, 2000),
        ], [], [], [], $this->consent([7, 8]));
        $this->assertSame([['seasonal', 7], ['reconnect', 8]], array_map(fn($c) => [$c['kind'], $c['contact_id']], $f['keep']));
    }

    public function test_a_quiet_pm_with_nobody_to_write_to_becomes_a_question(): void
    {
        $row = MiaFinder::row('pm_quiet', ['contact_id' => null], ['company' => 'Pacific Quorum'], 1, 0);
        $row['subject_key'] = 'mia:company:9';
        $row['company_id'] = 9;
        $row['company_name'] = 'Pacific Quorum';
        $f = MiaFinder::filter([$row], [], [], [], []);
        $this->assertTrue($f['keep'][0]['needs_contact']);
    }

    public function test_marketing_texts_are_off_even_with_sms_consent(): void
    {
        $f = MiaFinder::filter([
            $this->cand('reconnect', 1, ['receive_sms' => 1, 'consent_sms_express_at' => '2026-01-01']),
        ], [], [], [], $this->consent([1]));
        $this->assertFalse($f['keep'][0]['sms_ok'], 'a text cannot carry an unsubscribe link');
        $this->assertSame('implied consent until 2027-01-01', $f['keep'][0]['consent']);
    }

    public function test_asking_for_consent_is_fine_for_current_customers(): void
    {
        $f = MiaFinder::filter([$this->cand('consent_ask', 3)], [], [3 => true], [], $this->consent([3]));
        $this->assertCount(1, $f['keep']);
    }

    // ── What came of it ──────────────────────────────────────────────────

    public function test_the_strongest_result_inside_30_days_counts(): void
    {
        $now = new DateTimeImmutable('2026-10-05 12:00');
        $events = [
            ['type' => 'reply', 'at' => '2026-09-21 09:00:00', 'ref' => 'message:1'],
            ['type' => 'quote', 'at' => '2026-09-25 09:00:00', 'ref' => 'quote:4'],
            ['type' => 'booked', 'at' => '2026-08-01 09:00:00', 'ref' => 'plan:2'],   // before the send
        ];
        $o = MiaFinder::outcome('2026-09-20 10:00:00', $events, $now);
        $this->assertSame('quote', $o['outcome']);
        $this->assertSame('quote:4', $o['ref']);
    }

    public function test_nothing_yet_waits_and_nothing_after_30_days_is_none(): void
    {
        $this->assertNull(MiaFinder::outcome('2026-09-20 10:00:00', [], new DateTimeImmutable('2026-10-05')));
        $this->assertSame('none', MiaFinder::outcome('2026-08-01 10:00:00', [], new DateTimeImmutable('2026-10-05'))['outcome']);
        $late = [['type' => 'booked', 'at' => '2026-09-15 10:00:00', 'ref' => 'plan:1']];
        $this->assertSame('none', MiaFinder::outcome('2026-08-01 10:00:00', $late, new DateTimeImmutable('2026-10-05'))['outcome'], 'too late to be hers');
    }

    // ── Learning a threshold ─────────────────────────────────────────────

    public function test_seven_not_a_fit_skips_in_ten_make_her_wait_longer(): void
    {
        $d = fn(string $s, ?string $r = null) => ['status' => $s, 'skip_reason' => $r];
        $mostlyNotFit = array_merge(array_fill(0, 7, $d('skipped', 'not_fit')), array_fill(0, 3, $d('sent')));
        $this->assertSame(15, MiaFinder::tunedLapse(12, $mostlyNotFit));
        $this->assertSame(24, MiaFinder::tunedLapse(23, $mostlyNotFit), 'capped at 24 months');
        $this->assertSame(12, MiaFinder::tunedLapse(12, array_slice($mostlyNotFit, 0, 9)), 'needs 10 decisions');
        $notNow = array_merge(array_fill(0, 7, $d('skipped', 'not_now')), array_fill(0, 3, $d('sent')));
        $this->assertSame(12, MiaFinder::tunedLapse(12, $notNow), '"not now" is timing, not fit');
    }
}
