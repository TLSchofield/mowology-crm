<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaRules.php';
require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaRiskRegister.php';
require_once __DIR__ . '/../../../app/Modules/Technology/Services/AdaAcceptedRisks.php';
require_once __DIR__ . '/../../../app/Modules/Technology/Services/SecurityPostureRules.php';

/**
 * Ada harvest (2026-11-03) — the rules taken from the operations and finance plugins:
 * cost-of-waiting order, age on every line, the 3×3 risk level and acceptance, trend headline,
 * access review every 90 days, backup read-back, evidence without a date.
 */
class AdaHarvestRulesTest extends TestCase
{
    private const NOW = '2026-11-03 14:00:00';

    private function check(string $system, string $state, string $group = 'Security', array $extra = []): array
    {
        return ['system' => $system, 'group' => $group, 'name' => $system, 'last_worked' => null, 'state' => $state,
                'problem' => $state === 'green' ? 'ok' : 'bad', 'evidence' => "{$system}: evidence.", 'handoff' => $state === 'green' ? null : 'do it', 'handoff_to' => 'tim'] + $extra;
    }

    // B. cost of waiting ───────────────────────────────────────────────────────

    public function test_cost_rank_money_then_crew_then_rest(): void
    {
        $this->assertSame(0, AdaRules::costRank('stripe'));
        $this->assertSame(0, AdaRules::costRank('posture:db-privileges'));
        $this->assertSame(0, AdaRules::costRank('posture:backup-db'));
        $this->assertSame(0, AdaRules::costRank('security:login-failures'));
        $this->assertSame(0, AdaRules::costRank('cron:invoice_reminders'));
        $this->assertSame(1, AdaRules::costRank('apple:push'));
        $this->assertSame(1, AdaRules::costRank('cron:tracking_silent_alert'));
        $this->assertSame(1, AdaRules::costRank('trackimo'));
        $this->assertSame(2, AdaRules::costRank('google:search-console'));
        $this->assertSame(2, AdaRules::costRank('cron:social_publisher'));
    }

    public function test_sort_is_state_then_cost_of_waiting(): void
    {
        $sorted = AdaRules::sortChecks([
            $this->check('cron:social_publisher', 'red', 'Scheduled jobs'),
            $this->check('google:search-console', 'amber', 'Connections'),
            $this->check('stripe', 'red', 'Connections'),
            $this->check('apple:push', 'red', 'Connections'),
        ]);
        $this->assertSame(['stripe', 'apple:push', 'cron:social_publisher', 'google:search-console'], array_column($sorted, 'system'));
    }

    // A. age on every line ─────────────────────────────────────────────────────

    public function test_age_is_shown_from_two_days_and_since_comes_from_the_check(): void
    {
        $this->assertSame('x: bad.', AdaRules::withAge('x: bad.', '2026-11-02 20:00:00', self::NOW));        // under 2 days: nothing added
        $this->assertSame('x: bad — for 17 days.', AdaRules::withAge('x: bad.', '2026-10-17 00:41:04', self::NOW));
        $this->assertSame('x: bad.', AdaRules::withAge('x: bad.', null, self::NOW));
        $this->assertSame('2026-10-17 00:41:04', AdaRules::badSince(['red_since' => '2026-10-17 00:41:04']));
        $this->assertSame('2026-11-01 06:00:03', AdaRules::badSince(['bad_since' => '2026-11-01 06:00:03']));
        $this->assertNull(AdaRules::badSince(['red_since' => null]));
    }

    public function test_brief_items_carry_the_real_since_and_the_age(): void
    {
        $items = AdaRules::briefItems([
            $this->check('posture:drift', 'red', 'Security', ['red_since' => '2026-10-17 00:41:04']),
            $this->check('cron:x', 'amber', 'Scheduled jobs'),
        ], '2026-11-03');
        $this->assertSame('2026-10-17', $items[0]['since']);
        $this->assertStringContainsString('for 17 days', $items[0]['text']);
        $this->assertSame('2026-11-03', $items[1]['since']);
        $this->assertStringNotContainsString('for ', $items[1]['text']);
    }

