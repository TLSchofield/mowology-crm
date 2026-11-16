<?php
declare(strict_types=1);

/**
 * PreshiftGate
 *
 * The one answer to "should the pre-shift quiz be shown?"
 *
 * The quiz is on only when the business has switched it on AND has at least one active
 * question with text. A switched-on quiz with no questions cannot be taken or passed, so
 * it must read as off everywhere (web, mobile, app-home, driver portal). Every reader asks
 * here so the two conditions cannot drift apart.
 */
class PreshiftGate
{
    /** Business-level: switched on and at least one usable active question. */
    public static function isActive(PDO $db): bool
    {
        if (self::settingValue($db, 'quiz_preshift_enabled') !== '1') {
            return false;
        }
        return self::usableQuestionCount($db) >= 1;
    }

    /** Per-user: active for the business and not skipped for this user. */
    public static function isRequiredFor(PDO $db, int $userId): bool
    {
        if (!self::isActive($db)) {
            return false;
        }
        return !self::userSkips($db, $userId);
    }

    /** Active questions that have text (the same rule the question picker uses). */
    public static function usableQuestionCount(PDO $db): int
    {
        try {
            $stmt = $db->query(
                "SELECT COUNT(*) FROM quiz_questions
                  WHERE is_active = 1 AND question_text IS NOT NULL AND TRIM(question_text) != ''"
            );
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0; // quiz tables unavailable: nothing to take, so the quiz is off
        }
    }

    private static function settingValue(PDO $db, string $key): ?string
    {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value === false ? null : (string)$value;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function userSkips(PDO $db, int $userId): bool
    {
        // Column may not exist until migration 1015 runs; absent means not skipped.
        try {
            $stmt = $db->prepare("SELECT quiz_preshift_skip FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
