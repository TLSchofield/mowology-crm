<?php
/**
 * CharlieInboxService — every head's proposals in one queue: a view and a router.
 *
 * The heads keep their logic. A proposal's buttons post to the head's OWN endpoint (from
 * Tim's browser, with his CSRF token, so the head's permission checks and validation run);
 * the inbox only collects, ranks, batches and applies Tim's conflict rules.
 *   - Collected from: heads that publish proposals() (none yet), read-only adapters for
 *     Otto (his open suggestions) and Sam (his follow-up queue), Charlie's own deadlines,
 *     and every other brief item as a view-only row (link to the head's card).
 *   - De-duplicated by key; one message per client is a rule (ConflictRules).
 *   - Ranked by money at stake and deadline (CharlieRankService + a due-date factor).
 *   - Batched: two or more proposals with the same batch_key become one tap.
 *   - Buttons may only post to ENDPOINTS — anything else is dropped (the data can never
 *     make the inbox post somewhere new).
 * Charlie holds no money, filing, signing or messaging power of his own.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieRankService.php';
require_once __DIR__ . '/CharlieBriefService.php';
require_once __DIR__ . '/ConflictRules.php';
require_once __DIR__ . '/DeadlineService.php';

class CharlieInboxService
{
    public const ENDPOINTS = ['/crm/api/otto.php', '/crm/api/sales-head.php', '/crm/api/bookkeeper.php', '/crm/api/charlie.php', '/crm/api/mia.php'];
    public const MIN_BATCH = 2;

    private PDO $db;
    private CharlieBriefService $briefs;
    /** @var array<string, callable(string): array> extra proposal sources (tests inject) */
    private array $sources;
    private ?string $today;

    public function __construct(PDO $db, ?CharlieBriefService $briefs = null, ?array $sources = null, ?string $today = null)
    {
        $this->db = $db;
        $this->briefs = $briefs ?? new CharlieBriefService($db);
        $this->sources = $sources ?? $this->defaultSources();
        $this->today = $today;
    }

    private function today(): string { return $this->today ?? date('Y-m-d'); }

    public function ready(): bool
    {
        try {
            return $this->db->query("SHOW TABLES LIKE 'charlie_rules'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sources (read-only adapters — the heads' own files are never touched)
    // ─────────────────────────────────────────────────────────────────────────

    private function defaultSources(): array
    {
        $db = $this->db;
        $out = [];
        if (defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php')) {
            $out['otto'] = static function () use ($db) {
                require_once APP_ROOT . '/Modules/Operations/Services/OpsDeskService.php';
                $o = new OpsDeskService($db);
                if (method_exists($o, 'ready') && !$o->ready()) return [];
                return CharlieInboxService::fromOtto($o->current(false));
            };
        }
        if (defined('APP_ROOT') && is_file(APP_ROOT . '/Modules/Sales/Services/SalesDeskService.php')) {
            $out['sam'] = static function () use ($db) {
                require_once APP_ROOT . '/Modules/Sales/Services/SalesDeskService.php';
                return CharlieInboxService::fromSam((new SalesDeskService($db))->queue());
            };
        }
        $out['charlie'] = static function () use ($db) {
            require_once __DIR__ . '/DeadlineService.php';
            $d = new DeadlineService($db);
            return $d->ready() ? CharlieInboxService::fromDeadlines($d->brief()['items']) : [];
        };
        return $out;
    }

    /** Pure: Otto's open suggestions → proposals (buttons post to /crm/api/otto.php decide). */
    public static function fromOtto(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $sid = (int)($it['sid'] ?? 0);
            $p = [
                'key' => (string)$it['key'], 'head' => 'otto', 'kind' => 'otto:' . $it['kind'], 'channel' => 'schedule',
                'text' => (string)$it['text'], 'url' => $it['url'] ?? '/crm/dashboard_appstack.php#mw-otto',
                'priority' => (int)($it['priority'] ?? 2), 'value' => null,
                'due' => $it['for_date'] ?? null, 'actions' => [],
            ];
            $propose = (array)($it['propose'] ?? []);
            $post = fn(array $body, string $label, string $style = 'primary') =>
                ['label' => $label, 'endpoint' => '/crm/api/otto.php', 'body' => ['mode' => 'decide', 'suggestion_id' => $sid] + $body, 'style' => $style];
            if ($sid > 0) {
                switch ($it['kind']) {
                    case 'weather':
                        $p['actions'][] = $post(['choice' => 'keep'], 'Keep it on');
                        break;
                    case 'clock_out':
                        if (!empty($propose['clock_out'])) {
                            $p['actions'][] = $post(['choice' => 'apply', 'clock_out' => $propose['clock_out']], 'Clock out at ' . date('g:i a', strtotime((string)$propose['clock_out'])));
                            $p['batch_key'] = 'otto:clock_out';
                            $p['batch_label'] = 'Set %d suggested clock-outs';
                        }
                        break;
                    case 'job_timer':
                    case 'no_time':
                        if (!empty($propose['minutes'])) {
                            $p['actions'][] = $post(['choice' => 'apply', 'minutes' => (int)$propose['minutes']], 'Use ' . (int)$propose['minutes'] . ' min');
                            $p['batch_key'] = 'otto:minutes';
                            $p['batch_label'] = 'Fill in %d job times as suggested';
                        }
                        break;
                    case 'silent':
                        $p['actions'][] = $post(['choice' => 'fine'], "They're fine");
                        break;
                }
                $p['actions'][] = $post(['choice' => 'dismiss'], 'Leave it', 'quiet');
            }
            $out[] = $p;
        }
        return $out;
    }

    /** Pure: Sam's follow-up cards → message proposals (sending stays on Sam's card). */
    public static function fromSam(array $cards): array
    {
        $out = [];
        foreach ($cards as $c) {
            $replied = ($c['kind'] ?? '') === 'replied';
            $out[] = [
                'key' => 'sam:contact:' . $c['key'], 'head' => 'sam', 'kind' => $replied ? 'sam:customer_replied' : 'sam:quote_followup',
                'channel' => 'message', 'contact_id' => !empty($c['contact_id']) ? (int)$c['contact_id'] : null,
                'contact_name' => (string)($c['name'] ?? ''),
                'text' => $replied ? ($c['name'] ?? 'A customer') . ' replied about ' . ($c['label'] ?? 'their quote') . ' — they\'re waiting on you'
                                   : ($c['name'] ?? 'A customer') . ': ' . ($c['label'] ?? 'quote') . ' — ' . (int)($c['days'] ?? 0) . ' days, no reply. Sam has a follow-up ready.',
                'url' => '/crm/dashboard_appstack.php#mw-sam', 'priority' => $replied ? 1 : 2,
                'value' => isset($c['amount']) ? (float)$c['amount'] : null, 'due' => null,
                'actions' => [['label' => 'Not now', 'endpoint' => '/crm/api/sales-head.php',
                               'body' => ['mode' => 'park', 'card_key' => (string)$c['key'], 'how' => 'skip'], 'style' => 'quiet']],
            ];
        }
        return $out;
    }

    /** Pure: Charlie's deadline brief items → proposals with a Done button. */
    public static function fromDeadlines(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $out[] = $it + ['head' => 'charlie', 'channel' => 'other', 'due' => DeadlineService::parseKey($it['key'])[1] ?? null,
                'actions' => [['label' => 'Done', 'endpoint' => '/crm/api/charlie.php', 'body' => ['mode' => 'deadline_done', 'key' => $it['key']], 'style' => 'primary']]];
        }
        return $out;
    }

    /** Pure: drop any button that doesn't post to a known head endpoint. */
    public static function safeActions(array $actions): array
    {
        $ok = [];
        foreach ($actions as $a) {
            if (!is_array($a) || !in_array($a['endpoint'] ?? '', self::ENDPOINTS, true)) continue;
            if (!is_array($a['body'] ?? null) || !is_string($a['body']['mode'] ?? null)) continue;
            $ok[] = ['label' => mb_substr((string)($a['label'] ?? 'Do it'), 0, 60), 'endpoint' => $a['endpoint'], 'body' => $a['body'],
                     'style' => in_array($a['style'] ?? '', ['primary', 'quiet'], true) ? $a['style'] : 'primary'];
        }
        return $ok;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The queue
    // ─────────────────────────────────────────────────────────────────────────

    /** Every proposal, de-duplicated by key, ranked best first. */
    public function proposals(string $name): array
    {
        $by = [];
        foreach ($this->sources as $head => $fn) {
            try {
                foreach ((array)$fn($name) as $p) {
                    if (!is_array($p) || empty($p['key']) || isset($by[$p['key']])) continue;
                    $by[$p['key']] = $p;
                }
            } catch (Throwable $e) {
                error_log("Charlie inbox: {$head} proposals failed: " . $e->getMessage());
            }
        }
        // Everything else the heads said today, view-only (link to their card).
        $c = $this->briefs->collect($name);
        foreach ($c['items'] as $it) {
            if (isset($by[$it['key']])) continue;
            if (strpos($it['key'], 'sam:contact:') === 0) continue;   // Sam's full queue is above
            $by[$it['key']] = $it + ['channel' => 'other', 'due' => null, 'actions' => []];
        }
        $items = [];
        foreach ($by as $p) {
            $n = CharlieRankService::normalize($p, (string)($p['head'] ?? 'house'));
            if (!$n) continue;
            $items[] = $n + [
                'channel' => (string)($p['channel'] ?? 'other'), 'contact_id' => $p['contact_id'] ?? null,
                'contact_name' => (string)($p['contact_name'] ?? ''), 'due' => $p['due'] ?? null,
                'batch_key' => $p['batch_key'] ?? null, 'batch_label' => $p['batch_label'] ?? null,
                'actions' => self::safeActions((array)($p['actions'] ?? [])),
            ];
        }
        return self::rank($items, $this->prefs(), $this->today());
    }

    /** Pure: rank by Charlie's score with a deadline factor (due ≤1 day ×2, ≤3 days ×1.5). */
    public static function rank(array $items, array $prefs, string $today): array
    {
        foreach ($items as &$it) {
            $s = CharlieRankService::score($it, (float)($prefs[$it['kind']]['score'] ?? 1.0), $today);
            if (!empty($it['due'])) {
                $d = (int)round((strtotime($it['due']) - strtotime($today)) / 86400);
                $s *= $d <= 1 ? 2.0 : ($d <= 3 ? 1.5 : 1.0);
            }
            $it['score'] = round($s, 3);
        }
        unset($it);
        usort($items, fn($a, $b) => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);
        return $items;
    }

    /** Pure: two or more with the same batch_key → one row (in the best member's place). */
    public static function batch(array $ranked): array
    {
        $groups = [];
        foreach ($ranked as $p) {
            if (!empty($p['batch_key'])) $groups[$p['batch_key']][] = $p;
        }
        $rows = [];
        $done = [];
        foreach ($ranked as $p) {
            $bk = $p['batch_key'] ?? null;
            if ($bk && count($groups[$bk]) >= self::MIN_BATCH) {
                if (isset($done[$bk])) continue;
                $done[$bk] = true;
                $members = $groups[$bk];
                $rows[] = ['type' => 'batch', 'batch_key' => $bk, 'head' => $p['head'],
                           'label' => sprintf((string)($p['batch_label'] ?: 'Approve %d of these'), count($members)),
                           'value' => array_sum(array_map(fn($m) => (float)($m['value'] ?? 0), $members)) ?: null,
                           'score' => $p['score'], 'items' => $members];
                continue;
            }
            $rows[] = ['type' => 'one'] + $p;
        }
        return $rows;
    }

    private function prefs(): array
    {
        try {
            $out = [];
            foreach ($this->db->query("SELECT kind, score FROM charlie_prefs")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['kind']] = ['score' => (float)$r['score']];
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rules
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array> slug => row with decoded params and current sentence */
    public function rules(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT * FROM charlie_rules ORDER BY phase = 'later', id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['params'] = json_decode((string)$r['params'], true) ?: [];
            $r['enabled'] = (bool)$r['enabled'] && $r['phase'] === 'now';
            $r['sentence'] = ConflictRules::sentence($r['slug'], $r['params'], (string)$r['text']);
            $out[$r['slug']] = $r;
        }
        return $out;
    }

    public function saveRule(string $slug, bool $enabled, array $params): array
    {
        if (!in_array($slug, ConflictRules::LIVE, true)) return ['ok' => false, 'message' => "That rule isn't live yet"];
        $clean = [];
        foreach (ConflictRules::DEFAULTS[$slug] as $k => $def) {
            $v = (int)($params[$k] ?? $def);
            $clean[$k] = max(1, min(120, $v));
        }
        $this->db->prepare("UPDATE charlie_rules SET enabled = ?, params = ?, text = ? WHERE slug = ?")
            ->execute([$enabled ? 1 : 0, json_encode($clean), ConflictRules::sentence($slug, $clean, ''), $slug]);
        return ['ok' => true, 'message' => 'Rule saved.'];
    }

    /** Facts the rules need, for the clients in these proposals. */
    public function facts(array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $facts = ['late' => [], 'last_message' => []];
        if (!$ids) return $facts;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $today = $this->today();
        // Late invoices: the invoice's contact OR anyone it was billed to (invoice_contacts).
        try {
            $s = $this->db->prepare("
                SELECT x.cid, MAX(DATEDIFF(?, x.due_date)) AS late FROM (
                    SELECT i.contact_id AS cid, i.due_date FROM invoices i
                    WHERE i.contact_id IN ({$in}) AND i.balance_due > 0 AND i.due_date < ?
                      AND COALESCE(i.status, '') NOT IN ('paid', 'void', 'cancelled', 'draft')
                    UNION ALL
                    SELECT ic.contact_id AS cid, i.due_date FROM invoices i JOIN invoice_contacts ic ON ic.invoice_id = i.id
                    WHERE ic.contact_id IN ({$in}) AND i.balance_due > 0 AND i.due_date < ?
                      AND COALESCE(i.status, '') NOT IN ('paid', 'void', 'cancelled', 'draft')
                ) x GROUP BY x.cid
            ");
            $s->execute(array_merge([$today], $ids, [$today], $ids, [$today]));
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $facts['late'][(int)$r['cid']] = (int)$r['late'];
        } catch (Throwable $e) {
            error_log('Charlie inbox: late-invoice facts unavailable: ' . $e->getMessage());
        }
        // Last outbound message: Sam's mail log, campaign sends, the communication log (best effort —
        // other CRM emails aren't logged centrally; see the phase-2 plan).
        $sources = [
            "SELECT contact_id AS cid, MAX(sent_at) AS last FROM sales_messages WHERE direction = 'outbound' AND contact_id IN ({$in}) GROUP BY contact_id",
            "SELECT contact_id AS cid, MAX(sent_at) AS last FROM campaign_sends WHERE sent_at IS NOT NULL AND contact_id IN ({$in}) GROUP BY contact_id",
            "SELECT contact_id AS cid, MAX(created_at) AS last FROM communication_log WHERE direction = 'outbound' AND contact_id IN ({$in}) GROUP BY contact_id",
        ];
        foreach ($sources as $sql) {
            try {
                $s = $this->db->prepare($sql);
                $s->execute($ids);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!$r['last']) continue;
                    $days = max(0, (int)floor((strtotime($today) - strtotime(substr((string)$r['last'], 0, 10))) / 86400));
                    $cid = (int)$r['cid'];
                    $facts['last_message'][$cid] = min($facts['last_message'][$cid] ?? PHP_INT_MAX, $days);
                }
            } catch (Throwable $e) { /* table not there — that source is skipped */ }
        }
        return $facts;
    }

    /**
     * The inbox: rows (batches and singles, ranked), held proposals with their rule, counts.
     * Every hold and escalation is logged (once per rule, proposal and day).
     */
    public function view(string $name): array
    {
        $ranked = $this->proposals($name);
        $rules = $this->rules();
        $released = $this->releasedToday();
        $res = ConflictRules::apply($ranked, $this->facts(array_column($ranked, 'contact_id')), $rules, $released);
        $this->log($res);
        $held = array_map(fn($h) => ['key' => $h['proposal']['key'], 'head' => $h['proposal']['head'], 'text' => $h['proposal']['text'],
            'rule' => $h['rule'], 'rule_title' => $rules[$h['rule']]['title'] ?? $h['rule'], 'reason' => $h['reason'],
            'url' => $h['proposal']['url']], $res['held']);
        return [
            'rows'      => self::batch($res['allowed']),
            'held'      => $held,
            'escalated' => count($res['escalated']),
            'total'     => count($res['allowed']),
        ];
    }

    private function releasedToday(): array
    {
        $s = $this->db->prepare("SELECT rule_slug, proposal_key FROM charlie_rulings WHERE ruling_date = ? AND overridden_at IS NOT NULL");
        $s->execute([$this->today()]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['rule_slug'] . '|' . $r['proposal_key']] = true;
        return $out;
    }

    private function log(array $res): void
    {
        $ins = $this->db->prepare("
            INSERT IGNORE INTO charlie_rulings (rule_slug, proposal_key, head, contact_id, verdict, reason, detail, ruling_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($res['held'] as $h) {
            $p = $h['proposal'];
            $ins->execute([$h['rule'], $p['key'], $p['head'], $p['contact_id'] ?: null, 'held', mb_substr($h['reason'], 0, 500), json_encode($h['detail'] ?? []), $this->today()]);
        }
        foreach ($res['escalated'] as $h) {
            $p = $h['proposal'];
            $ins->execute([$h['rule'], $p['key'], $p['head'], $p['contact_id'] ?: null, 'escalated', mb_substr($h['reason'], 0, 500), null, $this->today()]);
        }
    }

    /** Tim overrides a hold: released for today, logged; twice in 60 days → a rewrite question. */
    public function override(string $rule, string $key, int $userId): array
    {
        $s = $this->db->prepare("UPDATE charlie_rulings SET overridden_at = NOW(), overridden_by = ? WHERE rule_slug = ? AND proposal_key = ? AND ruling_date = ? AND overridden_at IS NULL");
        $s->execute([$userId, $rule, $key, $this->today()]);
        if ($s->rowCount() === 0) return ['ok' => false, 'message' => 'Nothing to override'];

        $q = $this->db->prepare("SELECT detail FROM charlie_rulings WHERE rule_slug = ? AND overridden_at >= ? ORDER BY overridden_at DESC");
        $q->execute([$rule, date('Y-m-d', strtotime($this->today() . ' -' . ConflictRules::OVERRIDE_WINDOW_DAYS . ' days'))]);
        $details = array_map(fn($d) => json_decode((string)$d, true) ?: [], $q->fetchAll(PDO::FETCH_COLUMN));
        $msg = "Released — it's back in the queue. I've noted the override.";
        if (count($details) >= ConflictRules::OVERRIDES_TO_REWRITE) {
            $rules = $this->rules();
            $new = ConflictRules::rewrite($rule, $rules[$rule]['params'] ?? [], $details);
            if ($new) {
                $this->db->prepare("
                    INSERT IGNORE INTO charlie_questions (kind, pair_hash, kind_a, text_a, text_b) VALUES ('rule_rewrite', ?, ?, ?, ?)
                ")->execute([sha1('rule_rewrite|' . $rule . '|' . json_encode($new)), $rule,
                             ConflictRules::sentence($rule, $new, ''), json_encode($new)]);
                $msg .= " That's " . count($details) . ' overrides lately — I\'ve suggested a change to the rule.';
            }
        }
        return ['ok' => true, 'message' => $msg];
    }

    /** What Tim did from the inbox (for the log and for Charlie's learning). */
    public function logAction(string $key, ?string $head, string $label, ?string $batchKey, bool $ok, string $message, int $userId): void
    {
        $this->db->prepare("
            INSERT INTO charlie_inbox_actions (proposal_key, head, action_label, batch_key, ok, message, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([substr($key, 0, 120), $head ? substr($head, 0, 20) : null, mb_substr($label, 0, 80), $batchKey ? substr($batchKey, 0, 60) : null,
                     $ok ? 1 : 0, mb_substr($message, 0, 255), $userId]);
    }

    /** Recent rulings for the Rules tab (visible, newest first). */
    public function rulings(int $limit = 50): array
    {
        return $this->db->query("SELECT * FROM charlie_rulings ORDER BY id DESC LIMIT " . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    }
}
