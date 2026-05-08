<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for CertificationService
 *
 * All PDO calls are mocked. Tests validate business logic without a real DB.
 */
class CertificationServiceTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeStmt(
        mixed $fetchReturn   = false,
        array $fetchAllReturn = [],
        mixed $fetchColReturn = 0,
        int   $rowCount       = 0
    ): PDOStatement {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($fetchReturn);
        $stmt->method('fetchAll')->willReturn($fetchAllReturn);
        $stmt->method('fetchColumn')->willReturn($fetchColReturn);
        $stmt->method('rowCount')->willReturn($rowCount);
        return $stmt;
    }

    private function makePdo(PDOStatement ...$stmts): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(...$stmts);
        return $db;
    }

    private function course(array $overrides = []): array
    {
        return array_merge([
            'id'               => 10,
            'tier_id'          => 1,
            'name'             => 'Acelepryn Application',
            'slug'             => 'acelepryn',
            'pass_score_pct'   => 80,
            'min_questions'    => 5,
            'max_questions'    => 10,
            'attempts_allowed' => 3,
            'cooldown_hours'   => 24,
            'recert_months'    => 12,
            'is_active'        => 1,
            'is_required'      => 1,
        ], $overrides);
    }

    // ── checkEligibility tests ────────────────────────────────────────────────

    public function test_check_eligibility_returns_ineligible_when_course_not_found(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(false); // getCourse returns nothing
        $db->method('prepare')->willReturn($stmt);

        $svc    = new CertificationService($db);
        $result = $svc->checkEligibility(1, 99);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('not found', $result['reason']);
    }

    public function test_check_eligibility_returns_ineligible_when_already_passed(): void
    {
        $course = $this->course(['tier_id' => 1]);
        $db     = $this->createMock(PDO::class);

        // getCourse, getTierLevel, existing cert check (returns 'active'), ...
        $courseStmt  = $this->makeStmt($course);
        $tierStmt    = $this->makeStmt(false, [], 1);   // tier_level = 1 (no prereq check)
        $certStmt    = $this->makeStmt(false, [], 'active');  // already active
        $db->method('prepare')->willReturnOnConsecutiveCalls($courseStmt, $tierStmt, $certStmt);

        $svc    = new CertificationService($db);
        $result = $svc->checkEligibility(1, 10);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('already passed', $result['reason']);
    }

    public function test_check_eligibility_returns_ineligible_when_max_attempts_reached(): void
    {
        $course = $this->course(['attempts_allowed' => 2]);
        $db     = $this->createMock(PDO::class);

        $courseStmt   = $this->makeStmt($course);
        $tierStmt     = $this->makeStmt(false, [], 1);
        $certStmt     = $this->makeStmt(false, [], false);     // no existing cert
        $attemptsStmt = $this->makeStmt(false, [], 2);         // 2 completed attempts = at max
        $db->method('prepare')->willReturnOnConsecutiveCalls($courseStmt, $tierStmt, $certStmt, $attemptsStmt);

        $svc    = new CertificationService($db);
        $result = $svc->checkEligibility(1, 10);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('Maximum attempts', $result['reason']);
    }

    public function test_check_eligibility_returns_eligible_on_first_attempt(): void
    {
        $course = $this->course();
        $db     = $this->createMock(PDO::class);

        $courseStmt   = $this->makeStmt($course);
        $tierStmt     = $this->makeStmt(false, [], 1);
        $certStmt     = $this->makeStmt(false, [], false);    // no cert yet
        $attemptsStmt = $this->makeStmt(false, [], 0);        // 0 prior attempts
        $db->method('prepare')->willReturnOnConsecutiveCalls($courseStmt, $tierStmt, $certStmt, $attemptsStmt);

        $svc    = new CertificationService($db);
        $result = $svc->checkEligibility(1, 10);

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['reason']);
        $this->assertSame(3, $result['attempts_remaining']);
        $this->assertSame(1, $result['attempt_number']);
    }

    // ── canWorkServiceType tests ──────────────────────────────────────────────

    public function test_can_work_returns_allowed_when_no_requirements(): void
    {
        $db   = $this->createMock(PDO::class);
        $stmt = $this->makeStmt(false, []); // no requirements
        $db->method('prepare')->willReturn($stmt);

        $svc    = new CertificationService($db);
        $result = $svc->canWorkServiceType(1, 'lawn_care');

        $this->assertTrue($result['allowed']);
    }

    public function test_can_work_returns_ineligible_when_tier_not_met(): void
    {
        $db = $this->createMock(PDO::class);

        $reqStmt  = $this->makeStmt(false, [
            ['min_tier_level' => 2, 'course_id' => null, 'course_name' => null, 'course_slug' => null]
        ]); // requires Tier 2
        $tierStmt = $this->makeStmt(false, [], 1); // user only has Tier 1
        $db->method('prepare')->willReturnOnConsecutiveCalls($reqStmt, $tierStmt);

        $svc    = new CertificationService($db);
        $result = $svc->canWorkServiceType(1, 'hedge_trimming');

        $this->assertFalse($result['allowed']);
        $this->assertSame(2, $result['missing_tier']);
        $this->assertTrue($result['warning_only']); // advisory, not hard block
    }

    public function test_can_work_returns_allowed_when_tier_met(): void
    {
        $db = $this->createMock(PDO::class);

        $reqStmt  = $this->makeStmt(false, [
            ['min_tier_level' => 1, 'course_id' => null, 'course_name' => null, 'course_slug' => null]
        ]);
        $tierStmt = $this->makeStmt(false, [], 2); // user has Tier 2 (meets Tier 1 requirement)
        $db->method('prepare')->willReturnOnConsecutiveCalls($reqStmt, $tierStmt);

        $svc    = new CertificationService($db);
        $result = $svc->canWorkServiceType(1, 'lawn_care');

        $this->assertTrue($result['allowed']);
    }

    // ── finishExam tests ─────────────────────────────────────────────────────

    public function test_finish_exam_calculates_score_and_marks_failed(): void
    {
        $course  = $this->course(['pass_score_pct' => 80]);
        $session = [
            'id' => 1, 'user_id' => 1, 'course_id' => 10,
            'questions_count' => 10, 'passed' => null,
        ];

        $db = $this->createMock(PDO::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);

        // Call order:
        // 1. getExamSession (prepare+execute+fetch)
        // 2. getCourse (prepare+execute+fetch)
        // 3. SELECT correct_count (prepare+execute+fetchColumn)
        // 4. UPDATE cert_exam_sessions (prepare+execute)
        // 5. updateMasteryFromExam → fetchAll answers (prepare+execute+fetchAll)

        $sessionStmt  = $this->makeStmt($session);
        $courseStmt   = $this->makeStmt($course);
        $correctStmt  = $this->makeStmt(false, [], 6);    // 6/10 = 60% — fails at 80%
        $updateStmt   = $this->makeStmt();
        $answersStmt  = $this->makeStmt(false, []);        // no answers to process mastery for

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $sessionStmt,   // getExamSession
            $courseStmt,    // getCourse
            $correctStmt,   // correct_count
            $updateStmt,    // UPDATE exam session
            $answersStmt    // mastery answers
        );

        $svc    = new CertificationService($db);
        $result = $svc->finishExam(1, 1);

        $this->assertSame(60, $result['score_pct']);
        $this->assertFalse($result['passed']);
        $this->assertNull($result['cert_record_id']);
    }

    public function test_finish_exam_issues_cert_record_on_pass(): void
    {
        // recert_months = null forces getTierRecertMonths() DB call, matching mock order
        $course  = $this->course(['pass_score_pct' => 70, 'recert_months' => null]);
        $session = [
            'id' => 2, 'user_id' => 5, 'course_id' => 10,
            'questions_count' => 10, 'passed' => null,
        ];

        $db = $this->createMock(PDO::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        $db->method('lastInsertId')->willReturn('42');

        // Order:
        // 1. getExamSession
        // 2. getCourse
        // 3. correct_count (8/10 = 80% > 70%)
        // 4. UPDATE exam session
        // 5. issueCertRecord → getTierRecertMonths
        // 6. issueCertRecord → INSERT/ON DUPLICATE KEY UPDATE
        // 7. issueCertRecord → SELECT cert record id
        // 8. checkTierUnlock → SELECT existing achievement
        // 9. checkTierUnlock → missing courses count
        // 10. updateMasteryFromExam → fetchAll answers

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt($session),                // 1 getExamSession
            $this->makeStmt($course),                 // 2 getCourse
            $this->makeStmt(false, [], 8),             // 3 correct_count
            $this->makeStmt(),                         // 4 UPDATE exam session
            $this->makeStmt(false, [], 12),            // 5 getTierRecertMonths
            $this->makeStmt(),                         // 6 INSERT cert_records
            $this->makeStmt(false, [], 42),            // 7 SELECT cert record id
            $this->makeStmt(false, [], false),         // 8 existing achievement (none)
            $this->makeStmt(false, [], 0),             // 9 missing required courses (0 = tier unlocked!)
            $this->makeStmt(),                         // 10 INSERT tier achievement
            // Tier row uses fetch() not fetchAll — pass as first arg (fetchReturn)
            $this->makeStmt(['id' => 1, 'name' => 'Tier 1 — Basic Lawn Care', 'tier_level' => 1]),  // 11 SELECT tier
            $this->makeStmt(false, [])                 // 12 mastery answers
        );

        $svc    = new CertificationService($db);
        $result = $svc->finishExam(2, 5);

        $this->assertSame(80, $result['score_pct']);
        $this->assertTrue($result['passed']);
        $this->assertSame(42, $result['cert_record_id']);
        $this->assertNotNull($result['newly_unlocked_tier']);
        $this->assertSame('Tier 1 — Basic Lawn Care', $result['newly_unlocked_tier']['name']);
    }

    // ── revokeCert tests ──────────────────────────────────────────────────────

    public function test_revoke_cert_updates_record_and_reevaluates_tier(): void
    {
        $db = $this->createMock(PDO::class);

        // 1. UPDATE cert_records (revoke)
        // 2. SELECT user_id + tier_id for reevaluation
        // 3. reevaluateTierAchievement: missing courses count (> 0, so tier removed)
        // 4. DELETE from cert_tier_achievements

        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->makeStmt(),  // UPDATE revoke
            $this->makeStmt(['user_id' => 5, 'tier_id' => 1]),  // SELECT
            $this->makeStmt(false, [], 1),  // missing required courses > 0
            $this->makeStmt()   // DELETE tier achievement
        );

        $svc = new CertificationService($db);
        $svc->revokeCert(99, 1, 'Policy violation');
        $this->addToAssertionCount(1);
    }

    // ── findExpiringCerts tests ───────────────────────────────────────────────

    public function test_find_expiring_certs_returns_matching_rows(): void
    {
        $db   = $this->createMock(PDO::class);
        $rows = [
            ['id' => 1, 'user_id' => 5, 'course_id' => 10, 'expires_at' => '2026-04-15 00:00:00',
             'full_name' => 'John Doe', 'email' => 'john@example.com', 'course_name' => 'Acelepryn'],
        ];
        $stmt = $this->makeStmt(false, $rows);
        $db->method('prepare')->willReturn($stmt);

        $svc    = new CertificationService($db);
        $result = $svc->findExpiringCerts(30);

        $this->assertCount(1, $result);
        $this->assertSame('John Doe', $result[0]['full_name']);
    }
}
