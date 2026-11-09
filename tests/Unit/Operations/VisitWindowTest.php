<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VisitWindowTest extends TestCase
{
    private function visit(array $o): array
    {
        return $o + ['date' => '2026-11-02', 'entries' => [], 'gps_first' => null, 'gps_last' => null, 'gps_minutes' => null, 'actual' => null, 'shared_day' => false];
    }

    public function test_finish_is_the_later_of_timer_and_gps(): void
    {
        $f = VisitWindowRules::finish($this->visit(['actual' => 239, 'gps_last' => '2026-11-02 14:25:58', 'gps_minutes' => 235,
            'entries' => [['start' => '2026-11-02 10:30:47', 'end' => '2026-11-02 14:30:28', 'auto_stopped' => false]]]));
        self::assertSame(['finish' => 870, 'estimated' => false], $f);
    }

    public function test_auto_stopped_timer_does_not_count(): void
    {
        self::assertNull(VisitWindowRules::finish($this->visit(['entries' => [['start' => '2026-11-02 08:00:00', 'end' => '2026-11-02 23:00:00', 'auto_stopped' => true]]])));
    }

    public function test_gps_that_lost_the_crew_gives_start_plus_recorded_time_as_an_estimate(): void
    {
        $f = VisitWindowRules::finish($this->visit(['actual' => 370, 'gps_first' => '2026-11-02 08:29:59', 'gps_last' => '2026-11-02 09:17:44', 'gps_minutes' => 48]));
        self::assertSame(['finish' => 509 + 370, 'estimated' => true], $f);
    }

    public function test_a_shared_day_needs_a_timer_that_spans_the_recorded_time(): void
    {
        $badTimer = [['start' => '2026-11-02 08:31:00', 'end' => '2026-11-02 09:41:00', 'auto_stopped' => false], ['start' => '2026-11-02 08:31:00', 'end' => '2026-11-02 09:41:00', 'auto_stopped' => false]];
        self::assertNull(VisitWindowRules::finish($this->visit(['shared_day' => true, 'actual' => 446, 'entries' => $badTimer, 'gps_first' => '2026-11-02 08:31:10', 'gps_last' => '2026-11-02 14:43:09', 'gps_minutes' => 372])));
        self::assertSame(581, VisitWindowRules::finish($this->visit(['shared_day' => true, 'actual' => 70, 'entries' => [$badTimer[0]]]))['finish']);
    }

    private function finishes(array $times): array
    {
        $out = [];
        foreach ($times as $i => $t) $out[] = ['date' => '2026-11-0' . (9 - $i), 'finish' => VisitWindowRules::minutes($t)];
        return $out;
    }

    public function test_oakridge_mondays_come_out_at_3_pm(): void
    {
        $p = VisitWindowRules::propose($this->finishes(['14:30', '14:40', '12:36']), 510, 960);
        self::assertSame(900, $p['end']);
        self::assertSame(3, $p['fit']);
        self::assertSame('Oakridge, Mondays: book 8:30–3:00 instead of 8:30–4:00?', VisitWindowRules::line('Oakridge', 1, $p));
        self::assertSame('Finished by 3:00 on 3 of the last 3.', VisitWindowRules::detail($p));
    }

    public function test_too_few_or_close_to_the_booked_end_asks_nothing(): void
    {
        self::assertNull(VisitWindowRules::propose($this->finishes(['14:30', '14:40']), 510, 960));
        self::assertNull(VisitWindowRules::propose($this->finishes(['15:40', '15:10', '15:00']), 510, 960));
    }

    public function test_with_five_finishes_one_late_day_is_forgiven_but_not_with_four(): void
    {
        self::assertSame(900, VisitWindowRules::propose($this->finishes(['14:30', '17:10', '14:40', '13:00', '12:36']), 510, 1020)['end']);
        self::assertNull(VisitWindowRules::propose($this->finishes(['14:30', '17:10', '14:40', '13:00']), 510, 1020));
    }

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, property_name TEXT)');
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, title TEXT, property_id INT, status TEXT, is_recurring INT, recurrence_pattern TEXT, recurrence_day_of_week TEXT, default_time_end TEXT)');
        $db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, scheduled_time_start TEXT, scheduled_time_end TEXT, status TEXT, actual_duration_minutes INT)');
        $db->exec('CREATE TABLE visit_onsite_time (visit_id INT, minutes INT, first_at TEXT, last_at TEXT)');
        $db->exec('CREATE TABLE job_time_entries (id INTEGER PRIMARY KEY, visit_id INT, start_time TEXT, end_time TEXT, status TEXT, auto_stopped INT)');
        $db->exec('CREATE TABLE otto_suggestions (id INTEGER PRIMARY KEY, kind TEXT, subject_type TEXT, subject_id INT, for_date TEXT, status TEXT, outcome_json TEXT)');
        $db->exec('CREATE TABLE plan_weekday_windows (plan_id INT, day_of_week INT, time_end TEXT, updated_by INT, updated_at TEXT)');
        $db->exec('CREATE TABLE otto_window_changes (id INTEGER PRIMARY KEY, plan_id INT, day_of_week INT, old_end TEXT, new_end TEXT, old_window_end TEXT, on_plan INT, visit_ids TEXT, basis_json TEXT, changed_by INT, changed_at TEXT, undone_at TEXT)');
        $db->exec("INSERT INTO properties VALUES (73, '6015 Tisdall Street', 'Oakridge Gardens')");
        $db->exec("INSERT INTO job_plans VALUES (59, 'Beds', 73, 'active', 1, 'weekly', '1,3', '16:00:00')");
        $id = 1;
        foreach (['2026-11-02' => '14:30:00', '2026-10-26' => '14:40:00', '2026-10-19' => '12:36:00'] as $d => $end) {   // Mondays
            $db->exec("INSERT INTO job_visits VALUES ($id, 59, '$d', '08:30:00', '16:00:00', 'completed', 200)");
            $db->exec("INSERT INTO job_time_entries (visit_id, start_time, end_time, status, auto_stopped) VALUES ($id, '$d 08:30:00', '$d $end', 'completed', 0)");
            $id++;
        }
        $db->exec("INSERT INTO job_visits VALUES (10, 59, '2026-11-09', '08:30:00', '16:00:00', 'scheduled', NULL)");   // Monday
        $db->exec("INSERT INTO job_visits VALUES (11, 59, '2026-11-11', '08:30:00', '16:00:00', 'scheduled', NULL)");   // Wednesday
        $db->exec("INSERT INTO job_visits VALUES (12, 59, '2026-11-16', '08:30:00', '10:00:00', 'scheduled', NULL)");   // Monday, moved by hand
        return $db;
    }

    public function test_items_apply_and_undo_touch_only_that_weekday(): void
    {
        $db = $this->db();
        $svc = new VisitWindowService($db, '2026-11-08');
        $items = $svc->items();
        self::assertCount(1, $items);
        self::assertSame('visit_window', $items[0]['kind']);
        self::assertStringContainsString('Oakridge Gardens, Mondays: book 8:30–3:00', $items[0]['text']);
        self::assertSame('set_window', $items[0]['propose']['buttons'][0]['choice']);

        $r = $svc->apply(59, 1, '16:00:00', '15:00:00', 7, []);
        self::assertTrue($r['ok']);
        self::assertSame(1, $r['visits']);
        $end = fn(int $id) => $db->query("SELECT scheduled_time_end FROM job_visits WHERE id = $id")->fetchColumn();
        self::assertSame('15:00:00', $end(10));
        self::assertSame('16:00:00', $end(11));
        self::assertSame('10:00:00', $end(12));
        self::assertSame([1 => '15:00:00'], VisitWindowService::windowsFor($db, 59));
        self::assertSame('16:00:00', $db->query('SELECT default_time_end FROM job_plans WHERE id = 59')->fetchColumn());

        $after = $svc->items();
        self::assertSame(['visit_window_done'], array_column($after, 'kind'));
        self::assertSame('undo_window', $after[0]['propose']['buttons'][0]['choice']);

        self::assertTrue($svc->undo($r['change_id'], 7)['ok']);
        self::assertSame('16:00:00', $end(10));
        self::assertSame([], VisitWindowService::windowsFor($db, 59));
        self::assertFalse($svc->undo($r['change_id'], 7)['ok']);
    }

    public function test_a_one_weekday_plan_changes_its_default(): void
    {
        $db = $this->db();
        $db->exec("UPDATE job_plans SET recurrence_day_of_week = '1'");
        (new VisitWindowService($db, '2026-11-08'))->apply(59, 1, '16:00:00', '15:00:00', 7, []);
        self::assertSame('15:00:00', $db->query('SELECT default_time_end FROM job_plans WHERE id = 59')->fetchColumn());
        self::assertSame([], VisitWindowService::windowsFor($db, 59));
    }

    public function test_a_custom_plan_with_no_weekday_keeps_its_default(): void
    {
        $db = $this->db();
        $db->exec("UPDATE job_plans SET recurrence_pattern = 'custom', recurrence_day_of_week = NULL");
        (new VisitWindowService($db, '2026-11-08'))->apply(59, 1, '16:00:00', '15:00:00', 7, []);
        self::assertSame('16:00:00', $db->query('SELECT default_time_end FROM job_plans WHERE id = 59')->fetchColumn());
        self::assertSame([1 => '15:00:00'], VisitWindowService::windowsFor($db, 59));
    }

    public function test_leave_it_stays_quiet_until_three_more_finishes(): void
    {
        $db = $this->db();
        $db->exec("INSERT INTO otto_suggestions (kind, subject_type, subject_id, for_date, status, outcome_json) VALUES ('visit_window', 'plan', 59, '2026-11-02', 'dismissed', '{\"choice\":\"keep\",\"dow\":1}')");
        self::assertSame([], (new VisitWindowService($db, '2026-11-08'))->items());
    }

    public function test_a_mixed_length_plan_books_its_longest_regular_day(): void
    {
        $visits = [];
        foreach ([['2026-11-02', 240], ['2026-10-26', 300], ['2026-11-04', 100], ['2026-10-28', 120], ['2026-10-21', 90], ['2026-10-19', 280]] as $i => [$d, $m]) {
            $visits[] = ['visit_id' => $i + 1, 'date' => $d, 'crew_min' => $m, 'person_min' => $m, 'people' => 1, 'excluded' => null];
        }
        $s = VisitDurationRules::summarise($visits, 1245);
        self::assertSame(280, $s['proposed']);
        self::assertSame('Monday', $s['longest_day']['day']);
        self::assertStringContainsString('(Mondays, the longest day)', VisitDurationRules::line('Tisdall', $s));
    }
}
