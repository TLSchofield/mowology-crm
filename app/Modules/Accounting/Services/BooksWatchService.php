<?php
/**
 * BooksWatchService — the books tell Tim they're wrong the day they go wrong (2026-10-14, plan
 * docs/plans/2026-10-14-beyond-today.md item 1).
 *
 * Two watches, run once a night from the sync-ledger cron (run()); Charlie's 15-minute urgent pass
 * only READS what the night stored (urgent()), so the heavy balance report never runs every 15 min.
 *
 *   drift   — every tracked account's month-end books vs its statement (BankBalanceCheckService).
 *             A difference that is NEW or CHANGED by $1 or more since last night is an alert. The
 *             first night only records a baseline: the Feb–Sep 2026 differences were known on
 *             2026-10-14 and are being traced, not news.
 *   canary  — something that should keep happening has gone quiet: no bank statement imported in
 *             35 days, no Interac e-Transfer notice in 14 days (autodeposit was off from Jun 12 and
 *             nobody was told), the Mac's text bridge silent for a day.
 *
 * Stored in ops_settings 'books_watch' (json_encode in PHP, no MySQL JSON functions). Each alert
 * key carries the value or the last-seen date, so Tim hears about each change once.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/BankBalanceCheckService.php';
require_once __DIR__ . '/LedgerService.php';

class BooksWatchService
{
    public const SETTING = 'books_watch';
    public const MIN_CHANGE = 1.0;
    public const CENT = 0.005;
    /** name => [days quiet before an alert, what Tim is told, where to look] */
    public const CANARIES = [
        'bank_import' => [35, 'No bank statement imported for %d days (last %s). Penny can\'t check the books against the bank until one is.', '/crm/accounting/bank-import.php'],
        'etransfer'   => [14, 'No Interac e-Transfer notice has reached the CRM for %d days (last %s). Check autodeposit is still on and the info@ inbox poller is running.', '/crm/accounting/bank-import.php'],
        'text_bridge' => [1,  'The text bridge on the iMac hasn\'t checked in for %d days (last %s). Payment texts and Interac alerts aren\'t reaching Penny.', '/crm/dashboard_appstack.php'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Nightly: compute, compare with last night, store. Returns the stored state. */
    public function run(?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $prev = $this->stored();
        $drift = $this->drift();
        $state = [
            'at'     => $now,
            'drift'  => $drift,
            'alerts' => array_merge(
                self::driftAlerts($prev['drift'] ?? null, $drift),
                self::canaryAlerts($this->lastSeen(), $now)
            ),
        ];
        $this->save($state);
        return $state;
    }

    /** Charlie's urgent pass: what last night found (cheap; no report is run here). */
    public function urgent(): array
    {
        return (array)($this->stored()['alerts'] ?? []);
    }

    /** "CODE|YYYY-MM" => drift, for every account-month that has a statement figure. */
    public function drift(): array
    {
        $out = [];
        $report = (new BankBalanceCheckService($this->db, new LedgerService($this->db)))->report();
        foreach ($report['accounts'] ?? [] as $a) {
            foreach ($a['months'] ?? [] as $m) {
                if (($m['statement'] ?? null) === null || ($m['drift'] ?? null) === null) continue;
                $out[$a['code'] . '|' . $m['ym']] = ['drift' => round((float)$m['drift'], 2), 'name' => (string)$a['name']];
            }
        }
        return $out;
    }

    /** name => last date (Y-m-d H:i:s) each canary signal was seen, or null. */
    public function lastSeen(): array
    {
        $one = function (string $sql) {
            try {
                $v = $this->db->query($sql)->fetchColumn();
                return $v ? (string)$v : null;
            } catch (Throwable $e) {
                return null;
            }
        };
        $hb = $one("SELECT setting_value FROM ops_settings WHERE setting_key = 'text_bridge_heartbeat'");
        $hb = $hb ? (json_decode($hb, true)['at'] ?? null) : null;
        return [
            'bank_import' => $one("SELECT MAX(created_at) FROM bank_import_sessions"),
            'etransfer'   => $one("SELECT MAX(created_at) FROM etransfer_notifications"),
            'text_bridge' => $hb,
        ];
    }

    /**
     * Pure (tested): new or changed differences since last night. No previous snapshot = baseline, no alerts.
     * @param array|null $prev "CODE|YM" => ['drift'=>float,'name'=>string]
     */
    public static function driftAlerts(?array $prev, array $now): array
    {
        if ($prev === null) return [];
        $out = [];
        foreach ($now as $k => $n) {
            $was = isset($prev[$k]) ? (float)$prev[$k]['drift'] : 0.0;
            $d = (float)$n['drift'];
            if (abs($d - $was) < self::MIN_CHANGE) continue;
            [$code, $ym] = explode('|', $k, 2);
            $label = date('F Y', strtotime($ym . '-01'));
            $text = abs($d) < self::CENT
                ? sprintf('%s %s: %s now matches the bank (it was $%s off).', $code, $n['name'], $label, number_format(abs($was), 2))
                : sprintf('%s %s: %s books are now $%s %s the bank (was $%s %s).', $code, $n['name'], $label,
                    number_format(abs($d), 2), $d > 0 ? 'above' : 'below', number_format(abs($was), 2), $was >= 0 ? 'above' : 'below');
            $out[] = ['key' => 'urgent:books:' . $code . ':' . $ym . ':' . (int)round($d * 100), 'kind' => 'books',
                      'text' => $text, 'url' => '/crm/accounting/bank-balance-check.php'];
        }
        return $out;
    }

    /**
     * Pure (tested): signals quiet past their limit. A signal never seen at all is not judged
     * (a tenant without the text bridge must not be told it is broken).
     */
    public static function canaryAlerts(array $last, string $now): array
    {
        $out = [];
        foreach (self::CANARIES as $name => [$days, $text, $url]) {
            $seen = $last[$name] ?? null;
            if (!$seen) continue;
            $quiet = (int)floor((strtotime($now) - strtotime($seen)) / 86400);
            if ($quiet < $days) continue;
            $out[] = ['key' => 'urgent:canary:' . $name . ':' . substr($seen, 0, 10), 'kind' => 'books',
                      'text' => sprintf($text, $quiet, date('M j', strtotime($seen))), 'url' => $url];
        }
        return $out;
    }

    private function stored(): array
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            $v = $s->fetchColumn();
            $d = $v ? json_decode((string)$v, true) : null;
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Update-then-insert: works on MySQL and on the SQLite the tests use. */
    private function save(array $state): void
    {
        $v = json_encode($state);
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
        $u->execute([$v, self::SETTING]);
        if ($u->rowCount() > 0 || $this->stored()) return;
        $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                 ->execute([self::SETTING, $v, 'Penny: last night\'s books-vs-bank and quiet-signal check (BooksWatchService)']);
    }
}

