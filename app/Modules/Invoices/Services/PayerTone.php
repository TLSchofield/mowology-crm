<?php
declare(strict_types=1);

/**
 * PayerTone — how an overdue reminder should sound for THIS payer, from how they have paid us.
 *
 * Harvested from Anthropic's small-business plugin (invoice-chase, tone-matching), 2026-11-03:
 *   good-payer        paid on or before the due date in >= 75% of the last 12 months' invoices → gentle
 *   occasionally-late late in 25–50%, or fewer than 3 paid invoices on record                → neutral
 *   repeat-late       late in more than 50%                                                  → firm
 * One email per payer; several overdue invoices are listed in one message with the combined
 * total, using the payer's tone (never the most-overdue invoice's). One call to action per email.
 *
 * This class decides wording only. WHETHER a reminder goes at all is ReminderGate's job (grace,
 * AP inboxes, deposits, statements, recent payments) and nothing here overrides it. Reminders stay
 * link-only (Tim, 2026-10-06): the invoice PDF is attached only where the gate says an AP inbox needs it.
 *
 * Pure and static: the gate gathers the history, this class reasons about it.
 */
final class PayerTone
{
    public const GENTLE = 'gentle';
    public const NEUTRAL = 'neutral';
    public const FIRM = 'firm';

    public const MIN_INVOICES = 3;
    public const GOOD_ON_TIME_SHARE = 0.75;   // >= this share on time → gentle
    public const FIRM_LATE_SHARE = 0.50;      // > this share late → firm

    /**
     * @param array $history rows {due_date: 'Y-m-d', paid: 'Y-m-d' or datetime} — the payer's invoices
     *                       paid in the last 12 months (ReminderGate::historyFor()).
     * @return array{tone:string, n:int, on_time:int, late:int, on_time_share:?float, reason:string}
     */
    public static function classify(array $history): array
    {
        $n = 0; $onTime = 0;
        foreach ($history as $row) {
            $due = substr((string)($row['due_date'] ?? ''), 0, 10);
            $paid = substr((string)($row['paid'] ?? ''), 0, 10);
            if ($due === '' || $paid === '') continue;
            $n++;
            if ($paid <= $due) $onTime++;
        }
        $late = $n - $onTime;
        if ($n < self::MIN_INVOICES) {
            return ['tone' => self::NEUTRAL, 'n' => $n, 'on_time' => $onTime, 'late' => $late, 'on_time_share' => null,
                    'reason' => $n === 0 ? 'no paid invoices on record' : "only $n paid invoice" . ($n === 1 ? '' : 's') . ' on record'];
        }
        $share = $onTime / $n;
        $pct = (int)round($share * 100);
        if ($share >= self::GOOD_ON_TIME_SHARE) $tone = self::GENTLE;
        elseif (($late / $n) > self::FIRM_LATE_SHARE) $tone = self::FIRM;
        else $tone = self::NEUTRAL;
        return ['tone' => $tone, 'n' => $n, 'on_time' => $onTime, 'late' => $late, 'on_time_share' => round($share, 2),
                'reason' => "$onTime of $n paid on time ($pct%)"];
    }

