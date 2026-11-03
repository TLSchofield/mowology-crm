<?php
/**
 * MonthCloseListService — the month-close routine (plan docs/plans/2026-10-14-beyond-today.md item 3).
 *
 * Once a month, after MonthCloseService::GRACE_DAYS, Penny emails the owner ONE list for the oldest
 * month that isn't locked: what stands between it and exact (statements, books vs bank, each open
 * receipt, each bank line with her guess), each linked to the place on her card that settles it.
 * When the list is empty the email says the month is exact and asks for the Lock press.
 *
 * Penny never locks a month herself: there is no unlock (StatementCloseService::lockMonth sends
 * anything late into the current month), so locking stays a person's press. Email links only open
 * pages; nothing in the email changes the books.
 *
 * Sent at most once per calendar month (ops_settings month_close_list_sent = "YYYY-MM sent for YYYY-MM").
 * Called from Charlie's cron (charlie_morning_brief.php), which already knows the owner and the mail.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/MonthCloseService.php';
require_once __DIR__ . '/BankLineReceipts.php';
require_once __DIR__ . '/CloseNarrative.php';   // the month in plain words (2026-11-03)

class MonthCloseListService
{
    public const SETTING = 'month_close_list_sent';
    public const MAX_ROWS = 15;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The month whose list is due today, or null (too early this month, already sent, or nothing open). */
    public function due(?string $today = null): ?array
    {
        $today = $today ?? date('Y-m-d');
        $sent = $this->setting();
        foreach ((new MonthCloseService($this->db))->months($today) as $m) {
            if ($m['locked']) continue;
            if (!self::isDue($m['month'], $today, $sent)) return null;
            return $m;
        }
        return null;
    }

    /**
     * Pure (tested): is the list for $month due on $today? Its grace has passed, and no list went out
     * yet this calendar month.
     */
    public static function isDue(string $month, string $today, ?string $sent): bool
    {
        if ($today < MonthCloseService::graceUntil($month)) return false;
        return !($sent !== null && substr($sent, 0, 7) === substr($today, 0, 7));
    }

    /** Everything on the list for a month: [statements/drift lines, receipts, bank lines]. */
    public function items(array $m): array
    {
        $ym = (string)$m['month'];
        $receipts = [];
        try {
            $s = $this->db->prepare("
                SELECT e.id, e.expense_date, e.total, COALESCE(NULLIF(v.name, ''), e.vendor_name_raw, 'Receipt') AS vendor, e.payment_method,
                       (CASE WHEN " . BankLineReceipts::linkedSql($this->db, 'e.id') . " THEN 1 ELSE 0 END) AS linked
                FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id
                WHERE e.status IN ('draft', 'pending_approval') AND DATE_FORMAT(e.expense_date, '%Y-%m') = ?
                ORDER BY e.expense_date, e.id");
            $s->execute([$ym]);
            $receipts = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[MonthCloseList] receipts: ' . $e->getMessage());
        }
        $lines = [];
        try {
            require_once __DIR__ . '/BankDeskService.php';
            $lines = (new BankDeskService($this->db))->queue(30, $ym);
        } catch (Throwable $e) {
            error_log('[MonthCloseList] bank lines: ' . $e->getMessage());
        }
        return ['month' => $m, 'receipts' => $receipts, 'lines' => $lines,
                'since' => $this->openSince($m),                 // blocker text => first date it was on the list (carried items)
                'na' => self::unavailable($m),                    // sources that could not be read: named, never silent
                'narrative' => $this->narrative($ym, (array)($m['blockers'] ?? []))];
    }

    /**
     * Carried items (plugin close rule, 2026-11-03): a blocker that was on an earlier list is never dropped — it comes back
     * with the date it first appeared. Kept per month in ops_settings as JSON {text: first_seen}. Returns the map.
     */
    public function openSince(array $m, ?string $today = null): array
    {
        $today = $today ?: date('Y-m-d');
        $key = 'month_close_open_' . (string)$m['month'];
        $seen = [];
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $seen = json_decode((string)$s->fetchColumn(), true) ?: [];
        } catch (Throwable $e) { return []; }
        $now = [];
        foreach ((array)($m['blockers'] ?? []) as $b) {
            if (strpos($b, 'waiting until') === 0) continue;
            $k = preg_replace('/\$[\d,]+\.\d{2}|\b\d+\b/', '#', $b);   // the same item with a different count or amount is the same item
            $now[$k] = $seen[$k] ?? $today;
        }
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                     ->execute([$key, json_encode($now), 'Penny: when each open item first appeared on the ' . $m['month'] . ' close list']);
        } catch (Throwable $e) { /* cache only */ }
        $out = [];
        foreach ((array)($m['blockers'] ?? []) as $b) {
            $k = preg_replace('/\$[\d,]+\.\d{2}|\b\d+\b/', '#', $b);
            if (isset($now[$k])) $out[$b] = $now[$k];
        }
        return $out;
    }

    /** Pure: sources the close could not read this time — said in the email as n/a, not left looking clean. */
    public static function unavailable(array $m): array
    {
        $na = [];
        $st = $m['settlements'] ?? null;
        if (is_array($st) && !empty($st['error'])) $na[] = 'Stripe (payout check) — ' . $st['error'];
        if (!is_array($m['bank_age'] ?? null) && array_key_exists('bank_age', $m)) $na[] = 'bank-line ages';
        return $na;
    }

    /** The month in 150–250 plain words from the ledger (CloseNarrative); null when the P&L cannot be read. */
    public function narrative(string $ym, array $blockers): ?string
    {
        try {
            require_once __DIR__ . '/AccountingService.php';
            $acct = new AccountingService($this->db);
            $pl = static function (array $r): array {
                $lines = [];
                // Income (4xxx) and expense (5xxx–8xxx) accounts only: a liability such as GST collected or the card payable
                // can ride along in the P&L rows and must not be narrated as an expense that "is new this month".
                $isPl = static fn($row) => preg_match('/^[45678]/', (string)($row['code'] ?? '')) === 1 || !isset($row['code']);
                foreach ((array)($r['revenue'] ?? []) as $row) if ($isPl($row)) $lines[(string)$row['name']] = (float)$row['total'];
                foreach ((array)($r['expenses'] ?? []) as $row) if ($isPl($row)) $lines[(string)$row['name']] = -(float)$row['total'];
                return ['income' => (float)($r['total_revenue'] ?? array_sum(array_column((array)($r['revenue'] ?? []), 'total'))),
                        'expenses' => (float)($r['total_expenses'] ?? array_sum(array_column((array)($r['expenses'] ?? []), 'total'))), 'lines' => $lines];
            };
            $cur = $pl($acct->getProfitLoss($ym . '-01', date('Y-m-t', strtotime($ym . '-01'))));
            $pm = date('Y-m', strtotime($ym . '-01 -1 month'));
            $prior = $pm >= MonthCloseService::FIRST ? $pl($acct->getProfitLoss($pm . '-01', date('Y-m-t', strtotime($pm . '-01')))) : null;
            return CloseNarrative::write($ym, $cur, $prior, $blockers);
        } catch (Throwable $e) {
            error_log('[MonthCloseList] narrative: ' . $e->getMessage());
            return null;
        }
    }

    /** Pure (tested): the email. Returns [subject, html body]. */
    public static function email(array $items, string $name, string $baseUrl): array
    {
        $m = $items['month'];
        $label = date('F Y', strtotime($m['month'] . '-01'));
        $base = rtrim($baseUrl, '/');
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $font = "font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#0D3B2E;";
        $p = static fn($html) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.5;' . $font . '">' . $html . '</p>';
        $penny = $base . '/crm/dashboard_appstack.php#mw-penny';

        $blockers = array_values(array_filter((array)$m['blockers'], fn($b) => strpos($b, 'waiting until') !== 0));
        $since = (array)($items['since'] ?? []);
        $na = (array)($items['na'] ?? []);
        $story = !empty($items['narrative']) ? $p('<strong>The month in plain words.</strong> ' . $e($items['narrative'])) : '';
        $naLine = $na ? $p('<strong>Not checked this time:</strong> ' . $e(implode('; ', $na)) . ' — n/a is not clean; I will try again tomorrow.') : '';
        if (!$blockers) {
            $html = $p($e($name) . ', ' . $e($label) . ' is exact: every statement checks out, the books match the bank to the cent, '
                       . 'and nothing is open.') . $story . $naLine
                  . $p('<a href="' . $e($penny) . '" style="color:#0D3B2E;font-weight:bold;">Lock ' . $e($label) . '</a> on my card when you\'re happy. '
                       . 'Once it\'s locked, anything that turns up late for it goes into the current month.')
                  . $p('— Penny');
            return ['subject' => $label . ' is exact — ready to lock', 'body' => $html];
        }

        $li = static fn($t) => '<li style="margin:0 0 6px;">' . $t . '</li>';
        $ul = static fn($rows) => '<ul style="margin:0 0 14px;padding-left:18px;font-size:14px;line-height:1.45;' . $font . '">' . implode('', $rows) . '</ul>';
        $html = $p($e($name) . ', here is what stands between ' . $e($label) . ' and exact. Answer these and it can be locked.') . $story . $naLine;

        $top = [];
        foreach ($blockers as $b) {
            if (preg_match('/^\d+ (open receipt|bank line)/', $b)) continue;   // listed one by one below
            $first = $since[$b] ?? null;
            $carried = $first !== null && (strtotime($first) < strtotime('-7 days')) ? ' <span style="color:#e85d04;">(open since ' . $e(date('M j', strtotime($first))) . ')</span>' : '';
            $top[] = $li($e(ucfirst($b)) . $carried);
        }
        if ($top) $html .= $ul($top);

        $rows = [];
        foreach (array_slice($items['receipts'], 0, self::MAX_ROWS) as $r) {
            $hint = (int)$r['linked'] > 0 ? 'its bank line is found — check and approve'
                  : 'no bank or card line found — if you paid personally, choose "Paid personally (owner)"';
            $rows[] = $li('<a href="' . $e($base . '/crm/expenses_appstack.php?edit=' . (int)$r['id']) . '" style="color:#0D3B2E;">'
                     . $e($r['vendor']) . ', $' . number_format((float)$r['total'], 2) . ' (' . $e(date('M j', strtotime($r['expense_date']))) . ')</a> — ' . $e($hint));
        }
        if ($rows) {
            $more = count($items['receipts']) - self::MAX_ROWS;
            $html .= $p('<strong>Receipts to approve (' . count($items['receipts']) . ')</strong>') . $ul($rows)
                   . ($more > 0 ? $p($e('and ' . $more . ' more on the receipts page.')) : '');
        }

        $rows = [];
        foreach (array_slice($items['lines'], 0, self::MAX_ROWS) as $l) {
            $guess = !empty($l['suggestion']['name']) ? 'my guess: ' . $l['suggestion']['code'] . ' ' . $l['suggestion']['name']
                   : (!empty($l['client_payment']) ? 'a client payment — which invoice?' : 'I don\'t know yet');
            $rows[] = $li($e(date('M j', strtotime($l['date']))) . ' — ' . $e(mb_substr((string)$l['description'], 0, 60)) . ', $'
                     . number_format(abs((float)$l['amount']), 2) . ' — ' . $e($guess));
        }
        if ($rows) {
            $more = count($items['lines']) - self::MAX_ROWS;
            $html .= $p('<strong>Bank lines to sort (' . count($items['lines']) . ($more > 0 ? '+' : '') . ')</strong> — each is one tap on '
                        . '<a href="' . $e($penny) . '" style="color:#0D3B2E;">my card</a>.') . $ul($rows);
        }
        $html .= $p('— Penny');
        return ['subject' => $label . ' close: ' . count($blockers) . ' thing' . (count($blockers) === 1 ? '' : 's') . ' left', 'body' => $html];
    }

    public function markSent(string $month, ?string $today = null): void
    {
        $v = substr($today ?? date('Y-m-d'), 0, 7) . ' sent for ' . $month;
        $u = $this->db->prepare("UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?");
        $u->execute([$v, self::SETTING]);
        if ($u->rowCount() > 0 || $this->setting() !== null) return;
        $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)")
                 ->execute([self::SETTING, $v, 'Penny: the last month-close list emailed (MonthCloseListService)']);
    }

    private function setting(): ?string
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            $v = $s->fetchColumn();
            return $v === false || $v === null ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