    public function test_a_failing_cron_knows_when_it_started_failing(): void
    {
        $reg = ['key' => 'x', 'title' => 'X', 'expr' => '*/15 * * * *', 'app' => '/app/x.php'];
        $stats = ['last_at' => '2026-11-03 13:45:00', 'last_ok_at' => '2026-11-01 10:00:00', 'last_status' => 'error', 'last_error' => 'boom', 'max_ms' => 10,
                  'recent' => [['error', '2026-11-03 13:45:00', 'boom'], ['error', '2026-11-03 13:30:00', 'boom'], ['error', '2026-11-01 10:15:00', 'boom'], ['success', '2026-11-01 10:00:00', '']]];
        $c = AdaRules::cronCheck($reg, $stats, true, true, self::NOW);
        $this->assertSame('failing', $c['problem']);
        $this->assertSame('2026-11-01 10:15:00', $c['bad_since']);
        $missed = AdaRules::cronCheck($reg, ['last_at' => '2026-10-20 10:00:00', 'last_ok_at' => '2026-10-20 10:00:00', 'last_status' => 'success', 'last_error' => '', 'max_ms' => 1, 'recent' => [['success', '2026-10-20 10:00:00', '']]], true, true, self::NOW);
        $this->assertSame('missed', $missed['problem']);
        $this->assertSame('2026-10-20 10:00:00', $missed['bad_since']);
        $this->assertStringContainsString('for 14 days', AdaRules::withAge($missed['evidence'], AdaRules::badSince($missed), self::NOW));
    }

    // C. the 3×3 and acceptance ────────────────────────────────────────────────

    public function test_level_is_the_plugins_matrix(): void
    {
        $this->assertSame('Critical', AdaRiskRegister::level('red', 0));
        $this->assertSame('High', AdaRiskRegister::level('red', 1));
        $this->assertSame('Medium', AdaRiskRegister::level('red', 2));
        $this->assertSame('High', AdaRiskRegister::level('amber', 0));
        $this->assertSame('Medium', AdaRiskRegister::level('amber', 1));
        $this->assertSame('Low', AdaRiskRegister::level('amber', 2));
        $this->assertSame('Medium', AdaRiskRegister::level('unknown', 0));
        $this->assertSame('Low', AdaRiskRegister::level('unknown', 1));
        $this->assertSame('Low', AdaRiskRegister::level('unknown', 2));
        $this->assertNull(AdaRiskRegister::level('green', 0));
        $this->assertNull(AdaRiskRegister::level('info', 2));
    }

    public function test_critical_cannot_be_accepted_green_cannot_either(): void
    {
        $this->assertFalse(AdaRiskRegister::canAccept(['state' => 'red', 'cost_rank' => 0]));      // db-privileges today
        $this->assertTrue(AdaRiskRegister::canAccept(['state' => 'red', 'cost_rank' => 1]));
        $this->assertTrue(AdaRiskRegister::canAccept(['state' => 'amber', 'cost_rank' => 0]));
        $this->assertFalse(AdaRiskRegister::canAccept(['state' => 'green', 'cost_rank' => 2]));
        $this->assertSame('2027-02-01', AdaRiskRegister::reviewBy('2026-11-03 14:00:00'));
    }

