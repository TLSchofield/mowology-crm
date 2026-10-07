<?php
/**
 * YuiDeskService — Yui, the comms / client relations head: what she reads from the CRM.
 *
 * Read-only. Five sections (YuiRules has the decisions, unit tested):
 *   inbox     client replies nobody answered that aren't about a quote — UnclaimedReplyService's
 *             'client' lane (Sam keeps the 'quote' lane), so a reply is never shown twice;
 *   promises  inbound approvals ("council approved the quote") with no quote accepted after;
 *   accounts  PM firms / stratas missing people or emails, PM-managed buildings with nobody to
 *             send quotes to, contract holders still at lifecycle 'lead';
 *   renewals  contracts ending soon, last winter's salt/snow with nothing this winter, quiet PM firms;
 *   arrears   invoices more than 60 days overdue, with the right person to write to.
 * Every section fails on its own (logged, empty) — one bad query never blanks the card.
 *
 * brief() is Charlie's contract (CharlieBriefService) and works before migration 1191 has run;
 * the card (includes/yui-card.php) waits for ready().
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/YuiRules.php';

class YuiDeskService
{
    /** Rows per section on the card. */
    public const CARD_MAX = 12;
    public const NOT_TEST = 'ZZTEST';

    private PDO $db;
    private ?string $now;
    /** @var array<string, bool> */
    private array $cols = [];
    private ?array $sections = null;
    private ?array $samCards = null;
    /** Both lanes of unclaimed replies (Sam's and Yui's), for the promise check. */
    private array $unclaimedAll = [];
    private ?array $hidden = null;
    private bool $unclaimedRead = false;
    private ?array $firmCache = null;

    public function __construct(PDO $db, ?string $now = null)
    {
        $this->db = $db;
        $this->now = $now;
    }

    private function now(): string { return $this->now ?? date('Y-m-d H:i:s'); }
    private function today(): string { return substr($this->now(), 0, 10); }

    /** Migration 1191 has run (yui_actions: drafts, sends and "Handled"). */
    public function ready(): bool
    {
        return $this->hasTable('yui_actions');
    }

    public function hasTable(string $t): bool
    {
        try {
            // Literal, not a placeholder: MySQL won't take SHOW TABLES LIKE ? as a native prepared statement.
            return $this->db->query("SHOW TABLES LIKE " . $this->db->quote(preg_replace('/[^a-z0-9_]/', '', $t)))->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
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

    // ─────────────────────────────────────────────────────────────────────────
    // Sections
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array> section => items, in YuiRules::SECTIONS order */
    public function sections(): array
    {
        if ($this->sections !== null) return $this->sections;
        $out = [];
        foreach (YuiRules::SECTIONS as $s) {
            try {
                $out[$s] = $this->{$s}();
            } catch (Throwable $e) {
                error_log("Yui {$s}: " . $e->getMessage());
                $out[$s] = [];
            }
        }
        return $this->sections = $out;
    }

    /** Read-only and cheap — Charlie calls it for every head. */
    public function brief(string $ownerFirstName = ''): array
    {
        return YuiRules::brief($this->sections());
    }

    /** Keys not to show: Handled (any time), sent recently, or dismissed / snoozed in Charlie. */
    public function hiddenKeys(): array
    {
        if ($this->hidden !== null) return $this->hidden;
        $keys = [];
        if ($this->ready()) {
            try {
                $s = $this->db->prepare("SELECT DISTINCT item_key FROM yui_actions
                                         WHERE status = 'handled' OR (status IN ('sent', 'edited') AND decided_at >= ?)");
                $s->execute([date('Y-m-d H:i:s', strtotime($this->now()) - YuiRules::SENT_REST_DAYS * 86400)]);
                $keys = $s->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable $e) { /* nothing hidden */ }
        }
        if ($this->hasTable('charlie_items')) {
            try {
                $s = $this->db->prepare("SELECT item_key FROM charlie_items WHERE item_key LIKE 'yui:%' AND (dismissed_at IS NOT NULL OR snoozed_until > ?)");
                $s->execute([$this->today()]);
                $keys = array_merge($keys, $s->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) { /* nothing hidden */ }
        }
        return $this->hidden = array_values(array_unique(array_map('strval', $keys)));
    }

    /** Sam's follow-up queue (once per request): who is already "waiting on you" there. */
    private function samCards(): array
    {
        if ($this->samCards !== null) return $this->samCards;
        try {
            require_once dirname(__DIR__, 2) . '/Sales/Services/SalesDeskService.php';
            return $this->samCards = (new SalesDeskService($this->db))->queue();
        } catch (Throwable $e) {
            error_log('Yui (Sam queue): ' . $e->getMessage());
            return $this->samCards = [];
        }
    }

    private function samReplied(): array
    {
        return array_values(array_filter(array_map(fn($c) => ($c['kind'] ?? '') === 'replied' ? (int)$c['contact_id'] : 0, $this->samCards())));
    }

    public function inbox(): array
    {
        if (!$this->hasTable('sales_messages')) return [];
        $all = (new UnclaimedReplyService($this->db))->items($this->samReplied(), $this->now(), $this->hiddenKeys());
        $this->unclaimedAll = $all;
        $this->unclaimedRead = true;
        $items = YuiRules::inbox($all);
        $people = $this->people(array_column($items, 'contact_id'));
        foreach ($items as &$it) {
            $p = $people[(int)$it['contact_id']] ?? null;
            $it['to'] = $p ? YuiRules::pickContact([$p + ['billing' => true, 'why' => '']]) : null;
        }
        unset($it);
        return $items;
    }

    public function promises(): array
    {
        if (!$this->hasTable('sales_messages')) return [];
        if (!$this->unclaimedRead) {
            // inbox() runs first in sections(); called alone, read both lanes here.
            $this->unclaimedRead = true;
            try { $this->unclaimedAll = (new UnclaimedReplyService($this->db))->items($this->samReplied(), $this->now(), $this->hiddenKeys()); }
            catch (Throwable $e) { $this->unclaimedAll = []; }
        }
        $from = date('Y-m-d H:i:s', strtotime($this->now()) - YuiRules::PROMISE_WINDOW_DAYS * 86400);
        $emp = $this->hasColumn('contacts', 'employer_company_id') ? 'c.employer_company_id' : 'NULL';
        $s = $this->db->prepare("
            SELECT m.message_key, m.contact_id, m.channel, m.from_addr, m.subject, m.snippet, m.sent_at,
                   c.first_name, c.last_name, {$emp} AS employer_company_id
            FROM sales_messages m
            JOIN contacts c ON c.id = m.contact_id
            WHERE m.direction = 'inbound' AND m.contact_id IS NOT NULL AND m.sent_at >= ?
              AND CONCAT_WS(' ', c.first_name, c.last_name) NOT LIKE '%" . self::NOT_TEST . "%'
            ORDER BY m.sent_at DESC, m.id DESC
            LIMIT 600
        ");
        $s->execute([$from]);
        $messages = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$messages) return [];

        $accepted = [];
        try {
            $q = $this->db->prepare("
                SELECT q.contact_id, p.site_contact_id, q.company_id, p.property_manager_id AS pm_company_id, q.accepted_at
                FROM quotes q LEFT JOIN properties p ON p.id = q.property_id
                WHERE q.accepted_at IS NOT NULL AND q.accepted_at >= ?
            ");
            $q->execute([$from]);
            $accepted = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no quotes table → every approval shows */ }

        $items = YuiRules::promises($messages, [
            'accepted'      => $accepted,
            'skip_keys'     => array_column($this->unclaimedAll, 'message_key'),
            'skip_contacts' => $this->samReplied(),
            'hidden'        => $this->hiddenKeys(),
        ], $this->now());
        $names = [];
        foreach ($messages as $m) $names[(int)$m['contact_id']] = trim($m['first_name'] . ' ' . $m['last_name']);
        foreach ($items as &$it) {
            $it['quotes_url'] = '/crm/quotes/index.php?search=' . rawurlencode($names[$it['contact_id']] ?? '');
        }
        unset($it);
        return $items;
    }

    public function accounts(): array
    {
        [$firms] = $this->firms();
        $properties = [];
        $quoteCols = $this->hasColumn('properties', 'quote_contact_id') && $this->hasColumn('companies', 'quote_contact_id');
        $busy = $this->hasTable('contracts')
            ? "(EXISTS (SELECT 1 FROM contracts ct WHERE ct.property_id = p.id AND ct.status = 'active')
                OR EXISTS (SELECT 1 FROM quotes q WHERE q.property_id = p.id AND q.created_at >= ?))"
            : "EXISTS (SELECT 1 FROM quotes q WHERE q.property_id = p.id AND q.created_at >= ?)";
        $role = $this->hasColumn('contacts', 'contact_role') ? 'sc.contact_role' : 'NULL';
        $s = $this->db->prepare("
            SELECT p.id, p.address, p.property_manager_id AS firm_id, co.company_name AS firm_name,
                   " . ($quoteCols ? 'COALESCE(p.quote_contact_id, 0) AS p_quote, COALESCE(co.quote_contact_id, 0) AS co_quote' : '0 AS p_quote, 0 AS co_quote') . ",
                   {$role} AS site_role
            FROM properties p
            JOIN companies co ON co.id = p.property_manager_id
            LEFT JOIN contacts sc ON sc.id = p.site_contact_id
            WHERE COALESCE(p.status, 'active') = 'active' AND {$busy}
              AND CONCAT_WS(' ', p.address, co.company_name) NOT LIKE '%" . self::NOT_TEST . "%'
            ORDER BY co.company_name, p.address
            LIMIT 400
        ");
        $s->execute([date('Y-m-d', strtotime($this->today() . ' -365 days'))]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $reps = $this->strataReps(array_column($rows, 'id'));
        foreach ($rows as $r) {
            $properties[] = [
                'id' => (int)$r['id'], 'address' => (string)$r['address'], 'firm_id' => (int)$r['firm_id'], 'firm_name' => (string)$r['firm_name'],
                'has_quote_contact' => (int)$r['p_quote'] > 0 || (int)$r['co_quote'] > 0,
                'has_strata_rep' => in_array((string)$r['site_role'], ['strata_rep', 'property_manager'], true) || isset($reps[(int)$r['id']]),
            ];
        }

        $leads = [];
        if ($this->hasTable('contracts')) {
            $seen = [];
            foreach ($this->db->query("
                SELECT c.id AS contact_id, CONCAT_WS(' ', c.first_name, c.last_name) AS name, ct.id AS contract_id, ct.contract_number
                FROM contracts ct JOIN contacts c ON c.id = ct.contact_id
                WHERE ct.status = 'active' AND c.lifecycle_stage = 'lead'
                  AND CONCAT_WS(' ', c.first_name, c.last_name) NOT LIKE '%" . self::NOT_TEST . "%'
                ORDER BY ct.id
                LIMIT 200
            ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($seen[(int)$r['contact_id']])) continue;
                $seen[(int)$r['contact_id']] = true;
                $leads[] = $r;
            }
        }
        return YuiRules::accountFlags($firms, $properties, $leads, $this->hiddenKeys());
    }

    public function renewals(): array
    {
        if (!$this->hasTable('contracts')) return [];
        $hidden = $this->hiddenKeys();
        $today = $this->today();
        $auto = $this->hasColumn('contracts', 'auto_renew') ? 'ct.auto_renew' : '0';

        $s = $this->db->prepare("
            SELECT ct.id, ct.contract_number, ct.title, ct.end_date, {$auto} AS auto_renew, ct.billing_amount AS amount,
                   ct.contact_id, ct.property_id, p.address, p.property_manager_id, p.site_contact_id
            FROM contracts ct JOIN properties p ON p.id = ct.property_id
            WHERE ct.status = 'active' AND ct.end_date IS NOT NULL AND ct.end_date >= ? AND ct.end_date <= ?
            ORDER BY ct.end_date
            LIMIT 100
        ");
        $s->execute([$today, date('Y-m-d', strtotime($today . ' +' . YuiRules::RENEWAL_DAYS . ' days'))]);
        $ending = $s->fetchAll(PDO::FETCH_ASSOC);

        $season = YuiRules::season($this->now());
        $seasonal = [];
        $quoted = [];
        if ($season['days'] >= 0 && $season['days'] <= YuiRules::SEASON_LEAD_DAYS) {
            $s = $this->db->prepare("
                SELECT ct.id, ct.contract_number, ct.property_id, p.address, ct.title, COALESCE(q.service_type, '') AS service,
                       ct.status, ct.start_date, ct.end_date, ct.contact_id, p.property_manager_id, p.site_contact_id
                FROM contracts ct
                JOIN properties p ON p.id = ct.property_id
                LEFT JOIN quotes q ON q.id = ct.quote_id
                WHERE ct.status <> 'cancelled' AND (ct.end_date IS NULL OR ct.end_date >= ?)
                  AND CONCAT_WS(' ', p.address, ct.title) NOT LIKE '%" . self::NOT_TEST . "%'
                LIMIT 1000
            ");
            $s->execute([date('Y-m-d', strtotime($season['start'] . ' -' . YuiRules::LAST_SEASON_DAYS . ' days'))]);
            $seasonal = $s->fetchAll(PDO::FETCH_ASSOC);
            $q = $this->db->prepare("SELECT property_id, service_type, title FROM quotes WHERE property_id IS NOT NULL AND created_at >= ?");
            $q->execute([date('Y-m-d', strtotime($season['start'] . ' -150 days'))]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (YuiRules::isSeasonal((string)$r['service_type'] . ' ' . (string)$r['title'])) $quoted[] = (int)$r['property_id'];
            }
        }

        [$firms, $firmContacts] = $this->firms();
        $to = $this->contactResolver(array_merge($ending, $seasonal));
        foreach ($ending as &$r) $r['to'] = $to($r);
        unset($r);
        foreach ($seasonal as &$r) $r['to'] = $to($r);
        unset($r);

        // Quiet property-management firms: nothing sent to any of their people in 90 days.
        $checkins = [];
        if ($this->hasTable('sales_messages') && $firms) {
            $covered = false;
            try {
                $min = $this->db->query("SELECT MIN(sent_at) FROM sales_messages")->fetchColumn();
                $covered = $min && (string)$min <= date('Y-m-d H:i:s', strtotime($this->now()) - (YuiRules::CHECKIN_QUIET_DAYS - 7) * 86400);
            } catch (Throwable $e) { /* not covered */ }
            $all = array_values(array_unique(array_merge(...array_values($firmContacts ?: [[0]]))));
            $last = [];
            if ($covered && $all) {
                $in = implode(',', array_map('intval', $all));   // ints only
                foreach ($this->db->query("SELECT contact_id, MAX(sent_at) FROM sales_messages
                                           WHERE direction = 'outbound' AND contact_id IN ({$in}) GROUP BY contact_id")->fetchAll(PDO::FETCH_NUM) as $r) {
                    $last[(int)$r[0]] = (string)$r[1];
                }
            }
            $people = $this->people($all);
            $managing = $this->managingFirms();
            $mia = $this->miaQuietFirms();
            $rows = [];
            foreach ($firms as $f) {
                if (!isset($managing[(int)$f['id']]) || empty($firmContacts[(int)$f['id']]) || isset($mia[(int)$f['id']])) continue;
                $lo = '';
                foreach ($firmContacts[(int)$f['id']] as $cid) if (($last[$cid] ?? '') > $lo) $lo = $last[$cid];
                $cands = [];
                if (isset($people[(int)$f['primary_contact_id']])) $cands[] = $people[(int)$f['primary_contact_id']] + ['why' => 'primary contact'];
                foreach ($firmContacts[(int)$f['id']] as $cid) {
                    if (isset($people[$cid]) && in_array($people[$cid]['role'] ?? '', ['property_manager', 'strata_rep'], true)) $cands[] = $people[$cid] + ['why' => 'property manager'];
                }
                if (isset($people[(int)$f['billing_contact_id']])) $cands[] = $people[(int)$f['billing_contact_id']] + ['why' => 'billing contact', 'billing' => true];
                $rows[] = ['id' => (int)$f['id'], 'name' => $f['name'], 'last_out' => $lo ?: null, 'to' => YuiRules::pickContact($cands)];
            }
            $checkins = YuiRules::checkins($rows, $covered, $this->now(), $hidden);
        }

        return array_merge(
            YuiRules::endingContracts($ending, $this->now(), $hidden),
            YuiRules::seasonGaps($seasonal, $quoted, $this->now(), $hidden),
            $checkins
        );
    }

    public function arrears(): array
    {
        $cut = date('Y-m-d', strtotime($this->today() . ' -' . YuiRules::ARREARS_DAYS . ' days'));
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.balance_due, i.due_date, i.contact_id, i.company_id, i.property_id,
                   p.address, p.site_contact_id, p.property_manager_id,
                   co.company_name, co.primary_contact_id, co.billing_contact_id,
                   c.first_name, c.last_name
            FROM invoices i
            LEFT JOIN properties p ON p.id = i.property_id
            LEFT JOIN companies co ON co.id = i.company_id
            LEFT JOIN contacts c ON c.id = i.contact_id
            WHERE i.status IN ('sent', 'viewed', 'partial', 'overdue') AND i.balance_due > 0.005
              AND i.due_date IS NOT NULL AND i.due_date < ?
              AND CONCAT_WS(' ', c.first_name, c.last_name, co.company_name) NOT LIKE '%" . self::NOT_TEST . "%'
            ORDER BY i.due_date
            LIMIT 300
        ");
        $s->execute([$cut]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $groups = [];
        foreach ($rows as $r) {
            $payer = (int)$r['company_id'] > 0 ? 'company:' . (int)$r['company_id']
                : ((int)$r['contact_id'] > 0 ? 'contact:' . (int)$r['contact_id'] : 'property:' . (int)$r['property_id']);
            if (!isset($groups[$payer])) {
                $name = (int)$r['company_id'] > 0 ? (string)$r['company_name'] : trim($r['first_name'] . ' ' . $r['last_name']);
                $groups[$payer] = ['payer' => $payer, 'name' => $name, 'rows' => [], 'invoices' => [],
                                   'url' => '/crm/invoices/index.php?status=overdue&search=' . rawurlencode($name)];
            }
            $groups[$payer]['rows'][] = $r;
            $groups[$payer]['invoices'][] = ['id' => (int)$r['id'], 'number' => (string)$r['invoice_number'],
                                             'balance' => (float)$r['balance_due'], 'due_date' => (string)$r['due_date']];
        }

        $resolve = $this->contactResolver($rows, true);
        foreach ($groups as &$g) {
            $addresses = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)$r['address']), $g['rows']))));
            $g['place'] = count($addresses) === 1 ? $addresses[0] : $g['name'];
            $g['to'] = $resolve($g['rows'][0], $g['rows']);
            unset($g['rows']);
        }
        unset($g);
        return YuiRules::arrears(array_values($groups), $this->now(), $this->hiddenKeys());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Card
    // ─────────────────────────────────────────────────────────────────────────

    /** Everything the card shows: sections (CARD_MAX each) with drafts, the thread for replies, and numbers. */
    public function desk(string $owner): array
    {
        require_once __DIR__ . '/YuiDraftService.php';
        $drafts = new YuiDraftService($this->db);
        $out = [];
        foreach ($this->sections() as $s => $items) {
            $out[$s] = array_map(fn($it) => $this->forCard($it, $owner, $drafts), array_slice($items, 0, self::CARD_MAX));
        }
        return ['sections' => $out, 'stats' => $this->stats(), 'drafts_left' => $drafts->claudeLeft()];
    }

    /** One item by key, as the card has it (the server never trusts the browser's copy). */
    public function find(string $key, string $owner): ?array
    {
        foreach ($this->sections() as $items) {
            foreach ($items as $it) {
                if ($it['key'] === $key) {
                    require_once __DIR__ . '/YuiDraftService.php';
                    return $this->forCard($it, $owner, new YuiDraftService($this->db));
                }
            }
        }
        return null;
    }

    private function forCard(array $it, string $owner, YuiDraftService $drafts): array
    {
        $to = $it['to'] ?? null;
        if (!empty($it['template']) && $to) {
            $it['draft'] = YuiRules::draft($it, $owner, $drafts->learned((string)$it['template']));
            $it['sms_ok'] = $to['phone'] !== '' && $to['contact_id'] > 0 && function_exists('hasSmConsent') && hasSmConsent((int)$to['contact_id']);
        }
        if (($it['section'] ?? '') === 'inbox') $it['thread'] = $drafts->thread((int)$it['contact_id'], 4);
        unset($it['message_key']);
        return $it;
    }

    public function stats(): array
    {
        $s = $this->sections();
        $sent30 = 0;
        if ($this->ready()) {
            try {
                $q = $this->db->prepare("SELECT COUNT(*) FROM yui_actions WHERE status IN ('sent', 'edited') AND decided_at >= ?");
                $q->execute([date('Y-m-d H:i:s', strtotime($this->now()) - 30 * 86400)]);
                $sent30 = (int)$q->fetchColumn();
            } catch (Throwable $e) { /* none */ }
        }
        return [
            'inbox'         => count($s['inbox']),
            'yes'           => count(array_filter($s['inbox'], fn($i) => !empty($i['yes']))),
            'promises'      => count($s['promises']),
            'accounts'      => count($s['accounts']),
            'renewals'      => count($s['renewals']),
            'arrears'       => count($s['arrears']),
            'arrears_total' => round(array_sum(array_map(fn($a) => (float)$a['total'], $s['arrears'])), 2),
            'sent_30'       => $sent30,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // People
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<int, array{id, first_name, last_name, email, phone, role}> */
    public function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $role = $this->hasColumn('contacts', 'contact_role') ? 'contact_role' : 'NULL';
        $in = implode(',', $ids);   // ints only
        $out = [];
        foreach ($this->db->query("SELECT id, first_name, last_name, email, COALESCE(NULLIF(mobile, ''), phone) AS phone, {$role} AS role
                                   FROM contacts WHERE id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id'] = (int)$r['id'];
            $r['phone'] = (string)$r['phone'];
            $r['role'] = (string)$r['role'];
            $out[$r['id']] = $r;
        }
        return $out;
    }

    /**
     * Property-management firms and stratas (type, or named as a building's property manager),
     * with their people: primary, billing, and contacts employed there. Once per request.
     * @return array{0: array, 1: array<int, int[]>} firms (for YuiRules::accountFlags), firm id => contact ids
     */
    private function firms(): array
    {
        if ($this->firmCache !== null) return $this->firmCache;
        $rows = $this->db->query("
            SELECT co.id, co.company_name, co.company_type, co.primary_contact_id, co.billing_contact_id, co.billing_email
            FROM companies co
            WHERE (co.company_type IN ('property_manager', 'strata')
                   OR co.id IN (SELECT DISTINCT property_manager_id FROM properties WHERE property_manager_id IS NOT NULL))
              AND COALESCE(co.account_status, 'active') <> 'inactive'
              AND co.company_name NOT LIKE '%" . self::NOT_TEST . "%'
            ORDER BY co.company_name
            LIMIT 300
        ")->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $members = [];
        foreach ($rows as $r) $members[(int)$r['id']] = array_filter([(int)$r['primary_contact_id'], (int)$r['billing_contact_id']]);
        if ($ids && $this->hasColumn('contacts', 'employer_company_id')) {
            $in = implode(',', $ids);   // ints only
            foreach ($this->db->query("SELECT id, employer_company_id FROM contacts WHERE employer_company_id IN ({$in})")->fetchAll(PDO::FETCH_NUM) as $r) {
                $members[(int)$r[1]][] = (int)$r[0];
            }
        }
        $people = $this->people(array_merge([], ...array_values($members ?: [[]])));
        $firms = [];
        $contactsByFirm = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $mine = array_values(array_unique(array_filter($members[$id] ?? [], fn($c) => isset($people[$c]))));
            $contactsByFirm[$id] = $mine;
            $firms[] = [
                'id' => $id, 'name' => (string)$r['company_name'], 'type' => (string)$r['company_type'],
                'primary_contact_id' => (int)$r['primary_contact_id'], 'billing_contact_id' => (int)$r['billing_contact_id'],
                'billing_email' => (string)$r['billing_email'], 'contacts' => $mine,
                'people' => array_intersect_key($people, array_flip($mine)),
            ];
        }
        return $this->firmCache = [$firms, $contactsByFirm];
    }

    /**
     * Firms Mia is already reaching out to (pm_quiet suggestion open, or sent in the last
     * CHECKIN_QUIET_DAYS) — Yui doesn't draft a second note to the same people.
     */
    private function miaQuietFirms(): array
    {
        if (!$this->hasTable('mia_suggestions')) return [];
        $out = [];
        try {
            $s = $this->db->prepare("SELECT DISTINCT company_id FROM mia_suggestions
                                     WHERE kind = 'pm_quiet' AND company_id IS NOT NULL
                                       AND (status = 'open' OR (status = 'sent' AND decided_at >= ?))");
            $s->execute([date('Y-m-d H:i:s', strtotime($this->now()) - YuiRules::CHECKIN_QUIET_DAYS * 86400)]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
        } catch (Throwable $e) { /* Mia not set up → nothing to skip */ }
        return $out;
    }

    /** Firms that manage at least one active building. */
    private function managingFirms(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT DISTINCT property_manager_id FROM properties
                                   WHERE property_manager_id IS NOT NULL AND COALESCE(status, 'active') = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /** Buildings with a property-manager or strata-rep person on file (property_contacts). */
    private function strataReps(array $propertyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $propertyIds))));
        if (!$ids || !$this->hasTable('property_contacts')) return [];
        $in = implode(',', $ids);   // ints only
        $role = $this->hasColumn('contacts', 'contact_role') ? "OR c.contact_role IN ('strata_rep', 'property_manager')" : '';
        $out = [];
        foreach ($this->db->query("SELECT DISTINCT pc.property_id FROM property_contacts pc JOIN contacts c ON c.id = pc.contact_id
                                   WHERE pc.property_id IN ({$in}) AND (pc.contact_role = 'manager' {$role})")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /**
     * Who to write to about a contract or an invoice, in the owner-approved order: the
     * building's property manager (property_contacts 'manager', else the managing firm's
     * primary), the paying company's primary, then the billing contact (the only place an
     * accountant is allowed), then the contract / invoice contact.
     * @return callable(array $row, ?array $rows): ?array
     */
    private function contactResolver(array $rows, bool $invoices = false): callable
    {
        $propIds = array_filter(array_map(fn($r) => (int)($r['property_id'] ?? 0), $rows));
        $managers = [];
        if ($propIds && $this->hasTable('property_contacts')) {
            $in = implode(',', array_unique($propIds));   // ints only
            foreach ($this->db->query("SELECT property_id, contact_id FROM property_contacts
                                       WHERE property_id IN ({$in}) AND contact_role = 'manager' ORDER BY is_primary DESC, id")->fetchAll(PDO::FETCH_NUM) as $r) {
                if (!isset($managers[(int)$r[0]])) $managers[(int)$r[0]] = (int)$r[1];
            }
        }
        $companyIds = array_filter(array_merge(array_map(fn($r) => (int)($r['property_manager_id'] ?? 0), $rows),
                                               array_map(fn($r) => (int)($r['company_id'] ?? 0), $rows)));
        $companies = [];
        if ($companyIds) {
            $in = implode(',', array_unique($companyIds));   // ints only
            foreach ($this->db->query("SELECT id, primary_contact_id, billing_contact_id FROM companies WHERE id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $companies[(int)$c['id']] = $c;
            }
        }
        $ids = array_values($managers);
        foreach ($companies as $c) { $ids[] = (int)$c['primary_contact_id']; $ids[] = (int)$c['billing_contact_id']; }
        foreach ($rows as $r) { $ids[] = (int)($r['contact_id'] ?? 0); $ids[] = (int)($r['site_contact_id'] ?? 0); }
        $people = $this->people($ids);

        return function (array $row, ?array $all = null) use ($managers, $companies, $people, $invoices): ?array {
            $p = fn(int $id, string $why, bool $billing = false) => isset($people[$id]) ? [$people[$id] + ['why' => $why, 'billing' => $billing]] : [];
            $pm = $companies[(int)($row['property_manager_id'] ?? 0)] ?? null;
            $payer = $companies[(int)($row['company_id'] ?? 0)] ?? null;
            $c = array_merge(
                $p($managers[(int)($row['property_id'] ?? 0)] ?? 0, 'property manager'),
                $pm ? $p((int)$pm['primary_contact_id'], 'property manager') : [],
                $payer ? $p((int)$payer['primary_contact_id'], 'company primary') : [],
                $payer ? $p((int)$payer['billing_contact_id'], 'billing contact', true) : [],
                $p((int)($row['site_contact_id'] ?? 0), 'billing contact', true),
                $p((int)($row['contact_id'] ?? 0), $invoices ? 'billing contact' : 'contract contact', $invoices)
            );
            return YuiRules::pickContact($c);
        };
    }
}
