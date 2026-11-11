<?php
declare(strict_types=1);

/**
 * Penny's month-balancing job for the job strip (Tim, 2026-11-10: "flow line needs to deal with balancing each
 * month's line"). The line is MonthCloseService::months() — per month, books against the bank statement, open
 * receipts, bank lines still to sort — and the flow line carries the DECISION that moves the oldest open month
 * toward exact: one waiting bank line at a time, with the account Penny proposes for it.
 *
 * A new face on existing logic only: the lines come from BankDeskService::queue() for that month (the same
 * proposals the Bank card makes) and the buttons carry the exact requests penny-bank.js sends to
 * /crm/api/bookkeeper.php (bank_decide: approve on her account, or keep as it is). No second write path; nothing
 * here writes anything. A line she has no account for, a Stripe payout and a client's payment (they need invoice
 * matching) stay on the Bank card: the strip never offers Approve without a proposal.
 *
 * The rail carries one disc per month (monthDiscs): ✓ exact, ≠ with the difference (a difference the month before
 * already had is marked carried, drawn quieter: the books-against-bank gap is cumulative), a bare ✗ for a month that is not off the
 * bank but still has lines or receipts open, ℹ for one still inside its waiting days, and ✓ "locked".
 * Locking stays a person's press on the Ledger/Bank card: the strip never locks (v1).
 *
 * Off unless ops_settings it_month_job = '1'. Any failure means no job.
 * No namespace / no autoloader in production: require_once and `new`.
 */
class PennyMonthJobAdapter
{
    public const SETTING = 'it_month_job';
    /** Seconds the months summary is kept: it runs the whole balance check (as PennyFocusService does). */
    public const CACHE_SECONDS = 180;
    public const CACHE_KEY = 'it_month_job_cache';
    public const MAX_LINES = 10;
    public const MAX_DISCS = 12;
    /** A difference smaller than this is exact (MonthCloseService::CENT). */
    private const CENT = 0.005;

