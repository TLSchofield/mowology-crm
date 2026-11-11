<?php
/**
 * AdaSecurityRules — Ada's security alerts from the audit trail (Fable security audit #25d,
 * 2026-10-12). Pure: no database. AdaSecurityWatch feeds it rows.
 *
 *   security:login-failures   login_attempts (failed logins; a success clears its own pair) —
 *                             any clock hour in the last 24 h above LOGIN_FAILS_PER_HOUR is red
 *                             and urgent (Charlie emails Tim once per hour that crossed it).
 *   security:sensitive-changes activity_log rows from the last 24 h that change who can do what:
 *                             role / RBAC changes, passwords, accounts switched on/off or created,
 *                             migrations run, the system log cleared. Shown so Tim can say "that
 *                             was me". Clearing the system log is red (it hides evidence); a role
 *                             change and a cleared log are urgent.
 *
 * Rules, not AI: every verdict is a count against a stated threshold.
 */
class AdaSecurityRules
{
    public const LOGIN_FAILS_PER_HOUR = 50;
    public const WINDOW_HOURS = 24;
    public const LIST_MAX = 5;

    /** activity_log.action patterns the watch reads (SQL LIKE, case-insensitive collation). */
    public const ACTION_LIKES = ['%role%', '%RBAC%', '%password%', '%migration%', '%log%clear%',
                                 '%active status%', 'Changed employee%', 'Created employee%'];

    /** Which kind of sensitive change an activity row is, or null when it is not one. */
    public static function kind(string $action, ?string $details = null): ?string
    {
        $a = strtolower($action);
        $d = strtolower((string)$details);
        if (preg_match('/system[_ ]?log[_ ]?clear/', $a)) return 'log-clear';
        if (strpos($a, 'migration') !== false) return 'migration';
        if (preg_match('/\brole|rbac/', $a) || (strpos($a, 'employee') !== false && preg_match('/\brole\b|rbac/', $d))) return 'role';
        if (strpos($a, 'password') !== false || (strpos($a, 'employee') !== false && strpos($d, 'password') !== false)) return 'password';
        if (strpos($a, 'active status') !== false || (strpos($a, 'employee') !== false && preg_match('/\bactive\b/', $d))) return 'active';
        if (strpos($a, 'created employee') === 0) return 'account';
        return null;
    }

    /**
     * @param array<int, array{hour: string, n: int|string}> $hours  failed logins per clock hour
     *        ("Y-m-d H"), last WINDOW_HOURS
     */
    public static function loginFailuresCheck(array $hours, string $now): array
    {
        $base = ['system' => 'security:login-failures', 'group' => 'Security', 'name' => 'Failed logins', 'last_worked' => null];
        $total = 0;
        $peak = null;
        $over = [];
        foreach ($hours as $h) {
            $n = (int)$h['n'];
            $total += $n;
            if ($peak === null || $n > (int)$peak['n']) $peak = $h;
            if ($n > self::LOGIN_FAILS_PER_HOUR) $over[] = $h;
        }
        if ($over) {
            $worst = $peak;
            return $base + self::verdict('red', 'spike',
                'Failed logins: ' . (int)$worst['n'] . ' in one hour (' . self::hourLabel((string)$worst['hour']) . '), over the limit of '
                    . self::LOGIN_FAILS_PER_HOUR . "; {$total} in the last " . self::WINDOW_HOURS . ' hours — someone may be guessing passwords.',
                'Look at login_attempts for that hour (which emails and IPs). If it is not your own team locked out, block the IPs in cPanel (IP Blocker) and make sure every admin password is long and unique.',
                'tim');
        }
        return $base + self::verdict('green', 'ok',
            'Failed logins: ' . $total . ' in the last ' . self::WINDOW_HOURS . ' hours'
                . ($peak ? ', at most ' . (int)$peak['n'] . ' in one hour' : '') . ' (limit ' . self::LOGIN_FAILS_PER_HOUR . ' an hour).',
            null, null);
    }

