<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SnowRunGateTest extends TestCase
{
    private PDO $db;
    private string $now = '2026-11-03 06:00:00';

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE snow_runs (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, started_at TEXT, started_by INT, ended_at TEXT NULL, ended_by INT NULL, note TEXT NULL)');
        $this->db->exec('CREATE TABLE snow_route_rates (plan_id INT, role TEXT)');
        $this->db->exec('CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, status TEXT, scheduled_date TEXT, stop_id INT NULL)');
        $this->db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, status TEXT)');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, is_active INT)');
        $this->db->exec('CREATE TABLE ops_settings (setting_key TEXT, setting_value TEXT)');
        $this->db->exec("INSERT INTO snow_route_rates VALUES (10, 'salt')");
        $this->db->exec("INSERT INTO users VALUES (6, 'Crew Six', 'crew', 1), (9, 'Run Starter', 'manager', 1)");
    }

    private function gate(): SnowRunGate
    {
        return new SnowRunGate($this->db, $this->now);
    }

    public function test_filter_hides_only_scheduled_route_visits_outside_range(): void
    {
        $input = [
            '2026-11-02' => [
                100 => ['visits' => [
                    ['visit_id' => 1, 'plan_id' => 10, 'visit_status' => 'scheduled'],
                    ['visit_id' => 2, 'plan_id' => 20, 'visit_status' => 'scheduled'],
                ]],
                101 => ['visits' => [['visit_id' => 3, 'plan_id' => 10, 'visit_status' => 'scheduled']]],
                102 => ['visits' => [
                    ['visit_id' => 4, 'plan_id' => 10, 'visit_status' => 'in_progress'],
                    ['visit_id' => 5, 'plan_id' => 10, 'visit_status' => 'completed'],
                ]],
            ],
            '2026-11-01' => [103 => ['visits' => [['visit_id' => 6, 'plan_id' => 10, 'visit_status' => 'scheduled']]]],
        ];

        $result = SnowRunGate::filter($input, [10], null);
        $this->assertSame(3, $result['hidden_visits']);
        $this->assertSame(2, $result['hidden_stops']);
        $this->assertArrayNotHasKey('2026-11-01', $result['stops']);
        $this->assertSame([2], array_column($result['stops']['2026-11-02'][100]['visits'], 'visit_id'));
        $this->assertSame([4, 5], array_column($result['stops']['2026-11-02'][102]['visits'], 'visit_id'));

        $open = SnowRunGate::filter($input, [10], ['2026-11-01', '2026-11-02']);
        $this->assertSame($input, $open['stops']);
        $this->assertSame(0, $open['hidden_visits']);
        $this->assertSame(0, $open['hidden_stops']);
    }

    public function test_active_run_obeys_age_and_end_state(): void
    {
        $gate = $this->gate();
        $this->assertNull($gate->activeRun());

        $this->db->exec("INSERT INTO snow_runs (kind, started_at, started_by) VALUES ('salt', '2026-11-02 11:00:00', 9)");
        $run = $gate->activeRun();
        $this->assertSame('salt', $run['kind']);
        $this->assertSame('Run Starter', $run['started_by_name']);

        $this->db->exec("UPDATE snow_runs SET started_at = '2026-11-02 09:00:00'");
        $this->assertNull($gate->activeRun());
        $this->db->exec("UPDATE snow_runs SET started_at = '2026-11-02 11:00:00', ended_at = '2026-11-02 12:00:00'");
        $this->assertNull($gate->activeRun());
    }

    public function test_unlocked_range_runs_from_start_date_through_today(): void
    {
        $this->db->exec("INSERT INTO snow_runs (kind, started_at, started_by) VALUES ('snow', '2026-11-02 23:30:00', 9)");
        $this->assertSame(['2026-11-02', '2026-11-03'], $this->gate()->unlockedRange());
    }

    public function test_start_replaces_open_run_rejects_bad_kind_and_end_is_idempotent(): void
    {
        $this->db->exec("INSERT INTO snow_runs (kind, started_at, started_by) VALUES ('salt', '2026-11-03 01:00:00', 6)");
        $gate = $this->gate();
        $this->assertFalse($gate->start('hail', 9)['success']);
        $started = $gate->start('snow', 9);
        $this->assertTrue($started['success']);
        $this->assertSame('snow', $started['run']['kind']);
        $old = $this->db->query('SELECT ended_by, note FROM snow_runs WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('9', (string)$old['ended_by']);
        $this->assertSame('replaced', $old['note']);
        $this->assertSame(['success' => true, 'ended' => true], $gate->end(9));
        $this->assertSame(['success' => true, 'ended' => false], $gate->end(9));
    }

    public function test_refuse_start_only_blocks_scheduled_route_visit_without_run(): void
    {
        $this->db->exec("INSERT INTO job_visits VALUES (1, 10, 'scheduled', '2026-11-03', 1), (2, 20, 'scheduled', '2026-11-03', 2), (3, 10, 'completed', '2026-11-03', 3)");
        $gate = $this->gate();
        $this->assertSame(SnowRunGate::CODE, $gate->refuseStart(1)['code']);
        $this->assertNull($gate->refuseStart(2));
        $this->assertNull($gate->refuseStart(3));
        $this->assertNull($gate->refuseStart(99));
        $gate->start('salt', 9);
        $this->assertNull($gate->refuseStart(1));
    }

    public function test_may_control_allows_office_roles_and_configured_crew(): void
    {
        $this->db->exec("INSERT INTO ops_settings VALUES ('snow_route_crew_user_ids', '6,7,1')");
        $gate = $this->gate();
        $this->assertTrue($gate->mayControl('admin', 5));
        $this->assertTrue($gate->mayControl('manager', 5));
        $this->assertTrue($gate->mayControl('crew', 6));
        $this->assertFalse($gate->mayControl('crew', 5));
    }

    public function test_visible_sql_filters_scheduled_route_visits_until_run_opens(): void
    {
        $this->db->exec("INSERT INTO job_visits VALUES
            (1, 10, 'scheduled', '2026-11-03', 1),
            (2, 10, 'completed', '2026-11-03', 2),
            (3, 20, 'scheduled', '2026-11-03', 3),
            (4, 10, 'scheduled', '2026-11-01', 4)");
        $gate = $this->gate();
        $this->assertSame([2, 3], $this->visibleIds($gate));
        $gate->start('salt', 9);
        $this->assertSame([1, 2, 3], $this->visibleIds($gate));
    }

    public function test_not_ready_degrades_to_no_op(): void
    {
        $bare = new PDO('sqlite::memory:');
        $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $gate = new SnowRunGate($bare, $this->now);
        $input = ['2026-11-03' => [1 => ['visits' => []]]];
        $this->assertFalse($gate->ready());
        $this->assertNull($gate->activeRun());
        $this->assertSame($input, $gate->hideScheduledRouteVisits($input));
        $this->assertSame(['sql' => '1=1', 'params' => []], $gate->visitVisibleSql());
        $this->assertNull($gate->refuseStart(1));
    }

    private function visibleIds(SnowRunGate $gate): array
    {
        $visible = $gate->visitVisibleSql('jv');
        $stmt = $this->db->prepare('SELECT jv.id FROM job_visits jv WHERE ' . $visible['sql'] . ' ORDER BY jv.id');
        $stmt->execute($visible['params']);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