    public function test_apply_sets_accepted_apart_until_review_then_brings_it_back_and_releases_cleared(): void
    {
        $checks = [
            $this->check('posture:drift', 'red', 'Security', ['red_since' => '2026-10-17 00:41:04']),
            $this->check('posture:stripe-webhook', 'amber'),
            $this->check('posture:dependencies', 'amber'),
            $this->check('posture:certificate', 'green'),
        ];
        $acc = [
            'posture:stripe-webhook' => ['reason' => 'disputes are rare; handled by email', 'accepted_by_name' => 'Tim', 'accepted_at' => '2026-11-01 10:00:00', 'review_by' => '2027-01-30'],
            'posture:dependencies'   => ['reason' => 'old', 'accepted_by_name' => 'Tim', 'accepted_at' => '2026-07-01 10:00:00', 'review_by' => '2026-09-29'],
            'posture:certificate'    => ['reason' => 'was amber', 'accepted_by_name' => 'Tim', 'accepted_at' => '2026-10-01 10:00:00', 'review_by' => '2026-12-30'],
        ];
        $r = AdaRiskRegister::apply($checks, $acc, '2026-11-03');
        $this->assertSame(['posture:stripe-webhook'], array_column($r['accepted'], 'system'));
        $this->assertSame('Tim', $r['accepted'][0]['accepted']['by']);
        $this->assertSame(['posture:certificate'], $r['released']);                 // green now: acceptance released
        $active = array_column($r['active'], 'system');
        $this->assertContains('posture:dependencies', $active);                     // review overdue: back
        $dep = $r['active'][array_search('posture:dependencies', $active, true)];
        $this->assertStringContainsString('the review was due Sep 29', $dep['evidence']);
        $this->assertTrue($dep['accepted_overdue']);
        $drift = $r['active'][array_search('posture:drift', $active, true)];
        $this->assertSame('Critical', $drift['level']);
        $this->assertFalse($drift['acceptable']);
        $this->assertSame(0, $drift['cost_rank']);
    }

    public function test_reason_must_be_short_plain_and_free_of_tokens(): void
    {
        $this->assertNotNull(AdaRiskRegister::reasonProblem('   '));
        $this->assertNotNull(AdaRiskRegister::reasonProblem(str_repeat('x', 301)));
        $this->assertNotNull(AdaRiskRegister::reasonProblem('key sk_live_' . str_repeat('a', 40)));
        $this->assertNull(AdaRiskRegister::reasonProblem('Disputes come by email; webhook events add nothing.'));
    }

    public function test_accepted_risks_table_round_trip_and_quiet_without_it(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $off = new AdaAcceptedRisks($db);
        $this->assertFalse($off->ready());
        $this->assertSame([], $off->open());
        $this->assertFalse($off->accept('x', 'why', 1, 'Tim', self::NOW));
        $db->exec("CREATE TABLE ada_accepted_risks (id INTEGER PRIMARY KEY AUTOINCREMENT, system_key TEXT, reason TEXT, accepted_by INT, accepted_by_name TEXT,
                   accepted_at TEXT, review_by TEXT, released_at TEXT, released_how TEXT)");
        $r = new AdaAcceptedRisks($db);
        $this->assertTrue($r->ready());
        $this->assertFalse($r->accept('posture:x', '  ', 1, 'Tim', self::NOW));
        $this->assertTrue($r->accept('posture:x', 'fine  for now', 1, 'Tim', self::NOW));
        $this->assertTrue($r->accept('posture:x', 'second thought', 1, 'Tim', self::NOW));   // replaces
        $open = $r->open();
        $this->assertSame(['posture:x'], array_keys($open));
        $this->assertSame('second thought', $open['posture:x']['reason']);
        $this->assertSame('2027-02-01', $open['posture:x']['review_by']);
        $this->assertSame('replaced', $db->query("SELECT released_how FROM ada_accepted_risks WHERE id = 1")->fetchColumn());
        $r->release('posture:x', 'cleared', self::NOW);
        $this->assertSame([], $r->open());
        $r->release('posture:x', 'nonsense', self::NOW);   // ignored
    }

    // D. trend ────────────────────────────────────────────────────────────────

    public function test_headline_says_what_changed_since_yesterday(): void
    {
        $c = ['red' => 2, 'amber' => 4, 'unknown' => 0, 'green' => 30, 'info' => 0];
        $this->assertSame('2 broken, 4 to look at.', AdaRules::headline($c));
        $this->assertSame('2 broken (one fewer than yesterday), 4 to look at (2 more than yesterday).', AdaRules::headline($c, ['red' => 3, 'amber' => 2]));
        $this->assertSame('2 broken, 4 to look at.', AdaRules::headline($c, ['red' => 2, 'amber' => 4]));
        $ok = ['red' => 0, 'amber' => 0, 'unknown' => 0, 'green' => 30, 'info' => 0];
        $this->assertSame('Everything Ada watches is working. (Yesterday: 3 to look at.)', AdaRules::headline($ok, ['red' => 1, 'amber' => 2]));
        $this->assertSame('Everything Ada watches is working.', AdaRules::headline($ok, ['red' => 0, 'amber' => 0]));
    }

