<?php
/**
 * ContactTimelineService — "Recent conversation" on the contact page (2026-10-07).
 *
 * The last DAYS days with one contact, newest first, read-only:
 *   - sales_messages WHERE contact_id = this contact only (email + text, both ways) — never
 *     another contact's messages, even at the same building;
 *   - quotes in their scope: sent, viewed, accepted (quotes.sent_at / viewed_at / accepted_at);
 *   - invoices in their scope: sent, viewed, reminders (sent_at / viewed_at /
 *     first_reminder_sent_at / last_reminder_sent_at — whichever columns exist);
 *   - payments received (invoice_payment_allocations);
 *   - Sam's and Yui's sends (sam_followups / yui_actions, status sent|edited, for this contact).
 *
 * A send through Sam's card is also logged in sales_messages (mailbox 'sam'), so merge()
 * drops a Sam/Yui send when an outbound message on the same channel is within DEDUPE_MINUTES.
 * Scope (properties, quotes, invoices) comes from ContactTeamService::scope().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/ContactTeamService.php';

class ContactTimelineService
{
    public const DAYS = 90;
    public const PAGE = 15;
    /** Most entries returned (the page shows PAGE at a time with "Show more"). */
    public const MAX = 90;
    public const SNIPPET = 160;
    public const DEDUPE_MINUTES = 15;
    /** Our own number (CLAUDE.md rule 11) is in every text we send — not scrubbed. */
    public const OFFICE_PHONE = '(778) 846-9273';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{entries: array, total: int, page: int, days: int, first_name: string} */
    public function forContact(int $contactId, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $team = new ContactTeamService($this->db);
        $sc = $team->scope($contactId);
        if (!$sc) return ['entries' => [], 'total' => 0, 'page' => self::PAGE, 'days' => self::DAYS, 'first_name' => ''];
        $since = date('Y-m-d H:i:s', strtotime($now) - self::DAYS * 86400);
        $first = $sc['first_name'] ?: 'They';

        $lists = [];
        foreach (['messages', 'quotes', 'invoices', 'payments', 'sends'] as $src) {
            try {
                $lists[] = $this->{$src}($sc, $since, $first, $team);
            } catch (Throwable $e) {
                error_log("ContactTimeline {$src}: " . $e->getMessage());
            }
        }
        $all = self::merge($lists, $since, self::MAX);
        return ['entries' => $all['entries'], 'total' => $all['total'], 'page' => self::PAGE, 'days' => self::DAYS, 'first_name' => $sc['first_name']];
    }

    private function messages(array $sc, string $since, string $first, ContactTeamService $t): array
    {
        $s = $this->db->prepare("SELECT mailbox, direction, channel, subject, snippet, sent_at FROM sales_messages
                                 WHERE contact_id = ? AND sent_at >= ? ORDER BY sent_at DESC, id DESC LIMIT 200");
        $s->execute([$sc['contact'], $since]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $in = $m['direction'] === 'inbound';
            $subject = UnclaimedReplyService::cleanSubject((string)$m['subject']);
            $out[] = self::entry((string)$m['sent_at'], $in ? 'in' : 'out', $m['channel'] === 'sms' ? 'sms' : 'email',
                self::snippet((string)$m['snippet']) ?: ($subject !== '' ? $subject : ($in ? $first . ' wrote' : 'We wrote')),
                null, 'message', ['subject' => $subject, 'via' => (string)$m['mailbox']]);
        }
        return $out;
    }

    private function quotes(array $sc, string $since, string $first, ContactTeamService $t): array
    {
        if (!$sc['quotes']) return [];
        $view = $t->hasColumn('quotes', 'viewed_at') ? 'viewed_at' : 'NULL';
        $rows = $this->db->query("SELECT id, quote_number, title, COALESCE(NULLIF(total_amount, 0), amount, 0) AS amount, sent_at, {$view} AS viewed_at, accepted_at
                                  FROM quotes WHERE id IN (" . ContactTeamService::in($sc['quotes']) . ")")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $q) {
            $url = '/crm/quotes/view.php?id=' . (int)$q['id'];
            $what = $q['quote_number'] . ' (' . ContactTeamService::money((float)$q['amount']) . ')';
            if (!empty($q['sent_at'])) $out[] = self::entry((string)$q['sent_at'], 'out', 'quote', 'Quote ' . $what . ' sent', $url, 'quote');
            if (!empty($q['viewed_at'])) $out[] = self::entry((string)$q['viewed_at'], 'in', 'quote', $first . ' opened quote ' . $q['quote_number'], $url, 'quote');
            if (!empty($q['accepted_at'])) $out[] = self::entry((string)$q['accepted_at'], 'in', 'quote', $first . ' accepted quote ' . $what, $url, 'quote');
        }
        return $out;
    }

    private function invoices(array $sc, string $since, string $first, ContactTeamService $t): array
    {
        if (!$sc['invoices']) return [];
        $cols = [];
        foreach (['sent_at', 'viewed_at', 'first_reminder_sent_at', 'last_reminder_sent_at', 'reminder_count'] as $c) {
            $cols[] = ($t->hasColumn('invoices', $c) ? $c : 'NULL') . ' AS ' . $c;
        }
        $rows = $this->db->query("SELECT id, invoice_number, total, " . implode(', ', $cols) . "
                                  FROM invoices WHERE id IN (" . ContactTeamService::in($sc['invoices']) . ")")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $i) {
            $url = '/crm/invoices/view.php?id=' . (int)$i['id'];
            $what = $i['invoice_number'] . ' (' . ContactTeamService::money2((float)$i['total']) . ')';
            if (!empty($i['sent_at'])) $out[] = self::entry((string)$i['sent_at'], 'out', 'invoice', 'Invoice ' . $what . ' sent', $url, 'invoice');
            if (!empty($i['viewed_at'])) $out[] = self::entry((string)$i['viewed_at'], 'in', 'invoice', $first . ' opened invoice ' . $i['invoice_number'], $url, 'invoice');
            if (!empty($i['first_reminder_sent_at'])) $out[] = self::entry((string)$i['first_reminder_sent_at'], 'out', 'invoice', 'Reminder for ' . $i['invoice_number'], $url, 'invoice');
            if (!empty($i['last_reminder_sent_at']) && $i['last_reminder_sent_at'] !== $i['first_reminder_sent_at']) {
                $out[] = self::entry((string)$i['last_reminder_sent_at'], 'out', 'invoice', 'Reminder ' . max(2, (int)$i['reminder_count']) . ' for ' . $i['invoice_number'], $url, 'invoice');
            }
        }
        return $out;
    }

    private function payments(array $sc, string $since, string $first, ContactTeamService $t): array
    {
        if (!$sc['invoices']) return [];
        $s = $this->db->prepare("SELECT a.amount, a.payment_date, a.method, a.created_at, i.id AS invoice_id, i.invoice_number
                                 FROM invoice_payment_allocations a JOIN invoices i ON i.id = a.invoice_id
                                 WHERE a.invoice_id IN (" . ContactTeamService::in($sc['invoices']) . ") AND a.amount > 0 AND a.payment_date >= ?");
        $s->execute([substr($since, 0, 10)]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            // payment_date is a DATE; noon keeps it in its day without beating that day's messages.
            $out[] = self::entry($p['payment_date'] . ' 12:00:00', 'in', 'payment', 'Payment of ' . ContactTeamService::money2((float)$p['amount'])
                . ' (' . ContactTeamService::method((string)$p['method']) . ') on ' . $p['invoice_number'], '/crm/invoices/view.php?id=' . (int)$p['invoice_id'], 'payment');
        }
        return $out;
    }

    private function sends(array $sc, string $since, string $first, ContactTeamService $t): array
    {
        $out = [];
        $read = [
            'sam' => "SELECT channel, COALESCE(final_subject, suggested_subject) AS subject, COALESCE(final_body, suggested_body) AS body, decided_at
                      FROM sam_followups WHERE contact_id = ? AND status IN ('sent', 'edited') AND decided_at >= ?",
            'yui' => "SELECT channel, final_subject AS subject, COALESCE(final_body, suggested_body) AS body, decided_at
                      FROM yui_actions WHERE contact_id = ? AND status IN ('sent', 'edited') AND decided_at >= ?",
        ];
        foreach ($read as $who => $sql) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute([$sc['contact'], $since]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $out[] = self::entry((string)$r['decided_at'], 'out', $r['channel'] === 'sms' ? 'sms' : 'email',
                        self::snippet((string)$r['body']) ?: (string)$r['subject'], null, $who, ['who' => ucfirst($who)]);
                }
            } catch (Throwable $e) { /* table not migrated yet */ }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function entry(string $at, string $dir, string $channel, string $summary, ?string $url, string $source, array $extra = []): array
    {
        return ['at' => $at, 'dir' => $dir, 'channel' => $channel, 'summary' => $summary, 'url' => $url, 'source' => $source] + $extra;
    }

    /** One line, no email addresses or phone numbers, at most SNIPPET characters. */
    public static function snippet(string $s): string
    {
        // Our own number is in every text we send; keep it, scrub everyone else's.
        $line = str_replace('{office}', self::OFFICE_PHONE, UnclaimedReplyService::scrub(str_replace(self::OFFICE_PHONE, '{office}', $s)));
        return mb_strlen($line) > self::SNIPPET ? rtrim(mb_substr($line, 0, self::SNIPPET - 1)) . '…' : $line;
    }

    /**
     * Flatten, keep the window, drop Sam/Yui sends already in the message log, newest first,
     * cut to $max. @return array{entries: array, total: int}
     */
    public static function merge(array $lists, string $since, int $max): array
    {
        $all = [];
        foreach ($lists as $l) foreach ($l as $e) if ((string)$e['at'] >= $since) $all[] = $e;

        $outbound = [];
        foreach ($all as $e) {
            if ($e['source'] === 'message' && $e['dir'] === 'out') $outbound[] = [$e['channel'], strtotime($e['at'])];
        }
        $all = array_values(array_filter($all, function ($e) use ($outbound) {
            if (!in_array($e['source'], ['sam', 'yui'], true)) return true;
            $t = strtotime($e['at']);
            foreach ($outbound as [$ch, $ot]) {
                if ($ch === $e['channel'] && abs($ot - $t) <= self::DEDUPE_MINUTES * 60) return false;
            }
            return true;
        }));

        $i = 0;
        foreach ($all as &$e) $e['_i'] = $i++;
        unset($e);
        usort($all, fn($a, $b) => [$b['at'], $a['_i']] <=> [$a['at'], $b['_i']]);
        $all = array_map(function ($e) { unset($e['_i']); return $e; }, $all);
        return ['entries' => array_slice($all, 0, $max), 'total' => count($all)];
    }
}
