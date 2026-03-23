<?php
declare(strict_types=1);
/**
 * CertificationService
 *
 * Manages crew certification exams, tier unlocking, and job-type gating.
 * All methods take an explicit PDO to remain testable without side effects.
 */
class CertificationService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Exam session management ──────────────────────────────────────────────

    /**
     * Start a certification exam for a user on a specific course.
     * Validates eligibility (prereqs, attempts, cooldown), then selects
     * questions and creates a cert_exam_sessions row.
     *
     * Returns ['exam_session_id' => int, 'questions_count' => int,
     *          'course_name' => string, 'pass_score_pct' => int,
     *          'attempt_number' => int]
     * Throws \RuntimeException on ineligibility.
     */
    public function startExam(int $userId, int $courseId): array
    {
        $elig = $this->checkEligibility($userId, $courseId);
        if (!$elig['eligible']) {
            throw new \RuntimeException($elig['reason'] ?? 'Not eligible to take this exam.');
        }

        $course = $this->getCourse($courseId);
        if (!$course) {
            throw new \RuntimeException('Course not found.');
        }

        $questionIds = $this->selectExamQuestions($courseId, $userId);
        if (empty($questionIds)) {
            throw new \RuntimeException('No questions available for this course. Please contact an admin.');
        }

        $attemptNumber = $elig['attempt_number'];

        $stmt = $this->db->prepare(
            "INSERT INTO cert_exam_sessions
             (user_id, course_id, question_ids, questions_count, attempt_number)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $courseId,
            implode(',', $questionIds),
            count($questionIds),
            $attemptNumber,
        ]);
        $examId = (int)$this->db->lastInsertId();

        return [
            'exam_session_id' => $examId,
            'questions_count' => count($questionIds),
            'course_name'     => $course['name'],
            'pass_score_pct'  => (int)$course['pass_score_pct'],
            'attempt_number'  => $attemptNumber,
        ];
    }

    /**
     * Submit one answer to an in-progress exam.
     * Returns ['is_correct' => bool, 'correct_option_id' => int|null,
     *          'answered_count' => int, 'questions_count' => int,
     *          'correct_option_text' => string]
     */
    public function submitAnswer(
        int $examSessionId,
        int $questionId,
        ?int $selectedOptionId,
        int $timeTaken
    ): array {
        $session = $this->getExamSession($examSessionId);
        if (!$session) {
            throw new \RuntimeException('Exam session not found.');
        }
        if ($session['passed'] !== null) {
            throw new \RuntimeException('Exam is already completed.');
        }

        // Get correct option
        $stmt = $this->db->prepare(
            "SELECT id, option_text FROM quiz_options WHERE question_id = ? AND is_correct = 1 LIMIT 1"
        );
        $stmt->execute([$questionId]);
        $correct = $stmt->fetch(PDO::FETCH_ASSOC);

        $isCorrect = $correct && $selectedOptionId === (int)$correct['id'];

        $stmt = $this->db->prepare(
            "INSERT INTO cert_exam_answers
             (exam_session_id, question_id, selected_option_id, is_correct, time_taken_seconds)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $examSessionId,
            $questionId,
            $selectedOptionId,
            $isCorrect ? 1 : 0,
            $timeTaken,
        ]);

        // Count answers so far
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cert_exam_answers WHERE exam_session_id = ?"
        );
        $stmt->execute([$examSessionId]);
        $answeredCount = (int)$stmt->fetchColumn();

        return [
            'is_correct'          => $isCorrect,
            'correct_option_id'   => $correct ? (int)$correct['id'] : null,
            'correct_option_text' => $correct ? ($correct['option_text'] ?? '') : '',
            'answered_count'      => $answeredCount,
            'questions_count'     => (int)$session['questions_count'],
        ];
    }

    /**
     * Complete an exam session. Computes score, writes cert_record on pass,
     * checks for tier unlock. Updates quiz_user_mastery for spaced rep.
     *
     * Returns ['score_pct' => int, 'passed' => bool, 'correct_count' => int,
     *          'questions_count' => int, 'pass_score_pct' => int,
     *          'cert_record_id' => int|null, 'newly_unlocked_tier' => array|null]
     */
    public function finishExam(int $examSessionId, int $userId): array
    {
        $session = $this->getExamSession($examSessionId);
        if (!$session) {
            throw new \RuntimeException('Exam session not found.');
        }
        if ((int)$session['user_id'] !== $userId) {
            throw new \RuntimeException('Access denied.');
        }
        if ($session['passed'] !== null) {
            throw new \RuntimeException('Exam already finished.');
        }

        $course = $this->getCourse((int)$session['course_id']);
        if (!$course) {
            throw new \RuntimeException('Course not found.');
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cert_exam_answers WHERE exam_session_id = ? AND is_correct = 1"
        );
        $stmt->execute([$examSessionId]);
        $correctCount = (int)$stmt->fetchColumn();

        $questionsCount = (int)$session['questions_count'];
        $scorePct = $questionsCount > 0
            ? (int)round(($correctCount / $questionsCount) * 100)
            : 0;

        $requiredPct = (int)$course['pass_score_pct'];
        $passed = $scorePct >= $requiredPct;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE cert_exam_sessions
                 SET correct_count = ?, score_pct = ?, passed = ?, completed_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([$correctCount, $scorePct, $passed ? 1 : 0, $examSessionId]);

            $certRecordId = null;
            $newlyUnlockedTier = null;

            if ($passed) {
                $certRecordId = $this->issueCertRecord(
                    $userId, (int)$course['id'], (int)$course['tier_id'],
                    $examSessionId, $scorePct, $course
                );
                $newlyUnlockedTier = $this->checkTierUnlock($userId, (int)$course['tier_id']);
            }

            // Update spaced-rep mastery for all answered questions
            $this->updateMasteryFromExam($examSessionId, $userId, $passed);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'score_pct'           => $scorePct,
            'passed'              => $passed,
            'correct_count'       => $correctCount,
            'questions_count'     => $questionsCount,
            'pass_score_pct'      => $requiredPct,
            'cert_record_id'      => $certRecordId,
            'newly_unlocked_tier' => $newlyUnlockedTier,
        ];
    }

    // ── Eligibility & gating ─────────────────────────────────────────────────

    /**
     * Check whether a user can start a given course exam.
     * Returns ['eligible' => bool, 'reason' => string|null,
     *          'attempts_remaining' => int, 'attempt_number' => int,
     *          'cooldown_ends_at' => string|null]
     */
    public function checkEligibility(int $userId, int $courseId): array
    {
        $course = $this->getCourse($courseId);
        if (!$course || !(int)$course['is_active']) {
            return ['eligible' => false, 'reason' => 'Course not found or inactive.',
                    'attempts_remaining' => 0, 'attempt_number' => 1, 'cooldown_ends_at' => null];
        }

        // Check tier prereqs: all required courses in lower tiers must be passed
        $tierLevel = (int)$this->getTierLevel((int)$course['tier_id']);
        if ($tierLevel > 1) {
            $missing = $this->getMissingPrereqs($userId, $tierLevel);
            if (!empty($missing)) {
                return [
                    'eligible'          => false,
                    'reason'            => 'Complete all required Tier ' . ($tierLevel - 1) . ' courses first: ' . implode(', ', $missing),
                    'attempts_remaining'=> 0,
                    'attempt_number'    => 1,
                    'cooldown_ends_at'  => null,
                ];
            }
        }

        // Already passed and active?
        $stmt = $this->db->prepare(
            "SELECT status FROM cert_records WHERE user_id = ? AND course_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $courseId]);
        $existing = $stmt->fetchColumn();
        if ($existing === 'active') {
            return ['eligible' => false, 'reason' => 'You have already passed this course.',
                    'attempts_remaining' => 0, 'attempt_number' => 1, 'cooldown_ends_at' => null];
        }

        // Attempt count
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cert_exam_sessions WHERE user_id = ? AND course_id = ? AND passed IS NOT NULL"
        );
        $stmt->execute([$userId, $courseId]);
        $completedAttempts = (int)$stmt->fetchColumn();

        $maxAttempts = (int)$course['attempts_allowed'];
        $attemptsRemaining = $maxAttempts > 0 ? $maxAttempts - $completedAttempts : 999;
        $attemptNumber = $completedAttempts + 1;

        if ($maxAttempts > 0 && $completedAttempts >= $maxAttempts) {
            return ['eligible' => false,
                    'reason' => "Maximum attempts ({$maxAttempts}) reached for this course.",
                    'attempts_remaining' => 0, 'attempt_number' => $attemptNumber,
                    'cooldown_ends_at' => null];
        }

        // Cooldown: was the last attempt failed within cooldown_hours?
        $cooldownHours = (int)$course['cooldown_hours'];
        if ($cooldownHours > 0 && $completedAttempts > 0) {
            $stmt = $this->db->prepare(
                "SELECT completed_at FROM cert_exam_sessions
                 WHERE user_id = ? AND course_id = ? AND passed = 0
                 ORDER BY completed_at DESC LIMIT 1"
            );
            $stmt->execute([$userId, $courseId]);
            $lastFailed = $stmt->fetchColumn();
            if ($lastFailed) {
                $cooldownEnds = strtotime($lastFailed) + ($cooldownHours * 3600);
                if (time() < $cooldownEnds) {
                    return [
                        'eligible'           => false,
                        'reason'             => "Please wait {$cooldownHours} hours between attempts.",
                        'attempts_remaining' => $attemptsRemaining,
                        'attempt_number'     => $attemptNumber,
                        'cooldown_ends_at'   => date('Y-m-d H:i:s', $cooldownEnds),
                    ];
                }
            }
        }

        return [
            'eligible'           => true,
            'reason'             => null,
            'attempts_remaining' => $attemptsRemaining,
            'attempt_number'     => $attemptNumber,
            'cooldown_ends_at'   => null,
        ];
    }

    /**
     * Check if a user is certified to perform a service type.
     * Returns ['allowed' => bool, 'missing_tier' => int|null,
     *          'missing_course' => string|null, 'warning_only' => bool]
     */
    public function canWorkServiceType(int $userId, string $serviceTypeSlug): array
    {
        // Get requirements for this service type
        $stmt = $this->db->prepare(
            "SELECT r.min_tier_level, r.course_id, cc.name AS course_name, cc.slug AS course_slug
             FROM cert_service_type_requirements r
             JOIN service_types st ON st.id = r.service_type_id
             LEFT JOIN cert_courses cc ON cc.id = r.course_id
             WHERE st.slug = ?"
        );
        $stmt->execute([$serviceTypeSlug]);
        $requirements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($requirements)) {
            return ['allowed' => true, 'missing_tier' => null, 'missing_course' => null, 'warning_only' => false];
        }

        // Get user's highest achieved tier
        $stmt = $this->db->prepare(
            "SELECT MAX(ct.tier_level) FROM cert_tier_achievements cta
             JOIN cert_tiers ct ON ct.id = cta.tier_id
             WHERE cta.user_id = ?"
        );
        $stmt->execute([$userId]);
        $userTier = (int)($stmt->fetchColumn() ?: 0);

        foreach ($requirements as $req) {
            $minTier = (int)$req['min_tier_level'];
            if ($userTier < $minTier) {
                return [
                    'allowed'        => false,
                    'missing_tier'   => $minTier,
                    'missing_course' => null,
                    'warning_only'   => true, // advisory, not a hard block — admin can override
                ];
            }
            // Check specific course cert if required
            if ($req['course_id']) {
                $stmt = $this->db->prepare(
                    "SELECT id FROM cert_records
                     WHERE user_id = ? AND course_id = ? AND status = 'active' LIMIT 1"
                );
                $stmt->execute([$userId, (int)$req['course_id']]);
                if (!$stmt->fetchColumn()) {
                    return [
                        'allowed'        => false,
                        'missing_tier'   => null,
                        'missing_course' => $req['course_name'] ?? $req['course_slug'],
                        'warning_only'   => true,
                    ];
                }
            }
        }

        return ['allowed' => true, 'missing_tier' => null, 'missing_course' => null, 'warning_only' => false];
    }

    /**
     * Return all service type slugs the user is currently certified to perform.
     */
    public function getAllowedServiceTypes(int $userId): array
    {
        $stmt = $this->db->query("SELECT slug FROM service_types WHERE is_active = 1");
        $allSlugs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $allowed = [];
        foreach ($allSlugs as $slug) {
            $check = $this->canWorkServiceType($userId, $slug);
            if ($check['allowed']) {
                $allowed[] = $slug;
            }
        }
        return $allowed;
    }

    // ── Certification records ────────────────────────────────────────────────

    /**
     * Get a user's full certification profile structured by tier.
     * Returns nested array: [tier_level => ['tier' => ..., 'achieved' => bool, 'courses' => [...]]]
     */
    public function getUserCertProfile(int $userId): array
    {
        $tiers = $this->db->query(
            "SELECT * FROM cert_tiers WHERE is_active = 1 ORDER BY tier_level ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $courses = $this->db->query(
            "SELECT cc.*, ct.tier_level FROM cert_courses cc
             JOIN cert_tiers ct ON ct.id = cc.tier_id
             WHERE cc.is_active = 1 ORDER BY cc.sort_order ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT cr.*, cc.name AS course_name, cc.slug AS course_slug
             FROM cert_records cr
             JOIN cert_courses cc ON cc.id = cr.course_id
             WHERE cr.user_id = ?"
        );
        $stmt->execute([$userId]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recordsByCourse = [];
        foreach ($records as $r) {
            $recordsByCourse[$r['course_id']] = $r;
        }

        $stmt = $this->db->prepare(
            "SELECT tier_id FROM cert_tier_achievements WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $achievedTiers = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        // Get exam attempt counts per course
        $stmt = $this->db->prepare(
            "SELECT course_id, COUNT(*) AS attempts, MAX(score_pct) AS best_score,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passed_count
             FROM cert_exam_sessions WHERE user_id = ? AND passed IS NOT NULL
             GROUP BY course_id"
        );
        $stmt->execute([$userId]);
        $examStats = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $examStats[$r['course_id']] = $r;
        }

        // Get mastery readiness per course (questions with mastery >= 3 / total)
        $readiness = $this->getCourseReadiness($userId, array_column($courses, 'id'));

        $profile = [];
        foreach ($tiers as $tier) {
            $tierCourses = [];
            foreach ($courses as $c) {
                if ((int)$c['tier_level'] !== (int)$tier['tier_level']) continue;
                $rec   = $recordsByCourse[$c['id']] ?? null;
                $stats = $examStats[$c['id']] ?? null;
                $tierCourses[] = [
                    'course'           => $c,
                    'cert_record'      => $rec,
                    'status'           => $rec['status'] ?? 'not_started',
                    'best_score_pct'   => $stats ? (int)$stats['best_score'] : 0,
                    'attempts'         => $stats ? (int)$stats['attempts'] : 0,
                    'readiness_pct'    => $readiness[$c['id']] ?? 0,
                ];
            }
            $profile[(int)$tier['tier_level']] = [
                'tier'     => $tier,
                'achieved' => isset($achievedTiers[$tier['id']]),
                'courses'  => $tierCourses,
            ];
        }
        return $profile;
    }

    /**
     * Summary of all users' highest tier and active certs (admin overview).
     */
    public function getAllUserTierSummary(): array
    {
        $stmt = $this->db->query(
            "SELECT u.id AS user_id, u.full_name,
                    COALESCE(MAX(ct.tier_level), 0) AS highest_tier,
                    COUNT(cr.id) AS active_certs
             FROM users u
             LEFT JOIN cert_tier_achievements cta ON cta.user_id = u.id
             LEFT JOIN cert_tiers ct ON ct.id = cta.tier_id
             LEFT JOIN cert_records cr ON cr.user_id = u.id AND cr.status = 'active'
             WHERE u.is_active = 1
             GROUP BY u.id, u.full_name
             ORDER BY highest_tier DESC, active_certs DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Revoke a cert record (admin action).
     */
    public function revokeCert(int $certRecordId, int $revokedBy, string $reason): void
    {
        $stmt = $this->db->prepare(
            "UPDATE cert_records
             SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
             WHERE id = ?"
        );
        $stmt->execute([$revokedBy, $reason, $certRecordId]);

        // If revoke causes tier achievement to be invalid, remove it
        $stmt = $this->db->prepare(
            "SELECT cr.user_id, cc.tier_id FROM cert_records cr
             JOIN cert_courses cc ON cc.id = cr.course_id
             WHERE cr.id = ?"
        );
        $stmt->execute([$certRecordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->reevaluateTierAchievement((int)$row['user_id'], (int)$row['tier_id']);
        }
    }

    // ── Expiry management ────────────────────────────────────────────────────

    /**
     * Find cert records expiring within $daysAhead days that haven't been notified.
     */
    public function findExpiringCerts(int $daysAhead = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT cr.*, cc.name AS course_name, u.full_name, u.email
             FROM cert_records cr
             JOIN cert_courses cc ON cc.id = cr.course_id
             JOIN users u ON u.id = cr.user_id
             WHERE cr.status = 'active'
               AND cr.expires_at IS NOT NULL
               AND cr.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
               AND cr.expires_at > NOW()
               AND cr.recert_notified_at IS NULL"
        );
        $stmt->execute([$daysAhead]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Mark a cert record's expiry notification as sent.
     */
    public function markExpiryNotified(int $certRecordId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE cert_records SET recert_notified_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$certRecordId]);
    }

    /**
     * Mark expired certs (called by cron nightly).
     */
    public function expireOverdueCerts(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE cert_records SET status = 'expired'
             WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ── Admin course/tier management ─────────────────────────────────────────

    /**
     * List all courses, optionally filtered by tier_id.
     */
    public function listCourses(?int $tierId = null): array
    {
        if ($tierId !== null) {
            $stmt = $this->db->prepare(
                "SELECT cc.*, ct.name AS tier_name, ct.tier_level,
                        (SELECT COUNT(*) FROM cert_course_questions ccq WHERE ccq.course_id = cc.id) AS question_count
                 FROM cert_courses cc JOIN cert_tiers ct ON ct.id = cc.tier_id
                 WHERE cc.tier_id = ? ORDER BY cc.sort_order ASC"
            );
            $stmt->execute([$tierId]);
        } else {
            $stmt = $this->db->query(
                "SELECT cc.*, ct.name AS tier_name, ct.tier_level,
                        (SELECT COUNT(*) FROM cert_course_questions ccq WHERE ccq.course_id = cc.id) AS question_count
                 FROM cert_courses cc JOIN cert_tiers ct ON ct.id = cc.tier_id
                 ORDER BY ct.tier_level ASC, cc.sort_order ASC"
            );
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List all tiers with course counts.
     */
    public function listTiers(): array
    {
        return $this->db->query(
            "SELECT ct.*,
                    (SELECT COUNT(*) FROM cert_courses cc WHERE cc.tier_id = ct.id AND cc.is_active = 1) AS course_count,
                    (SELECT COUNT(*) FROM cert_tier_achievements cta WHERE cta.tier_id = ct.id) AS achieved_count
             FROM cert_tiers ct ORDER BY ct.tier_level ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Assign a question to a course (or update weight/is_required).
     */
    public function assignQuestionToCourse(int $courseId, int $questionId, bool $isRequired = false, int $weight = 1): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO cert_course_questions (course_id, question_id, is_required, weight)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_required = VALUES(is_required), weight = VALUES(weight)"
        );
        $stmt->execute([$courseId, $questionId, $isRequired ? 1 : 0, $weight]);
    }

    /**
     * Remove a question from a course.
     */
    public function removeQuestionFromCourse(int $courseId, int $questionId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM cert_course_questions WHERE course_id = ? AND question_id = ?"
        );
        $stmt->execute([$courseId, $questionId]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function getCourse(int $courseId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cert_courses WHERE id = ? LIMIT 1");
        $stmt->execute([$courseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getExamSession(int $sessionId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM cert_exam_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getTierLevel(int $tierId): int
    {
        $stmt = $this->db->prepare("SELECT tier_level FROM cert_tiers WHERE id = ? LIMIT 1");
        $stmt->execute([$tierId]);
        return (int)($stmt->fetchColumn() ?: 1);
    }

    /**
     * Return names of required courses in tiers below $targetTierLevel
     * that the user has not yet passed.
     */
    private function getMissingPrereqs(int $userId, int $targetTierLevel): array
    {
        $stmt = $this->db->prepare(
            "SELECT cc.name FROM cert_courses cc
             JOIN cert_tiers ct ON ct.id = cc.tier_id
             WHERE ct.tier_level < ?
               AND cc.is_required = 1
               AND cc.is_active = 1
               AND cc.id NOT IN (
                   SELECT course_id FROM cert_records
                   WHERE user_id = ? AND status = 'active'
               )
             ORDER BY ct.tier_level ASC, cc.sort_order ASC"
        );
        $stmt->execute([$targetTierLevel, $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Select question IDs for a certification exam.
     * Always includes is_required questions, then fills by weighted random sampling.
     * Never repeats questions the user got correct in a recent exam for this course.
     */
    private function selectExamQuestions(int $courseId, int $userId): array
    {
        $course = $this->getCourse($courseId);
        $maxQ = (int)($course['max_questions'] ?? 25);
        $minQ = (int)($course['min_questions'] ?? 10);

        // Required questions
        $stmt = $this->db->prepare(
            "SELECT ccq.question_id FROM cert_course_questions ccq
             JOIN quiz_questions qq ON qq.id = ccq.question_id
             WHERE ccq.course_id = ? AND ccq.is_required = 1 AND qq.is_active = 1"
        );
        $stmt->execute([$courseId]);
        $required = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $selected = array_map('intval', $required);

        // Exclude questions the user got right in the last 48 hours for this course
        $stmt = $this->db->prepare(
            "SELECT DISTINCT cea.question_id FROM cert_exam_answers cea
             JOIN cert_exam_sessions ces ON ces.id = cea.exam_session_id
             WHERE ces.user_id = ? AND ces.course_id = ? AND cea.is_correct = 1
               AND cea.answered_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)"
        );
        $stmt->execute([$userId, $courseId]);
        $recentlyCorrect = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Fill remaining slots with weighted random from the pool
        $remaining = $maxQ - count($selected);
        if ($remaining > 0) {
            $exclude = array_unique(array_merge($selected, $recentlyCorrect));
            $excl = implode(',', array_map('intval', $exclude ?: [0]));

            // Weight-aware sampling: expand rows by weight (max weight 5), then RAND
            $stmt = $this->db->prepare(
                "SELECT ccq.question_id FROM cert_course_questions ccq
                 JOIN quiz_questions qq ON qq.id = ccq.question_id
                 WHERE ccq.course_id = ? AND qq.is_active = 1
                   AND ccq.question_id NOT IN ($excl)
                 ORDER BY -(RAND() * COALESCE(ccq.weight, 1)) ASC
                 LIMIT $remaining"
            );
            $stmt->execute([$courseId]);
            $extra = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $selected = array_merge($selected, $extra);
        }

        // Pad with recently-correct if still under min
        if (count($selected) < $minQ && !empty($recentlyCorrect)) {
            $need = $minQ - count($selected);
            $pad = array_slice($recentlyCorrect, 0, $need);
            $selected = array_merge($selected, $pad);
        }

        shuffle($selected);
        return array_unique($selected);
    }

    /**
     * Issue or update a cert_record after a passing exam.
     * Returns the cert_record ID.
     */
    private function issueCertRecord(
        int $userId, int $courseId, int $tierId,
        int $examSessionId, int $scorePct, array $course
    ): int {
        // Calculate expiry
        $recertMonths = $course['recert_months'] !== null
            ? (int)$course['recert_months']
            : (int)($this->getTierRecertMonths($tierId));

        $expiresAt = $recertMonths > 0
            ? date('Y-m-d H:i:s', strtotime("+{$recertMonths} months"))
            : null;

        // Upsert (a user may be re-certifying)
        $stmt = $this->db->prepare(
            "INSERT INTO cert_records
                 (user_id, course_id, tier_id, exam_session_id, status, best_score_pct, issued_at, expires_at)
             VALUES (?, ?, ?, ?, 'active', ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                 exam_session_id = VALUES(exam_session_id),
                 status          = 'active',
                 best_score_pct  = GREATEST(best_score_pct, VALUES(best_score_pct)),
                 issued_at       = NOW(),
                 expires_at      = VALUES(expires_at),
                 revoked_at      = NULL,
                 revoked_by      = NULL,
                 revoke_reason   = NULL,
                 recert_notified_at = NULL"
        );
        $stmt->execute([$userId, $courseId, $tierId, $examSessionId, $scorePct, $expiresAt]);

        $stmt = $this->db->prepare(
            "SELECT id FROM cert_records WHERE user_id = ? AND course_id = ? LIMIT 1"
        );
        $stmt->execute([$userId, $courseId]);
        return (int)$stmt->fetchColumn();
    }

    private function getTierRecertMonths(int $tierId): int
    {
        $stmt = $this->db->prepare("SELECT recert_months FROM cert_tiers WHERE id = ? LIMIT 1");
        $stmt->execute([$tierId]);
        return (int)($stmt->fetchColumn() ?: 12);
    }

    /**
     * Check if passing this course completes all required courses in its tier,
     * unlocking a tier achievement. Returns the tier row if newly unlocked, else null.
     */
    private function checkTierUnlock(int $userId, int $tierId): ?array
    {
        // Already achieved?
        $stmt = $this->db->prepare(
            "SELECT id FROM cert_tier_achievements WHERE user_id = ? AND tier_id = ?"
        );
        $stmt->execute([$userId, $tierId]);
        if ($stmt->fetchColumn()) return null;

        // All required courses for this tier passed?
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cert_courses cc
             WHERE cc.tier_id = ? AND cc.is_required = 1 AND cc.is_active = 1
               AND cc.id NOT IN (
                   SELECT course_id FROM cert_records
                   WHERE user_id = ? AND status = 'active'
               )"
        );
        $stmt->execute([$tierId, $userId]);
        $missing = (int)$stmt->fetchColumn();
        if ($missing > 0) return null;

        // Unlock!
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO cert_tier_achievements (user_id, tier_id) VALUES (?, ?)"
        );
        $stmt->execute([$userId, $tierId]);

        $stmt = $this->db->prepare("SELECT * FROM cert_tiers WHERE id = ?");
        $stmt->execute([$tierId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Re-evaluate whether a user still qualifies for a tier achievement
     * (called after cert revocation).
     */
    private function reevaluateTierAchievement(int $userId, int $tierId): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM cert_courses cc
             WHERE cc.tier_id = ? AND cc.is_required = 1 AND cc.is_active = 1
               AND cc.id NOT IN (
                   SELECT course_id FROM cert_records
                   WHERE user_id = ? AND status = 'active'
               )"
        );
        $stmt->execute([$tierId, $userId]);
        $missing = (int)$stmt->fetchColumn();
        if ($missing > 0) {
            $stmt = $this->db->prepare(
                "DELETE FROM cert_tier_achievements WHERE user_id = ? AND tier_id = ?"
            );
            $stmt->execute([$userId, $tierId]);
        }
    }

    /**
     * Update quiz_user_mastery for all questions answered in an exam.
     * Correct answers increase mastery; wrong answers decrease it (floor 0).
     */
    private function updateMasteryFromExam(int $examSessionId, int $userId, bool $examPassed): void
    {
        $stmt = $this->db->prepare(
            "SELECT question_id, is_correct FROM cert_exam_answers WHERE exam_session_id = ?"
        );
        $stmt->execute([$examSessionId]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($answers as $a) {
            $qId      = (int)$a['question_id'];
            $correct  = (bool)$a['is_correct'];

            // Fetch existing mastery
            $stmt2 = $this->db->prepare(
                "SELECT id, mastery_level FROM quiz_user_mastery WHERE user_id = ? AND question_id = ? LIMIT 1"
            );
            $stmt2->execute([$userId, $qId]);
            $mastery = $stmt2->fetch(PDO::FETCH_ASSOC);

            if ($mastery) {
                $newLevel = $correct
                    ? min(5, (int)$mastery['mastery_level'] + 1)
                    : max(0, (int)$mastery['mastery_level'] - 1);
                $nextReview = $correct
                    ? date('Y-m-d H:i:s', strtotime('+' . (2 ** $newLevel) . ' days'))
                    : date('Y-m-d H:i:s', strtotime('+1 day'));
                $stmt3 = $this->db->prepare(
                    "UPDATE quiz_user_mastery
                     SET mastery_level = ?, last_answered_at = NOW(), next_review_at = ?,
                         correct_streak = IF(? = 1, correct_streak + 1, 0)
                     WHERE id = ?"
                );
                $stmt3->execute([$newLevel, $nextReview, $correct ? 1 : 0, (int)$mastery['id']]);
            } else {
                $newLevel   = $correct ? 1 : 0;
                $nextReview = $correct
                    ? date('Y-m-d H:i:s', strtotime('+2 days'))
                    : date('Y-m-d H:i:s', strtotime('+1 day'));
                $stmt3 = $this->db->prepare(
                    "INSERT INTO quiz_user_mastery
                     (user_id, question_id, mastery_level, last_answered_at, next_review_at, correct_streak)
                     VALUES (?, ?, ?, NOW(), ?, ?)
                     ON DUPLICATE KEY UPDATE mastery_level = VALUES(mastery_level),
                     last_answered_at = NOW(), next_review_at = VALUES(next_review_at)"
                );
                $stmt3->execute([$userId, $qId, $newLevel, $nextReview, $correct ? 1 : 0]);
            }
        }
    }

    /**
     * Compute readiness % (questions with mastery >= 3) for each course.
     * Returns [course_id => readiness_pct].
     */
    private function getCourseReadiness(int $userId, array $courseIds): array
    {
        if (empty($courseIds)) return [];
        $ids = implode(',', array_map('intval', $courseIds));

        $stmt = $this->db->prepare(
            "SELECT ccq.course_id,
                    COUNT(ccq.question_id)                                          AS total_q,
                    COUNT(CASE WHEN COALESCE(m.mastery_level, 0) >= 3 THEN 1 END) AS mastered_q
             FROM cert_course_questions ccq
             LEFT JOIN quiz_user_mastery m
               ON m.question_id = ccq.question_id AND m.user_id = ?
             WHERE ccq.course_id IN ($ids)
             GROUP BY ccq.course_id"
        );
        $stmt->execute([$userId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $total = (int)$r['total_q'];
            $mastered = (int)$r['mastered_q'];
            $result[(int)$r['course_id']] = $total > 0 ? (int)round(($mastered / $total) * 100) : 0;
        }
        return $result;
    }
}
