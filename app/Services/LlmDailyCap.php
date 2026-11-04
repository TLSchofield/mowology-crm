<?php
/**
 * LlmDailyCap — a hard daily ceiling on paid AI calls per purpose (security review, low, 2026-10-12).
 *
 * Receipts can arrive from outside (email), and each one can cost a Vision OCR and one or two Claude
 * calls; a flood of mail could run up the bill. take() counts one call against today's ceiling and
 * says whether it may go ahead. The counter lives in ops_settings (llm_calls_<bucket> = "YYYY-MM-DD:N",
 * updated in one statement, so parallel crons can't both take the last slot); the ceiling is
 * ops_settings llm_daily_cap_<bucket>, else the CLAUDE_DAILY_CAP constant, else the default given.
 * Without ops_settings (tests, fresh install) it allows the call — the ceiling is a cost guard,
 * never the reason a receipt is lost (the caller falls back to the plain parser).
 *
 * Global namespace; require_once it.
 */
declare(strict_types=1);

final class LlmDailyCap
{
    /** Ceiling for a bucket. */
    public static function cap(PDO $db, string $bucket, int $default): int
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute(['llm_daily_cap_' . $bucket]);
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return max(0, (int)$v);
        } catch (Throwable $e) { /* default */ }
        return defined('CLAUDE_DAILY_CAP') ? max(0, (int)constant('CLAUDE_DAILY_CAP')) : $default;
    }

    /** Calls already taken today ("YYYY-MM-DD:N" for today, else 0). PURE. */
    public static function usedToday(?string $stored, string $today): int
    {
        if ($stored === null || !preg_match('/^(\d{4}-\d{2}-\d{2}):(\d+)$/', $stored, $m)) return 0;
        return $m[1] === $today ? (int)$m[2] : 0;
    }

    /** Take one call from today's allowance; false = the ceiling is reached, don't call. */
    public static function take(PDO $db, string $bucket, int $default, bool $failClosed = false): bool
    {
        $cap = self::cap($db, $bucket, $default);
        $key = 'llm_calls_' . $bucket;
        $today = date('Y-m-d');
        try {
            // Increment only while under the cap, in one statement.
            $up = $db->prepare("UPDATE ops_settings
                                   SET setting_value = CONCAT(?, ':', IF(LEFT(setting_value, 10) = ?, CAST(SUBSTRING_INDEX(setting_value, ':', -1) AS UNSIGNED), 0) + 1)
                                 WHERE setting_key = ?
                                   AND IF(LEFT(setting_value, 10) = ?, CAST(SUBSTRING_INDEX(setting_value, ':', -1) AS UNSIGNED), 0) < ?");
            $up->execute([$today, $today, $key, $today, $cap]);
            if ($up->rowCount() === 1) return true;
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $cur = $s->fetchColumn();
            if ($cur === false) {
                if ($cap <= 0) return false;
                $db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, 'AI calls used today (LlmDailyCap)')")
                   ->execute([$key, $today . ':1']);
                return true;
            }
            return false;   // the row exists and is at the cap
        } catch (Throwable $e) {
            error_log('LlmDailyCap: ' . $e->getMessage());
            // Money-adjacent buckets pass $failClosed so an unreadable counter stops the call.
            return !$failClosed;
        }
    }
}
