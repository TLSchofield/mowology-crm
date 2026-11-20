<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MiaHubTestDb.php';

/**
 * Mia's year of campaigns: dates (including the year rollover), watering-stage holds, the
 * follow-up dates Tim sees on a proposal, real CRM prices, and the seed in migration 1195.
 */
class MiaCalendarTest extends TestCase
{
    private function e(array $over = []): array
    {
        return $over + [
            'key' => 'x', 'name' => 'X', 'audience' => 'homeowners', 'propose_on' => '09-08', 'send_from' => '09-15', 'send_to' => '09-24',
            'conditions' => [], 'reminder_days' => null, 'reminder_body' => '', 'lastcall_days' => null, 'lastcall_body' => '',
        ];
    }

    public function test_proposed_seven_days_before_the_send_window_and_until_it_closes(): void
    {
        $e = $this->e();
        $this->assertFalse(MiaCalendar::proposable($e, new DateTimeImmutable('2026-09-07')));
        $this->assertTrue(MiaCalendar::proposable($e, new DateTimeImmutable('2026-09-08')));
        $this->assertTrue(MiaCalendar::proposable($e, new DateTimeImmutable('2026-09-24')));
        $this->assertFalse(MiaCalendar::proposable($e, new DateTimeImmutable('2026-09-25')));
        $o = MiaCalendar::occurrence($e, new DateTimeImmutable('2026-09-25'));
        $this->assertSame('x_2027', $o['key'], 'after the window, the next one is next year');
        $this->assertSame('2027-09-08', $o['propose']);
    }

    public function test_year_rollover_both_ways(): void
    {
        // A January campaign proposed on Dec 29.
        $jan = $this->e(['key' => 'jan', 'propose_on' => '12-29', 'send_from' => '01-05', 'send_to' => '01-20']);
        $this->assertTrue(MiaCalendar::proposable($jan, new DateTimeImmutable('2026-12-30')));
        $o = MiaCalendar::occurrence($jan, new DateTimeImmutable('2026-12-30'));
        $this->assertSame(['year' => 2027, 'key' => 'jan_2027', 'propose' => '2026-12-29', 'send_from' => '2027-01-05', 'send_to' => '2027-01-20'], $o);
        $this->assertFalse(MiaCalendar::proposable($jan, new DateTimeImmutable('2026-12-28')));
        // A window that runs over New Year.
        $w = $this->e(['key' => 'w', 'propose_on' => '12-01', 'send_from' => '12-08', 'send_to' => '01-10']);
        $o = MiaCalendar::occurrence($w, new DateTimeImmutable('2027-01-05'));
        $this->assertSame('w_2026', $o['key']);
        $this->assertSame('2027-01-10', $o['send_to']);
        $this->assertTrue(MiaCalendar::proposable($w, new DateTimeImmutable('2027-01-05')));
    }

    public function test_upcoming_is_soonest_first_and_skips_finished_windows(): void
    {
        $up = MiaCalendar::upcoming(MiaCalendar::defaults(), new DateTimeImmutable('2026-10-06'), 3);
        $this->assertSame(['post_drought', 'fall_cleanup', 'pm_next_year'], array_map(fn($u) => $u['entry']['key'], $up));
        $up = MiaCalendar::upcoming(MiaCalendar::defaults(), new DateTimeImmutable('2026-12-15'), 2);
        $this->assertSame('2027-01-12', $up[0]['occ']['send_from'], 'January comes round again');
    }

    public function test_lawn_seeding_is_held_in_stage_2_and_3(): void
    {
        $cal = MiaCalendar::defaults();
        $fall = $cal['fall_lawn_main'];
        $this->assertTrue(MiaCalendar::conditionsHold($fall, ['stage' => 1])['ok'], 'Stage 1: seed permits are issued');
        $this->assertTrue(MiaCalendar::conditionsHold($fall, ['stage' => 0])['ok']);
        $h = MiaCalendar::conditionsHold($fall, ['stage' => 2]);
        $this->assertFalse($h['ok']);
        $this->assertStringContainsString('Stage 2', $h['reason']);
        $this->assertFalse(MiaCalendar::conditionsHold($fall, ['stage' => 3])['ok']);
        $this->assertFalse(MiaCalendar::conditionsHold($fall, ['stage' => null])['ok'], 'unknown in season: no promise on a guess');
        $this->assertTrue(MiaCalendar::conditionsHold($cal['hedges_post_nesting'], ['stage' => 3])['ok'], 'hedges need no watering');
        $this->assertFalse(MiaCalendar::conditionsHold($cal['post_drought'], ['stage' => 1, 'drought_year' => false])['ok']);
        $this->assertTrue(MiaCalendar::conditionsHold($cal['post_drought'], ['stage' => 2, 'drought_year' => true])['ok'], 'it sells the lift, not watering');
    }

