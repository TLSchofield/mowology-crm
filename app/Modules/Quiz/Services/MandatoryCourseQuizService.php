<?php
declare(strict_types=1);

/**
 * MandatoryCourseQuizService
 *
 * While a person has not yet mastered the required Hydrangea Masterclass course, their daily
 * (pre-shift) quiz is made of that course's questions. Mastered = every active course question
 * is at quiz_user_mastery level 4 ("Mastered") or higher for that person.
 *
 * Every public method FAILS OPEN: on any error it answers "nothing pending" so the daily quiz
 * falls back to the normal picker. This gate sits in front of clock-in, so a bug here must
 * never leave the crew without a quiz or locked out.
 *
 * Respects PreshiftGate: if the business has the daily quiz off, or this person is set to skip
 * it, nothing is pending for them.
 */
class MandatoryCourseQuizService
{
    public const COURSE_SLUG    = 'hydrangea-masterclass';
    public const MASTERED_LEVEL = 4;
    public const ROUND_LENGTH   = 10; // an allowed session length (3, 5 or 10)

    /**
     * The course id the user must still master, or null when nothing is pending.
     */
    public static function pendingCourseId(PDO $db, int $userId): ?int
    {
        try {
            if (!PreshiftGate::isRequiredFor($db, $userId)) {
                return null;
            }

            $course = $db->prepare(
                "SELECT id FROM cert_courses WHERE slug = ? AND is_active = 1 AND is_required = 1 LIMIT 1"
            );
            $course->execute([self::COURSE_SLUG]);
            $courseId = (int)$course->fetchColumn();
            if ($courseId <= 0) {
                return null;
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN COALESCE(m.mastery_level, 0) >= ? THEN 1 ELSE 0 END) AS mastered
                   FROM cert_course_questions ccq
                   JOIN quiz_questions q ON q.id = ccq.question_id
                        AND q.is_active = 1 AND q.question_text IS NOT NULL AND TRIM(q.question_text) != ''
                   LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                  WHERE ccq.course_id = ?"
            );
            $stmt->execute([self::MASTERED_LEVEL, $userId, $courseId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $total    = (int)$row['total'];
            $mastered = (int)$row['mastered'];
            // Fewer usable questions than a full round would make a short session while the gates
            // advertise ROUND_LENGTH (the crew web page asks for question ROUND_LENGTH and stalls).
            return ($total >= self::ROUND_LENGTH && $mastered < $total) ? $courseId : null;
        } catch (Throwable $e) {
            error_log('MandatoryCourseQuizService::pendingCourseId: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Question ids for one daily round: the weakest course questions first (not mastered, not
     * answered in the last 20 hours, lowest level), padded with the rest if fewer are left.
     *
     * @return int[] empty on any problem, so the caller falls back to the normal picker
     */
    public static function questionIds(PDO $db, int $userId, int $courseId, int $length): array
    {
        try {
            $length = max(1, min(self::ROUND_LENGTH, $length));
            $stmt = $db->prepare(
                "SELECT q.id
                   FROM cert_course_questions ccq
                   JOIN quiz_questions q ON q.id = ccq.question_id
                        AND q.is_active = 1 AND q.question_text IS NOT NULL AND TRIM(q.question_text) != ''
                   LEFT JOIN quiz_user_mastery m ON m.question_id = q.id AND m.user_id = ?
                  WHERE ccq.course_id = ?
                  ORDER BY (COALESCE(m.mastery_level, 0) >= ?) ASC,
                           (m.last_seen_at IS NOT NULL AND m.last_seen_at >= DATE_SUB(NOW(), INTERVAL 20 HOUR)) ASC,
                           COALESCE(m.mastery_level, 0) ASC,
                           RAND()
                  LIMIT {$length}"
            );
            $stmt->execute([$userId, $courseId, self::MASTERED_LEVEL]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            error_log('MandatoryCourseQuizService::questionIds: ' . $e->getMessage());
            return [];
        }
    }

    /** Session length for the round: the course length while pending, else the normal one. */
    public static function lengthFor(?int $pendingCourseId, int $default): int
    {
        return $pendingCourseId !== null ? self::ROUND_LENGTH : $default;
    }

    /** Wording the apps already show from the start response (season label, icon, tagline). */
    public static function label(): array
    {
        return [
            'season_label'   => 'Hydrangea Masterclass',
            'season_icon'    => '🌸',
            'season_tagline' => 'Your daily quiz is the hydrangea course until you have mastered it',
        ];
    }
}
