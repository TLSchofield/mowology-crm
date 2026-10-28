<?php
declare(strict_types=1);

/**
 * CharliePaymentStory — the WHY behind an unpaid invoice, worked out in code (Ittai, 2026-10-28).
 *
 * Ask Charlie used to hand the model a handful of invoice rows (the 8 newest) and let it guess: it could not say
 * whether a reminder had gone out, whether a card was on file, whether the invoice emails were bouncing, or whether
 * the client had said anything — and because only 8 rows went in, it even got the TOTAL wrong (a real account owing
 * $454 across 8 invoices was reported as $302.40 across 5). This class works those facts out from the CRM's own
 * tables and returns them as numbers plus a few plain-English `signals`. The model phrases; it never infers a cause.
 *
 *   build()  is pure (arrays in, story out) and unit tested.
 *   load()   gathers the arrays for one contact or company; every section fails soft (an older install without a
 *            table just leaves that fact out).
 *   forMatches() picks the one or two accounts a question is about.
 *
 * Wording rule: never a pronoun for a person (the CRM does not record one) — the first name, or "they".
 * No namespace / no autoloader: require_once and call statically.
 */
final class CharliePaymentStory
{
    /** The invoice_reminders cron stops after this many reminders per invoice (invoice_reminders.php $MAX_REMINDERS). */
    public const MAX_REMINDERS = 3;
    /** A payment this soon after a reminder counts as "paid because of the reminder". */
    public const REMINDER_PAYS_WITHIN_HOURS = 30;
    public const MAX_UNPAID_ROWS = 12;
    public const MAX_PAID_ROWS = 6;
    public const MAX_ACCOUNTS = 2;

    // ── Pure ─────────────────────────────────────────────────────────────────

