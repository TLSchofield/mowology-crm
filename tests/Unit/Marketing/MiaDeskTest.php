<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory SQLite that answers the few MySQL-isms Mia's desk uses. */
class MiaTestPdo extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        @$this->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        @$this->sqliteCreateFunction('FIELD', function ($v, ...$list) { $i = array_search($v, $list, true); return $i === false ? 0 : $i + 1; });
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (preg_match("/^\s*SHOW TABLES LIKE '([a-z_]+)'/i", $query, $m)) {
            $query = "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '{$m[1]}'";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/**
 * Mia's desk: Charlie's brief is read-only, skips are remembered, and the card speaks plainly.
 */
class MiaDeskTest extends TestCase
{
    private MiaTestPdo $db;

    protected function setUp(): void
    {
        $this->db = new MiaTestPdo();
        $this->db->exec("CREATE TABLE mia_suggestions (id INTEGER PRIMARY KEY, kind TEXT, subject_key TEXT, contact_id INT, company_id INT, property_id INT,
            reason_json TEXT, draft_subject TEXT, draft_body TEXT, draft_sms TEXT, status TEXT DEFAULT 'open', edited INT DEFAULT 0,
            sent_subject TEXT, sent_body TEXT, sent_channels TEXT, skip_reason TEXT, decided_by INT, decided_at TEXT,
            outcome TEXT, outcome_at TEXT, outcome_ref TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->db->exec("CREATE TABLE mia_mutes (subject_key TEXT PRIMARY KEY, until_date TEXT, reason TEXT, created_by INT, created_at TEXT)");
        $this->db->exec("CREATE TABLE mia_questions (id INTEGER PRIMARY KEY, kind TEXT, subject_key TEXT, question TEXT, status TEXT DEFAULT 'open',
            answer TEXT, answered_by INT, answered_at TEXT, created_at TEXT, UNIQUE(kind, subject_key))");
        $this->db->exec("CREATE TABLE contacts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, mobile TEXT, phone TEXT, has_reviewed INT DEFAULT 0)");
        $this->db->exec("CREATE TABLE companies (id INTEGER PRIMARY KEY, company_name TEXT)");
        $this->db->exec("CREATE TABLE properties (id INTEGER PRIMARY KEY, address TEXT)");
        $this->db->exec("INSERT INTO contacts (id, first_name, last_name, email) VALUES (12, 'Jane', 'Smith', 'jane@example.com')");
        $this->db->exec("INSERT INTO properties (id, address) VALUES (5, '1234 Oak Street')");
        $this->db->exec("INSERT INTO companies (id, company_name) VALUES (9, 'Pacific Quorum')");
        $ins = $this->db->prepare("INSERT INTO mia_suggestions (kind, subject_key, contact_id, company_id, property_id, reason_json, draft_subject, draft_body, draft_sms) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute(['seasonal', 'mia:contact:12', 12, null, 5, json_encode(['service' => 'fall_cleanup', 'last_done' => '2025-10-20', 'priority' => 1, 'value' => 380, 'sms_ok' => false]), 'S', 'B', 'T']);
        $ins->execute(['pm_quiet', 'mia:company:9', 12, 9, null, json_encode(['company' => 'Pacific Quorum', 'prior_visits' => 14, 'recent_visits' => 2, 'priority' => 1, 'value' => 5200]), 'S', 'B', 'T']);
        $this->db->exec("INSERT INTO mia_questions (kind, subject_key, question) VALUES ('review_check', 'mia:contact:12', 'Did Jane Smith leave a Google review?')");
    }

    private function changes(): int
    {
        return (int)$this->db->query("SELECT total_changes()")->fetchColumn();
    }

    public function test_brief_for_charlie_is_read_only_with_stable_keys(): void
    {
        $before = $this->changes();
        $b = (new MiaDeskService($this->db))->brief('Tim');
        $this->assertSame($before, $this->changes(), 'brief() must not write');
        $this->assertSame('mia', $b['head']);
        $this->assertStringStartsWith('Hey Tim — ', $b['headline']);
        $this->assertSame(3, $b['count']);
        $keys = array_column($b['items'], 'key');
        $this->assertSame(['mia:company:9', 'mia:contact:12', 'mia:question:1'], $keys, 'biggest first; questions last');
        foreach ($b['items'] as $it) {
            $this->assertArrayHasKey('text', $it);
            $this->assertContains($it['priority'], [1, 2, 3]);
        }
        $this->assertStringContainsString('Pacific Quorum', $b['items'][0]['text']);
    }

    public function test_brief_is_empty_not_fatal_before_the_migration(): void
    {
        $b = (new MiaDeskService(new MiaTestPdo()))->brief('Tim');
        $this->assertSame(['head' => 'mia', 'headline' => '', 'items' => [], 'count' => 0], $b);
    }

    public function test_a_skip_is_remembered_with_its_reason(): void
    {
        $desk = new MiaDeskService($this->db);
        $r = $desk->decide(1, 'skip', ['reason' => 'not_now'], ['id' => 3]);
        $this->assertTrue($r['ok']);
        $this->assertSame(date('Y-m-d', strtotime('+60 days')), $r['until']);
        $mute = $this->db->query("SELECT * FROM mia_mutes")->fetch();
        $this->assertSame('mia:contact:12', $mute['subject_key']);
        $this->assertSame('skipped', $this->db->query("SELECT status FROM mia_suggestions WHERE id = 1")->fetchColumn());

        $never = $desk->decide(2, 'skip', ['reason' => 'never'], ['id' => 3]);
        $this->assertNull($never['until'], 'never = no end date');
        $this->assertFalse($desk->decide(2, 'skip', ['reason' => 'never'], ['id' => 3])['ok'], 'already dealt with');
        $this->assertFalse($desk->decide(1, 'skip', ['reason' => 'bored'], ['id' => 3])['ok']);
    }

    public function test_a_send_with_a_leftover_placeholder_is_refused_before_anything_goes(): void
    {
        $r = (new MiaDeskService($this->db))->decide(1, 'send', ['subject' => 'Hi', 'body' => 'Hi {first_name}'], ['id' => 3]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('placeholder', $r['error']);
        $this->assertSame('open', $this->db->query("SELECT status FROM mia_suggestions WHERE id = 1")->fetchColumn());
    }

    public function test_answering_yes_to_a_review_question_ticks_has_reviewed(): void
    {
        $q = new MiaQuestionService($this->db);
        $this->assertFalse($q->answer(1, 'maybe', 3)['ok']);
        $this->assertTrue($q->answer(1, 'yes', 3)['ok']);
        $this->assertSame(1, (int)$this->db->query("SELECT has_reviewed FROM contacts WHERE id = 12")->fetchColumn());
        $this->assertFalse($q->answer(1, 'yes', 3)['ok'], 'asked once');
    }

    public function test_queue_cards_say_why_in_plain_words(): void
    {
        $q = (new MiaDeskService($this->db))->queue();
        $this->assertSame('mia:company:9', $q[0]['key']);
        $this->assertSame('Pacific Quorum: 14 visits this time last year, 2 in the last 3 months.', $q[0]['why']);
        $this->assertSame('/crm/companies/view.php?id=9', $q[0]['url']);
        $this->assertSame('Fall cleanup around this time last year (Oct 2025), not booked this year.', $q[1]['why']);
        $this->assertSame('1234 Oak Street', $q[1]['address']);
    }

    public function test_headline_reads_like_a_person(): void
    {
        $st = ['by_kind' => ['reconnect' => 2, 'seasonal' => 1, 'pm_quiet' => 1, 'referral' => 0], 'questions' => 0];
        $this->assertSame(
            "Hey Tim — 1 customer from this time last year hasn't booked again, 1 property manager has gone quiet and 2 past customers are worth a hello. I've drafted a message for each — you send.",
            MiaDeskService::headline($st, 'Tim')
        );
        $none = ['by_kind' => ['reconnect' => 0, 'seasonal' => 0, 'pm_quiet' => 0, 'referral' => 0], 'questions' => 1];
        $this->assertStringContainsString('question for you', MiaDeskService::headline($none, ''));
        $this->assertStringStartsWith('Hey — ', MiaDeskService::headline($none, ''));
    }

    public function test_badges_come_only_from_real_results(): void
    {
        $sent = fn(string $kind, ?string $outcome, int $edited = 0) => ['kind' => $kind, 'status' => 'sent', 'edited' => $edited, 'outcome' => $outcome];
        $b = MiaBadgeService::compute(array_merge(array_fill(0, 5, $sent('reconnect', 'booked')), [$sent('pm_quiet', 'quote', 1)]));
        $this->assertSame(['rebooker'], array_column($b['earned'], 'key'));
        $this->assertSame('tune', $b['next']['key'], '5 unedited in a row is the closest');
        $this->assertSame(5, $b['next']['have']);
        $this->assertSame(['referral'], array_column(MiaBadgeService::compute([], 1)['earned'], 'key'));
        $this->assertSame([], MiaBadgeService::compute([$sent('reconnect', 'reply')])['earned'], 'a reply is not booked work');
    }

    public function test_brain_brightness_is_how_often_tim_sends_what_she_suggests(): void
    {
        $this->assertSame(0.5, MiaBrainService::bright([]));
        $this->assertSame(0.75, MiaBrainService::bright(['sent', 'sent', 'sent', 'skipped']));
    }
}
