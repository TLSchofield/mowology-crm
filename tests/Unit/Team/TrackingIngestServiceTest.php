<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The rules that decide whether a GPS fix may be stored at all — i.e. the
 * "only while on the clock, with consent" boundary — plus replay integrity.
 */
class TrackingIngestServiceTest extends TestCase
{
    private const NOW = 1790000000;

    private function point(array $over = []): array
    {
        $raw = array_merge(['lat' => 49.25, 'lng' => -123.1, 'acc' => 12, 't' => self::NOW * 1000], $over);
        return TrackingIngestService::normalizePoints(['points' => [$raw]], self::NOW)[0];
    }

    // ---- normalizePoints ----------------------------------------------------

    public function test_legacy_single_ping_body_is_stamped_now_and_flagged(): void
    {
        $pts = TrackingIngestService::normalizePoints(['lat' => 49.2, 'lng' => -123.0, 'accuracy' => 30, 'visit_id' => 5], self::NOW);
        $this->assertCount(1, $pts);
        $this->assertSame(self::NOW, $pts[0]['ts']);
        $this->assertFalse($pts[0]['has_device_time']);
        $this->assertSame(30.0, $pts[0]['acc']);
        $this->assertSame(5, $pts[0]['visit_id']);
    }

    public function test_device_time_accepts_milliseconds_and_seconds(): void
    {
        $this->assertSame(self::NOW, $this->point(['t' => self::NOW * 1000])['ts']);
        $this->assertSame(self::NOW, $this->point(['t' => self::NOW])['ts'], 'a seconds epoch must not be read as ms (→1970)');
        $this->assertTrue($this->point()['has_device_time']);
    }

    public function test_points_without_coordinates_are_dropped_not_fatal(): void
    {
        $pts = TrackingIngestService::normalizePoints(['points' => [['lat' => 'x', 'lng' => 1], 'junk', ['lng' => 2], ['lat' => 49.1, 'lng' => -123.2]]], self::NOW);
        $this->assertCount(1, $pts);
    }

    public function test_invalid_accuracy_speed_heading_become_null(): void
    {
        $p = $this->point(['acc' => -1, 'speed' => -1, 'heading' => -1]);
        $this->assertNull($p['acc'], 'CoreLocation reports -1 for an invalid fix');
        $this->assertNull($p['speed']);
        $this->assertNull($p['heading']);
    }

    public function test_only_well_formed_uuids_are_kept(): void
    {
        $this->assertSame('0f8fad5b-d9cb-469f-a165-70867728950e', $this->point(['id' => '0F8FAD5B-D9CB-469F-A165-70867728950E'])['id']);
        $this->assertNull($this->point(['id' => "x'; DROP TABLE"])['id']);
    }

    public function test_batch_is_capped(): void
    {
        $many = array_fill(0, TrackingIngestService::MAX_BATCH + 50, ['lat' => 49.1, 'lng' => -123.1]);
        $this->assertCount(TrackingIngestService::MAX_BATCH, TrackingIngestService::normalizePoints(['points' => $many], self::NOW));
    }

    // ---- classify: the work-hours boundary ----------------------------------

    public function test_fix_during_an_open_shift_is_ok(): void
    {
        $this->assertSame('ok', TrackingIngestService::classify($this->point(), self::NOW, [[self::NOW - 3600, null]]));
    }

    public function test_fix_with_no_shift_at_all_is_outside_shift(): void
    {
        $this->assertSame('outside_shift', TrackingIngestService::classify($this->point(), self::NOW, []));
    }

    public function test_fix_after_clock_out_is_rejected_even_if_the_phone_kept_tracking(): void
    {
        $shift = [[self::NOW - 36000, self::NOW - 3600]];                     // clocked out an hour ago
        $this->assertSame('outside_shift', TrackingIngestService::classify($this->point(), self::NOW, $shift));
    }

    public function test_replayed_fix_is_judged_against_the_shift_it_was_recorded_in(): void
    {
        $shift  = [[self::NOW - 36000, self::NOW - 3600]];
        $during = $this->point(['t' => (self::NOW - 7200) * 1000]);
        $this->assertSame('ok', TrackingIngestService::classify($during, self::NOW, $shift));
    }

    public function test_grace_covers_a_fix_seconds_before_clock_in(): void
    {
        $shift = [[self::NOW - 60, null]];
        $this->assertSame('ok', TrackingIngestService::classify($this->point(['t' => (self::NOW - 150) * 1000]), self::NOW, $shift));
        $this->assertSame('outside_shift', TrackingIngestService::classify($this->point(['t' => (self::NOW - 900) * 1000]), self::NOW, $shift));
    }

