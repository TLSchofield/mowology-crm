<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto: real lawn-cut lengths. Per-visit minutes from timers (crew vs person, the truck login),
 * the median with its outliers dropped, when a change is proposed / confident, and apply / undo
 * against an in-memory database (nothing changes without the click; undo only if untouched since).
 */
class VisitDurationTest extends TestCase
{
    private static function e(int $uid, string $a, string $b, bool $truck = false, bool $auto = false, bool $gps = false): array
    {
        $s = strtotime("2026-09-01 $a");
        $t = strtotime("2026-09-01 $b");
        return ['user_id' => $uid, 'truck' => $truck, 'start' => $s, 'end' => $t, 'duration' => (int)round(($t - $s) / 60), 'auto_stopped' => $auto, 'end_gps' => $gps];
    }

    private static function v(int $id, int $crew, int $person = 0, int $people = 1, ?string $ex = null): array
    {
        return ['visit_id' => $id, 'date' => sprintf('2026-09-%02d', 30 - $id), 'crew_min' => $crew, 'person_min' => $person ?: $crew, 'people' => $people, 'excluded' => $ex, 'truck_only' => false];
    }

    // ── Per visit ───────────────────────────────────────────────────────────

    public function test_two_people_overlapping_are_crew_minutes_once_and_person_minutes_twice(): void
    {
        $m = VisitDurationRules::visitMinutes([self::e(7, '09:00', '09:30'), self::e(9, '09:05', '09:40')]);
        $this->assertSame(['person_min' => 65, 'crew_min' => 40, 'people' => 2, 'excluded' => null, 'truck_only' => false], $m);
    }

    public function test_the_truck_login_counts_only_when_nobody_else_timed_it(): void
    {
        $withPerson = VisitDurationRules::visitMinutes([self::e(8, '08:50', '10:30', true), self::e(7, '09:00', '09:32')]);
        $this->assertSame([32, 32, 1], [$withPerson['crew_min'], $withPerson['person_min'], $withPerson['people']]);
        $truckOnly = VisitDurationRules::visitMinutes([self::e(8, '09:00', '09:35', true)]);
        $this->assertSame([35, true], [$truckOnly['crew_min'], $truckOnly['truck_only']]);
        $this->assertSame('untimed', VisitDurationRules::visitMinutes([])['excluded']);
    }

    public function test_an_auto_stopped_timer_is_dropped_unless_the_crew_was_seen_leaving(): void
    {
        $this->assertSame('auto_stopped', VisitDurationRules::visitMinutes([self::e(7, '09:00', '23:59', false, true)])['excluded']);
        $this->assertNull(VisitDurationRules::visitMinutes([self::e(7, '09:00', '09:40', false, true, true)])['excluded'], 'end GPS = departure seen');
        $this->assertNull(VisitDurationRules::visitMinutes([self::e(7, '09:00', '09:40', false, true)], true)['excluded'], 'visit departure GPS');
    }

    // ── The median ──────────────────────────────────────────────────────────

    public function test_larch_style_plan_45_real_32_drops_the_runaway_timer_and_the_tap(): void
    {
        $visits = [self::v(1, 30, 60, 2), self::v(2, 34, 68, 2), self::v(3, 150, 300, 2), self::v(4, 31, 62, 2), self::v(5, 3),
                   self::v(6, 33, 66, 2), self::v(7, 32, 64, 2), self::v(8, 35, 70, 2), self::v(9, 0, 0, 0, 'untimed'), self::v(10, 29, 58, 2),
                   self::v(11, 400, 400, 1, 'auto_stopped')];
        $s = VisitDurationRules::summarise($visits, 45);
        $this->assertSame(7, $s['samples']);
        $this->assertSame(32, $s['median_crew']);
        $this->assertSame(64, $s['median_person']);
        $this->assertSame(2.0, $s['crew_size']);
        $this->assertEqualsCanonicalizing(['auto_stopped', 'under_5', 'over_3x'], array_column($s['dropped'], 'why'));
        $this->assertTrue($s['stable']);
        $this->assertTrue($s['confident']);
        $this->assertSame(30, $s['proposed']);
        $this->assertSame(-15, $s['change']);
        $this->assertSame('2448 Larch St: plan 45 min, real median 32 min over 7 visits → 30 min.', VisitDurationRules::line('2448 Larch St', $s));
        $this->assertStringContainsString('1 dropped (timer left running)', VisitDurationRules::detail($s));
    }