    public function test_the_proposal_states_its_follow_up_dates(): void
    {
        $fall = MiaCalendar::defaults()['fall_lawn_main'];
        $d = MiaCalendar::sequenceDates($fall, '2026-09-15');
        $this->assertSame([1 => '2026-09-29', 2 => '2026-10-08'], $d);
        $this->assertSame('includes a reminder on Sep 29 and a last call Oct 8', MiaCalendar::sequenceNote($d));
        $this->assertSame('', MiaCalendar::sequenceNote(MiaCalendar::sequenceDates(MiaCalendar::defaults()['moss_lawn_care'], '2026-04-07')));
        $occ = MiaCalendar::occurrenceFor($fall, 2026);
        $this->assertSame('2026-09-15', MiaCalendar::mainDate($occ, new DateTimeImmutable('2026-09-10')));
        $this->assertSame('2026-09-18', MiaCalendar::mainDate($occ, new DateTimeImmutable('2026-09-18')), 'approved late: counts from today');
    }

    public function test_prices_come_from_the_crm_and_missing_ones_are_refused(): void
    {
        $prices = MiaCalendar::pricesFrom([
            ['name' => 'Core Aeration', 'service_type' => 'aeration', 'base_price' => 120, 'min_price' => 95],
            ['name' => 'Aeration (large)', 'service_type' => 'aeration', 'base_price' => 180, 'min_price' => 0],
            ['name' => 'Overseeding', 'service_type' => null, 'base_price' => 90, 'min_price' => null],
            ['name' => 'Mulch (per yard)', 'service_type' => 'beds', 'base_price' => 175, 'min_price' => 0],
            ['name' => 'Free estimate', 'service_type' => 'hedge', 'base_price' => 0, 'min_price' => 0],
        ]);
        $this->assertSame(['aeration' => 95.0, 'overseed' => 90.0, 'mulch' => 175.0], $prices);
        $this->assertSame('Aeration starts at $95 and overseeding at $90. In 2027.', MiaCalendar::fill('Aeration starts at {price:aeration} and overseeding at {price:overseed}. In {next_year}.', $prices, 2026));
        $left = MiaCalendar::fill('Hedges start at {price:hedge}.', $prices, 2026);
        $this->assertSame('Hedges start at {price:hedge}.', $left);
        $this->assertContains('no CRM price found for hedge — write the price in or take the line out', MiaCampaignService::problems('Hedges', $left));
    }

    public function test_every_seeded_email_follows_tims_rules(): void
    {
        $prices = ['aeration' => 95, 'overseed' => 90, 'hedge' => 80, 'cleanup' => 150, 'mulch' => 175, 'topdress' => 60, 'snow' => 100, 'salt' => 40];
        foreach (MiaCalendar::defaults() as $k => $e) {
            $this->assertContains($e['audience'] === '' ? 'x' : explode(',', $e['audience'])[0], MiaCalendar::AUDIENCES, $k);
            $texts = [[$e['subject'], $e['body']]];
            if ($e['reminder_body'] !== '') $texts[] = [$e['reminder_subject'], $e['reminder_body']];
            if ($e['lastcall_body'] !== '') $texts[] = [$e['lastcall_subject'], $e['lastcall_body']];
            foreach ($texts as [$subject, $body]) {
                $body = MiaCalendar::fill($body, $prices, 2026);
                $this->assertSame([], MiaCampaignService::problems(MiaCalendar::fill($subject, $prices, 2026), $body, 1), "$k: $subject");
                $this->assertStringStartsWith('Hi {{first_name}},', $body, $k);
                $this->assertStringContainsString("Thanks,\nTim", $body, $k);
            }
            $this->assertMatchesRegularExpression('/^\d\d-\d\d$/', $e['propose_on']);
            $occ = MiaCalendar::occurrenceFor($e, 2026);
            $this->assertLessThanOrEqual($occ['send_from'], $occ['propose'], $k);
            if ($k !== 'post_drought') {
                $lead = (strtotime($occ['send_from']) - strtotime($occ['propose'])) / 86400;
                $this->assertGreaterThanOrEqual(6, $lead, "$k is proposed about a week ahead");
            }
        }
        // Stage 2: the summer emails never suggest watering a lawn.
        foreach (['beds_mulch', 'pm_snow_preseason', 'hedges_post_nesting', 'post_drought'] as $k) {
            $e = MiaCalendar::defaults()[$k];
            $this->assertFalse(MiaCampaignService::mentionsLawnWatering(MiaCalendar::fill($e['body'], $prices, 2026)), $k);
        }
    }

