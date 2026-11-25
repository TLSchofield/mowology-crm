<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/TripTrailFixture.php';

/**
 * Otto asks Penny: receipts (printed times, photos), vendor locations, past visits and bank lines
 * name the truck's stops — on the real 2026-10-07 day.
 */
class StopEvidenceServiceTest extends TestCase
{
    private const DATE = '2026-10-07';

    private function segments(?array $places = null): array
    {
        return TripSegmentService::segments(TripTrailFixture::real1007(), TripTrailFixture::properties(), $places ?? TripTrailFixture::places());
    }

    private function stopAt(array $segments, string $hm): array
    {
        foreach ($segments as $s) if ($s['type'] === 'stop' && date('H:i', $s['start']) === $hm) return $s;
        $this->fail("no stop starting $hm: " . implode(', ', array_map(fn($s) => date('H:i', $s['start']), array_filter($segments, fn($s) => $s['type'] === 'stop'))));
    }

    private function facts(array ...$rows): array
    {
        return array_map(fn($r) => StopEvidenceService::receiptFacts($r, self::DATE), $rows);
    }

    // ── Reading the slip ────────────────────────────────────────────────────

    public function test_scale_ticket_time_in_and_out_and_the_printed_date(): void
    {
        $p = StopEvidenceService::parseTimes(TripTrailFixture::ticket411()['raw_ocr_json']);
        $this->assertSame('09:49', $p['in']);
        $this->assertSame('10:01', $p['out']);
        $this->assertSame(['09:49', '10:01'], $p['times']);
        $this->assertContains('2026-10-07', $p['dates']);   // 10/07/26 read as MM/DD/YY
    }

    public function test_till_times_in_12h_24h_and_with_seconds(): void
    {
        $this->assertSame(['10:18'], StopEvidenceService::parseTimes("10/07/2026 10:18\nTOTAL 128.10")['times']);
        $this->assertSame(['14:05'], StopEvidenceService::parseTimes('Time: 2:05 PM')['times']);
        $this->assertSame(['14:05'], StopEvidenceService::parseTimes('Time: 2:05')['times'], 'an un-suffixed 2:05 is the afternoon');
        $this->assertSame(['13:42'], StopEvidenceService::parseTimes('2026-10-07 13:42:10')['times']);
        $this->assertSame([], StopEvidenceService::parseTimes("Phone (778) 846-9273\nTOTAL 25.71")['times']);
        $this->assertSame(['2026-10-07'], StopEvidenceService::parseTimes('Oct 7, 2026')['dates']);
    }

    public function test_a_receipt_printed_on_another_day_gives_no_times_for_this_one(): void
    {
        $r = TripTrailFixture::lawnBoySlip(412, '2026-10-06');
        $f = StopEvidenceService::receiptFacts($r, self::DATE);
        $this->assertSame([], $f['times']);
    }

    public function test_kind_from_category_and_name(): void
    {
        $this->assertSame('dump', StopEvidenceService::kindFor('Disposal/Dump'));
        $this->assertSame('supplier', StopEvidenceService::kindFor('Materials'));
        $this->assertSame('fuel', StopEvidenceService::kindFor('Tools/Equipment', null, 'Chevron #204'), 'gas under $50 is booked as equipment');
        $this->assertSame('supplier', StopEvidenceService::kindFor(null, null, 'LAWN BOY LANDSCAPE SUPPLY'));
        $this->assertNull(StopEvidenceService::kindFor('Meals', null, 'Tim Hortons'));
    }

    // ── 2026-10-07 ─────────────────────────────────────────────────────────