    /**
     * @param array{
     *   name:string, first?:string, today:string,
     *   unpaid:array<int,array<string,mixed>>, paid?:array<int,array<string,mixed>>,
     *   card?:array{brand?:?string,last4?:?string,has_pm?:bool,autopay_enabled?:bool}, live_mode?:?bool,
     *   messages?:array{last_in_at?:?string,last_in_says_paid?:bool,last_out_at?:?string},
     *   recipients?:array{emails?:string[],bounced?:string[]}
     * } $in  unpaid rows: invoice_number,total,balance,due_date,reminder_count,last_reminder_sent_at,view_count,
     *        email_opened_at,pending_etransfer,dispute_status,autopay_status,autopay_failure
     *        paid rows: invoice_number,total,paid_at,method,last_reminder_sent_at
     * @return array{account:string, owing:array, overdue:array, not_due:array, recently_paid:array, account_facts:array, signals:string[]}
     */
    public static function build(array $in): array
    {
        $today = (string)$in['today'];
        $name = trim((string)$in['name']) ?: 'This client';
        $first = trim((string)($in['first'] ?? '')) ?: $name;
        $unpaid = (array)($in['unpaid'] ?? []);
        $paid = (array)($in['paid'] ?? []);

        $overdue = [];
        $notDue = [];
        foreach ($unpaid as $r) {
            $bal = (float)($r['balance'] ?? 0);
            if ($bal <= 0.005) continue;
            $due = (string)($r['due_date'] ?? '');
            $r['_days'] = $due !== '' ? (int)floor((strtotime($today) - strtotime($due)) / 86400) : 0;
            if ($due !== '' && $due < $today) $overdue[] = $r; else $notDue[] = $r;
        }
        usort($overdue, fn($a, $b) => strcmp((string)$a['due_date'], (string)$b['due_date']));
        usort($notDue, fn($a, $b) => strcmp((string)$a['due_date'], (string)$b['due_date']));

        $sum = fn(array $rows) => round(array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $rows)), 2);
        $owing = [
            'count' => count($overdue) + count($notDue), 'total' => $sum(array_merge($overdue, $notDue)),
            'overdue_count' => count($overdue), 'overdue_total' => $sum($overdue),
            'not_due_count' => count($notDue), 'not_due_total' => $sum($notDue),
            'oldest_due' => $overdue ? (string)$overdue[0]['due_date'] : null,
        ];

        $signals = [];
        if ($owing['count'] === 0) {
            $signals[] = $name . ' has nothing outstanding.';
        } else {
            $s = $name . ' owes ' . self::money($owing['total']) . ' across ' . $owing['count'] . ' invoice' . ($owing['count'] === 1 ? '' : 's');
            if ($owing['overdue_count'] > 0) {
                $s .= ': ' . $owing['overdue_count'] . ' overdue (' . self::money($owing['overdue_total']) . ', the oldest due ' . self::day($owing['oldest_due']) . ')'
                    . ($owing['not_due_count'] > 0 ? ' and ' . $owing['not_due_count'] . ' not due yet (' . self::money($owing['not_due_total']) . ')' : '');
            } else {
                $s .= ', none of them overdue yet';
            }
            $signals[] = $s . '.';
        }

        // Reminders on the overdue invoices.
        if ($overdue) {
            $none = array_filter($overdue, fn($r) => (int)($r['reminder_count'] ?? 0) === 0);
            $maxed = array_filter($overdue, fn($r) => (int)($r['reminder_count'] ?? 0) >= self::MAX_REMINDERS);
            $some = count($overdue) - count($none);
            if (count($none) === count($overdue)) {
                $signals[] = 'No reminder has gone out on any of the overdue invoices yet.';
            } else {
                $line = $some . ' of the ' . count($overdue) . ' overdue invoice' . (count($overdue) === 1 ? ' has' : 's have') . ' had a reminder';
                if (count($maxed) > 0) {
                    $line .= '; ' . count($maxed) . ' ' . (count($maxed) === 1 ? 'has' : 'have') . ' used all ' . self::MAX_REMINDERS . ' reminders, so nothing more goes out automatically';
                }
                if (count($none) > 0) $line .= '; ' . count($none) . ' ' . (count($none) === 1 ? 'has' : 'have') . ' had none';
                $signals[] = $line . '.';
            }
            // Did they ever look at the invoices?
            $unopened = array_filter($overdue, fn($r) => (int)($r['view_count'] ?? 0) === 0);
            $emailOpened = array_filter($overdue, fn($r) => !empty($r['email_opened_at']));
            if (count($unopened) > 0 && count($unopened) === count($overdue)) {
                $signals[] = 'None of the overdue invoices has been opened on the invoice page' . (count($emailOpened) ? ', although the emails were opened' : '') . '.';
            } elseif (count($unopened) > 0) {
                $signals[] = count($unopened) . ' of the overdue invoices ' . (count($unopened) === 1 ? 'was' : 'were') . ' never opened on the invoice page.';
            }
        }

        // The pattern in what they DO pay.
        $afterReminder = [];
        foreach ($paid as $p) {
            $pa = (string)($p['paid_at'] ?? '');
            $lr = (string)($p['last_reminder_sent_at'] ?? '');
            if ($pa === '' || $lr === '') continue;
            $hours = (strtotime($pa) - strtotime($lr)) / 3600;
            if ($hours >= 0 && $hours <= self::REMINDER_PAYS_WITHIN_HOURS) $afterReminder[] = $p;
        }
        if (count($afterReminder) >= 2) {
            $signals[] = $first . ' paid ' . count($afterReminder) . ' recent invoices within a day of a reminder going out, so reminders seem to work on this account.';
        }
        $bigPaid = array_values(array_filter($paid, fn($p) => (float)($p['total'] ?? 0) >= 500));
        if ($bigPaid && $owing['count'] > 0) {
            $bits = array_map(fn($p) => self::money((float)$p['total']), array_slice($bigPaid, 0, 2));
            $methods = array_values(array_unique(array_filter(array_map(fn($p) => self::method($p['method'] ?? null), array_slice($bigPaid, 0, 2)))));
            $signals[] = $first . ' did pay the larger invoice' . (count($bigPaid) > 1 ? 's' : '') . ' (' . implode(' and ', $bits) . ')'
                . ($methods ? ' by ' . implode('/', $methods) : '') . ' on ' . self::day((string)$bigPaid[0]['paid_at']) . '.';
        }

        // Card and autopay.
        $card = (array)($in['card'] ?? []);
        $enabled = !empty($card['autopay_enabled']);
        $hasCard = !empty($card['has_pm']) || !empty($card['last4']);
        $cardText = $hasCard ? trim(ucfirst((string)($card['brand'] ?? 'A card')) . (!empty($card['last4']) ? ' ending ' . $card['last4'] : '')) : '';
        if ($owing['count'] > 0) {
            if ($enabled && $hasCard) {
                $signals[] = 'Autopay is on with ' . $cardText . ' on file' . (isset($in['live_mode']) && $in['live_mode'] === false ? ', but automatic charging is switched off for everyone right now' : '') . '.';
            } elseif ($hasCard) {
                $signals[] = $cardText . ' is on file, but ' . $first . ' is not enrolled in autopay, so nothing is charged automatically.';
            } else {
                $signals[] = 'There is no card on file and autopay is off, so nothing can be charged automatically.';
            }
        }
        $failed = array_filter($unpaid, fn($r) => in_array((string)($r['autopay_status'] ?? ''), ['failed', 'authentication_required'], true));
        if ($failed) {
            $f = array_values($failed)[0];
            $signals[] = 'An automatic charge failed' . (!empty($f['autopay_failure']) ? ' (' . $f['autopay_failure'] . ')' : '') . '.';
        }

        // Delivery.
        $rcp = (array)($in['recipients'] ?? []);
        if (!empty($rcp['bounced'])) {
            $signals[] = 'Invoice emails to ' . implode(', ', array_slice((array)$rcp['bounced'], 0, 2)) . ' bounced, so they may never have arrived.';
        } elseif ($owing['count'] > 0 && isset($rcp['emails']) && count((array)$rcp['emails']) === 0) {
            $signals[] = 'No billing email address is on file for the invoices.';
        }

        // Payment on the way / dispute.
        $onWay = array_filter($unpaid, fn($r) => !empty($r['pending_etransfer']));
        if ($onWay) $signals[] = 'An e-transfer for ' . count($onWay) . ' of the invoices is waiting to be recorded.';
        $disputed = array_filter($unpaid, fn($r) => !empty($r['dispute_status']));
        if ($disputed) $signals[] = count($disputed) . ' of the invoices is disputed (' . array_values($disputed)[0]['dispute_status'] . ').';

        // What they have said.
        $m = (array)($in['messages'] ?? []);
        if ($owing['count'] > 0) {
            if (!empty($m['last_in_says_paid'])) {
                $signals[] = $first . ' wrote on ' . self::day((string)$m['last_in_at']) . ' saying they had paid; no matching payment is recorded.';
            } elseif (!empty($m['last_in_at'])) {
                $signals[] = 'The last message from ' . $first . ' was on ' . self::day((string)$m['last_in_at']) . ' and did not say anything about paying.';
            } else {
                $signals[] = $first . ' has not written to us about these (nothing in the last 60 days).';
            }
        }

        $row = fn($r) => array_filter([
            'invoice' => $r['invoice_number'] ?? null, 'balance' => round((float)($r['balance'] ?? 0), 2), 'due' => $r['due_date'] ?? null,
            'days_overdue' => isset($r['_days']) && $r['_days'] > 0 ? $r['_days'] : null,
            'reminders' => (int)($r['reminder_count'] ?? 0), 'last_reminder' => isset($r['last_reminder_sent_at']) ? substr((string)$r['last_reminder_sent_at'], 0, 10) : null,
            'opened_on_page' => (int)($r['view_count'] ?? 0) > 0,
        ], fn($v) => $v !== null);

        return [
            'account' => $name,
            'owing' => $owing,
            'signals' => $signals,
            'overdue' => array_map($row, array_slice($overdue, 0, self::MAX_UNPAID_ROWS)),
            'not_due' => array_map($row, array_slice($notDue, 0, 4)),
            'recently_paid' => array_map(fn($p) => array_filter([
                'invoice' => $p['invoice_number'] ?? null, 'total' => round((float)($p['total'] ?? 0), 2), 'paid' => isset($p['paid_at']) ? substr((string)$p['paid_at'], 0, 10) : null,
                'by' => self::method($p['method'] ?? null),
            ], fn($v) => $v !== null && $v !== ''), array_slice($paid, 0, self::MAX_PAID_ROWS)),
            'account_facts' => array_filter(['card' => $cardText ?: null, 'autopay_enrolled' => $enabled, 'auto_charging_on' => $in['live_mode'] ?? null], fn($v) => $v !== null),
        ];
    }

    // ── DB ───────────────────────────────────────────────────────────────────

    /** Pick up to MAX_ACCOUNTS accounts from Charlie's matched context and build their stories. @return array[] */
    public static function forMatches(PDO $db, array $ctx, string $today): array
    {
        $accounts = [];
        foreach ((array)($ctx['contacts'] ?? []) as $c) {
            $accounts[] = ['kind' => 'contact', 'id' => (int)$c['id'], 'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')), 'first' => (string)($c['first_name'] ?? '')];
        }
        foreach ((array)($ctx['companies'] ?? []) as $c) {
            $accounts[] = ['kind' => 'company', 'id' => (int)$c['id'], 'name' => (string)($c['company_name'] ?? ''), 'first' => ''];
        }
        $stories = [];
        foreach ($accounts as $a) {
            try {
                $s = self::load($db, $a, $today);
            } catch (Throwable $e) {
                error_log('CharliePaymentStory: ' . $e->getMessage());
                continue;
            }
            if ($s && $s['owing']['count'] > 0) $stories[] = $s;
        }
        usort($stories, fn($a, $b) => $b['owing']['total'] <=> $a['owing']['total']);
        return array_slice($stories, 0, self::MAX_ACCOUNTS);
    }

    /** @param array{kind:string,id:int,name:string,first:string} $a */
    public static function load(PDO $db, array $a, string $today): ?array
    {
        $col = $a['kind'] === 'company' ? 'company_id' : 'contact_id';
        $id = (int)$a['id'];
        if ($id <= 0) return null;

        $unpaid = self::rows($db, "SELECT id, invoice_number, COALESCE(NULLIF(total, 0), total_amount, 0) AS total, balance_due AS balance, due_date, status,
                                          reminder_count, first_reminder_sent_at, last_reminder_sent_at, view_count, email_opened_at, dispute_status
                                   FROM invoices WHERE {$col} = ? AND balance_due > 0.005 AND status NOT IN ('cancelled', 'draft', 'void')
                                   ORDER BY due_date ASC LIMIT 60", [$id]);
        if (!$unpaid) return self::build(['name' => $a['name'], 'first' => $a['first'], 'today' => $today, 'unpaid' => []]);
        $ids = array_map(fn($r) => (int)$r['id'], $unpaid);
        $in = implode(',', $ids);

        $paid = self::rows($db, "SELECT invoice_number, COALESCE(NULLIF(total, 0), total_amount, 0) AS total, paid_at, payment_method AS method, last_reminder_sent_at
                                 FROM invoices WHERE {$col} = ? AND status = 'paid' AND paid_at IS NOT NULL ORDER BY paid_at DESC LIMIT " . self::MAX_PAID_ROWS, [$id]);

        $pending = array_flip(array_map('intval', array_column(self::rows($db, "SELECT matched_invoice_id AS id FROM etransfer_notifications
                                 WHERE matched_invoice_id IN ($in) AND status = 'pending'", []), 'id')));
        $autopay = [];
        foreach (self::rows($db, "SELECT invoice_id, status, failure_code FROM autopay_attempts WHERE invoice_id IN ($in) ORDER BY id DESC", []) as $r) {
            $autopay[(int)$r['invoice_id']] ??= $r;
        }
        foreach ($unpaid as &$u) {
            $u['pending_etransfer'] = isset($pending[(int)$u['id']]);
            $u['autopay_status'] = $autopay[(int)$u['id']]['status'] ?? null;
            $u['autopay_failure'] = $autopay[(int)$u['id']]['failure_code'] ?? null;
        }
        unset($u);

        $emails = [];
        $bounced = [];
        $any = false;
        foreach (self::rows($db, "SELECT email_address, bounced, email_bounced FROM invoice_contacts WHERE invoice_id IN ($in)", []) as $r) {
            $any = true;
            $e = trim((string)$r['email_address']);
            if ($e === '') continue;
            $emails[$e] = true;
            if (!empty($r['bounced']) || !empty($r['email_bounced'])) $bounced[$e] = true;
        }

        $card = [];
        if ($a['kind'] === 'contact') {
            $r = self::rows($db, "SELECT stripe_card_brand AS brand, stripe_card_last4 AS last4, stripe_payment_method_id AS pm, autopay_enabled FROM contacts WHERE id = ?", [$id])[0] ?? null;
        } else {
            $r = self::rows($db, "SELECT stripe_card_brand AS brand, stripe_card_last4 AS last4, stripe_payment_method_id AS pm, autopay_enabled FROM companies WHERE id = ?", [$id])[0] ?? null;
        }
        if ($r) $card = ['brand' => $r['brand'], 'last4' => $r['last4'], 'has_pm' => !empty($r['pm']), 'autopay_enabled' => !empty($r['autopay_enabled'])];

        $live = null;
        $lm = self::rows($db, "SELECT autopay_live_mode AS m FROM business_settings LIMIT 1", []);
        if ($lm) $live = (int)$lm[0]['m'] === 1;

        $msgs = [];
        if ($a['kind'] === 'contact') {
            $since = date('Y-m-d H:i:s', strtotime($today) - 60 * 86400);
            $in1 = self::rows($db, "SELECT sent_at, snippet FROM sales_messages WHERE contact_id = ? AND direction = 'inbound' AND sent_at >= ? ORDER BY sent_at DESC LIMIT 1", [$id, $since])[0] ?? null;
            $out1 = self::rows($db, "SELECT sent_at FROM sales_messages WHERE contact_id = ? AND direction = 'outbound' AND sent_at >= ? ORDER BY sent_at DESC LIMIT 1", [$id, $since])[0] ?? null;
            $says = false;
            if ($in1 && is_file(__DIR__ . '/../../Sales/Services/UnclaimedReplyService.php')) {
                require_once __DIR__ . '/../../Sales/Services/UnclaimedReplyService.php';
                $says = UnclaimedReplyService::isPaymentClaim((string)$in1['snippet']);
            }
            $msgs = ['last_in_at' => $in1['sent_at'] ?? null, 'last_in_says_paid' => $says, 'last_out_at' => $out1['sent_at'] ?? null];
        }

        return self::build([
            'name' => $a['name'], 'first' => $a['first'], 'today' => $today, 'unpaid' => $unpaid, 'paid' => $paid, 'card' => $card, 'live_mode' => $live,
            'messages' => $msgs, 'recipients' => $any ? ['emails' => array_keys($emails), 'bounced' => array_keys($bounced)] : [],
        ]);
    }

    /** One query, fail soft: a missing table or column simply yields no rows (that fact is left out of the story). */
    private static function rows(PDO $db, string $sql, array $args): array
    {
        try {
            $s = $db->prepare($sql);
            $s->execute($args);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── Words ────────────────────────────────────────────────────────────────

    public static function money(float $v): string
    {
        return '$' . number_format($v, abs($v - round($v)) < 0.005 ? 0 : 2);
    }

    /** "Oct 23" */
    public static function day(?string $d): string
    {
        $t = $d ? strtotime($d) : false;
        return $t ? date('M j', $t) : 'an unknown date';
    }

    public static function method($m): string
    {
        $map = ['e_transfer' => 'e-transfer', 'stripe' => 'card', 'credit_card' => 'card', 'cash' => 'cash', 'cheque' => 'cheque', 'account_credit' => 'account credit'];
        return $m ? ($map[(string)$m] ?? str_replace('_', ' ', (string)$m)) : '';
    }
}
