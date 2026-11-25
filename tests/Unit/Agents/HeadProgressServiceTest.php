<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HeadProgressServiceTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }

    public function testWeekStartsAndBucket(): void
    {
        $weeks = HeadProgressService::weekStarts(3, '2026-11-05');
        $this->assertSame(['2026-10-19', '2026-10-26', '2026-11-02'], $weeks);
        $this->assertSame([2.0, 3.0, 4.0], HeadProgressService::bucket([
            '2026-10-18' => 99, '2026-10-19' => 2, '2026-11-01' => 3, '2026-11-05' => 4,
        ], $weeks));
    }

    public function testWeeklyFailsSoftWithNoTables(): void
    {
        $weekly = (new HeadProgressService($this->db))->weekly(12, '2026-11-05');
        $this->assertSame(array_keys(AgentRegistry::heads()), array_keys($weekly['heads']));
        $this->assertCount(12, $weekly['weeks']);
        $this->assertSame(array_fill(0, 12, 0), $weekly['heads']['charlie']['metrics']['ai_calls']['values']);
        $this->assertSame(array_fill(0, 12, null), $weekly['heads']['penny']['metrics']['right_first_time']['values']);
    }

    public function testPennyCountsReceiptsAndOnlyLiveSuggestionsOnApprovedExpenses(): void
    {
        $this->db->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, status TEXT)");
        $this->db->exec("CREATE TABLE expense_suggestions (expense_id INT, source TEXT, status TEXT, model TEXT, input_tokens INT, output_tokens INT, created_at TEXT, decided_at TEXT)");
        $this->db->exec("INSERT INTO expenses VALUES (1, 'approved'), (2, 'forwarded'), (3, 'approved'), (4, 'approved'), (5, 'approved'), (6, 'pending_approval'), (7, 'approved')");
        $ins = $this->db->prepare('INSERT INTO expense_suggestions VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $week = ['2026-10-21 10:00:00', '2026-10-22 10:00:00'];
        $ins->execute([1, 'live', 'accepted', 'code', 1000000, 1000000, ...$week]);
        $ins->execute([1, 'live', 'accepted', 'ai', 1000000, 0, ...$week]);       // a second row on receipt 1: still one receipt
        foreach ([2, 3, 4] as $id) {
            $ins->execute([$id, 'live', 'accepted', 'ai', 1000000, 0, ...$week]);
        }
        $ins->execute([5, 'live', 'accepted', 'ai', 1000000, 0, ...$week]);
        $ins->execute([5, 'live', 'edited', 'ai', 1000000, 0, ...$week]);         // receipt 5 had a correction: not kept
        $ins->execute([6, 'live', 'accepted', 'ai', 0, 0, ...$week]);             // receipt not approved: read, not an answer
        $ins->execute([1, 'test', 'accepted', 'ai', 1000000, 0, ...$week]);       // backtest: ignored
        $ins->execute([7, 'live', 'accepted', 'ai', 0, 0, '2026-10-28 10:00:00', '2026-10-29 10:00:00']);

        $m = (new HeadProgressService($this->db))->weekly(3, '2026-11-05')['heads']['penny']['metrics'];
        $this->assertSame(5.0, $m['decided']['values'][0]);
        $this->assertSame(4.0, $m['kept']['values'][0]);
        $this->assertSame(80.0, $m['right_first_time']['values'][0]);
        $this->assertNull($m['right_first_time']['values'][1], 'one answer that week: too few to judge');
        $this->assertSame(6.0, $m['read']['values'][0], 'receipts read, not suggestion rows');
        $this->assertEquals(17.0, $m['code_alone']['values'][0]);
        $this->assertEqualsWithDelta(24.0 + 6 * 4.0, $m['ai_cost_est']['values'][0], 0.0001);
    }

    public function testSamBucketsAndExcludesTestQuotes(): void
    {
        $this->db->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
        $this->db->exec('CREATE TABLE quotes (id INTEGER PRIMARY KEY, contact_id INT, title TEXT, status TEXT, sent_at TEXT, accepted_at TEXT, updated_at TEXT, total_amount REAL, amount REAL)');
        $this->db->exec("INSERT INTO contacts VALUES (1, 'Real', 'Person'), (2, 'ZZTEST', 'Person')");
        $this->db->exec("INSERT INTO quotes VALUES
            (1,1,'Garden','accepted','2026-10-20','2026-10-22','2026-10-22',100,90),
            (2,1,'Lawn','declined','2026-10-21',NULL,'2026-10-23',0,50),
            (3,2,'Fake','accepted','2026-10-20','2026-10-22','2026-10-22',999,999)");
        $m = (new HeadProgressService($this->db))->weekly(3, '2026-11-05')['heads']['sam']['metrics'];
        $this->assertSame(2.0, $m['sent']['values'][0]);
        $this->assertSame(1.0, $m['won']['values'][0]);
        $this->assertSame(1.0, $m['lost']['values'][0]);
        $this->assertSame(100.0, $m['won_amount']['values'][0]);
    }

    public function testHonestHeadlineComparisonsAndSparklines(): void
    {
        $metric = static fn(array $v): array => ['won' => ['values' => $v, 'format' => 'int']];
        $this->assertStringContainsString('up from 1', HeadProgressService::headline('sam', $metric([0, 1, 0, 0, 0, 3, 0]))['sentence']);
        $this->assertStringContainsString('down from 4', HeadProgressService::headline('sam', $metric([0, 4, 0, 0, 0, 2, 0]))['sentence']);
        $this->assertStringContainsString('the same as 2', HeadProgressService::headline('sam', $metric([0, 2, 0, 0, 0, 2, 0]))['sentence']);
        $this->assertStringContainsString('Not enough history', HeadProgressService::headline('sam', $metric([3, 0]))['sentence']);

        $otto = ['answered' => ['values' => [0, 0], 'format' => 'int'], 'made' => ['values' => [2, 3]]];
        $this->assertSame('Otto made 5 suggestions in 2 weeks and none were answered — they expire before anyone looks.', HeadProgressService::headline('otto', $otto)['sentence']);
        $this->assertSame('', HeadProgressService::sparkline([null, 1, null]));
        $this->assertSame('0,14 120,14', HeadProgressService::sparkline([4, 4]));
        $this->assertSame('0,28 120,0', HeadProgressService::sparkline([1, 3]));
    }

    public function testSnapshotWritesAvailableKeysOnlyAndOnlyOnce(): void
    {
        $this->db->exec('CREATE TABLE head_stat_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, snap_date TEXT, head TEXT, stat_key TEXT, value REAL, created_at TEXT, UNIQUE(snap_date, head, stat_key))');
        $this->db->exec('CREATE TABLE expenses (status TEXT)');
        $this->db->exec("INSERT INTO expenses VALUES ('pending_approval'), ('draft')");
        $this->db->exec('CREATE TABLE agent_tasks (owner_head TEXT, state TEXT)');
        $this->db->exec("INSERT INTO agent_tasks VALUES ('penny','open'), ('sam','done')");
        $service = new HeadProgressService($this->db);
        $this->assertSame(9, $service->snapshot('2026-11-05'));
        $this->assertSame(0, $service->snapshot('2026-11-05'));
        $keys = $this->db->query("SELECT head || ':' || stat_key FROM head_stat_snapshots ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame('__snapshot__:claimed', $keys[0]);
        $this->assertContains('penny:waiting', $keys);
        $this->assertContains('penny:drafts', $keys);
        $this->assertContains('ada:open_tasks', $keys);
    }

    public function testSnapshotAtomicClaimPreventsASecondWriterFromFillingMissingKeys(): void
    {
        $this->db->exec('CREATE TABLE head_stat_snapshots (id INTEGER PRIMARY KEY AUTOINCREMENT, snap_date TEXT, head TEXT, stat_key TEXT, value REAL, created_at TEXT, UNIQUE(snap_date, head, stat_key))');
        $claim = $this->db->prepare('INSERT INTO head_stat_snapshots (snap_date, head, stat_key, value, created_at) VALUES (?, ?, ?, ?, ?)');
        $claim->execute(['2026-11-05', '__snapshot__', 'claimed', null, '2026-11-05 00:00:00']);
        $this->db->exec("CREATE TABLE expenses (status TEXT)");
        $this->db->exec("INSERT INTO expenses VALUES ('pending_approval')");

        $this->assertSame(0, (new HeadProgressService($this->db))->snapshot('2026-11-05'));
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM head_stat_snapshots")->fetchColumn());
    }

    public function testABusyHeadWithNoAnswersSaysSoInsteadOfNothingRecorded(): void
    {
        $metrics = ['sent' => ['values' => [0, 0, 0], 'format' => 'int'], 'ai_calls' => ['values' => [0, 79, 111], 'format' => 'int']];
        $this->assertSame('Mia has no answers from you to count yet; 79 AI requests last week.', HeadProgressService::headline('mia', $metrics)['sentence']);
        $quiet = ['sent' => ['values' => [0, 0, 0], 'format' => 'int'], 'ai_calls' => ['values' => [0, 0, 0], 'format' => 'int']];
        $this->assertSame('Yui: nothing recorded yet.', HeadProgressService::headline('yui', $quiet)['sentence']);
    }

    public function testWeeksBeforeAnyRecordAreLeftOutAndFlatZeroDrawsNoLine(): void
    {
        $this->assertSame(2, HeadProgressService::firstActiveWeek(['a' => ['values' => [0, null, 0, 3]], 'b' => ['values' => [0, 0, 5, 0]]]));
        $this->assertNull(HeadProgressService::firstActiveWeek(['a' => ['values' => [0, null, 0]]]));
        $this->assertSame('', HeadProgressService::sparkline([0, 0, 0]));
    }
}
