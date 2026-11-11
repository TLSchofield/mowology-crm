<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's end-of-day missed-visit review (MissedVisitReviewService) against in-memory SQLite:
 * building the day's review, who is pushed, and the apply paths (move / skip / cancel via the
 * injected shared paths) + the HeadLessons row when the person's answer differs from Otto's.
 * Review day: Tue 2026-10-13, 18:05.
 */
final class MissedVisitReviewServiceTest extends TestCase
{
    private PDO $db;
    private array $pushed = [];
    private array $applied = [];

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE otto_missed_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT NOT NULL DEFAULT 1, review_date TEXT NOT NULL,
                   opened_by TEXT, item_count INT DEFAULT 0, notified_at TEXT, notified_count INT DEFAULT 0, created_at TEXT, UNIQUE(site_id, review_date))");
        $db->exec("CREATE TABLE otto_missed_review_items (id INTEGER PRIMARY KEY AUTOINCREMENT, review_id INT, visit_id INT, plan_id INT, missed_date TEXT,
                   why_missed TEXT, pick TEXT, pick_date TEXT, pick_reason TEXT DEFAULT '', skip_allowed INT DEFAULT 1, skip_block TEXT,
                   decision TEXT, decision_date TEXT, decision_reason TEXT, decision_note TEXT, decided_by INT, decided_at TEXT, apply_error TEXT,
                   created_at TEXT, UNIQUE(review_id, visit_id))");
        $db->exec("CREATE TABLE job_plans (id INTEGER PRIMARY KEY, plan_number TEXT, title TEXT, service_type TEXT, is_recurring INT, is_prepaid_bundle INT DEFAULT 0,
                   recurrence_pattern TEXT, recurrence_interval INT, recurrence_interval_unit TEXT, recurrence_day_of_week TEXT, default_crew_id INT,
                   default_time_start TEXT, estimated_duration_minutes INT, property_id INT, contract_id INT, status TEXT)");
        $db->exec("CREATE TABLE job_visits (id INTEGER PRIMARY KEY, plan_id INT, scheduled_date TEXT, status TEXT, rollover_count INT DEFAULT 0,
                   original_scheduled_date TEXT, completion_notes TEXT, weather_status TEXT, weather_reason TEXT, assigned_crew_id INT,
                   scheduled_time_start TEXT, started_at TEXT, completed_at TEXT)");
        $db->exec("CREATE TABLE contracts (id INTEGER PRIMARY KEY, contract_number TEXT, status TEXT)");
        $db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT, city TEXT, latitude REAL, longitude REAL)");
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, is_active INT)");
        $db->exec("CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)");
        $db->exec("CREATE TABLE user_roles (user_id INT, role_id INT)");
        $db->exec("CREATE TABLE permissions (id INTEGER PRIMARY KEY, `key` TEXT)");
        $db->exec("CREATE TABLE role_permissions (role_id INT, permission_id INT)");
        $db->exec("CREATE TABLE time_clock_entries (id INTEGER PRIMARY KEY, user_id INT, clock_in TEXT, clock_out TEXT, status TEXT)");
        $db->exec("CREATE TABLE head_lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INT, head TEXT, subject_kind TEXT, subject_key TEXT, field TEXT,
                   said TEXT, correct TEXT, source TEXT, ref_type TEXT, ref_id INT, corrected_by INT, reason TEXT, created_at TEXT)");

        // People: Tim admin (1), the truck (5, RBAC admin), Nigel manager (6, RBAC manager), crew (8, RBAC staff), inactive manager (9).
        $db->exec("INSERT INTO users VALUES (1,'TIM SCHOFIELD','admin',1),(5,'MIGHT-E TRUCK','manager',1),(6,'NIGEL CASEY','manager',1),(8,'Himal Bam','user',1),(9,'Old Boss','manager',0)");
        $db->exec("INSERT INTO roles VALUES (1,'admin'),(2,'manager'),(3,'staff')");
        $db->exec("INSERT INTO user_roles VALUES (1,1),(5,1),(6,2),(8,3),(9,2)");
        $db->exec("INSERT INTO permissions VALUES (1,'schedule.edit'),(2,'schedule.view')");
        $db->exec("INSERT INTO role_permissions VALUES (1,1),(1,2),(2,1),(2,2),(3,2)");

        $db->exec("INSERT INTO properties VALUES (1,'12 Oak St','Vancouver',49.26,-123.1),(2,'40 Elm St','Vancouver',49.27,-123.1),(3,'7 Ash Ave','Burnaby',49.25,-123.0)");
        $db->exec("INSERT INTO contracts VALUES (14,'CTR-2026-0014','active')");
        // 10: weekly lawn cut (no contract); 11: weekly under contract; 12: one-off; 13: paused plan.
        $db->exec("INSERT INTO job_plans VALUES
            (10,'PLN-2026-0010','WEEKLY LAWN CUT','lawn_care',1,0,'weekly',1,'weeks',NULL,6,'08:00',60,1,NULL,'active'),
            (11,'PLN-2026-0011','FULL SERVICE','maintenance',1,0,'weekly',1,'weeks',NULL,6,'08:00',60,2,14,'active'),
            (12,'PLN-2026-0012','Hedge job','Hedge Trimming',0,0,NULL,1,NULL,NULL,6,'08:00',120,3,NULL,'active'),
            (13,'PLN-2026-0013','Paused','lawn_care',1,0,'weekly',1,'weeks',NULL,6,'08:00',60,3,NULL,'paused')");
        $db->exec("INSERT INTO job_visits (id, plan_id, scheduled_date, status, rollover_count, original_scheduled_date, completion_notes, weather_status, assigned_crew_id) VALUES
            (100,10,'2026-10-13','scheduled',0,NULL,NULL,NULL,6),
            (101,10,'2026-10-15','scheduled',0,NULL,NULL,NULL,6),
            (110,11,'2026-10-12','in_progress',3,'2026-10-09',NULL,'NOT_OK',6),
            (111,11,'2026-10-14','scheduled',0,NULL,NULL,NULL,6),
            (120,12,'2026-10-13','scheduled',0,NULL,NULL,NULL,6),
            (130,13,'2026-10-13','scheduled',0,NULL,NULL,NULL,6),
            (140,10,'2026-10-06','completed',0,NULL,NULL,NULL,6),
            (150,10,'2026-07-01','scheduled',0,NULL,NULL,NULL,6)");
        $this->db = $db;
    }

    private function svc(string $now = '2026-10-13 18:05:00'): MissedVisitReviewService
    {
        $rec = function (string $what) {
            return function (...$a) use ($what) {
                $this->applied[] = [$what, $a[1], $a[2] ?? null];
                $db = $a[0]; $id = (int)$a[1];
                if ($what === 'move') $db->prepare("UPDATE job_visits SET scheduled_date = ?, original_scheduled_date = COALESCE(original_scheduled_date, scheduled_date) WHERE id = ?")->execute([$a[2], $id]);
                if ($what === 'skip') $db->prepare("UPDATE job_visits SET status = 'skipped', completion_notes = ? WHERE id = ?")->execute([$a[2], $id]);
                if ($what === 'cancel') $db->prepare("UPDATE job_visits SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                if ($what === 'reopen') $db->prepare("UPDATE job_visits SET status = 'scheduled' WHERE id = ?")->execute([$id]);
                return ['ok' => true, 'message' => ''];
            };
        };
        return new class($this->db, function ($to, $t, $b, $d) { $this->pushed[] = [$to, $t, $b, $d]; }, fn() => $now,
                         ['move' => $rec('move'), 'skip' => $rec('skip'), 'cancel' => $rec('cancel'), 'reopen' => $rec('reopen')]) extends MissedVisitReviewService {
            protected function daysFor(array $v, string $date, array $facts): array
            {
                $out = [];
                for ($i = 1; $i <= 7; $i++) {
                    $d = date('Y-m-d', strtotime($date . ' +' . $i . ' day'));
                    $out[$d] = ['rest' => date('w', strtotime($d)) === '0', 'crew_works' => null, 'bylaw_ok' => true, 'wet' => null, 'route' => null, 'route_n' => 0,
                                'plan_visit' => (bool)$this->planHas((int)$v['plan_id'], (int)$v['visit_id'], $d)];
                }
                return $out;
            }
            private function planHas(int $plan, int $visit, string $d): bool
            {
                $r = (new ReflectionProperty(MissedVisitReviewService::class, 'db'));
                $s = $r->getValue($this)->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND id <> ? AND scheduled_date = ? AND status NOT IN ('skipped','cancelled')");
                $s->execute([$plan, $visit, $d]);
                return (int)$s->fetchColumn() > 0;
            }
        };
    }

    private function item(int $visitId): array
    {
        $s = $this->db->prepare('SELECT * FROM otto_missed_review_items WHERE visit_id = ? ORDER BY id DESC LIMIT 1');
        $s->execute([$visitId]);
        return $s->fetch();
    }

    public function testBuildCollectsTodaysAndOlderOpenVisitsWithOttosPick(): void
    {
        $r = $this->svc()->build('2026-10-13', 'six_pm');
        $this->assertTrue($r['new']);
        $visits = array_map('intval', $this->db->query('SELECT visit_id FROM otto_missed_review_items ORDER BY visit_id')->fetchAll(PDO::FETCH_COLUMN));
        // today's 100 + 120, yesterday's in-progress 110; not the paused plan (130), not done (140),
        // not >60 days old (150), not future (101, 111).
        $this->assertSame([100, 110, 120], $visits);

        $lawn = $this->item(100);      // next visit Thu 15 = 2 days → skip, no contract
        $this->assertSame('skip', $lawn['pick']);
        $contract = $this->item(110);  // contract, next visit Wed 14 → never skip, no free day before it → you decide
        $this->assertNull($contract['pick']);
        $this->assertSame(0, (int)$contract['skip_allowed']);
        $this->assertStringContainsString('Started, not finished', $contract['why_missed']);
        $this->assertStringContainsString('Rolled on 3 days since Fri Oct 9', $contract['why_missed']);
        $oneOff = $this->item(120);    // one-off: never skip → tomorrow
        $this->assertSame('tomorrow', $oneOff['pick']);
        $this->assertSame('2026-10-14', $oneOff['pick_date']);
    }

    public function testPushGoesToEveryoneWithScheduleEditOnceOnly(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $this->assertCount(1, $this->pushed);
        [$to, $title, , $data] = $this->pushed[0];
        $this->assertSame([1, 5, 6], $to, 'admin, truck, manager — not crew 8, not inactive 9');
        $this->assertStringContainsString('3 visits not done', $title);
        $this->assertSame('otto_review', $data['open']);
        $this->assertSame('otto', $data['head']);

        $again = $svc->build('2026-10-13', 'six_pm');
        $this->assertFalse($again['new']);
        $this->assertCount(1, $this->pushed, 'no second push for the same day');
    }

    public function testTickWaitsForTheLastClockOutOrSix(): void
    {
        $this->db->exec("INSERT INTO time_clock_entries (user_id, clock_in, clock_out, status) VALUES (6,'2026-10-13 07:30:00',NULL,'active'),(8,'2026-10-13 07:30:00','2026-10-13 15:00:00','completed')");
        $this->assertNull($this->svc('2026-10-13 16:00:00')->tick()['opened'], 'Nigel still on the clock');
        $this->db->exec("UPDATE time_clock_entries SET clock_out = '2026-10-13 16:10:00', status = 'completed' WHERE user_id = 6");
        $this->assertNull($this->svc('2026-10-13 16:20:00')->tick()['opened'], 'only 10 minutes since');
        $t = $this->svc('2026-10-13 16:35:00')->tick();
        $this->assertNotNull($t['opened']);
        $this->assertSame('last_clock_out', $t['trigger']);
        $this->assertSame('already open today', $this->svc('2026-10-13 18:02:00')->tick()['why']);
    }

    public function testAcceptingOttosPickLearnsNothingAndUsesTheSharedPath(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $r = $svc->decide((int)$this->item(120)['id'], 'accept', null, null, null, 6);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['move', 120, '2026-10-14'], $this->applied[0]);
        $this->assertSame('move', $this->item(120)['decision']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM head_lessons')->fetchColumn());
        $this->assertFalse($svc->decide((int)$this->item(120)['id'], 'skip', null, 'weather', null, 6)['ok'], 'decided once');
    }

    public function testADifferentAnswerIsAnOttoLesson(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        // Otto said skip for 100; Tim moves it to Fri 16 instead.
        $r = $svc->decide((int)$this->item(100)['id'], 'date', '2026-10-16', null, null, 1);
        $this->assertTrue($r['ok'], $r['message']);
        $l = $this->db->query('SELECT * FROM head_lessons')->fetch();
        $this->assertSame('otto', $l['head']);
        $this->assertSame('plan:10', $l['subject_key']);
        $this->assertSame('skip', $l['said']);
        $this->assertSame('move:2026-10-16', $l['correct']);
        $this->assertSame(100, (int)$l['ref_id']);
        $this->assertSame(1, (int)$l['corrected_by']);
    }

    public function testSkipAndCancelNeedAReasonAndInProgressIsReopenedFirst(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $id = (int)$this->item(110)['id'];
        $this->assertFalse($svc->decide($id, 'skip', null, null, null, 1)['ok'], 'no reason');
        $r = $svc->decide($id, 'skip', null, 'client', 'away this week', 1);
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('reopen', $this->applied[0][0]);
        $this->assertSame(['skip', 110, "Otto's review: Client asked — away this week"], $this->applied[1]);
        $this->assertSame('client', $this->item(110)['decision_reason']);
        // Otto had no pick ("you decide") — the answer is still recorded as a lesson.
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM head_lessons WHERE correct = 'skip'")->fetchColumn());

        $c = $svc->decide((int)$this->item(120)['id'], 'cancel', null, 'access', null, 1);
        $this->assertTrue($c['ok']);
        $this->assertSame('cancel', $this->applied[2][0]);
    }

    public function testMovesAreRefusedOntoAPassedDayOrADayThePlanAlreadyHas(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $id = (int)$this->item(100)['id'];
        $this->assertStringContainsString('has passed', $svc->decide($id, 'date', '2026-10-12', null, null, 1)['message']);
        $this->assertStringContainsString('already has a visit', $svc->decide($id, 'date', '2026-10-15', null, null, 1)['message']);
        $this->assertSame([], $this->applied);
    }

    public function testAVisitChangedOnTheScheduleSinceCannotBeDecided(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $this->db->exec("UPDATE job_visits SET status = 'completed' WHERE id = 120");
        $r = $svc->decide((int)$this->item(120)['id'], 'tomorrow', null, null, null, 1);
        $this->assertFalse($r['ok']);
        $open = $svc->open();
        $row = array_values(array_filter($open['items'], fn($i) => $i['visit_id'] === 120))[0];
        $this->assertFalse($row['can_decide']);
        $this->assertSame('Changed on the schedule since', $row['decision_text']);
    }

    public function testAcceptAllAppliesOnlyItemsWithAPick(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        $r = $svc->acceptAll(null, 1);
        $this->assertSame(2, $r['done'], $r['message']);   // 100 skip, 120 tomorrow
        $this->assertSame(1, $r['left']);                   // 110: you decide
        $this->assertSame('skipped', $this->db->query('SELECT status FROM job_visits WHERE id = 100')->fetchColumn());
        $this->assertSame('2026-10-14', $this->db->query('SELECT scheduled_date FROM job_visits WHERE id = 120')->fetchColumn());
        $this->assertSame('in_progress', $this->db->query('SELECT status FROM job_visits WHERE id = 110')->fetchColumn(), 'left alone');
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM head_lessons')->fetchColumn());
    }

    public function testHandleListsForTheScreens(): void
    {
        $svc = $this->svc();
        $svc->build('2026-10-13', 'six_pm');
        [$code, $body] = $svc->handle('GET', ['mode' => 'list'], 1);
        $this->assertSame(200, $code);
        $this->assertSame(3, $body['waiting']);
        $this->assertSame(2, $body['with_pick']);
        $this->assertSame('Tue Oct 13', $body['review']['label']);
        $first = $body['items'][0];
        foreach (['property', 'plan', 'why', 'pick_label', 'pick_reason', 'skip_allowed', 'can_decide'] as $k) $this->assertArrayHasKey($k, $first);
        $this->assertCount(4, $body['reasons']);
    }
}