    public static function enabled(PDO $db): bool
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::SETTING]);
            return (string)$s->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Cheap gate for the strip: is the switch on and is there a month with lines to sort? Any failure means no. */
    public static function hasWork(PDO $db): bool
    {
        try {
            if (!self::enabled($db)) return false;
            $months = self::months($db);
            return self::focusMonth($months) !== null || self::askCandidate($db, $months) !== null;
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::hasWork: ' . $e->getMessage());
            return false;
        }
    }

    /** @return list<array> the month's proposable lines as jobs (index/total set by the caller). [] on any failure. */
    public static function jobs(PDO $db): array
    {
        try {
            if (!self::enabled($db)) return [];
            self::load();
            $months = self::months($db);
            $desk = new BankDeskService($db);
            $out = [];
            // The oldest open month that has a line she can propose for: a month holding only Stripe payouts, client payments or
            // lines with no account of hers stays on the Bank card and must not stall the months after it (review, 2026-11-10).
            foreach ($months as $m) {
                if (!empty($m['locked']) || !empty($m['ready']) || (int)($m['bank_lines'] ?? 0) < 1) continue;
                $all = $desk->queue(30, (string)$m['month']);
                $lines = array_values(array_filter($all, [self::class, 'proposes']));
                $lines = array_slice(self::withoutClosedYearReceipts($db, $lines), 0, self::MAX_LINES);
                if (!$lines) continue;
                // the live count (the months summary is up to CACHE_SECONDS old): the queue holds at most 30
                if (count($all) < 30) $m['bank_lines'] = count($all);
                $discs = self::monthDiscs($months, (string)$m['month']);
                $facts = self::factsFor($db, $lines);
                foreach ($lines as $l) $out[] = self::fromLine($l, $m, $discs, 0, 0, $facts[(int)$l['id']] ?? []);
                break;
            }
            // Nothing to sort: the oldest gap Penny cannot match goes on the accountant's list (a question, never an email)
            if (!$out && ($ask = self::askCandidate($db, $months)) !== null) $out[] = $ask;
            return $out;
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * What a line's own evidence is, read from the books for the lines about to be offered: the account it came out of
     * (the journal entry's bank or card side — accounting_transactions.bank_account is empty on imports), how many earlier
     * lines with exactly this description are already filed on the account she proposes, and nothing else:
     * a receipt is evidence only when she says she found one (suggestion.expense_id). Two cheap queries per line; [] on failure.
     * @return array<int, array{account:string, earlier:int}>
     */
    public static function factsFor(PDO $db, array $lines): array
    {
        $out = [];
        try {
            foreach ($lines as $l) {
                $id = (int)$l['id'];
                $acct = '';
                $q = $db->prepare("SELECT c.code, c.name FROM journal_entries e JOIN journal_lines jl ON jl.entry_id = e.id
                                   JOIN chart_of_accounts c ON c.id = jl.account_id
                                   WHERE e.source_type = 'bank_import' AND e.source_id = ? AND e.reversed_by_entry_id IS NULL AND e.status = 'posted'
                                   AND c.code IN ('1010', '1020', '1025', '2400') ORDER BY e.id DESC LIMIT 1");
                $q->execute([$id]);
                if ($r = $q->fetch(PDO::FETCH_ASSOC)) $acct = $r['code'] . ' ' . $r['name'];
                $e = $db->prepare("SELECT COUNT(*) FROM accounting_transactions WHERE reference_type = 'bank_import' AND description = ? AND account_id = ? AND id <> ? AND transaction_date <= ?");
                $e->execute([(string)($l['description'] ?? ''), (int)($l['suggestion']['account_id'] ?? 0), $id, (string)($l['date'] ?? date('Y-m-d'))]);
                $out[$id] = ['account' => $acct, 'earlier' => (int)$e->fetchColumn()];
            }
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::factsFor: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * The "Add to the accountant's list" job for the oldest gap Penny cannot match, or null. A month qualifies when it is off the
     * bank by an amount that is NEW this month (not carried from the month before), has no waiting bank lines or open receipts
     * left to sort, is past its waiting days, and is not already on the list (in any state). The words come from the service.
     */
    public static function askCandidate(PDO $db, array $months, ?string $today = null): ?array
    {
        try {
            require_once __DIR__ . '/../../Accounting/Services/AccountantQuestionService.php';
            $aq = new AccountantQuestionService($db);
            if (!$aq->ready()) return null;
            // 1: only an admin may add to the list (bookkeeper.php refuses anyone else, so the job would never clear)
            if (!function_exists('isAdmin') || !isAdmin()) return null;
            $ym = self::askMonth($months, $today ?? date('Y-m-d'), static fn(string $m): bool => $aq->has(AccountantQuestionService::keyFor($m)));
            if ($ym === null) return null;
            self::load();
            $sig = md5(json_encode(array_values(array_filter($months, static fn($x) => $x['month'] === $ym))));
            $draft = self::cachedDraft($db, $ym . '|' . $sig, static fn() => $aq->draftFor($ym, $months));
            if ($draft === null) return null;
            $m = null; foreach ($months as $x) if ($x['month'] === $ym) $m = $x;
            return self::fromGap($draft, (array)$m, self::monthDiscs($months, $ym), 0, 0);
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::askCandidate: ' . $e->getMessage());
            return null;
        }
    }

    /** The question's draft is kept ASK_CACHE_SECONDS (the first month's reads the Dec 31 statements): same month, same amount. */
    public const ASK_CACHE_SECONDS = 600;
    private static function cachedDraft(PDO $db, string $ym, callable $make): ?array   // $ym: the month plus a signature of its figures, so a moved balance is re-read
    {
        $key = 'it_month_job_ask_cache';
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([$key]);
            $c = json_decode((string)$s->fetchColumn(), true);
            if (is_array($c) && ($c['ym'] ?? '') === $ym && time() - (int)($c['at'] ?? 0) < self::ASK_CACHE_SECONDS && is_array($c['draft'] ?? null)) return $c['draft'];
        } catch (Throwable $e) { /* compute */ }
        $draft = $make();
        if ($draft !== null) {
            try {
                $db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                   ->execute([$key, json_encode(['at' => time(), 'ym' => $ym, 'draft' => $draft])]);
            } catch (Throwable $e) { /* a miss next time is fine */ }
        }
        return $draft;
    }

    /** Pure: the oldest open month whose gap is new this month and has nothing left to sort; null when none. */
    public static function askMonth(array $months, string $today, ?callable $asked = null): ?string
    {
        $prev = null;
        foreach ($months as $m) {
            $drift = 0.0;
            foreach ((array)($m['drift'] ?? []) as $d) $drift += (float)$d;
            $open = empty($m['locked']) && empty($m['ready']);
            $isNew = abs($drift - (float)($prev ?? 0.0)) >= self::CENT;
            $quiet = (int)($m['bank_lines'] ?? 0) === 0 && (int)($m['receipts'] ?? 0) === 0;
            $past = empty($m['grace_until']) || (string)$m['grace_until'] <= $today;
            if ($open && abs($drift) >= self::CENT && $isNew && $quiet && $past && !($asked && $asked((string)$m['month']))) return (string)$m['month'];   // a month already on the list is skipped, the next new gap is still offered
            $prev = !empty($m['locked']) ? null : $drift;
        }
        return null;
    }

    /**
     * Pure: a gap Penny cannot match → the strip's job. Add to list posts accountant_question (bookkeeper.php; the server writes
     * the words from the books); Leave it records that the gap is not to be asked about.
     * @param array $draft AccountantQuestionService::draftFor()
     */
    public static function fromGap(array $draft, array $month, array $discs, int $index, int $total): array
    {
        $amount = (float)$draft['amount'];
        $money = '$' . number_format(abs($amount), 2);
        $monthName = date('F', strtotime($draft['month'] . '-01'));
        $after = [];
        foreach ($discs as $d) if (!empty($d['focus'])) $after = $d;
        if ($after) { $after['top'] = 'on the list'; $after['label'] = $monthName; }
        $req = static fn(string $action): array => ['mode' => 'accountant_question', 'month' => (string)$draft['month'], 'action' => $action];
        return [
            'head'       => 'penny',
            'kind'       => 'month',
            'key'        => 'gap:' . $draft['month'],
            'title'      => $monthName . ' is ' . $money . ' off the bank — nothing Penny can match',
            'confidence' => 'I checked every bank line and receipt in ' . $monthName . '; none explains it.',
            'sure'       => 'forming',
            'months'     => $discs,
            'after'      => $after ?: null,
            'left'       => ['label' => (string)$draft['account'], 'who' => 'Books ' . ($amount > 0 ? '+' : '−') . $money . ($amount > 0 ? ' over' : ' under') . ' the bank', 'when' => 'at the end of ' . $monthName],
            'right'      => ['label' => 'Ricole\'s list', 'who' => 'Penny adds the question', 'when' => (string)($draft['evidence_short'] ?? 'nothing is sent')],
            'columns'    => [],
            'primary'    => ['label' => 'Add to list', 'sub' => 'for Ricole', 'request' => $req('add')],
            'secondary'  => ['label' => 'Leave it', 'sub' => 'don\'t ask', 'request' => $req('drop')],
            'undo'       => null,
            'start'      => ['label' => 'Bank', 'href' => '/crm/accounting/bank-balance-check.php', 'icon' => 'bank'],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    /**
     * A line whose "found" receipt is dated before this ledger's first month (FY2025: filed, locked, receipts there are never booked
     * again) is NOT offered here: linking it reverses the line's own entry and the receipt has no 2026 entry to stand in for it, so
     * the month's books drift from the bank by the amount (seen 2026-11-10, University Landscapers $157.50: receipt of Dec 30 2025,
     * Approve moved January's books $157.50 off the bank). Such a line stays on the Bank card, where the choice is made knowingly.
     */
    public static function withoutClosedYearReceipts(PDO $db, array $lines): array
    {
        $ids = [];
        foreach ($lines as $l) if (!empty($l['suggestion']['expense_id'])) $ids[] = (int)$l['suggestion']['expense_id'];
        if (!$ids) return $lines;
        try {
            self::load();
            $q = $db->query("SELECT id, expense_date FROM expenses WHERE id IN (" . implode(',', array_unique($ids)) . ")");
            $dates = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $dates[(int)$r['id']] = (string)$r['expense_date'];
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::withoutClosedYearReceipts: ' . $e->getMessage());
            return array_values(array_filter($lines, static fn($l) => empty($l['suggestion']['expense_id'])));   // unsure: leave receipt links to the card
        }
        return array_values(array_filter($lines, static function ($l) use ($dates): bool {
            $eid = (int)($l['suggestion']['expense_id'] ?? 0);
            return $eid === 0 || !self::inClosedYear($dates[$eid] ?? '');
        }));
    }

    /** Pure: is this expense date before the ledger's first month (MonthCloseService::FIRST), or unknown? */
    public static function inClosedYear(string $expenseDate): bool
    {
        return !preg_match('/^\d{4}-\d{2}/', $expenseDate) || substr($expenseDate, 0, 7) < MonthCloseService::FIRST;
    }

    /** The months summary, kept CACHE_SECONDS in ops_settings: only what the rail and the blockers need. */
    private static function months(PDO $db): array
    {
        try {
            $s = $db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::CACHE_KEY]);
            $c = json_decode((string)$s->fetchColumn(), true);
            if (is_array($c) && isset($c['at'], $c['months']) && is_array($c['months']) && time() - (int)$c['at'] < self::CACHE_SECONDS) return $c['months'];
        } catch (Throwable $e) { /* no cache: compute */ }
        self::load();
        $months = [];
        foreach ((new MonthCloseService($db))->months() as $m) {
            $months[] = ['month' => (string)$m['month'], 'locked' => (bool)$m['locked'], 'ready' => (bool)$m['ready'],
                         'drift' => array_map('floatval', (array)$m['drift']), 'receipts' => (int)$m['receipts'],
                         'bank_lines' => (int)$m['bank_lines'], 'grace_until' => (string)($m['grace_until'] ?? ''),
                         'blockers' => array_values(array_map('strval', (array)$m['blockers']))];
        }
        try {
            $db->prepare("INSERT INTO ops_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
               ->execute([self::CACHE_KEY, json_encode(['at' => time(), 'months' => $months])]);
        } catch (Throwable $e) { /* a cache miss next time is fine */ }
        return $months;
    }

    /** Pure: the oldest month that is open and has bank lines waiting; null when none. */
    public static function focusMonth(array $months): ?array
    {
        foreach ($months as $m) {
            if (!empty($m['locked'])) continue;
            if (!empty($m['ready'])) continue;
            if ((int)($m['bank_lines'] ?? 0) > 0) return $m;
        }
        return null;
    }

    /** Pure: may the strip offer Approve for this bank desk line in one press? Not without her account; not Stripe or a client's payment. */
    public static function proposes(array $line): bool
    {
        $s = $line['suggestion'] ?? null;
        if (!is_array($s) || empty($s['account_id'])) return false;
        if (!empty($line['stripe']) || !empty($line['client_payment'])) return false;
        return true;
    }

    /**
     * Pure: the knowledge triangle for a bank line. Earned by record, never read off one match: she has no
     * recorded count here yet, so a line is forming, or held when she has a rule or a lesson behind the account.
     */
    public static function sureOf(array $suggestion): string
    {
        return in_array((string)($suggestion['source'] ?? ''), ['rule', 'learned'], true) ? 'held' : 'forming';
    }

    /**
     * Pure: one disc per month, oldest first, ending at the newest month; at most MAX_DISCS (the focus month always kept).
     * state is the rail's disc vocabulary: same (✓), differs (≠), missing (✗), info.
     * @return list<array{month:string,label:string,top:string,bottom:string,state:string,carried:bool,focus:bool}>
     */
    public static function monthDiscs(array $months, ?string $focus): array
    {
        $out = [];
        $prev = null;   // previous month's total difference, to tell carried from new
        foreach ($months as $m) {
            $drift = 0.0;
            foreach ((array)($m['drift'] ?? []) as $d) $drift += (float)$d;
            $label = date('M', strtotime($m['month'] . '-01'));
            $open = 0; $carried = false;
            if ((int)($m['bank_lines'] ?? 0) > 0) $open++;
            if ((int)($m['receipts'] ?? 0) > 0) $open++;
            if (!empty($m['locked'])) { $state = 'same'; $bottom = 'locked'; }
            elseif (!empty($m['ready'])) { $state = 'same'; $bottom = 'exact'; }
            elseif (abs($drift) >= self::CENT) {
                $state = 'differs';
                $carried = $prev !== null && abs($drift - $prev) < self::CENT;   // the gap is cumulative: the same figure as last month is not new
                $bottom = ($drift > 0 ? '+' : '−') . number_format(abs($drift), 2);
            }
            elseif ($open > 0) { $state = 'missing'; $bottom = (int)$m['bank_lines'] . ' lines'; if (!(int)$m['bank_lines']) $bottom = (int)$m['receipts'] . ' receipts'; }
            else { $state = 'info'; $bottom = !empty($m['grace_until']) ? 'waiting' : 'check'; }
            $out[] = ['month' => (string)$m['month'], 'label' => $label, 'top' => $label, 'bottom' => $bottom, 'state' => $state, 'carried' => $carried,
                      'focus' => $focus !== null && $m['month'] === $focus];
            $prev = empty($m['locked']) ? $drift : null;
        }
        if (count($out) > self::MAX_DISCS) {
            $keep = array_slice($out, -self::MAX_DISCS);
            $out = $keep;
        }
        return $out;
    }

    /**
     * One bank desk line of the focus month → the generic job shape. Pure.
     * The frame names both sides (the bank line above the rail, her account below), so the columns carry only the
     * comparison: the account it is on now against the one she proposes, and how long it has waited.
     */
    public static function fromLine(array $line, array $month, array $discs, int $index, int $total, array $facts = []): array
    {
        $s = (array)$line['suggestion'];
        $id = (int)$line['id'];
        $amount = (float)($line['amount'] ?? 0);
        $money = '$' . number_format(abs($amount), 2);
        $isIn = ($line['type'] ?? '') === 'income';
        $current = is_array($line['current'] ?? null) ? (string)($line['current']['code'] ?? '') : 'unsorted';
        $proposed = trim((string)$s['code'] . ' ' . (string)$s['name']);
        $date = !empty($line['date']) ? date('M j', strtotime((string)$line['date'])) : '';
        $age = (array)($line['age'] ?? []);
        $days = (int)($age['age'] ?? 0);
        $ageText = $days > 0 ? $days . ' day' . ($days === 1 ? '' : 's') : '';
        $ageClass = (string)($age['class'] ?? '');
        $monthName = date('F', strtotime($month['month'] . '-01'));
        $left = (int)($month['bank_lines'] ?? 0);

        // The evidence, honestly: a receipt only when she found one; otherwise what the earlier lines like it say.
        $earlier = (int)($facts['earlier'] ?? 0);
        $evidence = !empty($s['expense_id']) ? 'Receipt found'
            : ('No receipt' . ($earlier > 0 ? ' · ' . $earlier . ' earlier on ' . (string)$s['code'] : ''));
        $account = trim((string)($facts['account'] ?? ''));
        // After this press, the focus month still stands at its difference, with one line fewer
        $after = [];
        foreach ($discs as $d) if (!empty($d['focus'])) $after = $d;
        $afterLeft = max(0, $left - 1);
        if ($after) {
            $after['top'] = $afterLeft === 0 ? 'no lines left' : $afterLeft . ' line' . ($afterLeft === 1 ? '' : 's') . ' left';
            $after['label'] = $monthName;
        }

        $request = ['mode' => 'bank_decide', 'transaction_id' => $id, 'action' => 'approve',
                    'account_id' => (int)$s['account_id'], 'suggested_id' => (int)$s['account_id']];
        if (!empty($s['expense_id'])) $request['expense_id'] = (int)$s['expense_id'];

        return [
            'head'       => 'penny',
            'kind'       => 'month',
            'key'        => 'bank:' . $id,
            'title'      => 'Balancing ' . $monthName . ' — ' . $left . ' bank line' . ($left === 1 ? '' : 's') . ' to sort',
            'confidence' => trim((string)($s['reason'] ?? '')),
            'sure'       => self::sureOf($s),
            'months'     => $discs,
            'after'      => $after ?: null,
            'left'       => ['label' => $account !== '' ? $account : 'Bank line', 'who' => self::clip((string)($line['description'] ?? ''), 34),
                             'when' => trim($date . ' · ' . ($isIn ? '+' : '−') . $money . ' · now ' . $current, ' ·')],
            'right'      => ['label' => $proposed, 'who' => 'Penny proposes', 'when' => $evidence],
            // the frame names both sides (the line and where it is now, her account), so the one column is the comparison: how long it has waited
            'columns'    => [
                ['label' => 'Waiting', 'top' => $ageText, 'bottom' => '', 'state' => $ageClass === 'investigate' ? 'missing' : 'info'],
            ],
            'primary'    => ['label' => 'Approve', 'sub' => (string)$s['code'], 'request' => $request],
            'secondary'  => ['label' => 'Keep it', 'sub' => 'as it is',
                             'request' => ['mode' => 'bank_decide', 'transaction_id' => $id, 'action' => 'keep', 'suggested_id' => (int)$s['account_id']]],
            'undo'       => null,
            'start'      => ['label' => 'Bank', 'href' => '/crm/accounting/penny-bank.php', 'icon' => 'bank'],
            'anchor'     => ['label' => 'Ledger', 'href' => '/crm/ledger/'],
            'index'      => $index,
            'total'      => $total,
        ];
    }

    private static function clip(string $s, int $n): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
    }

    private static function load(): void
    {
        require_once __DIR__ . '/../../Accounting/Services/MonthCloseService.php';
        require_once __DIR__ . '/../../Accounting/Services/BankDeskService.php';
    }
}