    public function test_trend_history_keeps_fourteen_days_one_per_day(): void
    {
        $h = [];
        for ($d = 1; $d <= 20; $d++) $h = AdaRules::trendAppend($h, sprintf('2026-10-%02d', $d), ['red' => $d, 'amber' => 0, 'unknown' => 0, 'green' => 1], ['a']);
        $this->assertCount(14, $h);
        $this->assertSame('2026-10-07', array_key_first($h));
        $h = AdaRules::trendAppend($h, '2026-10-20', ['red' => 99, 'amber' => 0, 'unknown' => 0, 'green' => 1], []);
        $this->assertCount(14, $h);
        $this->assertSame(99, $h['2026-10-20']['red']);
        $this->assertSame(19, AdaRules::trendYesterday($h, '2026-10-20')['red']);
        $this->assertSame(99, AdaRules::trendYesterday($h, '2026-10-25')['red']);   // the last one taken
        $this->assertNull(AdaRules::trendYesterday($h, '2026-10-01'));
    }

    public function test_handoff_counts_by_owner(): void
    {
        $n = AdaRules::handoffCounts([
            $this->check('a', 'red') + [], ['system' => 'b', 'state' => 'amber', 'handoff' => 'x', 'handoff_to' => 'developer'],
            ['system' => 'c', 'state' => 'green', 'handoff' => null, 'handoff_to' => null], ['system' => 'd', 'state' => 'unknown', 'handoff' => 'y', 'handoff_to' => 'developer'],
        ]);
        $this->assertSame(['tim' => 1, 'developer' => 2], $n);
    }

    // E. access review ────────────────────────────────────────────────────────

    public function test_access_review_never_then_changed_then_due_then_green(): void
    {
        $users = [
            ['id' => 1, 'name' => 'Tim', 'role' => 'admin', 'is_active' => 1, 'last_login' => '2026-11-03 13:38:36'],
            ['id' => 4, 'name' => 'Old', 'role' => 'manager', 'is_active' => 0, 'last_login' => '2026-03-05 22:06:16'],
            ['id' => 6, 'name' => 'Nigel', 'role' => 'manager', 'is_active' => 1, 'last_login' => null],
        ];
        $never = AdaRules::accessReviewCheck($users, null, self::NOW);
        $this->assertSame(['amber', 'never-reviewed'], [$never['state'], $never['problem']]);
        $this->assertStringContainsString('2 logins can change', $never['evidence']);     // inactive one not counted
        $this->assertStringContainsString('Nigel (manager, never signed in)', $never['evidence']);
        $truck = AdaRules::accessReviewCheck([['id' => 7, 'name' => 'DODGE RAM', 'role' => 'manager', 'is_active' => 1, 'last_login' => null, 'is_vehicle_tablet' => 1]], null, self::NOW);
        $this->assertStringContainsString('DODGE RAM (manager, a truck tablet, never signed in)', $truck['evidence']);
        $this->assertSame('tim', $never['handoff_to']);
        $hash = $never['roster_hash'];

        $green = AdaRules::accessReviewCheck($users, ['at' => '2026-10-01 10:00:00', 'by' => 'Tim', 'hash' => $hash], self::NOW);
        $this->assertSame('green', $green['state']);
        $this->assertStringContainsString('Reviewed', $green['evidence']);

        $due = AdaRules::accessReviewCheck($users, ['at' => '2026-07-01 10:00:00', 'by' => 'Tim', 'hash' => $hash], self::NOW);
        $this->assertSame('review-due', $due['problem']);

        $users[] = ['id' => 9, 'name' => 'New', 'role' => 'manager', 'is_active' => 1, 'last_login' => null];
        $changed = AdaRules::accessReviewCheck($users, ['at' => '2026-10-01 10:00:00', 'by' => 'Tim', 'hash' => $hash], self::NOW);
        $this->assertSame('roster-changed', $changed['problem']);
        $this->assertNotSame($hash, $changed['roster_hash']);
        // switching the new one off restores the fingerprint
        $users[3]['is_active'] = 0;
        $this->assertSame($hash, AdaRules::rosterHash(array_filter($users, static fn($u) => $u['is_active'])));
    }