    public function test_only_the_last_eight_count(): void
    {
        $visits = [];
        for ($i = 1; $i <= 8; $i++) $visits[] = self::v($i, 60);
        for ($i = 9; $i <= 15; $i++) $visits[] = self::v($i, 20); // older, shorter — ignored
        $s = VisitDurationRules::summarise($visits, 45);
        $this->assertSame([8, 60, 60], [$s['samples'], $s['median_crew'], $s['proposed']]);
    }

    public function test_no_proposal_when_close_enough_or_too_few_and_uneven_is_not_confident(): void
    {
        $close = VisitDurationRules::summarise([self::v(1, 30), self::v(2, 32), self::v(3, 34), self::v(4, 33), self::v(5, 31)], 35);
        $this->assertNull($close['proposed']);
        $this->assertSame('close_enough', $close['reason']);

        $few = VisitDurationRules::summarise([self::v(1, 20), self::v(2, 22)], 45);
        $this->assertNull($few['proposed']);
        $this->assertSame('too_few', $few['reason']);

        $uneven = VisitDurationRules::summarise([self::v(1, 20), self::v(2, 55), self::v(3, 30), self::v(4, 60), self::v(5, 25), self::v(6, 50)], 90);
        $this->assertNotNull($uneven['proposed']);
        $this->assertFalse($uneven['stable']);
        $this->assertFalse($uneven['confident']);
        $this->assertSame('spread', $uneven['reason']);

        $three = VisitDurationRules::summarise([self::v(1, 20), self::v(2, 22), self::v(3, 21)], 45);
        $this->assertSame([20, 'few', false], [$three['proposed'], $three['reason'], $three['confident']]);
    }

    public function test_helpers(): void
    {
        $this->assertSame(40 * 60, VisitDurationRules::unionSeconds([[0, 1800], [300, 2400]]));
        $this->assertSame(50 * 60, VisitDurationRules::unionSeconds([[0, 1200], [1800, 3600]]));
        $this->assertSame(32.5, VisitDurationRules::median([30, 35, 32, 33]));
        $this->assertTrue(VisitDurationRules::isLawn('Lawn Maintenance'));
        $this->assertTrue(VisitDurationRules::isLawn('Other', 'Weekly mow'));
        $this->assertFalse(VisitDurationRules::isLawn('Hedge Trimming', 'Yew hedge'));
    }

