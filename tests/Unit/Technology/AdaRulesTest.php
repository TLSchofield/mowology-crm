<?php

use PHPUnit\Framework\TestCase;

/**
 * Ada's rules. The cases come from production on 2026-10-11 (prod-run preview): sync_ledger
 * failing 3 nights then recovering, jobs registered but never scheduled, 37 push failures that
 * were all "APNs not configured" before Apple push went live (older than 48 h, so not today's
 * problem), a test login never used.
 */
class AdaRulesTest extends TestCase
{
    private const NOW = '2026-10-11 15:00:00';

    private function reg(string $key, string $expr, ?string $run = '/crm/cron/x.php'): array
    {
        return ['key' => $key, 'title' => ucfirst($key), 'expr' => $expr, 'app' => '/app/Modules/X/Cron/' . $key . '.php', 'run' => $run];
    }

    private function stats(string $lastAt, string $status = 'success', array $recent = [], int $maxMs = 1000, ?string $lastOk = null, string $err = ''): array
    {
        return ['last_at' => $lastAt, 'last_ok_at' => $lastOk ?? ($status === 'error' ? null : $lastAt), 'last_status' => $status,
                'last_error' => $err, 'max_ms' => $maxMs, 'recent' => $recent ?: [[$status, $lastAt, $err]]];
    }

    public function test_registry_is_read_from_the_page_source(): void
    {
        $src = <<<'PHP'
$crons = [
    ['key'=>'generate_visits','title'=>'Generate Visits','cat'=>'jobs','human'=>'Every 6 hours','expr'=>'0 */6 * * *','app'=>'/app/Modules/Jobs/Cron/generate_visits.php','run'=>'/crm/cron/generate_visits.php','abbr'=>'GV','desc'=>'Generate future visits.'],
    ['key'=>'push_drain','title'=>'Push Notification Drain','cat'=>'core','expr'=>'*/5 * * * *','app'=>'/app/Services/Push/Cron/push-drain.php','abbr'=>'PD5','desc'=>'CLI-only &mdash; Apple\'s JWT'],
    ['key'=>'charlie_morning_brief','title'=>"Charlie: Brief & Urgent Alerts",'expr'=>'2,17,32,47 * * * *','app'=>'/app/Modules/ChiefOfStaff/Cron/charlie_morning_brief.php','run'=>'/crm/cron/charlie_morning_brief.php','desc'=>'x'],
];
PHP;
        $r = AdaRules::parseRegistry($src);
        $this->assertSame(['generate_visits', 'push_drain', 'charlie_morning_brief'], array_keys($r));
        $this->assertSame('0 */6 * * *', $r['generate_visits']['expr']);
        $this->assertSame('/crm/cron/generate_visits.php', $r['generate_visits']['run']);
        $this->assertNull($r['push_drain']['run']);
        $this->assertSame('Charlie: Brief & Urgent Alerts', $r['charlie_morning_brief']['title']);
    }

    /** @dataProvider intervals */
    public function test_interval_of_every_registry_shape(string $expr, int $minutes): void
    {
        $this->assertSame($minutes, AdaRules::intervalMinutes($expr));
    }

    public static function intervals(): array
    {
        return [
            ['* * * * *', 1], ['*/5 * * * *', 5], ['*/15 * * * *', 15], ['2,17,32,47 * * * *', 15],
            ['0,10,20,30,40,50 * * * *', 10], ['0,30 * * * *', 30], ['10 * * * *', 60],
            ['0 */6 * * *', 360], ['0 23 * * *', 1440], ['15 4 * * 1', 10080], ['0 6 1 * *', 44640],
        ];
    }

    public function test_cron_runs_log_is_not_cron_runs(): void
    {
        $this->assertTrue(AdaRules::scriptRecords("recordCronRun('x', 'success');"));
        $this->assertTrue(AdaRules::scriptRecords('INSERT INTO cron_runs (cron_key) VALUES (?)'));
        $this->assertFalse(AdaRules::scriptRecords('INSERT INTO cron_runs_log (job) VALUES (?)'));
    }

