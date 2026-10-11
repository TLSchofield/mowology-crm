<?php
/**
 * AdaRules — the pure decisions behind Ada, head of Technology (unit tested).
 *
 * Ada keeps the system working and tells Charlie when it is not. She reads, she never fixes:
 * every problem carries one evidence line and a hand-off — to a developer session (code) or
 * to Tim (anything behind his login: cPanel cron lines, reconnecting an account, users).
 * Rules, not AI. Skill: .claude/skills/ada-technology/.
 *
 * A check is: ['system', 'group', 'name', 'state' => red|amber|unknown|green|info,
 *              'last_worked' => ?Y-m-d H:i:s, 'evidence', 'handoff' => ?string,
 *              'handoff_to' => developer|tim|null, 'problem' => slug].
 *   red      something that should work does not (failing, missed its schedule)
 *   amber    worth a look (recovered after failing, slow, never scheduled, unused logins)
 *   unknown  Ada cannot tell — the job keeps no record of its runs
 *   info     not a problem, shown on the page only (renamed or retired jobs)
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class AdaRules
{
    public const HEAD = 'ada';
    /** A job is missed when it has not run for this many of its intervals (+ grace). */
    public const MISSED_INTERVALS = 2;
    public const GRACE_MINUTES = 10;
    /** A job that failed within this many days, then recovered, stays amber. */
    public const RECOVERED_DAYS = 3;
    /** A run longer than this share of the job's interval is slow (runs start to overlap). */
    public const SLOW_SHARE = 0.8;
    /** A login unused this long, with no phone seen either, is worth a look. */
    public const STALE_LOGIN_DAYS = 90;
    /** Brief: at most this many problem lines (plus one "can't tell" summary). */
    public const BRIEF_MAX = 5;

    public const STATE_ORDER = ['red' => 0, 'amber' => 1, 'unknown' => 2, 'green' => 3, 'info' => 4];

    // ─────────────────────────────────────────────────────────────────────────
    // The cron registry (public/crm/database_appstack.php $crons) — read as text, never run
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The registry rows from the Database Manager page source.
     * @return array<string, array{key: string, title: string, expr: string, app: string, run: ?string}>
     */
    public static function parseRegistry(string $src): array
    {
        $out = [];
        if (!preg_match_all("/\['key'\s*=>\s*'([a-z0-9_]+)'(.*?)\],?\s*\n/s", $src, $m, PREG_SET_ORDER)) return $out;
        foreach ($m as $row) {
            $f = static function (string $name) use ($row): ?string {
                return preg_match("/'" . $name . "'\s*=>\s*(['\"])(.*?)(?<!\\\\)\\1/s", $row[2], $x) ? stripslashes($x[2]) : null;
            };
            $expr = $f('expr');
            $app = $f('app');
            if ($expr === null || $app === null) continue;
            $out[$row[1]] = [
                'key'   => $row[1],
                'title' => html_entity_decode((string)($f('title') ?? $row[1]), ENT_QUOTES),
                'expr'  => $expr,
                'app'   => $app,
                'run'   => $f('run'),
            ];
        }
        return $out;
    }

    /**
     * Roughly how often a cron expression fires, in minutes (the longest normal gap).
     * Only the shapes the registry uses: "*", "* /n", lists and single values.
     */
    public static function intervalMinutes(string $expr): ?int
    {
        $p = preg_split('/\s+/', trim($expr));
        if (count($p) !== 5) return null;
        [$min, $hour, $dom, , $dow] = $p;
        if ($dom !== '*' && $dom !== '?') return 31 * 1440;                 // monthly
        if ($dow !== '*' && $dow !== '?') {                                  // weekly (or a few days a week)
            return (int)round(7 * 1440 / max(1, self::countOf($dow, 7)));
        }
        if ($hour === '*') {
            if ($min === '*') return 1;
            if (preg_match('#^\*/(\d+)$#', $min, $x)) return max(1, (int)$x[1]);
            return (int)round(60 / max(1, self::countOf($min, 60)));
        }
        if (preg_match('#^\*/(\d+)$#', $hour, $x)) return max(1, (int)$x[1]) * 60;
        return (int)round(1440 / max(1, self::countOf($hour, 24)));
    }

    private static function countOf(string $field, int $range): int
    {
        $n = 0;
        foreach (explode(',', $field) as $part) {
            if (preg_match('/^(\d+)-(\d+)$/', $part, $x)) $n += max(1, (int)$x[2] - (int)$x[1] + 1);
            elseif (preg_match('#^\*/(\d+)$#', $part, $x)) $n += (int)ceil($range / max(1, (int)$x[1]));
            else $n++;
        }
        return $n;
    }

    /** Does a cron script's text record its runs in cron_runs? (cron_runs_log is a different table.) */
    public static function scriptRecords(string $code): bool
    {
        return (bool)preg_match('/recordCronRun\s*\(|\bcron_runs\b/', $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (1) Scheduled jobs
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One registered job.
     * @param array $reg   a parseRegistry() row
     * @param ?array $stats null when cron_runs has no row for the key, else
     *   [last_at, last_ok_at, last_status, last_error, max_ms, recent => [[status, ran_at, error], …] newest first]
     * @param bool $records the script's text records its runs
     * @param bool $filesExist the script files the registry names are on the server
     */
    public static function cronCheck(array $reg, ?array $stats, bool $records, bool $filesExist, string $now): array
    {
        $key = (string)$reg['key'];
        $name = (string)($reg['title'] ?? $key);
        $every = self::intervalMinutes((string)$reg['expr']);
        $runPath = (string)($reg['run'] ?? $reg['app']);
        $base = ['system' => 'cron:' . $key, 'group' => 'Scheduled jobs', 'name' => $name,
                 'last_worked' => $stats['last_ok_at'] ?? null];

        if (!$filesExist) {
            return $base + self::verdict('red', 'missing-script',
                "{$name}: the script it runs ({$runPath}) is not on the server.",
                "Ada: cron '{$key}' points at {$runPath}, which is missing on production. Restore or deploy it, or remove the registry entry in public/crm/database_appstack.php.",
                'developer');
        }

        if ($stats === null) {
            if (!$records) {
                return $base + self::verdict('unknown', 'no-record',
                    "{$name}: doesn't record its runs, so Ada can't tell whether it works.",
                    "Ada: cron '{$key}' ({$reg['app']}) never calls recordCronRun(). Add recordCronRun('{$key}', …) at the end of the script (success and failure paths) so its runs can be checked.",
                    'developer');
            }
            return $base + self::verdict('amber', 'never-ran',
                "{$name}: no run on record, ever — most likely it is not in the cPanel schedule (or it only records when it has work).",
                "cPanel → Cron Jobs: check there is a line for {$runPath} ({$reg['expr']}). If there is, run it once from the Database Manager's Crons tab and see what it says.",
                'tim');
        }

        $age = self::minutesBetween((string)$stats['last_at'], $now);
        $recent = (array)($stats['recent'] ?? []);

        if (($stats['last_status'] ?? '') === 'error') {
            $fails = 0;
            foreach ($recent as $r) { if (($r[0] ?? '') !== 'error') break; $fails++; }
            $since = $stats['last_ok_at'] ? ' Last worked ' . self::ago((string)$stats['last_ok_at'], $now) . '.' : ' It has not worked on record.';
            $err = self::shortError((string)($stats['last_error'] ?? ''));
            return $base + self::verdict('red', 'failing',
                "{$name}: failed its last " . ($fails > 1 ? "{$fails} runs" : 'run') . ($err !== '' ? " — \"{$err}\"" : '') . '.' . $since,
                "Ada: cron '{$key}' ({$reg['app']}) is failing" . ($err !== '' ? " with \"{$err}\"" : '') . ". Read its cron_runs rows and fix the cause; prove it with one run from the Database Manager.",
                'developer');
        }

        if ($every !== null && $age !== null && $age > self::MISSED_INTERVALS * $every + self::GRACE_MINUTES) {
            if (!$records) {
                return $base + self::verdict('unknown', 'no-record',
                    "{$name}: last run on record " . self::ago((string)$stats['last_at'], $now) . ", and its script no longer records runs — Ada can't tell if it still works.",
                    "Ada: cron '{$key}' ({$reg['app']}) does not call recordCronRun(), and its last cron_runs row is " . $stats['last_at'] . ". Add recordCronRun('{$key}', …) so its runs can be checked, then confirm it is in the cPanel schedule.",
                    'developer');
            }
            return $base + self::verdict('red', 'missed',
                "{$name}: should run " . self::everyWords($every) . ", last ran " . self::ago((string)$stats['last_at'], $now) . '.',
                "cPanel → Cron Jobs: check the line for {$runPath} ({$reg['expr']}) is still there and pointing at the right file. Its last recorded run was {$stats['last_at']}.",
                'tim');
        }

        $errors = 0;
        $lastErr = '';
        foreach ($recent as $r) {
            if (($r[0] ?? '') !== 'error') continue;
            $d = self::minutesBetween((string)($r[1] ?? ''), $now);
            if ($d === null || $d > self::RECOVERED_DAYS * 1440) continue;
            $errors++;
            if ($lastErr === '') $lastErr = self::shortError((string)($r[2] ?? ''));
        }
        if ($errors > 0) {
            return $base + self::verdict('amber', 'recovered',
                "{$name}: working again, but failed {$errors} time" . ($errors === 1 ? '' : 's') . ' in the last ' . self::RECOVERED_DAYS . ' days' . ($lastErr !== '' ? " (\"{$lastErr}\")" : '') . '.',
                "Ada: cron '{$key}' failed {$errors}× in " . self::RECOVERED_DAYS . " days" . ($lastErr !== '' ? " with \"{$lastErr}\"" : '') . " before recovering. Find why, so it does not come back.",
                'developer');
        }

        $maxMs = (int)($stats['max_ms'] ?? 0);
        if ($every !== null && $maxMs > 0 && $maxMs > self::SLOW_SHARE * $every * 60000) {
            return $base + self::verdict('amber', 'slow',
                "{$name}: one run took " . self::duration($maxMs) . ' in the last 14 days, for a job that runs ' . self::everyWords($every) . ' — runs can overlap.',
                "Ada: cron '{$key}' took up to " . self::duration($maxMs) . " (interval {$every} min). Find the slow step; cap its work per run.",
                'developer');
        }

        return $base + self::verdict('green', 'ok', "{$name}: last ran " . self::ago((string)$stats['last_at'], $now) . ', worked.', null, null);
    }

    /** A cron key with runs on record that the registry does not list (renamed, retired or unlisted). */
    public static function unlistedCron(string $key, string $lastAt, string $now): array
    {
        $age = self::minutesBetween($lastAt, $now) ?? PHP_INT_MAX;
        $recent = $age <= 2 * 1440;
        return ['system' => 'cron:' . $key, 'group' => 'Scheduled jobs', 'name' => $key, 'last_worked' => $lastAt]
            + self::verdict('info', $recent ? 'unlisted' : 'retired',
                $recent ? "{$key}: runs (last " . self::ago($lastAt, $now) . ") but is not in the Database Manager's cron list."
                        : "{$key}: last ran " . self::ago($lastAt, $now) . ' and is not in the cron list — renamed or retired.',
                $recent ? "Ada: cron key '{$key}' records runs but has no entry in \$crons (public/crm/database_appstack.php). Add one so it can be checked." : null,
                $recent ? 'developer' : null);
    }

    /**
     * Jobs that keep no record but whose work shows elsewhere: job key => the connection check
     * whose green state proves the job is running (a second source, as the skill asks).
     */
    public const PROXIES = ['trackimo_poll' => 'trackimo', 'push_drain' => 'apple:push'];

    /** A "can't tell" job becomes green when its proxy connection is green. */
    public static function applyProxies(array $checks): array
    {
        $bySystem = [];
        foreach ($checks as $c) $bySystem[$c['system']] = $c;
        foreach ($checks as $i => $c) {
            $key = substr((string)$c['system'], 5);
            if (strpos((string)$c['system'], 'cron:') !== 0 || $c['state'] !== 'unknown' || !isset(self::PROXIES[$key])) continue;
            $proxy = $bySystem[self::PROXIES[$key]] ?? null;
            if (!$proxy || $proxy['state'] !== 'green') continue;
            $checks[$i] = array_merge($c, [
                'state' => 'green', 'problem' => 'ok-by-proxy', 'last_worked' => $proxy['last_worked'],
                'evidence' => "{$c['name']}: keeps no record of its runs, but its work shows — " . lcfirst((string)$proxy['evidence']),
            ]);
        }
        return $checks;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (2) Outside connections
    // ─────────────────────────────────────────────────────────────────────────

    /** Facebook / Instagram / Google Business: what SocialAccountHealth stored (active accounts only). */
    public static function socialCheck(string $platform, ?array $health, string $now): array
    {
        $label = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'gbp' => 'Google Business Profile'][$platform] ?? ucfirst($platform);
        $base = ['system' => 'social:' . $platform, 'group' => 'Connections', 'name' => $label];
        if ($health === null) {
            return $base + ['last_worked' => null] + self::verdict('unknown', 'unchecked',
                "{$label}: connected, but its health has never been checked.",
                "Ada: social account '{$platform}' has no meta_json['health']. SocialAccountHealth::sweep() runs from social_publisher — check it reaches this account.",
                'developer');
        }
        $checked = (string)($health['checked_at'] ?? '');
        if (($health['status'] ?? '') === 'ok') {
            $age = self::minutesBetween($checked, $now);
            if ($age !== null && $age > 1440) {
                return $base + ['last_worked' => $checked] + self::verdict('amber', 'stale-check',
                    "{$label}: last checked " . self::ago($checked, $now) . ' — the hourly check has stopped.',
                    "Ada: SocialAccountHealth for '{$platform}' last ran {$checked}. It runs from the social_publisher cron — check that cron.",
                    'developer');
            }
            return $base + ['last_worked' => $checked] + self::verdict('green', 'ok', "{$label}: reached " . self::ago($checked, $now) . '.', null, null);
        }
        $reason = (string)($health['reason'] ?? 'error');
        return $base + ['last_worked' => null] + self::verdict('red', 'down',
            "{$label}: not working since at least " . self::ago($checked, $now) . " ({$reason}).",
            "Settings → Social accounts: reconnect {$label}. Ada's evidence: " . self::shortError((string)($health['detail'] ?? $reason)),
            'tim');
    }

    /** Google Search Console: the newest sync. */
    public static function gscCheck(?array $last, string $now): array
    {
        $base = ['system' => 'google:search-console', 'group' => 'Connections', 'name' => 'Google Search Console'];
        if ($last === null) {
            return $base + ['last_worked' => null] + self::verdict('unknown', 'never', 'Google Search Console: no sync on record.', null, null);
        }
        $at = (string)($last['completed_at'] ?: $last['started_at']);
        if (($last['status'] ?? '') !== 'success') {
            return $base + ['last_worked' => null] + self::verdict('red', 'failing',
                'Google Search Console: last sync failed ' . self::ago($at, $now) . ' — "' . self::shortError((string)($last['error_message'] ?? '')) . '".',
                "Ada: gsc_sync_history #{$last['id']} status {$last['status']}: " . self::shortError((string)($last['error_message'] ?? '')) . '. Check the Google service account and the property.',
                'developer');
        }
        $age = self::minutesBetween($at, $now);
        if ($age !== null && $age > 3 * 1440) {
            return $base + ['last_worked' => $at] + self::verdict('amber', 'stale',
                'Google Search Console: last synced ' . self::ago($at, $now) . ' (it syncs nightly).',
                'Ada: no gsc_sync_history row for 3+ days. Check the nightly sync cron.', 'developer');
        }
        return $base + ['last_worked' => $at] + self::verdict('green', 'ok',
            'Google Search Console: synced ' . self::ago($at, $now) . ', ' . (int)$last['rows_processed'] . ' rows.', null, null);
    }

    /** Apple push: the last 48 hours of push_queue. */
    public static function pushCheck(int $sent, int $failed, ?string $lastSentAt, ?string $lastError, string $now): array
    {
        $base = ['system' => 'apple:push', 'group' => 'Connections', 'name' => 'Apple push notifications', 'last_worked' => $lastSentAt];
        if ($failed > 0 && $failed >= max(3, $sent * 0.2)) {
            return $base + self::verdict('red', 'failing',
                "Apple push: {$failed} of " . ($sent + $failed) . ' notifications failed in the last 48 hours' . ($lastError ? " (\"" . self::shortError($lastError) . "\")" : '') . '.',
                "Ada: push_queue has {$failed} failed in 48 h" . ($lastError ? " — \"" . self::shortError($lastError) . "\"" : '') . '. Check the APNs key in secrets.php and ApnsService.',
                'developer');
        }
        if ($sent + $failed === 0) {
            return $base + self::verdict('green', 'quiet', 'Apple push: nothing to send in the last 48 hours' . ($lastSentAt ? ' (last sent ' . self::ago($lastSentAt, $now) . ')' : '') . '.', null, null);
        }
        return $base + self::verdict('green', 'ok', "Apple push: {$sent} sent in 48 hours" . ($failed ? ", {$failed} failed" : '') . '.', null, null);
    }

    /** Stripe: the newest payment (a proxy — there is no key-health record). */
    public static function stripeCheck(?string $lastAt, int $last30, string $now): array
    {
        $base = ['system' => 'stripe', 'group' => 'Connections', 'name' => 'Stripe payments', 'last_worked' => $lastAt];
        $age = $lastAt ? self::minutesBetween($lastAt, $now) : null;
        if ($age === null || $age > 21 * 1440) {
            return $base + self::verdict('amber', 'quiet',
                'Stripe: no card payment ' . ($lastAt ? 'since ' . self::ago($lastAt, $now) : 'on record') . ' — quiet season, or the webhook has stopped.',
                'Ada: newest stripe_payments row is ' . ($lastAt ?: 'none') . '. Check Stripe → Developers → Webhooks for failed deliveries to /api/stripe/webhook.php.',
                'tim');
        }
        return $base + self::verdict('green', 'ok', "Stripe: {$last30} payments in 30 days, last " . self::ago($lastAt, $now) . '.', null, null);
    }

    /** Trackimo (truck GPS): the newest position fetched. Business hours = Mon–Sat 7–18. */
    public static function trackimoCheck(?string $lastFetched, string $now): array
    {
        $base = ['system' => 'trackimo', 'group' => 'Connections', 'name' => 'Truck GPS (Trackimo)', 'last_worked' => $lastFetched];
        $age = $lastFetched ? self::minutesBetween($lastFetched, $now) : null;
        $ts = strtotime($now);
        $working = (int)date('N', $ts) <= 6 && (int)date('G', $ts) >= 7 && (int)date('G', $ts) < 18;
        $limit = $working ? 30 : 24 * 60;
        if ($age === null || $age > $limit) {
            return $base + self::verdict('red', 'stale',
                'Truck GPS: no truck position ' . ($lastFetched ? 'since ' . self::ago($lastFetched, $now) : 'on record') . '.',
                'Ada: newest vehicle_location_pings.fetched_at is ' . ($lastFetched ?: 'none') . '. trackimo_poll records no runs — run it once by hand and read its output; check the Trackimo login in secrets.php.',
                'developer');
        }
        return $base + self::verdict('green', 'ok', 'Truck GPS: last position ' . self::ago($lastFetched, $now) . '.', null, null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // (3) Security — first slice
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Active logins nobody has used: no web/app login AND no phone seen for STALE_LOGIN_DAYS.
     * @param array $users [[id, name, role, last_login, seen], …] active, non-tablet users
     */
    public static function loginsCheck(array $users, string $now): array
    {
        $stale = [];
        foreach ($users as $u) {
            $latest = max((string)($u['last_login'] ?? ''), (string)($u['seen'] ?? ''));
            $age = $latest !== '' ? self::minutesBetween($latest, $now) : null;
            if ($age === null || $age > self::STALE_LOGIN_DAYS * 1440) {
                $stale[] = trim((string)($u['name'] ?? '')) ?: ('user #' . (int)$u['id']);
            }
        }
        $base = ['system' => 'security:logins', 'group' => 'Security', 'name' => 'Unused logins', 'last_worked' => null];
        if (!$stale) {
            return $base + self::verdict('green', 'ok', 'Logins: every active login was used in the last ' . self::STALE_LOGIN_DAYS . ' days.', null, null);
        }
        $n = count($stale);
        return $base + self::verdict('amber', 'stale',
            "Logins: {$n} active login" . ($n === 1 ? '' : 's') . ' unused for ' . self::STALE_LOGIN_DAYS . '+ days (' . implode(', ', $stale) . ').',
            'Settings → Users: switch off any login for someone who no longer works here (' . implode(', ', $stale) . '). An unused login is a door nobody watches.',
            'tim');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Charlie's brief
    // ─────────────────────────────────────────────────────────────────────────

    public static function sortChecks(array $checks): array
    {
        usort($checks, fn($a, $b) => [self::STATE_ORDER[$a['state']] ?? 9, $a['group'], $a['name']]
                                 <=> [self::STATE_ORDER[$b['state']] ?? 9, $b['group'], $b['name']]);
        return $checks;
    }

    /** Charlie's contract: red and amber as lines (red first), then one line for "can't tell". */
    public static function briefItems(array $checks, string $today): array
    {
        $items = [];
        $unknown = [];
        foreach (self::sortChecks($checks) as $c) {
            if ($c['state'] === 'unknown') { $unknown[] = $c['name']; continue; }
            if ($c['state'] !== 'red' && $c['state'] !== 'amber') continue;
            if (count($items) >= self::BRIEF_MAX) continue;
            $items[] = [
                'key'      => 'ada:' . $c['system'] . ':' . $c['problem'],
                'kind'     => 'ada:' . $c['problem'],
                'text'     => $c['evidence'],
                'url'      => '/crm/ada_appstack.php#' . rawurlencode($c['system']),
                'priority' => $c['state'] === 'red' ? 1 : 2,
                'since'    => $today,
            ];
        }
        if ($unknown) {
            $n = count($unknown);
            $items[] = [
                'key'      => 'ada:cant-tell:' . $n,
                'kind'     => 'ada:cant-tell',
                'text'     => "{$n} scheduled job" . ($n === 1 ? " doesn't" : "s don't") . ' record their runs, so Ada can\'t tell if they work.',
                'url'      => '/crm/ada_appstack.php',
                'priority' => 3,
                'since'    => $today,
            ];
        }
        return $items;
    }

    /** @return array{red: int, amber: int, unknown: int, green: int, info: int} */
    public static function counts(array $checks): array
    {
        $n = ['red' => 0, 'amber' => 0, 'unknown' => 0, 'green' => 0, 'info' => 0];
        foreach ($checks as $c) { if (isset($n[$c['state']])) $n[$c['state']]++; }
        return $n;
    }

    public static function headline(array $counts): string
    {
        if ($counts['red'] + $counts['amber'] === 0) return 'Everything Ada watches is working.';
        $parts = [];
        if ($counts['red']) $parts[] = $counts['red'] . ' broken';
        if ($counts['amber']) $parts[] = $counts['amber'] . ' to look at';
        return implode(', ', $parts) . '.';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Words
    // ─────────────────────────────────────────────────────────────────────────

    private static function verdict(string $state, string $problem, string $evidence, ?string $handoff, ?string $to): array
    {
        return ['state' => $state, 'problem' => $problem, 'evidence' => $evidence, 'handoff' => $handoff, 'handoff_to' => $to];
    }

    public static function minutesBetween(string $from, string $to): ?int
    {
        $a = strtotime($from);
        $b = strtotime($to);
        return ($a === false || $b === false || $from === '') ? null : (int)floor(($b - $a) / 60);
    }

    public static function ago(string $at, string $now): string
    {
        $m = self::minutesBetween($at, $now);
        if ($m === null) return 'at an unknown time';
        if ($m < 2) return 'just now';
        if ($m < 120) return "{$m} min ago";
        if ($m < 48 * 60) return (int)floor($m / 60) . ' hours ago';
        $days = (int)floor($m / 1440);
        return $days < 45 ? "{$days} days ago" : 'on ' . date('M j', strtotime($at));
    }

    public static function everyWords(int $minutes): string
    {
        if ($minutes <= 1) return 'every minute';
        if ($minutes < 60) return "every {$minutes} min";
        if ($minutes < 1440) return $minutes % 60 === 0 ? 'every ' . ($minutes / 60 === 1 ? 'hour' : ($minutes / 60) . ' hours') : "every {$minutes} min";
        if ($minutes < 7 * 1440) return $minutes === 1440 ? 'daily' : 'every ' . round($minutes / 1440) . ' days';
        if ($minutes < 28 * 1440) return 'weekly';
        return 'monthly';
    }

    public static function duration(int $ms): string
    {
        $s = (int)round($ms / 1000);
        return $s < 120 ? "{$s} s" : round($s / 60) . ' min';
    }

    /** One readable line of an error, no paths or secrets-looking strings. */
    public static function shortError(string $e): string
    {
        $e = trim(preg_replace('/\s+/', ' ', $e));
        $e = preg_replace('#/home/[^\s:]+#', '…', $e);
        $e = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '…', $e);   // token-shaped strings
        return mb_strlen($e) > 120 ? mb_substr($e, 0, 117) . '…' : $e;
    }
}
