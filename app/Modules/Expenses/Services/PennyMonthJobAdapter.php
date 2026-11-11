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
            return self::focusMonth(self::months($db)) !== null;
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
                $lines = array_slice(array_values(array_filter($all, [self::class, 'proposes'])), 0, self::MAX_LINES);
                if (!$lines) continue;
                // the live count (the months summary is up to CACHE_SECONDS old): the queue holds at most 30
                if (count($all) < 30) $m['bank_lines'] = count($all);
                $discs = self::monthDiscs($months, (string)$m['month']);
                foreach ($lines as $l) $out[] = self::fromLine($l, $m, $discs, 0, 0);
                break;
            }
            return $out;
        } catch (Throwable $e) {
            error_log('PennyMonthJobAdapter::jobs: ' . $e->getMessage());
            return [];
        }
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
    public static function fromLine(array $line, array $month, array $discs, int $index, int $total): array
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
        $ageText = (string)($age['age'] ?? '');
        $ageClass = (string)($age['class'] ?? '');
        $monthName = date('F', strtotime($month['month'] . '-01'));
        $left = (int)($month['bank_lines'] ?? 0);

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
            'left'       => ['label' => 'Bank line', 'who' => self::clip((string)($line['description'] ?? ''), 34),
                             'when' => trim($date . ' · ' . ($isIn ? '+' : '−') . $money . ' · now ' . $current, ' ·')],
            'right'      => ['label' => $proposed, 'who' => 'Penny proposes', 'when' => ''],
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