    public function test_ticket_411_is_the_transfer_station_stop_with_12_minutes_on_site(): void
    {
        $seg = $this->segments();
        $dump = $this->stopAt($seg, '09:51');
        $this->assertSame('Vancouver Transfer Station', $dump['label']['name']);
        $this->assertSame(9.0, $dump['minutes'], 'GPS sees 9 minutes');

        $ev = StopEvidenceService::evaluate($seg, $this->facts(TripTrailFixture::ticket411()));
        $e = $ev['stops'][$dump['start']][0];
        $this->assertSame(411, $e['receipt_id']);
        $this->assertSame('printed_time', $e['basis']);
        $this->assertSame(12.0, $e['ticket']['minutes']);
        $this->assertSame('scale ticket #411 9:49–10:01', StopEvidenceService::evidenceLine($e));
        $this->assertCount(1, $ev['stops'], 'the ticket is evidence for one stop only');

        // On the run: the ticket replaces the GPS minutes, and links the receipt (not the vendor words).
        $runs = TripSegmentService::runs($seg, self::DATE);
        $this->assertCount(1, $runs);
        $leg = TripCostService::applyEvidence($runs[0]['legs'][0], $ev['stops'][$dump['start']]);
        $this->assertSame(12.0, $leg['onsite_min']);
        $this->assertSame('ticket', $leg['onsite_basis']);
        $this->assertSame('09:49', date('H:i', $leg['ticket_in']));
    }

    public function test_the_1015_stop_stays_open_with_no_supplier_evidence(): void
    {
        $seg = $this->segments();
        $lawn = $this->stopAt($seg, '10:15');
        $this->assertSame('unnamed', $lawn['label']['type']);
        $this->assertEqualsWithDelta(49.2069, $lawn['lat'], 0.0002);

        // #411 was filed at 11:21, while the truck was driving — no photo evidence anywhere.
        $ev = StopEvidenceService::evaluate($seg, $this->facts(TripTrailFixture::ticket411()));
        $this->assertSame([], $ev['strong']);
        $this->assertSame([], $ev['weak']);
        $this->assertArrayNotHasKey($lawn['start'], $ev['stops']);
        $this->assertCount(2, TripSegmentService::unnamedStops($seg), '10:15 and 11:29 are still open');
    }

    public function test_a_lawn_boy_slip_printed_1018_names_the_stop_lawn_boy_supplier(): void
    {
        $seg = $this->segments();
        $lawn = $this->stopAt($seg, '10:15');
        $ev = StopEvidenceService::evaluate($seg, $this->facts(TripTrailFixture::ticket411(), TripTrailFixture::lawnBoySlip()));
        $e = $ev['strong'][$lawn['start']];
        $this->assertSame('Lawn Boy', $e['name']);
        $this->assertSame('supplier', $e['kind']);
        $this->assertSame(31, $e['vendor_id']);
        $this->assertSame(412, $e['receipt_id']);
        $this->assertSame('receipt #412 printed 10:18', StopEvidenceService::evidenceLine($e));
        $this->assertCount(1, $ev['strong'], 'the 11:29 stop has no slip of its own');

        // Once the place exists, both visits there are labelled — and the second becomes a run too.
        $places = TripTrailFixture::places();
        $places[] = ['id' => 9, 'name' => 'Lawn Boy', 'kind' => 'supplier', 'lat' => $lawn['lat'], 'lng' => $lawn['lng'], 'radius_m' => 150, 'vendor_match' => 'lawn boy'];
        $seg2 = $this->segments($places);
        $this->assertSame('Lawn Boy', $this->stopAt($seg2, '10:15')['label']['name']);
        $this->assertSame('Lawn Boy', $this->stopAt($seg2, '11:29')['label']['name']);
        $this->assertSame([], TripSegmentService::unnamedStops($seg2));
        $legs = TripSegmentService::runs($seg2, self::DATE)[0]['legs'];
        $this->assertSame(['dump', 'supplier'], array_column($legs, 'kind'));
    }