    /**
     * The overdue email for one payer: one or many invoices, one tone, one call to action.
     * @param array $invoices rows {invoice_number, balance_due, due_date, url?} — url renders a per-invoice "view" link when there are several
     * @return array{subject:string, html:string}
     */
    public static function overdueEmail(string $tone, array $invoices, string $firstName, string $today, string $companyName = ''): array
    {
        $firstName = trim($firstName) !== '' ? trim($firstName) : 'there';
        $total = 0.0; $oldest = null; $rows = [];
        foreach ($invoices as $inv) {
            $bal = (float)($inv['balance_due'] ?? 0);
            $total += $bal;
            $due = substr((string)($inv['due_date'] ?? ''), 0, 10);
            if ($due !== '' && ($oldest === null || $due < $oldest)) $oldest = $due;
            $rows[] = ['num' => (string)($inv['invoice_number'] ?? ''), 'amount' => self::money($bal), 'due' => $due,
                       'days' => $due !== '' ? self::daysBetween($due, $today) : 0, 'url' => (string)($inv['url'] ?? '')];
        }
        $amount = self::money($total);
        $many = count($rows) > 1;
        $first = $rows[0] ?? ['num' => '', 'amount' => $amount, 'due' => $oldest ?? $today, 'days' => 0];
        $daysPast = $oldest !== null ? self::daysBetween($oldest, $today) : 0;
        $h = static function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

        if ($many) {
            $what = count($rows) . ' invoices';
            $subject = $tone === self::GENTLE ? "Quick reminder: $what totalling $amount"
                : ($tone === self::FIRM ? "Past due notice: $what — $amount ($daysPast days overdue)"
                : "Following up: $what — $amount past due");
        } else {
            $subject = $tone === self::GENTLE ? "Quick reminder: Invoice {$first['num']} for {$first['amount']}"
                : ($tone === self::FIRM ? "Past due notice: Invoice {$first['num']} — {$first['amount']} ({$first['days']} days overdue)"
                : "Following up: Invoice {$first['num']} — {$first['amount']} past due");
        }

        $body = "<p>Hi {$h($firstName)},</p>";
        if ($tone === self::GENTLE) $body .= '<p>I know things get busy, so this is just a quick nudge.</p>';
        if ($many) {
            $body .= '<p>These invoices are past due:</p><ul>';
            foreach ($rows as $r) {
                $body .= '<li>Invoice <strong>' . $h($r['num']) . '</strong> — ' . $h($r['amount']) . ', due ' . $h(self::dateLabel($r['due'])) . ' (' . $r['days'] . ' days ago)'
                       . ($r['url'] !== '' ? ' · <a href="' . $h($r['url']) . '">view</a>' : '') . '</li>';
            }
            $body .= '</ul><p>Together that is <strong>' . $h($amount) . '</strong>.</p>';
        } else {
            $body .= '<p>Invoice <strong>' . $h($first['num']) . '</strong> for <strong>' . $h($first['amount']) . '</strong> was due on '
                   . $h(self::dateLabel($first['due'])) . ', ' . $first['days'] . ' days ago.</p>';
        }
        if ($tone === self::FIRM) {
            $remit = date('F j, Y', strtotime($today . ' +7 days'));
            $body .= '<p>Please remit by <strong>' . $h($remit) . '</strong>. The button below takes you straight to it.</p>';
        } else {
            $body .= '<p>The button below takes you straight to it. If you have paid another way, reply and tell us how, so we can match it up.</p>';
        }
        if ($tone === self::GENTLE) $body .= '<p>Sorry for the chase if it has already gone.</p>';
        return ['subject' => $subject, 'html' => $body];
    }

    /** The overdue SMS for one payer (plain text, no URLs, under 160 characters). */
    public static function overdueSms(array $invoices, string $brand = 'Mowology'): string
    {
        $total = 0.0; foreach ($invoices as $i) $total += (float)($i['balance_due'] ?? 0);
        $amount = self::money($total);
        $what = count($invoices) === 1 ? 'Invoice ' . (string)($invoices[0]['invoice_number'] ?? '') : count($invoices) . ' invoices';
        $msg = "$brand: $what ($amount) overdue. Check your email to pay. Questions? (778) 846-9273.";
        if (strlen($msg) > 160) $msg = "$brand: $what overdue ($amount). Check email to pay. (778) 846-9273.";
        return $msg;
    }

    public static function money(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    private static function dateLabel(string $ymd): string
    {
        $ts = strtotime($ymd);
        return $ts ? date('F j, Y', $ts) : $ymd;
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int)floor((strtotime($to) - strtotime($from)) / 86400);
    }
}