    /**
     * @param array<int, array{id:int|string, action:string, details:?string, created_at:string, who:?string}> $rows
     *        activity_log rows from the last WINDOW_HOURS, newest first
     */
    public static function sensitiveChangesCheck(array $rows, string $now): array
    {
        $base = ['system' => 'security:sensitive-changes', 'group' => 'Security', 'name' => 'Role, password and system changes', 'last_worked' => null];
        $byKind = [];
        $lines = [];
        foreach ($rows as $r) {
            $k = self::kind((string)$r['action'], $r['details'] ?? null);
            if ($k === null) continue;
            $byKind[$k] = ($byKind[$k] ?? 0) + 1;
            if (count($lines) < self::LIST_MAX) {
                $lines[] = trim((string)$r['action']) . ' (' . (trim((string)($r['who'] ?? '')) ?: 'system') . ', ' . AdaRules::ago((string)$r['created_at'], $now) . ')';
            }
        }
        if (!$byKind) {
            return $base + self::verdict('green', 'ok',
                'No role, password, account or migration changes and no log clearing in the last ' . self::WINDOW_HOURS . ' hours.', null, null);
        }
        $words = ['log-clear' => 'system log cleared', 'role' => 'role change', 'password' => 'password change',
                  'active' => 'account switched on/off', 'account' => 'account created', 'migration' => 'migration run'];
        $parts = [];
        foreach ($words as $k => $w) {
            if (!empty($byKind[$k])) $parts[] = $byKind[$k] . ' ' . $w . ($byKind[$k] === 1 ? '' : 's');
        }
        $state = !empty($byKind['log-clear']) ? 'red' : 'amber';
        return $base + self::verdict($state, !empty($byKind['log-clear']) ? 'log-cleared' : 'changes',
            'Security changes in the last ' . self::WINDOW_HOURS . ' hours: ' . implode(', ', $parts) . ' — ' . implode('; ', $lines) . '.',
            'If any of these was not you or someone you asked, change that login\'s password and check Settings → Users now. Full detail: Activity log.',
            'tim');
    }

    /**
     * Charlie's urgent alerts (each key emails once): an hour over the failed-login limit, a
     * role change, a cleared system log.
     * @return array<int, array{key: string, kind: string, text: string, url: string}>
     */
    public static function urgentAlerts(array $hours, array $rows): array
    {
        $out = [];
        foreach ($hours as $h) {
            if ((int)$h['n'] <= self::LOGIN_FAILS_PER_HOUR) continue;
            $out[] = ['key' => 'urgent:security:logins:' . $h['hour'], 'kind' => 'security',
                      'text' => (int)$h['n'] . ' failed logins in one hour (' . self::hourLabel((string)$h['hour']) . ') — someone may be guessing passwords.',
                      'url' => '/crm/ada_appstack.php#security%3Alogin-failures'];
        }
        foreach ($rows as $r) {
            $k = self::kind((string)$r['action'], $r['details'] ?? null);
            if ($k !== 'role' && $k !== 'log-clear') continue;
            $who = trim((string)($r['who'] ?? '')) ?: 'the system';
            $what = $k === 'log-clear' ? 'The system error log was cleared' : 'A login\'s role changed: ' . trim((string)$r['action'])
                  . (trim((string)($r['details'] ?? '')) !== '' ? ' (' . mb_substr(trim((string)$r['details']), 0, 120) . ')' : '');
            $out[] = ['key' => 'urgent:security:' . $k . ':' . (int)$r['id'], 'kind' => 'security',
                      'text' => $what . ' by ' . $who . '. If that was not you, act now.',
                      'url' => '/crm/ada_appstack.php#security%3Asensitive-changes'];
        }
        return $out;
    }

    /** "2026-10-12 14" → "Oct 12, 2 pm". */
    public static function hourLabel(string $hour): string
    {
        $t = strtotime($hour . ':00:00');
        return $t ? date('M j, g a', $t) : $hour;
    }

    private static function verdict(string $state, string $problem, string $evidence, ?string $handoff, ?string $to): array
    {
        return ['state' => $state, 'problem' => $problem, 'evidence' => $evidence, 'handoff' => $handoff, 'handoff_to' => $to];
    }
}