    public function test_a_slip_photographed_at_the_stop_names_it_even_without_a_printed_time(): void
    {
        $seg = $this->segments();
        $lawn = $this->stopAt($seg, '10:15');
        $r = TripTrailFixture::lawnBoySlip();
        $r['raw_ocr_json'] = "LAWN BOY\n2 YD BLACK MULCH\nTOTAL 128.10";
        $r['created_at'] = '2026-10-07 10:22:00';
        $r['receipt_lat'] = 49.2071; $r['receipt_lng'] = -123.1179;
        $ev = StopEvidenceService::evaluate($seg, $this->facts($r));
        $this->assertSame('photo_at_stop', $ev['strong'][$lawn['start']]['basis']);

        // Photographed 2 km away (e.g. back at Oakridge) proves nothing.
        $r['receipt_lat'] = 49.2305; $r['receipt_lng'] = -123.1229;
        $this->assertSame([], StopEvidenceService::evaluate($seg, $this->facts($r))['strong']);
    }

    public function test_a_vendor_location_penny_already_has_names_the_stop(): void
    {
        $seg = $this->segments();
        $spots = [
            ['vendor_id' => 31, 'name' => 'Lawn Boy', 'category' => 'Materials', 'gbp' => null, 'lat' => 49.2070, 'lng' => -123.1178, 'source' => null, 'receipts_seen' => 0],
            ['vendor_id' => 50, 'name' => 'Starbucks', 'category' => 'Meals', 'gbp' => null, 'lat' => 49.2069, 'lng' => -123.1176, 'source' => null, 'receipts_seen' => 0],
        ];
        $ev = StopEvidenceService::evaluate($seg, [], $spots);
        $e = $ev['strong'][$this->stopAt($seg, '10:15')['start']];
        $this->assertSame('vendor_location', $e['basis']);
        $this->assertSame('Lawn Boy', $e['name']);
        // A learned spot with a single receipt is not enough.
        $spots[0]['source'] = 'learned';
        $spots[0]['receipts_seen'] = 1;
        $this->assertSame([], StopEvidenceService::evaluate($seg, [], $spots)['strong']);
    }

    public function test_a_supplier_card_charge_is_only_a_guess_and_a_no_is_remembered(): void
    {
        $seg = $this->segments();
        $lawn = $this->stopAt($seg, '10:15');
        $bank = [
            ['id' => 900, 'amount' => '27.00', 'description' => 'Point of sale CITY OF VANCOUVER LANDFILL', 'vendor_id' => null],
            ['id' => 901, 'amount' => '128.10', 'description' => 'Point of sale LAWN BOY LANDSCAPE SUP VANCOUVER', 'vendor_id' => null],
        ];
        $vendors = [
            ['id' => 12, 'name' => 'City of Vancouver Vancouver Landfill', 'aliases' => 'Vancouver Landfill', 'category' => 'Disposal/Dump', 'gbp' => null],
            ['id' => 31, 'name' => 'Lawn Boy', 'aliases' => 'Lawnboy', 'category' => 'Materials', 'gbp' => null],
        ];
        $ev = StopEvidenceService::evaluate($seg, $this->facts(TripTrailFixture::ticket411()), [], $bank, $vendors);
        $this->assertSame([], $ev['strong']);
        $g = $ev['weak'][$lawn['start']];
        $this->assertSame('weak', $g['strength']);
        $this->assertSame('Lawn Boy', $g['name']);
        $this->assertSame(901, $g['txn_id']);
        $this->assertSame('card charge $128.10', StopEvidenceService::evidenceLine($g));
        $this->assertCount(1, $ev['weak'], 'one charge, one guess — the 11:29 stop is not offered the same charge');

        $rej = [['lat' => $lawn['lat'], 'lng' => $lawn['lng'], 'name' => 'Lawn Boy']];
        $this->assertArrayNotHasKey($lawn['start'], StopEvidenceService::evaluate($seg, [], [], $bank, $vendors, $rej)['weak']);

        // With a Lawn Boy slip already filed, the charge is explained and the slip names the stop.
        $ev = StopEvidenceService::evaluate($seg, $this->facts(TripTrailFixture::lawnBoySlip()), [], $bank, $vendors);
        $this->assertSame('printed_time', $ev['strong'][$lawn['start']]['basis']);
        $this->assertSame([], $ev['weak']);
    }

