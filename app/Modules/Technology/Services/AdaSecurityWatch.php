<?php
/**
 * AdaSecurityWatch — reads login_attempts and activity_log for Ada's security alerts
 * (Fable security audit #25d, 2026-10-12). The decisions are in AdaSecurityRules (tested).
 *
 *   checks() → two Ada checks for her desk / System Health page (AdaDeskService::securityChecks)
 *   urgent() → alerts for Charlie's interruption email (CharlieUrgentService::find)
 *
 * Read-only, two indexed queries (login_attempts.idx_attempted_at, activity_log.idx_created).
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/AdaRules.php';
require_once __DIR__ . '/AdaSecurityRules.php';

class AdaSecurityWatch
{
    private PDO $db;
    private ?string $now;

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now;
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }

    private function since(): string
    {
        return date('Y-m-d H:i:s', strtotime($this->now()) - AdaSecurityRules::WINDOW_HOURS * 3600);
    }

    /** Failed logins per clock hour, last 24 h. */
    public function loginHours(): array
    {
        $st = $this->db->prepare("
            SELECT DATE_FORMAT(attempted_at, '%Y-%m-%d %H') AS hour, COUNT(*) AS n
            FROM login_attempts
            WHERE attempted_at >= ?
            GROUP BY hour
            ORDER BY hour
        ");
        $st->execute([$this->since()]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Sensitive activity rows, last 24 h, newest first. */
    public function sensitiveRows(): array
    {
        $likes = AdaSecurityRules::ACTION_LIKES;
        $where = implode(' OR ', array_fill(0, count($likes), 'al.action LIKE ?'));
        $st = $this->db->prepare("
            SELECT al.id, al.action, al.details, al.created_at,
                   COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.full_name, u.email) AS who
            FROM activity_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.created_at >= ? AND ({$where})
            ORDER BY al.id DESC
            LIMIT 200
        ");
        $st->execute(array_merge([$this->since()], $likes));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checks(): array
    {
        $now = $this->now();
        return [
            AdaSecurityRules::loginFailuresCheck($this->loginHours(), $now),
            AdaSecurityRules::sensitiveChangesCheck($this->sensitiveRows(), $now),
        ];
    }

    public function urgent(): array
    {
        return AdaSecurityRules::urgentAlerts($this->loginHours(), $this->sensitiveRows());
    }
}