    public function test_a_failing_job_is_red_with_the_error_and_a_developer_handoff(): void
    {
        $recent = [['error', '2026-10-10 23:30:00', '1615 Prepared statement needs to be re-prepared'], ['error', '2026-10-09 23:30:00', 'x'], ['success', '2026-10-08 23:30:00', '']];
        $c = AdaRules::cronCheck($this->reg('sync_ledger', '0 2 * * *'), $this->stats('2026-10-10 23:30:00', 'error', $recent, 1000, '2026-10-08 23:30:00', '1615 Prepared statement needs to be re-prepared'), true, true, self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertStringContainsString('failed its last 2 runs', $c['evidence']);
        $this->assertStringContainsString('re-prepared', $c['evidence']);
        $this->assertSame('developer', $c['handoff_to']);
        $this->assertSame('2026-10-08 23:30:00', $c['last_worked']);
    }

    public function test_recovered_after_failing_is_amber_for_three_days(): void
    {
        $recent = [['success', '2026-10-10 23:30:00', ''], ['error', '2026-10-09 23:30:00', '1615 Prepared statement'], ['error', '2026-10-08 23:30:00', '1615'], ['error', '2026-10-07 23:30:00', '1615']];
        $c = AdaRules::cronCheck($this->reg('sync_ledger', '0 2 * * *'), $this->stats('2026-10-10 23:30:00', 'success', $recent), true, true, self::NOW);
        $this->assertSame('amber', $c['state']);
        $this->assertSame('recovered', $c['problem']);
        $this->assertStringContainsString('failed 2 times', $c['evidence']);   // Oct 7 is outside 3 days

        $c = AdaRules::cronCheck($this->reg('sync_ledger', '0 2 * * *'), $this->stats('2026-10-10 23:30:00', 'success', $recent), true, true, '2026-10-14 15:00:00');
        $this->assertNotSame('recovered', $c['problem']);
    }

    public function test_a_missed_job_is_red_and_goes_to_tim_for_cpanel(): void
    {
        $c = AdaRules::cronCheck($this->reg('auto_rollover', '0 23 * * *'), $this->stats('2026-10-08 20:00:00'), true, true, self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertSame('missed', $c['problem']);
        $this->assertSame('tim', $c['handoff_to']);
        $this->assertStringContainsString('cPanel', $c['handoff']);
    }

    public function test_within_two_intervals_is_not_missed(): void
    {
        $c = AdaRules::cronCheck($this->reg('auto_rollover', '0 23 * * *'), $this->stats('2026-10-10 20:00:00'), true, true, self::NOW);
        $this->assertSame('green', $c['state']);
    }

    public function test_overdue_but_no_longer_records_is_cant_tell_not_missed(): void
    {
        $c = AdaRules::cronCheck($this->reg('contract_billing', '0 6 1 * *'), $this->stats('2026-06-01 03:00:16'), false, true, self::NOW);
        $this->assertSame('unknown', $c['state']);
        $this->assertStringContainsString("can't tell", $c['evidence']);
    }

    public function test_never_ran_records_is_amber_never_ran_does_not_record_is_unknown(): void
    {
        $this->assertSame('never-ran', AdaRules::cronCheck($this->reg('closer_recalibrate', '15 4 * * 1'), null, true, true, self::NOW)['problem']);
        $this->assertSame('amber', AdaRules::cronCheck($this->reg('closer_recalibrate', '15 4 * * 1'), null, true, true, self::NOW)['state']);
        $this->assertSame('unknown', AdaRules::cronCheck($this->reg('campaign_sender', '*/15 * * * *'), null, false, true, self::NOW)['state']);
    }

    public function test_missing_script_is_red(): void
    {
        $this->assertSame('red', AdaRules::cronCheck($this->reg('x', '0 1 * * *'), null, false, false, self::NOW)['state']);
    }

    public function test_a_run_near_its_interval_is_slow(): void
    {
        $c = AdaRules::cronCheck($this->reg('icloud_inbox_poll', '7,22,37,52 * * * *'), $this->stats('2026-10-11 14:52:00', 'success', [], 800000), true, true, self::NOW);
        $this->assertSame('slow', $c['problem']);
        $c = AdaRules::cronCheck($this->reg('icloud_inbox_poll', '7,22,37,52 * * * *'), $this->stats('2026-10-11 14:52:00', 'success', [], 601042), true, true, self::NOW);
        $this->assertSame('green', $c['state']);   // 10 min of a 15 min interval: under 80%
    }

    public function test_unlisted_keys_are_info(): void
    {
        $this->assertSame('unlisted', AdaRules::unlistedCron('process_ocr_queue', '2026-10-11 00:51:00', self::NOW)['problem']);
        $this->assertSame('retired', AdaRules::unlistedCron('social_metrics_sync', '2026-07-13 06:00:00', self::NOW)['problem']);
        $this->assertSame('info', AdaRules::unlistedCron('social_metrics_sync', '2026-07-13 06:00:00', self::NOW)['state']);
    }

    public function test_a_proxy_turns_cant_tell_into_works(): void
    {
        $checks = [
            AdaRules::cronCheck($this->reg('trackimo_poll', '* * * * *'), null, false, true, self::NOW),
            AdaRules::trackimoCheck('2026-10-11 14:59:00', self::NOW),
            AdaRules::cronCheck($this->reg('campaign_sender', '*/15 * * * *'), null, false, true, self::NOW),
        ];
        $out = AdaRules::applyProxies($checks);
        $this->assertSame('green', $out[0]['state']);
        $this->assertStringContainsString('its work shows', $out[0]['evidence']);
        $this->assertSame('unknown', $out[2]['state']);
    }

    public function test_push_failures_older_than_48_hours_are_not_counted_by_the_caller_and_few_failures_stay_green(): void
    {
        $this->assertSame('green', AdaRules::pushCheck(88, 0, '2026-10-11 14:10:00', null, self::NOW)['state']);
        $this->assertSame('green', AdaRules::pushCheck(88, 2, '2026-10-11 14:10:00', 'BadDeviceToken', self::NOW)['state']);
        $c = AdaRules::pushCheck(5, 37, '2026-10-11 14:10:00', 'APNs not configured', self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertStringContainsString('APNs not configured', $c['evidence']);
    }

    public function test_social_down_goes_to_tim_to_reconnect(): void
    {
        $c = AdaRules::socialCheck('facebook', ['status' => 'error', 'reason' => 'decrypt_failed', 'detail' => 'token could not be decrypted', 'checked_at' => '2026-10-11 14:00:00'], self::NOW);
        $this->assertSame('red', $c['state']);
        $this->assertSame('tim', $c['handoff_to']);
        $this->assertSame('green', AdaRules::socialCheck('facebook', ['status' => 'ok', 'checked_at' => '2026-10-11 14:40:00'], self::NOW)['state']);
        $this->assertSame('amber', AdaRules::socialCheck('facebook', ['status' => 'ok', 'checked_at' => '2026-10-09 14:40:00'], self::NOW)['state']);
    }

    public function test_truck_gps_is_strict_in_working_hours_only(): void
    {
        // Saturday 15:00: working hours.
        $this->assertSame('red', AdaRules::trackimoCheck('2026-10-10 14:00:00', '2026-10-10 15:00:00')['state']);
        // Sunday: a day of silence is fine.
        $this->assertSame('green', AdaRules::trackimoCheck('2026-10-11 02:00:00', '2026-10-11 15:00:00')['state']);
    }

    public function test_a_login_counts_as_used_when_the_phone_was_seen(): void
    {
        $users = [
            ['id' => 5, 'name' => 'Nigel', 'last_login' => '2026-03-01 00:00:00', 'seen' => '2026-10-10 00:00:00'],
            ['id' => 8, 'name' => 'Test Driver', 'last_login' => null, 'seen' => null],
            ['id' => 9, 'name' => 'Himal Bam', 'last_login' => '2026-03-17 20:21:03', 'seen' => null],
        ];
        $c = AdaRules::loginsCheck($users, self::NOW);
        $this->assertSame('amber', $c['state']);
        $this->assertStringContainsString('2 active logins', $c['evidence']);
        $this->assertStringNotContainsString('Nigel', $c['evidence']);
    }

    public function test_brief_lists_red_first_caps_lines_and_sums_cant_tell(): void
    {
        $checks = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $k) $checks[] = AdaRules::cronCheck($this->reg($k, '15 4 * * 1'), null, true, true, self::NOW);
        $checks[] = AdaRules::cronCheck($this->reg('z', '0 23 * * *'), $this->stats('2026-10-01 20:00:00'), true, true, self::NOW);
        $checks[] = AdaRules::cronCheck($this->reg('u1', '0 1 * * *'), null, false, true, self::NOW);
        $checks[] = AdaRules::cronCheck($this->reg('u2', '0 1 * * *'), null, false, true, self::NOW);
        $items = AdaRules::briefItems($checks, '2026-10-11');
        $this->assertCount(AdaRules::BRIEF_MAX + 1, $items);
        $this->assertSame('ada:cron:z:missed', $items[0]['key']);
        $this->assertSame(1, $items[0]['priority']);
        $this->assertSame('ada:cant-tell', end($items)['kind']);
        $this->assertStringContainsString('2 scheduled jobs', end($items)['text']);
        $this->assertSame('1 broken, 6 to look at.', AdaRules::headline(AdaRules::counts($checks)));
    }

    public function test_errors_never_carry_paths_or_token_shaped_strings(): void
    {
        // A token-shaped string, built at runtime so secret scanners don't mistake the test for a leak.
        $e = AdaRules::shortError('Failed at /home/mowology/public_html/app/x.php: key ' . 'tok' . '_' . str_repeat('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', 1) . ' rejected');
        $this->assertStringNotContainsString('/home/', $e);
        $this->assertStringNotContainsString('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $e);
    }
}