    public function test_payee_strips_the_bank_prefix(): void
    {
        $this->assertSame('LAWN BOY #2 VANCOUVER', StopEvidenceService::payee('Point of sale LAWN BOY #2 VANCOUVER'));
        $this->assertSame('CHEVRON 204', StopEvidenceService::payee('POS - Interac CHEVRON 204'));
    }

    // ── Learning from past receipts ────────────────────────────────────────

    public function test_two_past_lawn_boy_visits_at_the_same_truck_stop_name_todays_stop_without_a_receipt(): void
    {
        // Two earlier days: the truck stopped at the same spot and a Lawn Boy slip printed inside the stop.
        $obs = [];
        foreach (['2026-09-18' => '13:05', '2026-09-30' => '08:40'] as $date => $hm) {
            $pings = [];
            $t0 = strtotime("$date $hm") - 300;
            for ($i = 0; $i <= 12; $i++) {
                $pings[] = ['lat' => 49.2069 + ($i % 2) * 0.00005, 'lng' => -123.1176, 'speed_kph' => 0.0, 't' => $t0 + $i * 60];
            }
            $seg = TripSegmentService::segments($pings, TripTrailFixture::properties(), TripTrailFixture::places());
            $facts = [StopEvidenceService::receiptFacts(TripTrailFixture::lawnBoySlip(300 + count($obs), $date, $hm), $date)];
            foreach (StopEvidenceService::assignReceipts(array_values(array_filter($seg, fn($s) => $s['type'] === 'stop')), $facts) as $start => $list) {
                foreach ($seg as $s) {
                    if ($s['type'] === 'stop' && $s['start'] === $start) {
                        $obs[] = ['vendor_id' => $list[0]['vendor_id'], 'name' => $list[0]['name'], 'kind' => $list[0]['kind'],
                                  'lat' => $s['lat'], 'lng' => $s['lng'], 'date' => $date, 'receipt_id' => $list[0]['receipt_id']];
                    }
                }
            }
        }
        $this->assertCount(2, $obs);
        $learned = StopEvidenceService::clusterObservations($obs);
        $this->assertCount(1, $learned);
        $this->assertSame('Lawn Boy', $learned[0]['name']);
        $this->assertSame(2, $learned[0]['visits']);

        // One visit is not enough.
        $this->assertSame([], StopEvidenceService::clusterObservations([$obs[0]]));

        // With the learned place, today's 10:15 stop is Lawn Boy — no receipt for today needed.
        $places = TripTrailFixture::places();
        $places[] = ['id' => 10, 'name' => $learned[0]['name'], 'kind' => $learned[0]['kind'], 'lat' => $learned[0]['lat'], 'lng' => $learned[0]['lng'], 'radius_m' => 150, 'vendor_match' => 'lawn boy'];
        $stop = $this->stopAt($this->segments($places), '10:15');
        $this->assertSame('place', $stop['label']['type']);
        $this->assertSame('Lawn Boy', $stop['label']['name']);
        $this->assertSame('supplier', $stop['label']['kind']);
    }

    public function test_a_spot_three_vendors_claim_is_not_learned(): void
    {
        $obs = [];
        foreach ([1 => 'Lawn Boy', 2 => 'Home Depot', 3 => 'Rona'] as $vid => $name) {
            foreach (['2026-09-01', '2026-09-08'] as $i => $d) {
                $obs[] = ['vendor_id' => $vid, 'name' => $name, 'kind' => 'supplier', 'lat' => 49.25, 'lng' => -123.10, 'date' => $d, 'receipt_id' => $vid * 10 + $i];
            }
        }
        $this->assertSame([], StopEvidenceService::clusterObservations($obs));
    }

    public function test_receipts_time_matched_elsewhere_are_not_vendor_matched_here(): void
    {
        $by = [100 => [['receipt_id' => 411]], 200 => [['receipt_id' => 412]]];
        $this->assertSame([412], TripCostService::matchedElsewhere($by, 100));
    }
}