    public function test_future_dated_fix_is_rejected(): void
    {
        // One future row used to make the receipt-time rate limiter skip every later live ping.
        $p = $this->point(['t' => (self::NOW + 86400) * 1000]);
        $this->assertSame('future', TrackingIngestService::classify($p, self::NOW, [[self::NOW - 3600, null]]));
    }

    public function test_ancient_fix_is_rejected(): void
    {
        $p = $this->point(['t' => (self::NOW - 10 * 86400) * 1000]);
        $this->assertSame('too_old', TrackingIngestService::classify($p, self::NOW, [[self::NOW - 20 * 86400, null]]));
    }

    public function test_null_island_and_out_of_range_coordinates_are_rejected(): void
    {
        $open = [[self::NOW - 3600, null]];
        $this->assertSame('bad_coords', TrackingIngestService::classify($this->point(['lat' => 0, 'lng' => 0]), self::NOW, $open));
        $this->assertSame('bad_coords', TrackingIngestService::classify($this->point(['lat' => 91]), self::NOW, $open));
    }

    // ---- thin ---------------------------------------------------------------

    public function test_thin_sorts_oldest_first_and_drops_bursts(): void
    {
        $pts = [
            $this->point(['t' => (self::NOW - 10) * 1000]),
            $this->point(['t' => (self::NOW - 60) * 1000]),
            $this->point(['t' => (self::NOW - 59) * 1000]),   // 1 s after the previous — dropped
            $this->point(['t' => (self::NOW - 30) * 1000]),
        ];
        $this->assertSame(
            [self::NOW - 60, self::NOW - 30, self::NOW - 10],
            array_column(TrackingIngestService::thin($pts), 'ts')
        );
    }

    // ---- policy -------------------------------------------------------------

    public function test_clocked_out_user_is_told_to_stop(): void
    {
        $p = TrackingIngestService::policy(true, true, true, false, null);
        $this->assertFalse($p['tracking_allowed']);
        $this->assertSame('not_clocked_in', $p['reason']);
        $this->assertSame('off', $p['tier']);
    }

    public function test_reasons_are_reported_in_priority_order(): void
    {
        $this->assertSame('account_inactive',  TrackingIngestService::policy(false, false, false, false, null)['reason']);
        $this->assertSame('tracking_disabled', TrackingIngestService::policy(true, false, false, true, null)['reason']);
        $this->assertSame('consent_required',  TrackingIngestService::policy(true, true, false, true, null)['reason']);
    }

    public function test_on_a_job_means_enhanced_between_jobs_means_baseline(): void
    {
        $this->assertSame('baseline', TrackingIngestService::policy(true, true, true, true, null)['tier']);
        $on = TrackingIngestService::policy(true, true, true, true, 1418);
        $this->assertSame('enhanced', $on['tier']);
        $this->assertSame(1418, $on['active_visit_id']);
        $this->assertLessThan($on['baseline']['interval_s'], $on['enhanced']['interval_s']);
    }

    public function test_active_visit_is_never_leaked_when_tracking_is_not_allowed(): void
    {
        $p = TrackingIngestService::policy(true, true, true, false, 1418);
        $this->assertNull($p['active_visit_id']);
        $this->assertSame('off', $p['tier']);
    }

    // ---- consent ------------------------------------------------------------

    public function test_consent_must_match_the_current_disclosure_and_not_be_withdrawn(): void
    {
        $v = TrackingConsentService::DISCLOSURE_VERSION;
        $this->assertTrue(TrackingConsentService::isCurrent(['disclosure_version' => $v, 'withdrawn_at' => null]));
        $this->assertFalse(TrackingConsentService::isCurrent(['disclosure_version' => 'old', 'withdrawn_at' => null]));
        $this->assertFalse(TrackingConsentService::isCurrent(['disclosure_version' => $v, 'withdrawn_at' => '2026-09-19 10:00:00']));
        $this->assertFalse(TrackingConsentService::isCurrent(null));
    }

    public function test_disclosure_names_the_tenant_and_covers_client_visibility(): void
    {
        $d    = TrackingConsentService::disclosure('Acme Snow Co');
        $text = json_encode($d);
        $this->assertStringContainsString('Acme Snow Co', $d['summary']);
        $this->assertStringContainsString('never shown your name', $text, 'client-facing route proof must be disclosed as anonymous');
        $this->assertStringContainsString('salting and snow removal', $text);
        $this->assertSame(TrackingConsentService::DISCLOSURE_VERSION, $d['version']);
        $this->assertStringContainsString('your employer', TrackingConsentService::disclosure('')['summary']);
    }
}
