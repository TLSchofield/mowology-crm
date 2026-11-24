<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The phones' crash/freeze reports (AppVitalsService) and what Ada makes of them (AdaAppVitals). */
class AppVitalsServiceTest extends TestCase
{
    private const NOW = '2026-11-15 12:00:00';
    // The real line the iPhone writes today for a CPU exception (build 581, 2026-11-12), as one event message.
    private const CPU_LINE = 'CPU EXCEPTION: v1.3.139(581) iPhone17,3 iPhone OS 18.5 (22F76) · cpu 48 sec of 48 sec sampled · samples 15: SwiftUICore 33%, libswiftCore.dylib 20% · top app frames (last MowologyCRM frame before leaf): MowologyCRM+0x8499f[10BFDF3A] ×11';

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE app_vitals_events (id INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT UNIQUE, business TEXT, user_id INT, platform TEXT, install_id TEXT,
            app_version TEXT, build TEXT, device TEXT, os TEXT, kind TEXT, severity TEXT, signature TEXT, message TEXT, breadcrumbs TEXT, occurred_at TEXT, received_at TEXT)");
        $db->exec("CREATE TABLE app_vitals_issues (signature TEXT PRIMARY KEY, kind TEXT, platform TEXT, title TEXT, first_seen TEXT, last_seen TEXT,
            first_build TEXT, last_build TEXT, event_count INT DEFAULT 1, status TEXT DEFAULT 'open')");
        return $db;
    }

    private function batch(array $events, string $install = 'phone-a', string $build = '581'): array
    {
        return ['device' => ['platform' => 'ios', 'install_id' => $install, 'model' => 'iPhone17,3', 'os' => 'iOS 18.5', 'app_version' => '1.3.139', 'build' => $build], 'events' => $events];
    }

    private function cpu(string $id, array $extra = []): array
    {
        return $extra + ['id' => $id, 'at' => '2026-11-15T11:00:00Z', 'kind' => 'cpu_exception', 'severity' => 'warn', 'message' => self::CPU_LINE,
                         'signature_parts' => ['cause' => 'cpu exception', 'frame' => 'MowologyCRM+0x8499f', 'binary_uuid' => '10BFDF3A']];
    }

    public function test_a_batch_without_a_known_platform_or_install_is_unusable(): void
    {
        $this->assertNull(AppVitalsService::clean(['device' => ['platform' => 'web', 'install_id' => 'x'], 'events' => [$this->cpu('1')]]));
        $this->assertNull(AppVitalsService::clean(['device' => ['platform' => 'ios'], 'events' => [$this->cpu('1')]]));
        $this->assertNull(AppVitalsService::clean($this->batch([])));
    }

    public function test_bad_events_are_dropped_and_long_ones_cut(): void
    {
        $long = $this->cpu('2', ['message' => str_repeat('x', 2000), 'breadcrumbs' => array_map(fn($i) => "step $i", range(1, 80))]);
        $c = AppVitalsService::clean($this->batch([$this->cpu('1', ['kind' => 'made_up']), $this->cpu('', []), $this->cpu('3', ['at' => 'not a date']), $long]));
        $this->assertCount(1, $c['events']);
        $this->assertSame(600, mb_strlen($c['events'][0]['message']));
        $this->assertCount(30, $c['events'][0]['breadcrumbs']);
        $this->assertSame('step 80', end($c['events'][0]['breadcrumbs']), 'the crumbs nearest the failure are kept');
    }

    public function test_only_the_first_fifty_events_of_a_batch_count(): void
    {
        $events = array_map(fn($i) => $this->cpu("e$i"), range(1, 70));
        $this->assertCount(50, AppVitalsService::clean($this->batch($events))['events']);
    }

    public function test_same_cause_and_frame_share_a_signature_across_phones_and_builds(): void
    {
        $a = AppVitalsService::signature('cpu_exception', ['cause' => 'cpu exception', 'frame' => 'MowologyCRM+0x8499f', 'binary_uuid' => '10BFDF3A']);
        $this->assertSame($a, AppVitalsService::signature('cpu_exception', ['cause' => 'CPU Exception', 'frame' => 'MowologyCRM+0x8499f', 'binary_uuid' => '10BFDF3A']));
        $this->assertNotSame($a, AppVitalsService::signature('cpu_exception', ['cause' => 'cpu exception', 'frame' => 'MowologyCRM+0x1', 'binary_uuid' => '10BFDF3A']));
        $this->assertNotSame($a, AppVitalsService::signature('hang', ['cause' => 'cpu exception', 'frame' => 'MowologyCRM+0x8499f', 'binary_uuid' => '10BFDF3A']));
        $this->assertSame(AppVitalsService::signature('hang', [], 'hang 2.1 sec on Schedule'), AppVitalsService::signature('hang', [], 'hang 3.4 sec on Schedule'), 'numbers in the message do not split an issue');
    }

    public function test_metric_rows_are_stored_without_becoming_issues(): void
    {
        $db = $this->db();
        $r = (new AppVitalsService($db))->ingest('mowology', 7, $this->batch([['id' => 'm1', 'at' => '2026-11-15T01:00:00Z', 'kind' => 'daily_metrics', 'message' => 'launch 640 ms median · hitches 7.2 ms/s']]), self::NOW);
        $this->assertSame(1, $r['accepted']);
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) FROM app_vitals_issues")->fetchColumn());
        $this->assertNull($db->query("SELECT signature FROM app_vitals_events")->fetchColumn());
    }

    public function test_resending_a_batch_stores_nothing_twice(): void
    {
        $db = $this->db();
        $svc = new AppVitalsService($db);
        $b = $this->batch([$this->cpu('e1'), $this->cpu('e2')]);
        $this->assertSame(['accepted' => 2, 'duplicate' => 0, 'rejected' => 0], $svc->ingest('mowology', 7, $b, self::NOW));
        $this->assertSame(['accepted' => 0, 'duplicate' => 2, 'rejected' => 0], $svc->ingest('mowology', 7, $b, self::NOW));
        $this->assertSame(2, (int)$db->query("SELECT event_count FROM app_vitals_issues")->fetchColumn(), 'a resend does not inflate the issue');
    }

    public function test_one_issue_collects_events_from_several_phones_and_remembers_builds(): void
    {
        $db = $this->db();
        $svc = new AppVitalsService($db);
        $svc->ingest('mowology', 7, $this->batch([$this->cpu('a1')], 'phone-a', '581'), '2026-11-14 10:00:00');
        $svc->ingest('mowology', 8, $this->batch([$this->cpu('b1')], 'phone-b', '587'), self::NOW);
        $row = $db->query("SELECT * FROM app_vitals_issues")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$row['event_count']);
        $this->assertSame('581', $row['first_build']);
        $this->assertSame('587', $row['last_build']);
        $this->assertSame('2026-11-14 10:00:00', $row['first_seen']);
    }

    public function test_a_looping_phone_cannot_fill_the_table(): void
    {
        $db = $this->db();
        $svc = new AppVitalsService($db);
        $accepted = 0;
        $rejected = 0;
        for ($b = 0; $b < 5; $b++) {
            $r = $svc->ingest('mowology', 7, $this->batch(array_map(fn($i) => $this->cpu("b{$b}e{$i}"), range(1, 50))), self::NOW);
            $accepted += $r['accepted'];
            $rejected += $r['rejected'];
        }
        $this->assertSame(AppVitalsService::DAILY_CAP, $accepted);
        $this->assertSame(250 - AppVitalsService::DAILY_CAP, $rejected);
    }

    public function test_old_events_are_purged_but_issues_stay(): void
    {
        $db = $this->db();
        $svc = new AppVitalsService($db);
        $svc->ingest('mowology', 7, $this->batch([$this->cpu('old')]), '2026-07-01 10:00:00');
        $svc->ingest('mowology', 7, $this->batch([$this->cpu('new')]), self::NOW);
        $this->assertSame(1, $svc->purge(self::NOW));
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM app_vitals_events")->fetchColumn());
        $this->assertSame(1, (int)$db->query("SELECT COUNT(*) FROM app_vitals_issues")->fetchColumn());
    }

    public function test_ada_says_so_when_no_phone_has_ever_reported(): void
    {
        $c = AdaAppVitals::checksFrom([], null, self::NOW);
        $this->assertSame('amber', $c[0]['state']);
        $this->assertSame('silent', $c[0]['problem']);
    }

    public function test_ada_notices_a_pipe_that_went_quiet(): void
    {
        $c = AdaAppVitals::checksFrom([], '2026-11-10 12:00:00', self::NOW);
        $this->assertSame('silent', $c[0]['problem']);
        $this->assertStringContainsString('5 days', $c[0]['evidence']);
    }

    public function test_ada_is_green_when_phones_report_and_nothing_is_wrong(): void
    {
        $c = AdaAppVitals::checksFrom([], '2026-11-15 06:00:00', self::NOW);
        $this->assertCount(1, $c);
        $this->assertSame('green', $c[0]['state']);
    }

    public function test_a_new_single_phone_issue_is_amber_and_a_crash_on_two_phones_is_red(): void
    {
        $base = ['signature' => 'a', 'kind' => 'hang', 'platform' => 'ios', 'title' => 'HANG: 2.4 sec', 'first_seen' => '2026-11-15 09:00:00', 'first_build' => '587', 'last_build' => '587', 'n' => 1, 'devices' => 1];
        $crash = ['signature' => 'b', 'kind' => 'crash', 'first_seen' => '2026-11-01 09:00:00', 'n' => 3, 'devices' => 2] + $base;
        $c = AdaAppVitals::checksFrom([$base, $crash], '2026-11-15 11:00:00', self::NOW);
        $this->assertSame('New: HANG on ios', $c[0]['name']);
        $this->assertSame('amber', $c[0]['state']);
        $this->assertSame('CRASH on ios', $c[1]['name']);
        $this->assertSame('red', $c[1]['state']);
    }

    public function test_ada_reads_the_database_and_counts_phones_in_the_window(): void
    {
        $db = $this->db();
        $svc = new AppVitalsService($db);
        $svc->ingest('mowology', 7, $this->batch([$this->cpu('a1')], 'phone-a', '587'), '2026-11-15 08:00:00');
        $svc->ingest('mowology', 8, $this->batch([$this->cpu('b1')], 'phone-b', '587'), '2026-11-15 09:00:00');
        $svc->ingest('mowology', 8, $this->batch([$this->cpu('old')], 'phone-c', '581'), '2026-11-10 09:00:00');
        $c = (new AdaAppVitals($db, self::NOW))->checks();
        $this->assertCount(1, $c);
        $this->assertStringContainsString('2 reports from 2 phones', $c[0]['evidence'], 'the 5-day-old event is outside the window');
    }
}