    // F. backup read-back ─────────────────────────────────────────────────────

    public function test_backup_read_back_states(): void
    {
        $this->assertSame('amber', SecurityPostureRules::backupVerifyCheck(null, 212, self::NOW)['state']);
        $ok = ['ok' => 1, 'advisories' => 212, 'detail' => 'x.sql.gz: gzip ok, 212 tables', 'finished_at' => '2026-11-03 02:35:00'];
        $g = SecurityPostureRules::backupVerifyCheck($ok, 212, self::NOW);
        $this->assertSame('green', $g['state']);
        $this->assertStringContainsString('212 tables, matches the database', $g['evidence']);
        $short = SecurityPostureRules::backupVerifyCheck(['advisories' => 200] + $ok, 212, self::NOW);
        $this->assertSame(['red', 'short'], [$short['state'], $short['problem']]);
        $this->assertStringContainsString('missing 12', $short['evidence']);
        $this->assertSame('developer', $short['handoff_to']);
        $bad = SecurityPostureRules::backupVerifyCheck(['ok' => 0, 'detail' => 'x: gzip test FAILED'] + $ok, 212, self::NOW);
        $this->assertSame(['red', 'unreadable'], [$bad['state'], $bad['problem']]);
        $stale = SecurityPostureRules::backupVerifyCheck(['finished_at' => '2026-10-30 02:35:00'] + $ok, 212, self::NOW);
        $this->assertSame('stale', $stale['problem']);
        $this->assertSame('green', SecurityPostureRules::backupVerifyCheck($ok, null, self::NOW)['state']);   // live count unknown: no claim
        $this->assertContains('verify', SecurityPostureRules::HEARTBEAT_KINDS);
        $this->assertIsArray(SecurityPostureRules::heartbeatRow(['kind' => 'verify', 'ok' => true, 'advisories' => 212], self::NOW));
    }

    // G. evidence without a date ──────────────────────────────────────────────

    public function test_evidence_dated_and_the_info_row(): void
    {
        $this->assertTrue(AdaRules::evidenceDated('X: last ran 12 min ago, worked.', null));
        $this->assertTrue(AdaRules::evidenceDated('cert expires Jan 20, 2027 (78 days).', null));
        $this->assertTrue(AdaRules::evidenceDated('nothing', '2026-11-01 00:00:00'));
        $this->assertTrue(AdaRules::evidenceDated('Database backup finished 23 hours ago (22.8 MB).', null));
        $this->assertTrue(AdaRules::evidenceDated('11 error lines in the PHP error log in 24 h (of 4527 log lines).', null));
        $this->assertFalse(AdaRules::evidenceDated('app login can change tables — finish step 3 (data-only user). It holds: ALL PRIVILEGES.', null));
        $this->assertTrue(AdaRules::evidenceDated("Stuck: 2 receipts the reader couldn't read. (checked 2:20 pm)", null));
        $this->assertTrue(AdaRules::evidenceDated('X: no run on record, ever — most likely it is not in the cPanel schedule.', null));
        $this->assertNull(AdaRules::evidenceCheck([['system' => 'p', 'name' => 'P', 'state' => 'red', 'evidence' => 'no when', 'last_worked' => null, 'checked_at' => '2026-11-03 01:42:05']]));
        $row = AdaRules::evidenceCheck([
            ['system' => 'a', 'name' => 'A', 'state' => 'red', 'evidence' => 'bad, no when', 'last_worked' => null],
            ['system' => 'b', 'name' => 'B', 'state' => 'green', 'evidence' => 'fine, no when', 'last_worked' => null],
            ['system' => 'c', 'name' => 'C', 'state' => 'amber', 'evidence' => 'bad 3 days ago', 'last_worked' => null],
        ]);
        $this->assertSame('info', $row['state']);
        $this->assertStringContainsString('1 finding reports without saying when (A)', $row['evidence']);
        $this->assertNull(AdaRules::evidenceCheck([['system' => 'c', 'name' => 'C', 'state' => 'amber', 'evidence' => 'bad 3 days ago', 'last_worked' => null]]));
    }
}
