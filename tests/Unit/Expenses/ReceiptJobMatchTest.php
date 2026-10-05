<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptParser.php';
require_once __DIR__ . '/../../../app/Services/Receipts/ReceiptSmartMatch.php';

/**
 * Job matching by the receipt's own date and printed time (roadmap B1).
 *
 * The schedule match used to compare stops for today/tomorrow against the upload
 * time, so a receipt uploaded the next morning, rescanned, or queued offline lost
 * its job. On live data (2026-10-05) 80% of scanned receipts print a time and
 * purchases peak at 8am — supplies bought on the way to the job.
 */
class ReceiptJobMatchTest extends TestCase
{
    // ── extractPurchaseTime ───────────────────────────────────────────

    /** @dataProvider printedTimes */
    public function test_reads_the_printed_purchase_time(string $text, ?string $expected): void
    {
        $this->assertSame($expected, extractPurchaseTime(preg_split('/\n/', $text)));
    }

    public static function printedTimes(): array
    {
        return [
            'ISO date + time'         => ["SHELL\n2026-10-02 13:58:13\nTOTAL 40.00", '13:58'],
            'US date + PM'            => ["9/29/2026 3:11 PM", '15:11'],
            'labelled time'           => ["DATE: 2026-09-18 TIME: 10:56:26", '10:56'],
            'a.m. with dots'          => ["11:15 a.m.", '11:15'],
            'spaced P M'              => ["OCT 04 2:03 P M", '14:03'],
            'midnight AM'             => ["10/04/26 12:05 AM", '00:05'],
            'store hours skipped'     => ["HOURS 7:00-21:00\n2026-10-04 08:12", '08:12'],
            'range without label'     => ["MON-FRI 8:00 - 17:00\n08:41", '08:41'],
            'dated line beats lone'   => ["REF 09:00\n2026-10-04 15:00:00", '15:00'],
            'no time'                 => ["SUBWAY\nTOTAL 12.50", null],
            'impossible 12h hour'     => ["13:10 PM", null],
        ];
    }

    // ── receiptMatchDate ──────────────────────────────────────────────

    public function test_match_date_rejects_unusable_dates(): void
    {
        $this->assertSame(date('Y-m-d'), receiptMatchDate(date('Y-m-d')));
        $this->assertNull(receiptMatchDate(null));
        $this->assertNull(receiptMatchDate('10/04/2026'));
        $this->assertNull(receiptMatchDate(date('Y-m-d', strtotime('+10 days'))));
        $this->assertNull(receiptMatchDate('2019-01-01'));
    }

    // ── scoreStopForReceipt / nextStopAfterPurchase ───────────────────

    private function stop(string $arrive, string $depart, array $crew = [7]): array
    {
        return ['estimated_arrival' => $arrive, 'estimated_departure' => $depart, 'crew_ids' => $crew, 'visits' => [[]]];
    }

    public function test_bought_during_the_visit_scores_highest(): void
    {
        $at = strtotime('2026-10-04 10:20');
        $m = scoreStopForReceipt($this->stop('10:00:00', '11:30:00'), '2026-10-04', 7, null, null, $at, true, true);
        $this->assertSame(10 + 20 + 30, $m['score']);   // same day + crew + during visit
        $this->assertContains('Bought during this visit', $m['reasons']);
    }

    public function test_visit_later_that_morning_scores_and_next_stop_is_picked(): void
    {
        $at = strtotime('2026-10-04 08:05');
        $rows = [];
        foreach ([['08:45:00', '09:30:00'], ['10:00:00', '11:00:00'], ['13:00:00', '14:00:00']] as [$a, $d]) {
            $m = scoreStopForReceipt($this->stop($a, $d), '2026-10-04', 7, null, null, $at, true, true);
            $rows[] = ['score' => $m['score'], '_start' => $m['start'], '_crew' => $m['crew']];
        }
        $this->assertSame(40, $rows[0]['score']);  // same day + crew + within 3h
        $this->assertSame(40, $rows[1]['score']);
        $this->assertSame(30, $rows[2]['score']);  // 5h later: same day + crew only
        $this->assertSame(0, nextStopAfterPurchase($rows, $at), 'first visit after the 8:05 purchase');
    }

    public function test_next_stop_prefers_the_purchasers_crew(): void
    {
        $at = strtotime('2026-10-04 08:00');
        $rows = [
            ['_start' => strtotime('2026-10-04 08:30'), '_crew' => false],
            ['_start' => strtotime('2026-10-04 09:15'), '_crew' => true],
        ];
        $this->assertSame(1, nextStopAfterPurchase($rows, $at));
    }

    public function test_date_only_receipt_gets_same_day_but_no_time_signal(): void
    {
        $m = scoreStopForReceipt($this->stop('10:00:00', '11:00:00', [3]), '2026-10-04', 7, null, null, null, false, true);
        $this->assertSame(10, $m['score'], 'same day only — filtered out by the >10 threshold');
    }

    public function test_falls_back_to_scheduled_slot_when_no_estimate(): void
    {
        $stop = ['estimated_arrival' => null, 'estimated_departure' => null, 'crew_ids' => [],
                 'visits' => [['scheduled_time_start' => '09:00:00', 'scheduled_time_end' => '10:00:00']]];
        $m = scoreStopForReceipt($stop, '2026-10-04', 7, null, null, strtotime('2026-10-04 09:30'), true, true);
        $this->assertContains('Bought during this visit', $m['reasons']);
    }
}
