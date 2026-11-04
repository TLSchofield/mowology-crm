<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Pulse runs, records, prunes, alerts once per incident, and never lets a broken probe break the run. */
class PulseServiceTest extends TestCase
{
    private PDO $db;
    private int $now = 1793193600;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE ops_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, description TEXT)");
        $this->db->exec("CREATE TABLE pulse_checks (id INTEGER PRIMARY KEY AUTOINCREMENT, check_key TEXT, status TEXT, detail TEXT, ms INT, run_at TEXT)");
    }

    private function svc(array $probes): PulseService
    {
        return new PulseService($this->db, fn() => $this->now, $probes);
    }

    public function testRunRecordsEveryProbeAndSurvivesAThrowingOne(): void
    {
        $svc = $this->svc([
            'database'   => fn() => ['status' => 'ok', 'detail' => 'Database answered in 3 ms.'],
            'head_cards' => function () { throw new RuntimeException('boom'); },
            'pages'      => fn() => ['status' => 'bogus', 'detail' => ''],
        ]);
        $r = $svc->run();
        $this->assertSame('ok', $r['database']['status']);
        $this->assertSame('unknown', $r['head_cards']['status']);
        $this->assertStringContainsString('could not run', $r['head_cards']['detail']);
        $this->assertSame('unknown', $r['pages']['status'], 'a nonsense status is not trusted');
        $this->assertSame(3, (int)$this->db->query("SELECT COUNT(*) FROM pulse_checks")->fetchColumn());
    }

    public function testOldRowsArePruned(): void
    {
        $this->db->prepare("INSERT INTO pulse_checks (check_key, status, detail, ms, run_at) VALUES ('database', 'ok', '', 1, ?)")
                 ->execute([date('Y-m-d H:i:s', $this->now - 20 * 86400)]);
        $this->svc(['database' => fn() => ['status' => 'ok', 'detail' => '']])->run();
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM pulse_checks")->fetchColumn());
    }

    private function runFailing(int $times, string $key = 'head_cards'): PulseService
    {
        $svc = $this->svc([$key => fn() => ['status' => 'fail', 'detail' => 'Otto card is not building.']]);
        for ($i = 0; $i < $times; $i++) {
            $svc->run();
            $this->now += 300;
        }
        return $svc;
    }

    public function testOneBadRunIsNotAnAlertTwoAre(): void
    {
        $this->assertSame([], $this->runFailing(1)->urgent());
        $alerts = $this->runFailing(1)->urgent();   // second run in the same history = two bad runs
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString("isn't working", $alerts[0]['text']);
        $this->assertStringContainsString('urgent:pulse:head_cards:', $alerts[0]['key']);
        $this->assertSame('pulse', $alerts[0]['kind']);
    }

    public function testTheSameIncidentKeepsTheSameKeyAndRecoveryClearsIt(): void
    {
        $svc = $this->runFailing(2);
        $k1 = $svc->urgent()[0]['key'];
        $svc2 = $this->runFailing(2);   // the incident continues for two more runs
        $this->assertSame($k1, $svc2->urgent()[0]['key'], 'one incident, one key — it is emailed once');

        $ok = $this->svc(['head_cards' => fn() => ['status' => 'ok', 'detail' => 'fine']]);
        $ok->run();
        $this->assertSame([], $ok->urgent(), 'recovered: no alert');
    }

    public function testAdaRowsShowStateWhenItLastWorkedAndAHandoff(): void
    {
        $svc = $this->svc(['database' => fn() => ['status' => 'ok', 'detail' => 'Database answered in 3 ms.']]);
        $svc->run();
        $this->now += 300;
        $bad = $this->svc(['head_cards' => fn() => ['status' => 'fail', 'detail' => 'Otto card is not building.']]);
        $bad->run();
        $rows = array_column($bad->adaChecks(), null, 'system');
        $this->assertSame('Live checks', $rows['pulse:head_cards']['group']);
        $this->assertSame('red', $rows['pulse:head_cards']['state']);
        $this->assertNotEmpty($rows['pulse:head_cards']['handoff']);
        $this->assertSame('developer', $rows['pulse:head_cards']['handoff_to']);
        $this->assertSame('green', $rows['pulse:database']['state']);
        $this->assertNull($rows['pulse:database']['handoff']);
        $this->assertSame('unknown', $rows['pulse:pages']['state'], 'never run yet');
        $this->assertCount(count(PulseRules::LABELS), $bad->adaChecks());
    }

    public function testDefaultAiAndDatabaseProbesWorkOnARealSchema(): void
    {
        $this->db->exec("CREATE TABLE llm_calls (id INTEGER PRIMARY KEY, outcome TEXT, created_at TEXT)");
        $ins = $this->db->prepare("INSERT INTO llm_calls (outcome, created_at) VALUES (?, ?)");
        foreach (['error', 'error', 'error', 'ok'] as $o) $ins->execute([$o, date('Y-m-d H:i:s', $this->now - 600)]);
        $svc = new PulseService($this->db, fn() => $this->now);
        $m = new ReflectionMethod($svc, 'probeAi');
        if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
        $this->assertSame('fail', $m->invoke($svc)['status']);
        $d = new ReflectionMethod($svc, 'probeDatabase');
        if (PHP_VERSION_ID < 80100) { $d->setAccessible(true); }
        $this->assertSame('ok', $d->invoke($svc)['status']);
        $q = new ReflectionMethod($svc, 'probeQueues');
        if (PHP_VERSION_ID < 80100) { $q->setAccessible(true); }
        $this->assertSame('unknown', $q->invoke($svc)['status'], 'none of the queue tables exist here');
    }

    public function testALongOutageKeepsOneAlertKey(): void
    {
        $svc = $this->svc(['head_cards' => fn() => ['status' => 'fail', 'detail' => 'down']]);
        $svc->run();
        $this->now += 300;
        $svc->run();
        $first = $svc->urgent()[0]['key'];
        for ($i = 0; $i < 40; $i++) {   // 40 more failing runs, far beyond any history window
            $this->now += 300;
            $svc->run();
        }
        $this->assertSame($first, $svc->urgent()[0]['key'], 'the key is the incident start, which does not move');
    }

    public function testAStuckQueueWarnsThenFails(): void
    {
        $this->db->exec("CREATE TABLE push_queue (id INTEGER PRIMARY KEY, status TEXT, created_at TEXT)");
        $svc = new PulseService($this->db, fn() => $this->now);
        $m = new ReflectionMethod($svc, 'probeQueues');
        if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
        $this->assertSame('ok', $m->invoke($svc)['status']);
        $this->db->prepare("INSERT INTO push_queue (status, created_at) VALUES ('pending', ?)")->execute([date('Y-m-d H:i:s', $this->now - 45 * 60)]);
        $this->assertSame('warn', $m->invoke($svc)['status'], 'stuck 45 minutes');
        $this->db->exec("DELETE FROM push_queue");
        $this->db->prepare("INSERT INTO push_queue (status, created_at) VALUES ('pending', ?)")->execute([date('Y-m-d H:i:s', $this->now - 3 * 3600)]);
        $r = $m->invoke($svc);
        $this->assertSame('fail', $r['status'], 'stuck three hours is an outage and must reach the owner');
        $this->assertStringContainsString('oldest 180 min', $r['detail']);
    }

    public function testPhpFatalsShowOnAdaButAreNotEmailedTwice(): void
    {
        $svc = $this->svc(['php_fatals' => fn() => ['status' => 'fail', 'detail' => '4 PHP fatal errors in 15 minutes.']]);
        $svc->run();
        $this->now += 300;
        $svc->run();
        $this->assertSame([], $svc->urgent(), 'AdaServerErrors already emails crashes; Pulse does not send a second one');
        $rows = array_column($svc->adaChecks(), null, 'system');
        $this->assertSame('red', $rows['pulse:php_fatals']['state'], 'but it is red on Ada\'s page');
    }

    public function testDeadReceiptJobsAreReportedAsCouldNotBeReadNotAsStuck(): void
    {
        $this->db->exec("CREATE TABLE expense_ocr_jobs (id INTEGER PRIMARY KEY, status TEXT, attempts INT, created_at TEXT)");
        $old = date('Y-m-d H:i:s', $this->now - 20 * 86400);
        $this->db->prepare("INSERT INTO expense_ocr_jobs (status, attempts, created_at) VALUES ('pending', 3, ?), ('pending', 3, ?)")->execute([$old, $old]);
        $svc = new PulseService($this->db, fn() => $this->now);
        $m = new ReflectionMethod($svc, 'probeQueues');
        if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
        $r = $m->invoke($svc);
        $this->assertSame('warn', $r['status'], 'something to look at, but not an outage: 2 dead jobs are not "stuck for 29,000 minutes"');
        $this->assertStringContainsString("2 receipts the reader couldn't read", $r['detail']);
        $this->assertStringNotContainsString('waiting to be read', $r['detail']);
        // A job that still has attempts left and is old IS stuck.
        $this->db->exec("DELETE FROM expense_ocr_jobs");
        $this->db->prepare("INSERT INTO expense_ocr_jobs (status, attempts, created_at) VALUES ('pending', 1, ?)")->execute([date('Y-m-d H:i:s', $this->now - 50 * 60)]);
        $this->assertStringContainsString('waiting to be read', $m->invoke($svc)['detail']);
    }
}
