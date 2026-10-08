<?php
/**
 * StatementCoverageService — Penny's "statements check": is every bank / card statement in?
 *
 * StatementCloseService proves each IMPORTED statement (opening + lines = closing). This
 * service asks the other question: what is NOT imported?
 *
 *   1. Running-balance continuity. Most statement lines carry the bank's running balance
 *      (the last column of a TD / Vancity CSV, the second trailing amount of a PDF line),
 *      kept verbatim in bank_import_rows.raw_row → raw_line. Walking every line of an
 *      account in order, each balance must equal the previous balance ± the line. A break
 *      means lines are missing — and the size of the break is exactly what is missing
 *      ("TD chequing: 12–19 Feb, $1,240 missing between balances").
 *      A line that chains to a DIFFERENT balance is another account printed on the same
 *      statement (Known-Failure-Patterns, 2026-10-05: Vancity's multi-account statement),
 *      not a gap: it starts its own track.
 *   2. Without balances (an account whose lines carry none): weaker signs — a long run of
 *      days with no lines at all, or a month with less than half the account's usual count.
 *      Reported as "possible gap".
 *   3. Expected accounts (bank_statement_accounts, migration 1231): every account that has
 *      ever had an import, plus the credit card(s), even when never imported. For each, is
 *      last month in (to its last day, or to its statement closing day)?
 *
 * Computed at most once a day (penny_prepare cron, or the first view of the day) and cached
 * in ops_settings 'penny_statements_check'. Read-only apart from that cache, the seeding of
 * bank_statement_accounts and the owner's own edits to it. Dedupe rules are not touched.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class StatementCoverageService
{
    public const CACHE_KEY = 'penny_statements_check';
    public const CENT = 0.015;
    /** A run of days with no lines at all that looks like a missing statement (no-balance accounts). */
    public const QUIET_DAYS = 14;
    /** A month below this share of the account's median line count is a possible gap. */
    public const DROP_SHARE = 0.5;
    /** End-of-month slack: a statement's last line is rarely ON the last day. */
    public const END_SLACK_DAYS = 4;
    /** From this day of the month, a missing last-month statement goes on the Action Board. */
    public const BRIEF_FROM_DAY = 3;
    /** How far ahead a line that breaks the chain looks for the old balance resuming. */
    public const LOOKAHEAD = 60;
    public const URL = '/crm/accounting/bank-import.php#statements-check';

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    private function today(): string
    {
        return $this->today ?? date('Y-m-d');
    }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'bank_statement_accounts'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Expected accounts (bank_statement_accounts)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Add any account that has had an import, and any credit-card account, that the list
     * doesn't know yet. Never changes a row the owner has edited (INSERT IGNORE).
     */
    public function ensureSeeded(): int
    {
        if (!$this->ready()) return 0;
        $n = $this->db->exec("
            INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, bank_name_match, created_at)
            SELECT c.id, c.name,
                   CASE WHEN c.sub_type = 'credit_card' OR c.type = 'liability' THEN 'card' ELSE 'bank' END,
                   1,
                   (SELECT s2.bank_name FROM bank_import_sessions s2
                     WHERE s2.bank_account_id = c.id AND s2.bank_name IS NOT NULL AND s2.bank_name <> ''
                     GROUP BY s2.bank_name ORDER BY COUNT(*) DESC LIMIT 1),
                   NOW()
            FROM chart_of_accounts c
            WHERE c.id IN (SELECT s.bank_account_id FROM bank_import_sessions s
                            WHERE s.status = 'imported' AND s.bank_account_id IS NOT NULL)
        ");
        $n += $this->db->exec("
            INSERT IGNORE INTO bank_statement_accounts (account_id, label, kind, expected, created_at)
            SELECT c.id, c.name, 'card', 1, NOW()
            FROM chart_of_accounts c
            WHERE c.sub_type = 'credit_card'
               OR (c.type = 'liability' AND (c.name LIKE '%credit card%' OR c.name LIKE '%visa%' OR c.name LIKE '%mastercard%'))
        ");
        return (int)$n;
    }

    /** @return array<int, array> the list, with the chart account's code */
    public function accounts(): array
    {
        if (!$this->ready()) return [];
        return $this->db->query("
            SELECT a.id, a.account_id, a.label, a.kind, a.expected, a.statement_day, a.bank_name_match,
                   c.code AS account_code, c.name AS account_name
            FROM bank_statement_accounts a
            LEFT JOIN chart_of_accounts c ON c.id = a.account_id
            ORDER BY a.kind, c.code, a.id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The owner's edit: label, expected yes/no, statement closing day (null = month end). */
    public function saveAccount(int $id, string $label, bool $expected, ?int $statementDay, string $kind = ''): array
    {
        $label = trim($label);
        if ($id <= 0 || $label === '') return ['ok' => false, 'message' => 'Which account, and what should it be called?'];
        if ($statementDay !== null && ($statementDay < 1 || $statementDay > 31)) $statementDay = null;
        $kind = in_array($kind, ['bank', 'card'], true) ? $kind : null;
        $this->db->prepare("
            UPDATE bank_statement_accounts
               SET label = ?, expected = ?, statement_day = ?, kind = COALESCE(?, kind), updated_at = NOW()
             WHERE id = ?
        ")->execute([mb_substr($label, 0, 80), $expected ? 1 : 0, $statementDay, $kind, $id]);
        $this->forget();
        return ['ok' => true, 'message' => 'Saved.'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Re-import safety — surface the count, never change the dedupe rule
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lines the preview flagged "already imported" that the owner left unticked never reach
     * commit(), so the session's duplicate_count said 0. Add them so Import History (and
     * Penny) can say "N lines already in the CRM were skipped".
     */
    public function recordSkippedDuplicates(int $sessionId, int $count): void
    {
        if ($sessionId <= 0 || $count <= 0) return;
        $this->db->prepare("UPDATE bank_import_sessions SET duplicate_count = duplicate_count + ? WHERE id = ?")
                 ->execute([$count, $sessionId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache — at most once a day
    // ─────────────────────────────────────────────────────────────────────────

    /** Today's report: from the cache when it was computed today, else computed now and cached. */
    public function latest(): array
    {
        $c = $this->cached();
        if ($c && ($c['date'] ?? '') === $this->today()) return $c;
        return $this->refresh();
    }

    /** For the cron: compute only when today's report isn't there yet. */
    public function refreshDaily(): array
    {
        $c = $this->cached();
        if ($c && ($c['date'] ?? '') === $this->today()) return ['ran' => false, 'report' => $c];
        return ['ran' => true, 'report' => $this->refresh()];
    }

    public function refresh(): array
    {
        $report = $this->compute();
        try {
            $this->db->prepare("
                INSERT INTO ops_settings (setting_key, setting_value, description)
                VALUES (?, ?, 'Penny statements check — StatementCoverageService, recomputed daily')
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ")->execute([self::CACHE_KEY, json_encode($report)]);
        } catch (Throwable $e) {
            error_log('Statements check cache: ' . $e->getMessage());
        }
        return $report;
    }

    public function cached(): ?array
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([self::CACHE_KEY]);
            $v = $s->fetchColumn();
            $r = $v ? json_decode((string)$v, true) : null;
            return is_array($r) ? $r : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** An import or an edit changes the answer: drop today's cache. */
    public function forget(): void
    {
        try {
            $this->db->prepare("DELETE FROM ops_settings WHERE setting_key = ?")->execute([self::CACHE_KEY]);
        } catch (Throwable $e) {
            // no cache table — nothing to forget
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Compute
    // ─────────────────────────────────────────────────────────────────────────

    public function compute(): array
    {
        $this->ensureSeeded();
        $accounts = $this->accounts();

        $sessions = $this->db->query("
            SELECT s.id, s.bank_account_id, s.bank_name, s.account_name, s.date_from, s.date_to,
                   s.duplicate_count, s.imported_count, s.created_at,
                   c.code AS acct_code, c.name AS acct_name
            FROM bank_import_sessions s
            LEFT JOIN chart_of_accounts c ON c.id = s.bank_account_id
            WHERE s.status = 'imported'
            ORDER BY s.date_from, s.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        // A session with no account id belongs to the expected account whose bank name it carries
        // (only when exactly one account claims that name).
        $byName = [];
        foreach ($accounts as $a) {
            $k = strtolower(trim((string)$a['bank_name_match']));
            if ($k !== '') $byName[$k][] = (int)$a['account_id'];
        }
        $groupOf = [];
        $groups = [];
        foreach ($accounts as $a) {
            $groups['a' . (int)$a['account_id']] = [
                'key' => 'a' . (int)$a['account_id'], 'account_id' => (int)$a['account_id'],
                'label' => (string)$a['label'], 'kind' => (string)$a['kind'],
                'expected' => (int)$a['expected'] === 1, 'statement_day' => $a['statement_day'] !== null ? (int)$a['statement_day'] : null,
                'list_id' => (int)$a['id'], 'sessions' => [],
            ];
        }
        foreach ($sessions as $s) {
            if ($s['bank_account_id']) {
                $key = 'a' . (int)$s['bank_account_id'];
            } else {
                $nm = strtolower(trim((string)$s['bank_name']));
                $key = isset($byName[$nm]) && count($byName[$nm]) === 1 ? 'a' . $byName[$nm][0] : 'n' . $nm;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key, 'account_id' => $s['bank_account_id'] ? (int)$s['bank_account_id'] : null,
                    'label' => $s['acct_name'] ? (string)$s['acct_name'] : (trim((string)$s['bank_name'] . ' ' . (string)$s['account_name']) ?: 'Unassigned import'),
                    'kind' => 'bank', 'expected' => false, 'statement_day' => null, 'list_id' => null, 'sessions' => [],
                ];
            }
            $groups[$key]['sessions'][] = [
                'id' => (int)$s['id'], 'from' => $s['date_from'], 'to' => $s['date_to'],
                'duplicates' => (int)$s['duplicate_count'], 'imported' => (int)$s['imported_count'],
                'created_at' => (string)$s['created_at'],
            ];
            $groupOf[(int)$s['id']] = $key;
        }

        $lines = [];
        if ($groupOf) {
            $rows = $this->db->query("
                SELECT r.id, r.session_id, r.transaction_date, r.type, r.amount, r.raw_amount, r.is_duplicate, r.raw_row
                FROM bank_import_rows r
                JOIN bank_import_sessions s ON s.id = r.session_id AND s.status = 'imported'
                ORDER BY r.session_id, r.id
            ");
            while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
                $key = $groupOf[(int)$r['session_id']] ?? null;
                if ($key === null) continue;
                $raw = json_decode((string)$r['raw_row'], true) ?: [];
                $lines[$key][] = self::lineFromRow($r, $raw, $groups[$key]['kind']);
            }
        }

        return self::analyse(array_values($groups), $lines, $this->today());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * One stored statement line → the shape the walk uses.
     * signed: + money in (bank) / owed more (card), − the other way, null = direction unknown
     * (a transfer row: bank_import_rows stages it as 'expense' but its raw_amount sign is not
     * reliable — see BankImportService::commit()).
     */
    public static function lineFromRow(array $r, array $raw, string $kind = 'bank'): array
    {
        $amount = abs((float)($raw['amount'] ?? $r['amount'] ?? 0));
        $type = (string)($raw['type'] ?? $r['type'] ?? '');
        $signed = null;
        if ($kind === 'card' && !empty($raw['cc_role'])) {
            $signed = $raw['cc_role'] === 'charge' ? $amount : -$amount;
        } elseif ($type === 'income') {
            $signed = $amount;
        } elseif ($type === 'expense') {
            $signed = ((float)($raw['amount'] ?? 0)) < 0 ? $amount : -$amount;   // a negative 'expense' is a refund
        }
        return [
            'id' => (int)($r['id'] ?? 0),
            'session' => (int)($r['session_id'] ?? 0),
            'date' => (string)($r['transaction_date'] ?? ($raw['date'] ?? '')),
            'amount' => round($amount, 2),
            'signed' => $signed === null ? null : round($signed, 2),
            'balance' => self::parseBalance((string)($raw['raw_line'] ?? ''), $amount),
            'duplicate' => !empty($r['is_duplicate']),
        ];
    }

    /**
     * The running balance printed on a statement line, or null.
     *   CSV  "2026-02-03,SHELL,45.00,,1234.56"  → the last number AFTER the column holding the amount
     *   PDF  "FEB 03 SHELL 45.00 1,234.56"       → the second of two trailing amounts, when the first is the amount
     * Overdrawn balances: "1,234.56-", "(1,234.56)", "1,234.56 OD" → negative.
     */
    public static function parseBalance(string $line, float $amount): ?float
    {
        $line = trim($line);
        if ($line === '' || $amount <= 0) return null;
        $amount = round(abs($amount), 2);

        $cols = str_getcsv($line, ',', '"', '');
        if (count($cols) >= 3 && (self::looksLikeDate($cols[0]) || self::looksLikeDate($cols[1] ?? ''))) {
            $at = null;
            foreach ($cols as $i => $c) {
                if ($i === 0) continue;
                $n = self::number($c);
                if ($n !== null && abs(abs($n) - $amount) < 0.005) { $at = $i; break; }
            }
            if ($at === null) return null;
            for ($i = count($cols) - 1; $i > $at; $i--) {
                $n = self::number($cols[$i]);
                if ($n !== null) return $n;
            }
            return null;
        }

        $money = '\(?-?\$?\d{1,3}(?:,\d{3})*\.\d{2}\)?(?:\s?(?:-|CR|DR|OD)(?![A-Za-z]))?';
        if (!preg_match('/(' . $money . ')\s+(' . $money . ')\s*$/i', $line, $m)) return null;
        $first = self::number($m[1]);
        $second = self::number($m[2]);
        if ($first === null || $second === null || abs(abs($first) - $amount) >= 0.005) return null;
        return $second;
    }

    private static function looksLikeDate(string $s): bool
    {
        $s = trim($s, " \t\"");
        return (bool)preg_match('/^(\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}|\d{1,2}[\-\s][A-Za-z]{3}[\-\s]\d{2,4}|[A-Za-z]{3}\.?\s+\d{1,2},?\s+\d{4})$/', $s);
    }

    private static function number(string $s): ?float
    {
        $s = trim($s, " \t\"");
        if ($s === '') return null;
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        if (preg_match('/^(.*?)\s?(-|OD|DR)$/i', $s, $m) && $m[1] !== '') { $neg = true; $s = $m[1]; }
        elseif (preg_match('/^(.*?)\s?CR$/i', $s, $m) && $m[1] !== '') { $s = $m[1]; }
        $s = str_replace(['$', ',', ' '], '', $s);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) return null;
        $v = (float)$s;
        return round($neg ? -abs($v) : $v, 2);
    }

    /**
     * Put one account's lines in statement order. Each session's lines are tried forwards and
     * backwards (newest-first exports) and with the balance sign either way (a card balance
     * may print owed as negative); the reading that links the most lines wins. Then sessions
     * are merged by date and a line printed in two overlapping statements (same date, amount
     * and balance) is kept once.
     */
    public static function orderLines(array $lines): array
    {
        $bySession = [];
        foreach ($lines as $l) $bySession[$l['session']][] = $l;
        $merged = [];
        $seen = [];
        $sIdx = 0;
        foreach ($bySession as $sid => $ls) {
            usort($ls, fn($a, $b) => $a['id'] <=> $b['id']);
            $best = null;
            foreach ([false, true] as $rev) {
                foreach ([1, -1] as $pol) {
                    $try = $rev ? array_reverse($ls) : $ls;
                    $links = self::countLinks($try, $pol);
                    if ($best === null || $links > $best[0]) $best = [$links, $try, $pol];
                }
            }
            [, $ordered, $pol] = $best;
            foreach ($ordered as $pos => $l) {
                if ($l['balance'] !== null) {
                    $k = $l['date'] . '|' . number_format($l['amount'], 2, '.', '') . '|' . number_format($l['balance'] * $pol, 2, '.', '');
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;
                    $l['balance'] = round($l['balance'] * $pol, 2);
                } elseif ($l['duplicate']) {
                    continue;   // a copy of a line already imported, and nothing to chain it by
                }
                $l['_s'] = $sIdx;
                $l['_p'] = $pos;
                $merged[] = $l;
            }
            $sIdx++;
        }
        usort($merged, fn($a, $b) => [$a['date'], $a['_s'], $a['_p']] <=> [$b['date'], $b['_s'], $b['_p']]);
        return $merged;
    }

    private static function countLinks(array $ls, int $pol): int
    {
        $n = 0;
        $prev = null;
        foreach ($ls as $l) {
            if ($l['balance'] === null) continue;
            $b = $l['balance'] * $pol;
            if ($prev !== null && self::chains($prev, 0.0, $l, $b)) $n++;
            $prev = $b;
        }
        return $n;
    }

    /** Does $l (balance $bal) follow a balance of $prev with $pending of balance-less lines between? */
    private static function chains(float $prev, float $pending, array $l, float $bal): bool
    {
        if ($l['signed'] === null) {
            return abs($prev + $pending + $l['amount'] - $bal) < self::CENT || abs($prev + $pending - $l['amount'] - $bal) < self::CENT;
        }
        return abs($prev + $pending + $l['signed'] - $bal) < self::CENT;
    }

    /**
     * Walk the running balance. Returns:
     *   breaks        [{from, to, missing, before, after}] — a balance that doesn't follow the one before;
     *                 `missing` is the net of what is not in the CRM (+ in, − out)
     *   other_account [{from, to, lines}] — runs of lines that chain to a balance of their own
     *   linked / with_balance / lines
     */
    public static function walkChain(array $ordered): array
    {
        $tracks = [];      // each: ['last' => float, 'date' => string, 'lines' => int, 'from' => string]
        $breaks = [];
        $pending = 0.0;
        $pendingLines = [];
        $linked = 0;
        $withBal = 0;
        $n = count($ordered);
        for ($i = 0; $i < $n; $i++) {
            $l = $ordered[$i];
            if ($l['balance'] === null) {
                if ($l['signed'] !== null) $pending += $l['signed'];
                $pendingLines[] = $l;
                continue;
            }
            $withBal++;
            $bal = $l['balance'];
            if (!$tracks) {
                $tracks[] = ['last' => $bal, 'date' => $l['date'], 'lines' => 1, 'from' => $l['date']];
                $pending = 0.0; $pendingLines = [];
                continue;
            }
            $hit = null;
            foreach ($tracks as $t => $tr) {
                if (self::chains($tr['last'], $pending, $l, $bal) || self::chainsFlippingOne($tr['last'], $pending, $pendingLines, $l, $bal)
                    || ($pendingLines && self::chains($tr['last'], 0.0, $l, $bal))) {
                    $hit = $t;
                    break;
                }
            }
            if ($hit !== null) {
                $linked++;
                $tracks[$hit]['last'] = $bal;
                $tracks[$hit]['date'] = $l['date'];
                $tracks[$hit]['lines']++;
            } else {
                $main = self::mainTrack($tracks);
                if (self::resumesLater($ordered, $i, $tracks[$main]['last'])) {
                    // The main balance carries on further down: this line is another account's.
                    $tracks[] = ['last' => $bal, 'date' => $l['date'], 'lines' => 1, 'from' => $l['date']];
                } else {
                    // The main account jumped: lines are missing in between.
                    $prev = $tracks[$main]['last'];
                    if ($l['signed'] === null) {
                        $a = $bal - ($prev + $pending + $l['amount']);
                        $b = $bal - ($prev + $pending - $l['amount']);
                        $missing = abs($a) <= abs($b) ? $a : $b;
                    } else {
                        $missing = $bal - ($prev + $pending + $l['signed']);
                    }
                    $breaks[] = ['from' => $tracks[$main]['date'], 'to' => $l['date'], 'missing' => round($missing, 2),
                                 'before' => round($prev, 2), 'after' => round($bal, 2)];
                    $tracks[$main]['last'] = $bal;
                    $tracks[$main]['date'] = $l['date'];
                    $tracks[$main]['lines']++;
                }
            }
            $pending = 0.0;
            $pendingLines = [];
        }
        $other = [];
        $main = $tracks ? self::mainTrack($tracks) : null;
        foreach ($tracks as $t => $tr) {
            if ($t !== $main) $other[] = ['from' => $tr['from'], 'to' => $tr['date'], 'lines' => $tr['lines']];
        }
        return ['breaks' => $breaks, 'other_account' => $other, 'linked' => $linked, 'with_balance' => $withBal, 'lines' => $n];
    }

    /** The main account is the track with the most lines so far. */
    private static function mainTrack(array $tracks): int
    {
        $main = 0;
        foreach ($tracks as $t => $tr) if ($tr['lines'] > $tracks[$main]['lines']) $main = $t;
        return $main;
    }

    /** A balance-less line read the wrong way round (keyword-guessed PDF type) still chains. */
    private static function chainsFlippingOne(float $prev, float $pending, array $pendingLines, array $l, float $bal): bool
    {
        foreach (array_slice($pendingLines, 0, 12) as $p) {
            if ($p['signed'] === null) {
                foreach ([$p['amount'], -$p['amount']] as $v) {
                    if (self::chains($prev, $pending + $v, $l, $bal)) return true;
                }
            } elseif (self::chains($prev, $pending - 2 * $p['signed'], $l, $bal)) {
                return true;
            }
        }
        return false;
    }

    /** Does a later line (within LOOKAHEAD) chain onto the balance $last? */
    private static function resumesLater(array $ordered, int $i, float $last): bool
    {
        $n = min(count($ordered), $i + 1 + self::LOOKAHEAD);
        $pending = 0.0;
        for ($j = $i + 1; $j < $n; $j++) {
            $m = $ordered[$j];
            if ($m['balance'] === null) { if ($m['signed'] !== null) $pending += $m['signed']; continue; }
            if (self::chains($last, $pending, $m, $m['balance']) || self::chains($last, 0.0, $m, $m['balance'])) return true;
            $pending = 0.0;
        }
        return false;
    }

    /**
     * Without balances: runs of QUIET_DAYS+ days with no line, and months below DROP_SHARE of
     * the account's median (the first month and the current month are partial and skipped).
     */
    public static function fallbackGaps(array $lines, string $today): array
    {
        $dates = array_values(array_unique(array_filter(array_column($lines, 'date'))));
        sort($dates);
        $out = [];
        for ($i = 1; $i < count($dates); $i++) {
            $days = (int)((strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400) - 1;
            if ($days >= self::QUIET_DAYS) {
                $out[] = ['type' => 'quiet', 'from' => $dates[$i - 1], 'to' => $dates[$i], 'days' => $days];
            }
        }
        $counts = [];
        foreach ($lines as $l) { $m = substr((string)$l['date'], 0, 7); $counts[$m] = ($counts[$m] ?? 0) + 1; }
        ksort($counts);
        $current = substr($today, 0, 7);
        $months = array_keys($counts);
        $judge = array_values(array_filter($months, fn($m) => $m !== $months[0] && $m < $current));
        if (count($judge) >= 3) {
            $vals = array_map(fn($m) => $counts[$m], $judge);
            sort($vals);
            $mid = intdiv(count($vals), 2);
            $median = count($vals) % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
            foreach ($judge as $m) {
                if ($counts[$m] < $median * self::DROP_SHARE) {
                    $out[] = ['type' => 'drop', 'month' => $m, 'count' => $counts[$m], 'median' => $median];
                }
            }
        }
        return $out;
    }

    /** Per month: line count, first / last line date, sessions. */
    public static function months(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $m = substr((string)$l['date'], 0, 7);
            if (!isset($out[$m])) $out[$m] = ['month' => $m, 'lines' => 0, 'first' => $l['date'], 'last' => $l['date'], 'sessions' => []];
            $out[$m]['lines']++;
            if ($l['date'] < $out[$m]['first']) $out[$m]['first'] = $l['date'];
            if ($l['date'] > $out[$m]['last']) $out[$m]['last'] = $l['date'];
            $out[$m]['sessions'][$l['session']] = true;
        }
        ksort($out);
        foreach ($out as &$m) $m['sessions'] = array_keys($m['sessions']);
        return array_values($out);
    }

    /**
     * Is the month in for this account?
     *   never   — no line from this account, ever
     *   missing — no line in the month
     *   partial — lines stop before the month's end (or the statement closing day), and nothing later
     *   gap     — in, but the running balance breaks inside the month
     *   ok
     */
    public static function monthStatus(array $lines, string $ym, ?int $statementDay, array $breaks = []): array
    {
        if (!$lines) return ['status' => 'never', 'through' => null];
        $days = (int)date('t', strtotime($ym . '-01'));
        $end = $statementDay ? sprintf('%s-%02d', $ym, min($statementDay, $days)) : sprintf('%s-%02d', $ym, $days);
        $start = $statementDay
            ? date('Y-m-d', strtotime(date('Y-m', strtotime($ym . '-01 -1 month')) . '-' . sprintf('%02d', min($statementDay, (int)date('t', strtotime($ym . '-01 -1 month')))) . ' +1 day'))
            : $ym . '-01';
        $in = 0; $last = null; $later = false;
        foreach ($lines as $l) {
            $d = (string)$l['date'];
            if ($d >= $start && $d <= $end) { $in++; if ($last === null || $d > $last) $last = $d; }
            if ($d > $end) $later = true;
        }
        if ($in === 0) return ['status' => 'missing', 'through' => null];
        foreach ($breaks as $b) {
            if ($b['to'] >= $start && $b['from'] <= $end) return ['status' => 'gap', 'through' => $last];
        }
        $slack = date('Y-m-d', strtotime($end . ' -' . self::END_SLACK_DAYS . ' days'));
        if ($last < $slack && !$later) return ['status' => 'partial', 'through' => $last];
        return ['status' => 'ok', 'through' => $last];
    }

    /**
     * The whole report from accounts (+ their sessions) and their lines.
     * @param array $groups  [{key, account_id, label, kind, expected, statement_day, list_id, sessions}]
     * @param array $linesByKey  key => lines (lineFromRow shape)
     */
    public static function analyse(array $groups, array $linesByKey, string $today): array
    {
        $lastMonth = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
        $out = [];
        foreach ($groups as $g) {
            $all = $linesByKey[$g['key']] ?? [];
            $future = array_values(array_filter($all, fn($l) => $l['date'] > $today));
            $lines = array_values(array_filter($all, fn($l) => $l['date'] <= $today));
            $ordered = self::orderLines($lines);
            $chain = self::walkChain($ordered);
            $hasBalances = $chain['with_balance'] > 0 && $chain['with_balance'] >= 0.5 * max(1, count($ordered));
            $fallback = $hasBalances ? [] : self::fallbackGaps($lines, $today);
            $status = self::monthStatus($lines, $lastMonth, $g['statement_day'], $chain['breaks']);
            $recent = array_values(array_filter($g['sessions'], fn($s) => substr((string)$s['created_at'], 0, 10) >= date('Y-m-d', strtotime($today . ' -7 days'))));
            $out[] = [
                'key' => $g['key'], 'account_id' => $g['account_id'], 'list_id' => $g['list_id'],
                'label' => $g['label'], 'kind' => $g['kind'], 'expected' => (bool)$g['expected'],
                'statement_day' => $g['statement_day'],
                'lines' => count($lines), 'first' => $lines ? min(array_column($lines, 'date')) : null,
                'last' => $lines ? max(array_column($lines, 'date')) : null,
                'sessions' => count($g['sessions']),
                'method' => $hasBalances ? 'balance' : ($lines ? 'counts' : 'none'),
                'linked' => $chain['linked'], 'with_balance' => $chain['with_balance'],
                'breaks' => $chain['breaks'], 'other_account' => $chain['other_account'],
                'possible_gaps' => $fallback,
                'future_lines' => count($future),
                'months' => self::months($lines),
                'last_month' => ['month' => $lastMonth] + $status,
                'recent_skipped' => array_sum(array_column($recent, 'duplicates')),
            ];
        }
        usort($out, fn($a, $b) => [!$a['expected'], $a['kind'], $a['label']] <=> [!$b['expected'], $b['kind'], $b['label']]);
        return ['date' => $today, 'computed_at' => date('Y-m-d H:i:s'), 'last_month' => $lastMonth, 'accounts' => $out];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Words — Penny's strip, the Action Board
    // ─────────────────────────────────────────────────────────────────────────

    /** "TD chequing: 12–19 Feb, $1,240 missing between balances" (the sign — in or out — is in `missing`). */
    public static function breakText(string $label, array $b, ?string $today = null): string
    {
        $from = strtotime($b['from']); $to = strtotime($b['to']);
        $range = date('M', $from) === date('M', $to) && date('Y', $from) === date('Y', $to)
            ? date('j', $from) . '–' . date('j M', $to)
            : date('j M', $from) . ' – ' . date('j M', $to);
        if (date('Y', $to) !== substr($today ?? date('Y-m-d'), 0, 4)) $range .= ' ' . date('Y', $to);
        return sprintf('%s: %s, %s missing between balances', $label, $range,
            '$' . number_format(abs($b['missing']), abs($b['missing']) >= 100 ? 0 : 2));
    }

    public static function possibleGapText(string $label, array $g): string
    {
        if ($g['type'] === 'quiet') {
            return sprintf('%s: no lines %s – %s (%d days) — possible gap', $label, date('j M', strtotime($g['from'])), date('j M Y', strtotime($g['to'])), $g['days']);
        }
        return sprintf('%s: only %d lines in %s (usually ~%d) — possible gap', $label, $g['count'], date('M Y', strtotime($g['month'] . '-01')), (int)round($g['median']));
    }

    /** The strip's items: one per expected account for last month, then the gaps (newest first). */
    public static function strip(array $report, int $maxGaps = 3): array
    {
        $month = date('F', strtotime(($report['last_month'] ?? date('Y-m')) . '-01'));
        $accounts = [];
        $gaps = [];
        foreach ($report['accounts'] ?? [] as $a) {
            if ($a['expected']) {
                $st = $a['last_month']['status'];
                $accounts[] = ['label' => $a['label'], 'ok' => $st === 'ok', 'status' => $st,
                               'note' => $st === 'never' ? 'never imported' : ($st === 'missing' ? 'not imported' : ($st === 'partial' ? 'only to ' . date('j M', strtotime($a['last_month']['through'])) : ($st === 'gap' ? 'gap inside' : '')))];
            }
            foreach ($a['breaks'] as $b) $gaps[] = ['to' => $b['to'], 'text' => self::breakText($a['label'], $b, $report['date'] ?? null), 'weak' => false];
            foreach ($a['possible_gaps'] as $g) $gaps[] = ['to' => $g['to'] ?? ($g['month'] . '-28'), 'text' => self::possibleGapText($a['label'], $g), 'weak' => true];
        }
        usort($gaps, fn($x, $y) => strcmp($y['to'], $x['to']));
        $skipped = array_sum(array_column($report['accounts'] ?? [], 'recent_skipped'));
        return ['month' => $month, 'accounts' => $accounts, 'gaps' => array_slice($gaps, 0, $maxGaps),
                'more_gaps' => max(0, count($gaps) - $maxGaps), 'skipped' => (int)$skipped];
    }

    /**
     * From the 3rd of the month, every expected account whose last-month statement isn't in
     * is an Action Board item.
     */
    public static function briefItems(array $report, string $today): array
    {
        if ((int)substr($today, 8, 2) < self::BRIEF_FROM_DAY) return [];
        $ym = (string)($report['last_month'] ?? date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month')));
        if ($ym !== date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'))) return [];   // a stale report
        $month = date('F', strtotime($ym . '-01'));
        $since = substr($today, 0, 7) . '-' . sprintf('%02d', self::BRIEF_FROM_DAY);
        $items = [];
        foreach ($report['accounts'] ?? [] as $a) {
            if (!$a['expected']) continue;
            $st = $a['last_month']['status'];
            if ($st === 'ok') continue;
            if ($st === 'never') $text = "{$a['label']} has never been imported — the {$month} statement is missing";
            elseif ($st === 'missing') $text = "The {$month} {$a['label']} statement isn't imported yet";
            elseif ($st === 'partial') $text = "The {$month} {$a['label']} statement is only in to " . date('j M', strtotime($a['last_month']['through']));
            else $text = "The {$month} {$a['label']} statement has a gap — lines are missing between balances";
            $items[] = ['key' => 'penny:statement:' . $a['key'] . ':' . $ym, 'kind' => 'penny:statement_missing',
                        'value' => null, 'since' => $since, 'text' => $text, 'url' => self::URL,
                        'priority' => $st === 'gap' ? 3 : 2];
        }
        return $items;
    }
}