    // ── Apply / undo ────────────────────────────────────────────────────────

    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE job_plans (id INTEGER PRIMARY KEY, property_id INT, estimated_duration_minutes INT)');
        $db->exec('CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT)');
        $db->exec('CREATE TABLE otto_duration_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INT, old_minutes INT, new_minutes INT, median_crew INT, median_person INT,
                   samples INT, confident INT, batch_key TEXT, applied_by INT, applied_at TEXT, undone_by INT, undone_at TEXT)');
        $db->exec("CREATE TABLE otto_suggestions (id INTEGER PRIMARY KEY, kind TEXT, subject_type TEXT, subject_id INT, for_date TEXT, status TEXT,
                   outcome_json TEXT, decided_by INT, decided_at TEXT)");
        $db->exec("INSERT INTO properties VALUES (441, '2448 Larch St'), (300, '1900 Oak St'), (310, '12 Elm St')");
        $db->exec("INSERT INTO job_plans VALUES (82, 441, 45), (90, 300, 60), (91, 310, 30)");
        $db->exec("INSERT INTO otto_suggestions VALUES (5, 'duration', 'plan', 82, '2026-09-29', 'open', NULL, NULL, NULL)");
        return $db;
    }

    private function plan(PDO $db, int $id): ?int
    {
        $v = $db->query("SELECT estimated_duration_minutes FROM job_plans WHERE id = {$id}")->fetchColumn();
        return $v === null ? null : (int)$v;
    }

    public function test_apply_logs_the_change_closes_the_suggestion_and_undo_puts_it_back(): void
    {
        $db = $this->db();
        $svc = new VisitDurationService($db, '2026-10-07');
        $this->assertTrue($svc->ready());
        $r = $svc->apply(82, 30, 1, ['median_crew' => 32, 'median_person' => 64, 'samples' => 7, 'confident' => true, 'proposed' => 30]);
        $this->assertTrue($r['ok']);
        $this->assertSame('Plan set to 30 min (was 45).', $r['message']);
        $this->assertSame(30, $this->plan($db, 82));
        $row = $db->query('SELECT * FROM otto_duration_changes')->fetch();
        $this->assertSame([45, 30, 32, 7], [(int)$row['old_minutes'], (int)$row['new_minutes'], (int)$row['median_crew'], (int)$row['samples']]);
        $this->assertSame('accepted', $db->query('SELECT status FROM otto_suggestions WHERE id = 5')->fetchColumn());

        $this->assertFalse($svc->apply(82, 30, 1)['ok'], 'already 30');
        $this->assertFalse($svc->apply(82, 2, 1)['ok'], 'too short');

        $u = $svc->undo($r['change_id'], 1);
        $this->assertSame(['ok' => true, 'message' => 'Back to 45 min.'], $u);
        $this->assertSame(45, $this->plan($db, 82));
        $this->assertFalse($svc->undo($r['change_id'], 1)['ok'], 'already undone');
    }

    public function test_undo_refuses_when_the_plan_was_changed_since(): void
    {
        $db = $this->db();
        $svc = new VisitDurationService($db, '2026-10-07');
        $r = $svc->apply(90, 40, 1);
        $db->exec('UPDATE job_plans SET estimated_duration_minutes = 55 WHERE id = 90');
        $u = $svc->undo($r['change_id'], 1);
        $this->assertFalse($u['ok']);
        $this->assertStringContainsString('changed again since (now 55 min)', $u['message']);
        $this->assertSame(55, $this->plan($db, 90));
    }

    public function test_apply_all_takes_only_confident_proposals_as_one_batch_and_undoes_as_one(): void
    {
        $db = $this->db();
        $svc = new class($db, '2026-10-07') extends VisitDurationService {
            protected function loadPlans(bool $all): array
            {
                return [
                    ['id' => 82, 'plan_number' => 'PLN-2026-0068', 'title' => 'Weekly lawn', 'service_type' => 'Lawn Maintenance', 'estimated_duration_minutes' => 45, 'default_crew_size' => 2, 'property_id' => 441, 'address' => '2448 Larch St, Vancouver'],
                    ['id' => 90, 'plan_number' => 'PLN-2026-0012', 'title' => 'Weekly lawn', 'service_type' => 'Lawn Maintenance', 'estimated_duration_minutes' => 60, 'default_crew_size' => 1, 'property_id' => 300, 'address' => '1900 Oak St'],
                    ['id' => 91, 'plan_number' => 'PLN-2026-0013', 'title' => 'Weekly lawn', 'service_type' => 'Lawn Maintenance', 'estimated_duration_minutes' => 30, 'default_crew_size' => 1, 'property_id' => 310, 'address' => '12 Elm St'],
                ];
            }
            protected function loadVisits(array $planIds): array
            {
                $mk = function (array $mins) {
                    $out = [];
                    foreach ($mins as $i => $m) {
                        $s = strtotime('2026-09-01 09:00') - $i * 7 * 86400;
                        $out[] = ['visit_id' => 1000 + $i, 'date' => date('Y-m-d', $s), 'departure' => false,
                            'entries' => [['user_id' => 7, 'truck' => false, 'start' => $s, 'end' => $s + $m * 60, 'duration' => $m, 'auto_stopped' => false, 'end_gps' => false]]];
                    }
                    return $out;
                };
                return [
                    82 => $mk([32, 30, 34, 31, 33, 32]),       // confident: 45 → 30
                    90 => $mk([25, 70, 35, 65, 30, 55]),       // uneven: proposed, not confident
                    91 => $mk([29, 31, 30, 32, 28]),           // plan is right
                ];
            }
        };
        $items = $svc->items();
        $this->assertSame(['otto:duration:82', 'otto:duration:90'], array_column($items, 'key'));
        $this->assertStringEndsWith('(times vary a lot)', $items[1]['text']);

        $r = $svc->applyAllConfident(1);
        $this->assertSame([82], array_column($r['applied'], 'plan_id'));
        $this->assertSame(30, $this->plan($db, 82));
        $this->assertSame(60, $this->plan($db, 90), 'not confident — left for the owner');
        $u = $svc->undoBatch($r['batch'], 1);
        $this->assertTrue($u['ok']);
        $this->assertSame(45, $this->plan($db, 82));
    }
}