    public function test_hedges_respect_nesting_season(): void
    {
        $cal = MiaCalendar::defaults();
        $before = MiaCalendar::occurrenceFor($cal['hedge_before_nesting'], 2027);
        $this->assertLessThan('2027-03-15', $before['send_to']);
        $after = MiaCalendar::occurrenceFor($cal['hedges_post_nesting'], 2027);
        $this->assertGreaterThan('2027-08-15', $after['send_from']);
        $snow = MiaCalendar::occurrenceFor($cal['pm_snow_signup'], 2027);
        $this->assertLessThan('2027-10-01', $snow['send_to'], 'strata winter contracts sign before October 1');
    }

    public function test_migration_1195_seeds_exactly_the_defaults(): void
    {
        $sql = (string)file_get_contents(__DIR__ . '/../../../database/migrations/1195_mia_email_hub.sql');
        $this->assertSame($sql, (string)file_get_contents(__DIR__ . '/../../../public/database/migrations/1195_mia_email_hub.sql'), 'both migration folders');
        $this->assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', implode("\n", self::split($sql)), 'MariaDB-only syntax fails on prod MySQL');
        $db = new MiaHubTestDb();
        $db->exec("CREATE TABLE mia_calendar (id INTEGER PRIMARY KEY, cal_key TEXT UNIQUE, name TEXT, audience TEXT, propose_on TEXT, send_from TEXT, send_to TEXT,
                   work_window TEXT, services TEXT, conditions_json TEXT, why TEXT, subject TEXT, body_text TEXT, reminder_days INT, reminder_subject TEXT,
                   reminder_body TEXT, lastcall_days INT, lastcall_subject TEXT, lastcall_body TEXT, photo_pattern TEXT, label TEXT, is_active INT DEFAULT 1, sort_order INT)");
        $n = 0;
        foreach (self::split($sql) as $stmt) {
            if (stripos($stmt, 'INSERT IGNORE INTO mia_calendar') === 0) { $db->exec($stmt); $n++; }
        }
        $this->assertSame(count(MiaCalendar::defaults()), $n);
        $loaded = MiaCalendar::load($db);
        foreach (MiaCalendar::defaults() as $k => $e) {
            unset($e['sort']);
            $got = $loaded[$k];
            ksort($e);
            ksort($got);
            $this->assertSame($e, $got, $k);
        }
    }

    /** The migration runner's splitter (quote-aware, '' escapes, -- comments). */
    private static function split(string $sql): array
    {
        $out = [];
        $cur = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') { $e = strpos($sql, "\n", $i); $i = $e === false ? $len : $e; continue; }
            if ($ch === "'") {
                $cur .= $ch;
                for ($i++; $i < $len; $i++) {
                    if ($sql[$i] === "'" && ($sql[$i + 1] ?? '') === "'") { $cur .= "''"; $i++; continue; }
                    $cur .= $sql[$i];
                    if ($sql[$i] === "'") break;
                }
                continue;
            }
            if ($ch === ';') { if (trim($cur) !== '') $out[] = trim($cur); $cur = ''; continue; }
            $cur .= $ch;
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }
}
