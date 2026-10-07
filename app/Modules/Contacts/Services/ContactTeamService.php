<?php
/**
 * ContactTeamService — "Team on this client" on the contact page (2026-10-07).
 *
 * Each department head says what they know about ONE contact, in their own voice, with one
 * action link. Nothing here invents a rule: every head's items come from that head's own
 * service, filtered down to this contact's scope (the contact, their properties, the
 * companies they speak for, and those properties' quotes, invoices, plans and contracts):
 *
 *   Sam     SalesDeskService::queue() / openQuotes() (follow-up state), ::unclaimed() (quote
 *           replies, lane 'quote'), MiaCampaignService::replyItems() (campaign leads), and the
 *           pipeline stage on the contact (contacts.lifecycle_stage — PipelineStageService
 *           writes it once that branch is live).
 *   Yui     YuiDeskService::sections() — inbox (client lane), promises, accounts, renewals,
 *           arrears — kept only when the item points at something in scope.
 *   Penny   open invoices; InvoiceReconciliationService::topCandidatesForInvoiceIds() (the
 *           same "likely match" deposits the Invoices page shows); EtransferDeskService::queue()
 *           (pending e-Transfers naming one of their invoices or sent by them); recent
 *           payments (invoice_payment_allocations). KEY CASE: the customer's latest message
 *           says they paid (saysPaid()) but an invoice is still open.
 *   Otto    PropertyReadinessService::gaps() per property; next visit; stuck / unbilled visits
 *           (same definitions as getWorkQueueItems()'s critical lane, for this contact only).
 *   Mia     ConsentLedgerService::allows() (email, sms); campaign_sends + MiaCampaignService::
 *           tally() for the last campaign they got; ReviewRequestService::isEligible().
 *   Charlie only when nobody else has anything to act on: "Nothing needs you here."
 *
 * Order (order()): a clear yes, then money, then replies, then other actions, then
 * information. The page shows MAX_ITEMS with "+N more".
 *
 * Read-only. Every head is wrapped: a failure costs that head's lines, never the panel.
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once dirname(__DIR__, 2) . '/Sales/Services/UnclaimedReplyService.php';

class ContactTeamService
{
    public const MAX_ITEMS = 6;

    /** order() groups. */
    public const G_YES = 0;
    public const G_MONEY = 1;
    public const G_REPLY = 2;
    public const G_ACTION = 3;
    public const G_INFO = 4;

    /** slug => [name, role] */
    public const HEADS = [
        'sam'     => ['Sam', 'Sales'],
        'yui'     => ['Yui', 'Comms'],
        'penny'   => ['Penny', 'Bookkeeper'],
        'otto'    => ['Otto', 'Operations'],
        'mia'     => ['Mia', 'Marketing'],
        'charlie' => ['Charlie', 'Foreman'],
    ];

    /** A "says they paid" message counts this long. */
    public const PAID_CLAIM_DAYS = 30;
    /** Recent payments shown by Penny. */
    public const RECENT_PAYMENT_DAYS = 45;
    public const OPEN_STATUSES = ['sent', 'viewed', 'partial', 'overdue'];

    private PDO $db;
    private array $cols = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{items: array, rest: array, more: int, total: int} items = the first MAX_ITEMS, rest = behind "+N more" */
    public function forContact(int $contactId, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $scope = $this->scope($contactId);
        if (!$scope) return ['items' => [], 'rest' => [], 'more' => 0, 'total' => 0];

        $items = [];
        foreach (['sam', 'yui', 'penny', 'otto', 'mia'] as $head) {
            try {
                $items = array_merge($items, $this->{$head}($scope, $now));
            } catch (Throwable $e) {
                error_log("ContactTeam {$head}: " . $e->getMessage());
            }
        }
        return self::finish($items, self::MAX_ITEMS);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scope: what belongs to this contact
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array|null contact (row), first_name, name, contact, properties, companies, plans,
     *                    quotes, invoices, contracts (int[] each)
     */
    public function scope(int $cid): ?array
    {
        if ($cid < 1) return null;
        $s = $this->db->prepare('SELECT * FROM contacts WHERE id = ?');
        $s->execute([$cid]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $props = $this->ids("SELECT id FROM properties WHERE site_contact_id = ?", [$cid]);
        if ($this->hasColumn('properties', 'quote_contact_id')) {
            $props = array_merge($props, $this->ids("SELECT id FROM properties WHERE quote_contact_id = ?", [$cid]));
        }
        $props = array_merge($props, $this->ids("SELECT property_id FROM property_contacts WHERE contact_id = ?", [$cid]));
        $props = self::uniq($props);

        $cos = [];
        foreach (['primary_contact_id', 'billing_contact_id', 'quote_contact_id'] as $col) {
            if ($this->hasColumn('companies', $col)) {
                $cos = array_merge($cos, $this->ids("SELECT id FROM companies WHERE {$col} = ?", [$cid]));
            }
        }
        $cos = self::uniq($cos);

        $plans = $props ? $this->ids('SELECT id FROM job_plans WHERE property_id IN (' . self::in($props) . ')') : [];

        $where = ['contact_id = ' . $cid];
        if ($props) $where[] = 'property_id IN (' . self::in($props) . ')';
        if ($cos) $where[] = 'company_id IN (' . self::in($cos) . ')';
        $quotes = $this->ids('SELECT id FROM quotes WHERE ' . implode(' OR ', $where));

        $iw = $where;
        if ($plans) $iw[] = 'plan_id IN (' . self::in($plans) . ')';
        $invoices = $this->ids('SELECT id FROM invoices WHERE ' . implode(' OR ', $iw));

        $cw = ['contact_id = ' . $cid];
        if ($props) $cw[] = 'property_id IN (' . self::in($props) . ')';
        $contracts = $this->ids('SELECT id FROM contracts WHERE ' . implode(' OR ', $cw));

        return [
            'contact'    => $cid,
            'row'        => $c,
            'first_name' => UnclaimedReplyService::firstName((string)($c['first_name'] ?? '')),
            'name'       => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
            'properties' => $props,
            'companies'  => $cos,
            'plans'      => self::uniq($plans),
            'quotes'     => self::uniq($quotes),
            'invoices'   => self::uniq($invoices),
            'contracts'  => self::uniq($contracts),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sam
    // ─────────────────────────────────────────────────────────────────────────

    private function sam(array $sc, string $now): array
    {
        require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
        $sales = new SalesDeskService($this->db);
        $out = [];
        $cid = $sc['contact'];

        $cards = $sales->queue();
        $inQueue = [];
        foreach ($cards as $card) {
            $ids = array_map(fn($q) => (int)$q['id'], $card['quotes'] ?? []);
            if ((int)($card['contact_id'] ?? 0) !== $cid && !array_intersect($ids, $sc['quotes'])) continue;
            foreach ($ids as $id) $inQueue[$id] = true;
            $one = count($card['quotes']) === 1 ? $card['quotes'][0] : null;
            $url = $one ? '/crm/quotes/view.php?id=' . (int)$one['id'] : '/crm/quotes/index.php?search=' . rawurlencode($sc['name']);
            if ($card['kind'] === 'replied') {
                $out[] = self::item('sam', 'quote_replied', self::G_REPLY, 'They wrote back about ' . $card['label'] . ' (' . self::money((float)$card['amount'])
                    . '). Answer them before any nudge.', 'Open quote', $url, 1);
            } else {
                $n = (int)$card['followups'];
                $out[] = self::item('sam', 'quote_waiting', self::G_ACTION, $card['label'] . ' (' . self::money((float)$card['amount']) . ') has waited '
                    . (int)$card['days'] . ' days' . ($card['viewed'] ? ', and they opened it' : '') . '. Follow-up ' . ($n + 1) . ' of '
                    . SalesDeskService::MAX_FOLLOWUPS . ' is due.', 'Open quote', $url, 2);
            }
        }
        // Open quotes not due a nudge yet — just the state.
        $waiting = array_values(array_filter($sales->openQuotes(), fn($q) => in_array((int)$q['id'], $sc['quotes'], true) && !isset($inQueue[(int)$q['id']])));
        if ($waiting) {
            $q = $waiting[0];
            $more = count($waiting) - 1;
            $out[] = self::item('sam', 'quote_open', self::G_INFO, $q['quote_number'] . ' (' . self::money((float)$q['amount']) . ') went out '
                . self::day((string)$q['sent_at']) . ($more > 0 ? ', plus ' . $more . ' more open' : '') . '. Not due a nudge yet.',
                'Open quote', '/crm/quotes/view.php?id=' . (int)$q['id'], 3);
        }

        foreach ($sales->unclaimed($cards) as $u) {
            if ((int)($u['contact_id'] ?? 0) !== $cid) continue;
            $out[] = self::item('sam', 'quote_reply', !empty($u['yes']) ? self::G_YES : self::G_REPLY, (string)$u['text'],
                !empty($u['yes']) ? 'Make the quote' : 'Answer on Sam\'s card',
                !empty($u['yes']) ? '/crm/quote-workflow.php?contact_id=' . $cid : '/crm/dashboard_appstack.php#mw-sam', (int)($u['priority'] ?? 2));
        }

        try {
            require_once dirname(__DIR__, 2) . '/Marketing/Services/MiaCampaignService.php';
            foreach ((new MiaCampaignService($this->db))->replyItems(new DateTimeImmutable($now)) as $it) {
                if (!self::inScope($it, $sc)) continue;
                $out[] = self::item('sam', 'campaign_lead', self::G_YES, (string)$it['text'], 'Start the quote', (string)$it['url'], 1);
            }
        } catch (Throwable $e) {
            error_log('ContactTeam sam/campaign: ' . $e->getMessage());
        }

        $stage = (string)($sc['row']['lifecycle_stage'] ?? $sc['row']['prospect_status'] ?? '');
        $since = $this->clientSince($sc);
        $out[] = self::item('sam', 'stage', self::G_INFO, self::stageLine($stage, $since),
            'New quote', '/crm/quote-workflow.php?contact_id=' . $cid, 3);
        return $out;
    }

    /** Earliest accepted quote, contract start or paid invoice in scope. */
    private function clientSince(array $sc): ?string
    {
        $d = [];
        if ($sc['quotes']) $d[] = $this->one('SELECT MIN(accepted_at) FROM quotes WHERE accepted_at IS NOT NULL AND id IN (' . self::in($sc['quotes']) . ')');
        if ($sc['contracts']) $d[] = $this->one('SELECT MIN(start_date) FROM contracts WHERE start_date IS NOT NULL AND id IN (' . self::in($sc['contracts']) . ')');
        if ($sc['invoices']) $d[] = $this->one("SELECT MIN(issue_date) FROM invoices WHERE status = 'paid' AND id IN (" . self::in($sc['invoices']) . ')');
        $d = array_filter(array_map('strval', $d));
        return $d ? substr(min($d), 0, 10) : null;
    }

    /** Sam's one line about where they are in the pipeline. */
    public static function stageLine(string $stage, ?string $since): string
    {
        $stage = strtolower(trim($stage));
        if ($since !== null && $since !== '' && !in_array($stage, ['lost', 'inactive'], true)) {
            return 'Client since ' . date('M Y', strtotime($since)) . '.';
        }
        switch ($stage) {
            case 'lost':        return 'Marked lost. A new quote would reopen them.';
            case 'inactive':    return 'Inactive' . ($since ? ' (a client since ' . date('M Y', strtotime($since)) . ')' : '') . '. Worth a check-in.';
            case 'opportunity': return 'An opportunity: a quote is out, nothing accepted yet.';
            case 'client':      return 'A client.';
            case '':            return 'No pipeline stage yet.';
            default:            return 'Still a ' . str_replace('_', ' ', $stage) . '. Nothing accepted yet.';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Yui
    // ─────────────────────────────────────────────────────────────────────────

    private function yui(array $sc, string $now): array
    {
        require_once dirname(__DIR__, 2) . '/Comms/Services/YuiDeskService.php';
        $out = [];
        foreach ((new YuiDeskService($this->db, $now))->sections() as $section => $items) {
            foreach ($items as $it) {
                if (!self::inScope($it, $sc)) continue;
                $yes = !empty($it['yes']) || ($it['kind'] ?? '') === 'promise';
                $group = $yes ? self::G_YES : ($section === 'arrears' ? self::G_MONEY : ($section === 'inbox' ? self::G_REPLY : self::G_ACTION));
                $label = ['inbox' => 'Answer on Yui\'s card', 'promises' => 'Find the quote', 'accounts' => 'Fix it', 'renewals' => 'Open', 'arrears' => 'Draft the note'][$section] ?? 'Open';
                // Inbox / arrears drafts live on her card; a promise needs its quote found and accepted.
                $url = in_array($section, ['inbox', 'arrears'], true) ? '/crm/dashboard_appstack.php#mw-yui'
                    : ($section === 'promises' ? (string)($it['quotes_url'] ?? $it['url'] ?? '') : (string)($it['url'] ?? ''));
                $out[] = self::item('yui', (string)($it['kind'] ?? $section), $group, (string)$it['text'], $label, $url, (int)($it['priority'] ?? 3));
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Penny
    // ─────────────────────────────────────────────────────────────────────────

    private function penny(array $sc, string $now): array
    {
        $out = [];
        $open = [];
        if ($sc['invoices']) {
            $sent = $this->hasColumn('invoices', 'sent_at') ? 'i.sent_at' : 'NULL';
            $open = $this->db->query("
                SELECT i.id, i.invoice_number, i.balance_due, i.total, i.due_date, i.issue_date, {$sent} AS sent_at, i.status
                FROM invoices i
                WHERE i.id IN (" . self::in($sc['invoices']) . ") AND i.status IN ('" . implode("','", self::OPEN_STATUSES) . "') AND i.balance_due > 0.005
                ORDER BY COALESCE(i.due_date, i.issue_date)
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
        $today = substr($now, 0, 10);

        // Likely deposits (the Invoices page's "likely match") and pending e-Transfers.
        $deposits = [];
        if ($open) {
            try {
                require_once dirname(__DIR__, 2) . '/Accounting/Services/InvoiceReconciliationService.php';
                $deposits = (new InvoiceReconciliationService($this->db))->topCandidatesForInvoiceIds(array_map(fn($i) => (int)$i['id'], $open), 1);
            } catch (Throwable $e) {
                error_log('ContactTeam penny/deposits: ' . $e->getMessage());
            }
        }
        $transfers = [];
        try {
            require_once dirname(__DIR__, 2) . '/Accounting/Services/EtransferDeskService.php';
            $numbers = array_map(fn($i) => (string)$i['invoice_number'], $open);
            foreach ((new EtransferDeskService($this->db))->queue(25)['items'] as $et) {
                $lines = array_column($et['lines'], 'invoice');
                $byName = $sc['name'] !== '' && EtransferInboxService::samePayer((string)$et['sender'], $sc['name']);
                if (array_intersect($lines, $numbers) || $byName) $transfers[] = $et;
            }
        } catch (Throwable $e) {
            error_log('ContactTeam penny/etransfers: ' . $e->getMessage());
        }

        // KEY CASE: they say they've paid.
        $claim = null;
        if ($open) {
            $msg = $this->latestInbound($sc['contact'], date('Y-m-d H:i:s', strtotime($now) - self::PAID_CLAIM_DAYS * 86400));
            if ($msg && self::saysPaid((string)$msg['snippet'])) {
                $claim = self::paidClaim($sc['first_name'] ?: 'They', $msg, $open, $deposits, $transfers, $sc['contact']);
            }
        }
        if ($claim) {
            $out[] = $claim;
        } else {
            if ($open) $out[] = self::openLine($open, $today, $sc['contact']);
            foreach ($open as $inv) {
                $d = $deposits[(int)$inv['id']][0] ?? null;
                if (!$d) continue;
                $out[] = self::item('penny', 'deposit_match', self::G_MONEY, 'A ' . self::money2((float)$d['amount']) . ' deposit on ' . self::day($d['date'])
                    . ' looks like ' . $inv['invoice_number'] . '. Match it if it is theirs.', 'Match deposit', '/crm/invoices/view.php?id=' . (int)$inv['id'], 1);
                break;
            }
            foreach (array_slice($transfers, 0, 1) as $et) {
                $out[] = self::item('penny', 'etransfer', self::G_MONEY, (string)$et['say'], 'Check e-Transfers', '/crm/invoices/index.php#etransfers', 1);
            }
        }

        // Recent payments — information.
        if ($sc['invoices']) {
            try {
                $s = $this->db->prepare("
                    SELECT a.amount, a.payment_date, a.method, i.invoice_number
                    FROM invoice_payment_allocations a JOIN invoices i ON i.id = a.invoice_id
                    WHERE a.invoice_id IN (" . self::in($sc['invoices']) . ") AND a.amount > 0 AND a.payment_date >= ?
                    ORDER BY a.payment_date DESC, a.id DESC LIMIT 5
                ");
                $s->execute([date('Y-m-d', strtotime($today . ' -' . self::RECENT_PAYMENT_DAYS . ' days'))]);
                $pays = $s->fetchAll(PDO::FETCH_ASSOC);
                if ($pays) {
                    $p = $pays[0];
                    $out[] = self::item('penny', 'payment', self::G_INFO, 'Paid ' . self::money2((float)$p['amount']) . ' on ' . self::day($p['payment_date'])
                        . ' (' . self::method((string)$p['method']) . ') toward ' . $p['invoice_number']
                        . (count($pays) > 1 ? '; ' . (count($pays) - 1) . ' more payment' . (count($pays) > 2 ? 's' : '') . ' lately.' : '.'),
                        'Statement', '/crm/invoices/statement.php?contact_id=' . $sc['contact'], 3);
                }
            } catch (Throwable $e) { /* no allocations table */ }
        }
        return $out;
    }

    private function latestInbound(int $cid, string $from): ?array
    {
        try {
            $s = $this->db->prepare("SELECT channel, subject, snippet, sent_at FROM sales_messages
                                     WHERE contact_id = ? AND direction = 'inbound' AND sent_at >= ?
                                     ORDER BY sent_at DESC, id DESC LIMIT 1");
            $s->execute([$cid, $from]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Pure: Penny's line when the customer says they paid and an invoice is still open.
     * The amount is the invoice(s) sent before the message (else every open one).
     */
    public static function paidClaim(string $first, array $msg, array $open, array $deposits, array $transfers, int $cid): array
    {
        $at = (string)$msg['sent_at'];
        $before = array_values(array_filter($open, fn($i) => (string)($i['sent_at'] ?: $i['issue_date']) <= $at));
        $set = $before ?: $open;
        $amount = round(array_sum(array_map(fn($i) => (float)$i['balance_due'], $set)), 2);
        $head = $first . ' says it\'s paid (' . self::money2($amount) . ')';
        foreach ($set as $inv) {
            $d = $deposits[(int)$inv['id']][0] ?? null;
            if ($d) {
                return self::item('penny', 'paid_claim', self::G_MONEY, $head . ', and a ' . self::money2((float)$d['amount']) . ' deposit on '
                    . self::day((string)$d['date']) . ' fits ' . $inv['invoice_number'] . '. Match it.', 'Match deposit', '/crm/invoices/view.php?id=' . (int)$inv['id'], 1);
            }
        }
        if ($transfers) {
            $et = $transfers[0];
            return self::item('penny', 'paid_claim', self::G_MONEY, $head . ', and an e-Transfer from ' . $et['sender'] . ' for '
                . self::money2((float)$et['active']) . ' is waiting. Record it.', 'Check e-Transfers', '/crm/invoices/index.php#etransfers', 1);
        }
        return self::item('penny', 'paid_claim', self::G_MONEY, $head . ', but I can\'t see a matching deposit yet; check e-Transfers.',
            'Check e-Transfers', '/crm/invoices/index.php#etransfers', 1);
    }

    /** Pure: one line for what is still owed. */
    public static function openLine(array $open, string $today, int $cid): array
    {
        $total = round(array_sum(array_map(fn($i) => (float)$i['balance_due'], $open)), 2);
        $oldest = $open[0];
        $due = substr((string)($oldest['due_date'] ?? ''), 0, 10);
        $late = $due !== '' ? (int)floor((strtotime($today) - strtotime($due)) / 86400) : 0;
        $when = $due === '' ? 'no due date' : ($late > 0 ? $late . ' day' . ($late === 1 ? '' : 's') . ' overdue' : ($late === 0 ? 'due today' : 'due ' . self::day($due)));
        if (count($open) === 1) {
            return self::item('penny', 'open_invoice', self::G_MONEY, $oldest['invoice_number'] . ' has ' . self::money2($total) . ' open, ' . $when . '.',
                'Open invoice', '/crm/invoices/view.php?id=' . (int)$oldest['id'], $late > 30 ? 1 : 2);
        }
        return self::item('penny', 'open_invoice', self::G_MONEY, count($open) . ' invoices open, ' . self::money2($total) . ' in all; the oldest is ' . $when . '.',
            'Statement', '/crm/invoices/statement.php?contact_id=' . $cid, $late > 30 ? 1 : 2);
    }

    /**
     * Pure: does this message say the customer has paid? "Paid. Thanks!", "sent the e-transfer",
     * "payment sent", "e-transferred it", a bare "done". Never a question, a negation
     * ("haven't paid", "unpaid") or a promise ("will pay Friday", "once it's paid").
     */
    public static function saysPaid(string $text): bool
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(["\u{2019}", "\u{2018}"], "'", $text))));
        if ($t === '' || strpos($t, '?') !== false) return false;
        // Negations and promises anywhere near the payment words.
        if (preg_match("/\b(?:not|never|haven't|hasn't|havent|hasnt|didn't|didnt|won't|can't|cannot|unable to|yet to|unpaid|still owe|before|once|when|if|after)\b[^.!]{0,25}\b(?:paid|pay|payment|sent|send|e-?\s?transfer)/", $t)) return false;
        if (preg_match("/\b(?:will|'ll|going to|gonna|plan to|planning to|about to|tomorrow|next week|later)\b[^.!]{0,25}\b(?:paid|pay|payment|send|sent|e-?\s?transfer)/", $t)) return false;
        if (preg_match("/\b(?:pay|send|e-?\s?transfer)\b[^.!]{0,25}\b(?:tomorrow|next week|later|friday|monday|soon|shortly)\b/", $t)) return false;
        if (preg_match('/\bpaid\b/', $t)) return true;
        if (preg_match('/\be-?\s?transferred\b/', $t)) return true;
        if (preg_match('/\b(?:sent|made|submitted|done|completed)\b[^.!]{0,20}\b(?:e-?\s?transfer|etransfer|interac|payment)/', $t)) return true;
        if (preg_match('/\b(?:e-?\s?transfer|etransfer|interac|payment)\b[^.!]{0,20}\b(?:sent|made|submitted|done|completed|went through|is through)\b/', $t)) return true;
        return (bool)preg_match('/^(?:all )?done(?: and done)?[\s.,!]*(?:(?:thanks|thank you|thx|ty)(?: so much)?[\s.,!]*)?$/', $t);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Otto
    // ─────────────────────────────────────────────────────────────────────────

    private function otto(array $sc, string $now): array
    {
        $out = [];
        $today = substr($now, 0, 10);
        if ($sc['properties']) {
            require_once dirname(__DIR__, 2) . '/Operations/Services/PropertyReadinessService.php';
            $ready = new PropertyReadinessService($this->db);
            $live = $this->ids("SELECT id FROM properties WHERE id IN (" . self::in($sc['properties']) . ") AND COALESCE(status, 'active') NOT IN ('archived', 'inactive') ORDER BY id LIMIT 6");
            foreach ($live as $pid) {
                $g = $ready->gaps($pid);
                if (!$g['property'] || !$g['missing']) continue;
                $addr = trim($g['property']['address']) ?: 'Their property';
                $out[] = self::item('otto', 'property_gaps', self::G_ACTION, self::street($addr) . ': ' . PropertyReadinessService::message($g['missing']),
                    in_array('pin', $g['missing'], true) ? 'Pin it' : (in_array('border', $g['missing'], true) ? 'Draw border' : 'Measure'),
                    in_array('pin', $g['missing'], true) || in_array('border', $g['missing'], true)
                        ? '/crm/properties/view.php?id=' . $pid : '/crm/products/area-measurement.php?property_id=' . $pid, 2);
            }
        }
        if ($sc['plans']) {
            $plans = self::in($sc['plans']);
            // Same definitions as getWorkQueueItems(): stuck = past date, still scheduled / in progress;
            // unbilled = completed with no invoice.
            $stuck = $this->db->query("SELECT jv.id, jv.plan_id, jv.scheduled_date FROM job_visits jv
                                       WHERE jv.plan_id IN ({$plans}) AND jv.scheduled_date < " . $this->db->quote($today) . "
                                         AND jv.status IN ('scheduled', 'in_progress') ORDER BY jv.scheduled_date")->fetchAll(PDO::FETCH_ASSOC);
            if ($stuck) {
                $out[] = self::item('otto', 'stuck_visits', self::G_ACTION, count($stuck) === 1
                    ? 'The ' . self::day($stuck[0]['scheduled_date']) . ' visit is still open. Close it or move it.'
                    : count($stuck) . ' past visits are still open, oldest ' . self::day($stuck[0]['scheduled_date']) . '. Close or move them.',
                    'Open plan', '/crm/jobs/view.php?id=' . (int)$stuck[0]['plan_id'], 2);
            }
            $unbilled = $this->db->query("SELECT jv.id, jv.plan_id, COALESCE(jv.actual_amount, jp.price_per_visit, 0) AS amt
                                          FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id
                                          WHERE jv.plan_id IN ({$plans}) AND jv.status = 'completed'
                                            AND (jv.is_invoiced = 0 OR jv.invoice_id IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
            if ($unbilled) {
                $amt = array_sum(array_map(fn($r) => (float)$r['amt'], $unbilled));
                $out[] = self::item('otto', 'unbilled_visits', self::G_MONEY, count($unbilled) . ' finished visit' . (count($unbilled) === 1 ? '' : 's')
                    . ' not invoiced yet' . ($amt > 0 ? ' (' . self::money2($amt) . ')' : '') . '.', 'Invoice it',
                    '/crm/invoices/create.php?contact_id=' . $sc['contact'], 2);
            }
            $s = $this->db->prepare("SELECT jv.scheduled_date, jp.id AS plan_id, jp.title, jp.service_type, p.address
                                     FROM job_visits jv JOIN job_plans jp ON jp.id = jv.plan_id JOIN properties p ON p.id = jp.property_id
                                     WHERE jv.plan_id IN ({$plans}) AND jv.scheduled_date >= ? AND jv.status = 'scheduled'
                                     ORDER BY jv.scheduled_date, jv.id LIMIT 1");
            $s->execute([$today]);
            $next = $s->fetch(PDO::FETCH_ASSOC);
            $active = (int)$this->one("SELECT COUNT(*) FROM job_plans WHERE id IN ({$plans}) AND status = 'active'");
            if ($next) {
                $what = trim((string)($next['title'] ?: str_replace('_', ' ', (string)$next['service_type']))) ?: 'Visit';
                $out[] = self::item('otto', 'next_visit', self::G_INFO, 'Next: ' . $what . ' on ' . date('D M j', strtotime($next['scheduled_date']))
                    . ($active > 0 ? '. ' . $active . ' active plan' . ($active === 1 ? '' : 's') . '.' : '.'),
                    'Open plan', '/crm/jobs/view.php?id=' . (int)$next['plan_id'], 3);
            } elseif ($active > 0) {
                $out[] = self::item('otto', 'no_next_visit', self::G_ACTION, $active . ' active plan' . ($active === 1 ? '' : 's') . ' but nothing on the schedule.',
                    'Schedule', '/crm/jobs/plans.php', 2);
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mia
    // ─────────────────────────────────────────────────────────────────────────

    private function mia(array $sc, string $now): array
    {
        $out = [];
        $c = $sc['row'];
        $cid = $sc['contact'];
        $nowDt = new DateTimeImmutable($now);

        // Consent.
        $email = $sms = null;
        try {
            require_once dirname(__DIR__, 2) . '/Consent/Services/ConsentLedgerService.php';
            $ledger = new ConsentLedgerService($this->db);
            if ($ledger->ready()) {
                $email = $ledger->allows($cid, 'email', $nowDt);
                $sms = $ledger->allows($cid, 'sms', $nowDt);
            }
        } catch (Throwable $e) { /* no ledger */ }
        $smsOk = $sms ? (bool)$sms['ok'] : (!empty($c['receive_sms']) || !empty($c['consent_sms']));
        $out[] = self::item('mia', 'consent', self::G_INFO, self::consentLine($email, $smsOk, trim((string)($c['email'] ?? '')) !== ''),
            'Consent', '/crm/clients_appstack.php?action=edit_contact&id=' . $cid, 3);

        // The last campaign they got, and what came of it.
        try {
            $s = $this->db->prepare("SELECT cs.contact_id, cs.status, cs.sent_at, cs.opened_at, mc.id AS campaign_id, mc.name
                                     FROM campaign_sends cs JOIN marketing_campaigns mc ON mc.id = cs.campaign_id
                                     WHERE cs.contact_id = ? AND cs.status = 'sent' AND cs.sent_at >= ?
                                     ORDER BY cs.sent_at DESC LIMIT 1");
            $s->execute([$cid, $nowDt->modify('-365 days')->format('Y-m-d')]);
            $send = $s->fetch(PDO::FETCH_ASSOC);
            if ($send) {
                require_once dirname(__DIR__, 2) . '/Marketing/Services/MiaCampaignService.php';
                $from = (string)$send['sent_at'];
                $q = $this->db->prepare("SELECT ? AS contact_id, q.status, COALESCE(NULLIF(q.total_amount, 0), q.amount, 0) AS amount, q.created_at FROM quotes q WHERE q.created_at >= ?"
                    . ($sc['quotes'] ? ' AND q.id IN (' . self::in($sc['quotes']) . ')' : ' AND 0'));
                $q->execute([$cid, $from]);
                $p = $sc['plans'] ? $this->db->prepare("SELECT ? AS contact_id, created_at FROM job_plans WHERE created_at >= ? AND id IN (" . self::in($sc['plans']) . ")") : null;
                $plans = [];
                if ($p) { $p->execute([$cid, $from]); $plans = $p->fetchAll(PDO::FETCH_ASSOC); }
                $r = $this->db->prepare("SELECT contact_id, sent_at FROM sales_messages WHERE contact_id = ? AND direction = 'inbound' AND sent_at >= ?");
                $r->execute([$cid, $from]);
                $t = MiaCampaignService::tally([$send], $q->fetchAll(PDO::FETCH_ASSOC), $plans, $r->fetchAll(PDO::FETCH_ASSOC), []);
                $out[] = self::item('mia', 'campaign', self::G_INFO, self::campaignLine((string)$send['name'], $from, $t), 'Campaigns', '/crm/marketing/campaigns.php', 3);
            }
        } catch (Throwable $e) { /* no campaign tables */ }

        // Review request.
        try {
            require_once dirname(__DIR__, 2) . '/Reviews/Services/ReviewRequestService.php';
            $done = $sc['plans'] ? (int)$this->one("SELECT COUNT(*) FROM job_visits WHERE status = 'completed' AND plan_id IN (" . self::in($sc['plans']) . ")") : 0;
            $line = self::reviewLine($c, $done, ReviewRequestService::isEligible($c, $nowDt));
            if ($line !== null) {
                $out[] = self::item('mia', 'review', self::G_INFO, $line, 'Contact', '/crm/clients_appstack.php?action=edit_contact&id=' . $cid, 3);
            }
        } catch (Throwable $e) { /* reviews columns missing */ }
        return $out;
    }

    public static function consentLine(?array $email, bool $smsOk, bool $hasEmail): string
    {
        if (!$hasEmail) $e = 'No email on file';
        elseif ($email === null) $e = 'Email consent isn\'t on the ledger yet';
        elseif ($email['ok']) $e = 'I can email them (' . $email['type'] . ($email['type'] === 'implied' && $email['expires_at'] ? ' until ' . date('M Y', strtotime($email['expires_at'])) : '') . ')';
        else $e = 'I can\'t email them: ' . $email['reason'];
        return $e . ($smsOk ? '; texts are fine.' : '; no texts.');
    }

    public static function campaignLine(string $name, string $sentAt, array $t): string
    {
        $name = trim(preg_replace('/\s*\(\d{4}\)\s*$/', '', $name)) ?: 'a campaign';
        $result = $t['booked'] ? 'they booked' : ($t['quoted'] ? 'they got a quote' : ($t['replied'] ? 'they replied' : ($t['opened'] ? 'opened, no reply' : 'no reply')));
        return 'Got "' . $name . '" on ' . self::day($sentAt) . ': ' . $result . '.';
    }

    /** @param int $done completed visits for them */
    public static function reviewLine(array $c, int $done, bool $eligible): ?string
    {
        if (!empty($c['has_reviewed'])) return 'They left us a Google review.';
        if (!empty($c['review_request_opted_out'])) return 'They asked not to get review requests.';
        $n = (int)($c['review_request_sent_count'] ?? 0);
        if (!empty($c['review_request_sent_at'])) {
            return 'Asked for a review ' . self::day((string)$c['review_request_sent_at']) . ' (' . $n . ' of 3)' . ($eligible && $done > 0 ? '; the next visit can ask again.' : '.');
        }
        if ($done > 0 && $eligible) return 'Review due: ' . $done . ' visit' . ($done === 1 ? '' : 's') . ' done, never asked. The next finished visit asks.';
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function item(string $head, string $kind, int $group, string $text, string $label, string $url, int $priority = 2): array
    {
        [$name, $role] = self::HEADS[$head] ?? [ucfirst($head), ''];
        return [
            'head' => $head, 'name' => $name, 'role' => $role, 'kind' => $kind,
            'group' => $group, 'priority' => $priority, 'text' => $text,
            'link' => $url !== '' ? ['label' => $label, 'url' => $url] : null,
        ];
    }

    /** A clear yes, then money, then replies, then other actions, then information; priority, then as given. */
    public static function order(array $items): array
    {
        $i = 0;
        foreach ($items as &$it) $it['_i'] = $i++;
        unset($it);
        usort($items, function ($a, $b) {
            return [$a['group'], $a['priority'], $a['_i']] <=> [$b['group'], $b['priority'], $b['_i']];
        });
        return array_map(function ($it) { unset($it['_i']); return $it; }, $items);
    }

    /** Ordered, Charlie added when nobody has anything to act on, cut to $max with the rest counted. */
    public static function finish(array $items, int $max = self::MAX_ITEMS): array
    {
        $acting = array_filter($items, fn($it) => $it['group'] < self::G_INFO);
        if (!$acting) {
            array_unshift($items, self::item('charlie', 'all_clear', self::G_ACTION, 'Nothing needs you here.', '', '', 9));
        }
        $items = self::order($items);
        return ['items' => array_slice($items, 0, $max), 'rest' => array_slice($items, $max), 'more' => max(0, count($items) - $max), 'total' => count($items)];
    }

    /** What an item's url (and ids) point at. */
    public static function refs(array $item): array
    {
        $r = ['contact' => [], 'properties' => [], 'companies' => [], 'quotes' => [], 'invoices' => [], 'contracts' => [], 'plans' => []];
        if (!empty($item['contact_id'])) $r['contact'][] = (int)$item['contact_id'];
        $url = (string)($item['url'] ?? '');
        $map = [
            '#view_contact&(?:amp;)?id=(\d+)#'           => 'contact',
            '#/properties/view\.php\?id=(\d+)#'          => 'properties',
            '#/companies/view\.php\?id=(\d+)#'           => 'companies',
            '#/quotes/view\.php\?id=(\d+)#'              => 'quotes',
            '#/invoices/view\.php\?id=(\d+)#'            => 'invoices',
            '#/contracts/view\.php\?id=(\d+)#'           => 'contracts',
            '#/jobs/view\.php\?id=(\d+)#'                => 'plans',
        ];
        foreach ($map as $re => $k) {
            if (preg_match($re, $url, $m)) $r[$k][] = (int)$m[1];
        }
        foreach ((array)($item['invoices'] ?? []) as $inv) {
            if (!empty($inv['id'])) $r['invoices'][] = (int)$inv['id'];
        }
        return $r;
    }

    /** Is this head item about this contact (their scope)? A note addressed to them counts. */
    public static function inScope(array $item, array $scope): bool
    {
        if (!empty($item['to']['contact_id']) && (int)$item['to']['contact_id'] === (int)$scope['contact']) return true;
        foreach (self::refs($item) as $k => $ids) {
            $own = $k === 'contact' ? [(int)$scope['contact']] : (array)($scope[$k] ?? []);
            if ($ids && array_intersect($ids, $own)) return true;
        }
        return false;
    }

    public static function money(float $v): string
    {
        return '$' . number_format($v, $v >= 1000 ? 0 : 2);
    }

    public static function money2(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    public static function day(string $d): string
    {
        $t = strtotime($d);
        return $t ? date('M j', $t) : '';
    }

    public static function street(string $address): string
    {
        return trim((string)strtok($address, ','));
    }

    public static function method(string $m): string
    {
        $m = strtolower($m);
        $names = ['e_transfer' => 'e-Transfer', 'etransfer' => 'e-Transfer', 'cheque' => 'cheque', 'check' => 'cheque', 'cash' => 'cash',
                  'stripe' => 'card', 'credit_card' => 'card', 'card' => 'card', 'eft' => 'EFT', 'bank' => 'bank'];
        return $names[$m] ?? ($m !== '' ? str_replace('_', ' ', $m) : 'payment');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Small DB helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function ids(string $sql, array $p = []): array
    {
        try {
            $s = $this->db->prepare($sql);
            $s->execute($p);
            return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function one(string $sql)
    {
        try {
            return $this->db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function hasColumn(string $table, string $col): bool
    {
        $k = $table . '.' . $col;
        if (!isset($this->cols[$k])) {
            try {
                $this->db->query('SELECT `' . preg_replace('/[^a-z0-9_]/', '', $col) . '` FROM `' . preg_replace('/[^a-z0-9_]/', '', $table) . '` LIMIT 0');
                $this->cols[$k] = true;
            } catch (Throwable $e) {
                $this->cols[$k] = false;
            }
        }
        return $this->cols[$k];
    }

    /** ints only — safe to inline */
    public static function in(array $ids): string
    {
        $ids = array_map('intval', $ids);
        return $ids ? implode(',', $ids) : '0';
    }

    public static function uniq(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}

