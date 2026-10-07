<?php
/**
 * CharlieAskService — "Ask Charlie": the owner types a question about the business
 * ("has Linda's quote been sent?") and Charlie answers from CRM data. iOS Team tab first.
 *
 * Read-only and on-click only:
 *   - Context is looked up HERE with prepared statements — names, streets and quote/invoice
 *     numbers taken from the question are matched against contacts, companies and
 *     properties, then their recent quotes, invoices and visits are read. The model never
 *     writes SQL and never sees anything but this compact context.
 *   - Nothing matched → Charlie says so without calling Claude (free, not counted).
 *   - A daily cap (ops_settings charlie_ask_daily_cap, default 30 calls).
 *   - Every ask is logged in charlie_asks with tokens and cost (migration 1215).
 *   - Counts and totals from the other heads (Otto's truck log and cost facts, Penny's receipts,
 *     the schedule) are answered first by CharlieFactAnswerer with plain SQL — free, not counted
 *     against the cap, logged with source 'facts'. When it isn't sure, the same summaries are
 *     added to Claude's context (counts and totals, never raw rows).
 *   - Every answer ends with where it came from ("— from Otto's truck log").
 * Claude is called over HTTP the way BankGuidanceService does (no SDK on the FTP-deployed
 * host); tests inject a fake transport.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/CharlieFactAnswerer.php';

class CharlieAskService
{
    public const API_URL     = 'https://api.anthropic.com/v1/messages';
    public const MODEL       = 'claude-sonnet-5-5';
    /** USD per million tokens (Claude Sonnet list prices, as BankGuidanceService). */
    public const PRICE_IN    = 3.0;
    public const PRICE_OUT   = 15.0;
    public const CAP_KEY     = 'charlie_ask_daily_cap';
    public const DEFAULT_CAP = 30;
    public const QUESTION_MAX = 300;
    public const MAX_TERMS   = 4;
    public const ROWS        = 6;

    public const SYSTEM_PROMPT = <<<'TXT'
You are Charlie, Chief of Staff for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC. The owner asks you quick questions about his customers from his phone.

Answer ONLY from the CRM records you are given. They are everything the CRM holds that matched his question; if the answer isn't in them, say plainly that you can't see it in the CRM and what you did find. Never guess or invent names, dates, amounts or statuses.

How to read the records:
- quote status: draft = not sent yet; sent = sent (sent_at is when); viewed = the customer opened it; accepted = won; declined / expired = lost.
- invoice: balance_due > 0 means still owing; paid_at is when it was paid; overdue = past its due date and unpaid.
- visits: scheduled = coming up; completed = done.
- Several people can share a first name — if more than one matches, say which one you mean or list them briefly.
- facts: counts and totals already worked out from Otto's truck log and cost facts, Penny's receipts and the schedule, for the period named. Trust them as given; don't recount.

Reply in Charlie's voice: plain, short, friendly, first person, to the owner. At most 4 short sentences, plain text, no markdown, no bullet points. Use real dates (e.g. "Oct 3") and amounts ($1,240). You can't change anything in the CRM — you only look things up.
TXT;

    /** Words that are never a name or a street. */
    public const STOP = [
        'the', 'and', 'for', 'has', 'have', 'had', 'been', 'was', 'were', 'are', 'is', 'did', 'does', 'do', 'what', 'when', 'where',
        'which', 'who', 'whom', 'why', 'how', 'much', 'many', 'any', 'all', 'our', 'their', 'his', 'her', 'hers', 'they', 'them', 'she',
        'him', 'you', 'your', 'can', 'could', 'would', 'should', 'will', 'still', 'yet', 'already', 'last', 'next', 'this', 'that',
        'with', 'from', 'about', 'there', 'here', 'out', 'not', 'get', 'got', 'give', 'tell', 'show', 'find', 'check', 'please',
        'quote', 'quotes', 'invoice', 'invoices', 'bill', 'bills', 'job', 'jobs', 'visit', 'visits', 'client', 'clients', 'customer',
        'customers', 'sent', 'send', 'paid', 'pay', 'owe', 'owes', 'owing', 'due', 'overdue', 'accepted', 'signed', 'booked', 'scheduled',
        'today', 'tomorrow', 'yesterday', 'week', 'month', 'year', 'mow', 'mowing', 'lawn', 'cut', 'service', 'balance', 'status',
        'money', 'amount', 'total', 'new', 'old', 'open', 'outstanding', 'charlie', 'mowology', 'street', 'avenue', 'road', 'drive',
        'place', 'house', 'property', 'properties', 'address', 'contact', 'contacts', 'company', 'companies', 'strata', 'building',
    ];

    private PDO $db;
    /** @var callable|null fn(array $requestBody): array{code: int, body: string} — injected in tests */
    private $transport;
    private ?string $today;
    private CharlieFactAnswerer $facts;

    public function __construct(PDO $db, ?callable $transport = null, ?string $today = null, ?CharlieFactAnswerer $facts = null)
    {
        $this->db = $db;
        $this->transport = $transport;
        $this->today = $today;
        $this->facts = $facts ?? new CharlieFactAnswerer($db, $today);
    }

    private function today(): string { return $this->today ?? date('Y-m-d'); }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM charlie_asks LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasKey(): bool
    {
        return $this->transport !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    // ── Cap ──────────────────────────────────────────────────────────────────

    public function dailyCap(): int
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::CAP_KEY]);
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return max(0, (int)$v);
        } catch (Throwable $e) { /* no ops_settings → default */ }
        return self::DEFAULT_CAP;
    }

    /** Claude calls made today (answers with nothing matched are free and don't count). */
    public function callsToday(): int
    {
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM charlie_asks WHERE source = 'claude' AND created_at >= ?");
            $s->execute([$this->today() . ' 00:00:00']);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return PHP_INT_MAX;   // can't count → don't spend
        }
    }

    /** For the card: {ready, cap, used, left}. */
    public function status(): array
    {
        if (!$this->ready()) return ['ready' => false, 'cap' => 0, 'used' => 0, 'left' => 0];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        return ['ready' => $this->hasKey(), 'cap' => $cap, 'used' => min($used, $cap), 'left' => self::capLeft($cap, $used)];
    }

    // ── Ask ──────────────────────────────────────────────────────────────────

    /** @return array{ok: bool, answer?: string, message?: string, source?: string, left: int, capped?: bool} */
    public function ask(string $question, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Ask Charlie needs migration 1215.', 'left' => 0];
        $question = self::cleanQuestion($question);
        if (mb_strlen($question) < 3) return ['ok' => false, 'message' => 'Type a question first.', 'left' => $this->status()['left']];

        // Counts and totals from the other heads: plain SQL, no model, not counted.
        $fact = null;
        try {
            $fact = $this->facts->answer($question);
        } catch (Throwable $e) {
            error_log('Charlie facts: ' . $e->getMessage());
        }
        if ($fact) {
            $this->record($question, [$fact['about']], ['facts' => $fact['head']], 'facts', $fact['answer'], null, 0, 0, null, $userId);
            return ['ok' => true, 'answer' => $fact['answer'], 'source' => 'facts', 'left' => $this->status()['left']];
        }

        $terms = self::terms($question);
        $ctx = $this->context($terms);
        $summary = $this->facts->summary($question);
        $from = array_sum(self::matched($ctx)) > 0 ? ['the CRM'] : [];
        if ($summary) {
            $ctx['facts'] = $summary['lines'];
            foreach ($summary['heads'] as $h) $from[] = CharlieFactAnswerer::FROM[$h];
        }
        $matched = self::matched($ctx);

        // Nothing in the CRM matched: say so for free.
        if (array_sum($matched) === 0) {
            $answer = $terms
                ? "I can't find anyone or anything matching " . self::quoteList($terms) . " in the CRM. Try a last name, a street, or a quote or invoice number."
                : "I need a name, a street, or a quote or invoice number to look up. Who is this about?";
            $this->record($question, $terms, $matched, 'none', $answer, null, 0, 0, null, $userId);
            return ['ok' => true, 'answer' => $answer, 'source' => 'none', 'left' => $this->status()['left']];
        }

        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up, so I can\'t answer questions yet.', 'left' => 0];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        if (!self::canCall($cap, $used)) {
            return ['ok' => false, 'capped' => true, 'left' => 0,
                    'message' => "That's today's {$cap} questions used up. Ask me again tomorrow, or look it up in the CRM."];
        }

        $res = $this->send(self::buildRequest(self::buildPrompt($question, $ctx, $this->today())));
        $error = null;
        $answer = null;
        $usage = [];
        if ($res['code'] !== 200) {
            $error = 'HTTP ' . $res['code'];
        } else {
            $resp = json_decode($res['body'], true) ?: [];
            $usage = $resp['usage'] ?? [];
            $answer = self::textOf($resp);
            if (($resp['stop_reason'] ?? '') === 'refusal') $error = 'Declined';
            elseif ($answer === '') $error = 'Empty answer';
        }
        $in = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
        $out = (int)($usage['output_tokens'] ?? 0);
        $this->record($question, $terms, $matched, 'claude', $error ? null : $answer, self::MODEL, $in, $out, $error, $userId);
        if ($error) {
            error_log('Charlie ask: ' . $error);
            return ['ok' => false, 'message' => 'I couldn\'t answer that just now (' . $error . '). Try again in a minute.',
                    'left' => self::capLeft($cap, $used + 1)];
        }
        if ($from) $answer .= "\n— from " . implode(' and ', array_values(array_unique($from)));
        return ['ok' => true, 'answer' => $answer, 'source' => 'claude', 'left' => self::capLeft($cap, $used + 1)];
    }

    private function record(string $q, array $terms, array $matched, string $source, ?string $answer, ?string $model, int $in, int $out, ?string $error, int $userId): void
    {
        try {
            $this->db->prepare("INSERT INTO charlie_asks (question, terms, matched, source, answer, model, input_tokens, output_tokens, cost_usd, error, requested_by, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$q, mb_substr(implode(', ', $terms), 0, 255), mb_substr(json_encode($matched), 0, 255), $source, $answer, $model,
                          $in, $out, self::cost($in, $out), $error ? substr($error, 0, 255) : null, $userId ?: null, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('Charlie ask log: ' . $e->getMessage());
        }
    }

    private function send(array $body): array
    {
        if ($this->transport) {
            return ($this->transport)($body);
        }
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            error_log('Charlie ask curl: ' . curl_error($ch));
        }
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    // ── Context (read-only, prepared statements only) ───────────────────────

    /**
     * What the CRM holds for the question's terms.
     * @return array{contacts: array, companies: array, properties: array, quotes: array, invoices: array, visits: array}
     */
    public function context(array $terms): array
    {
        $ctx = ['contacts' => [], 'companies' => [], 'properties' => [], 'quotes' => [], 'invoices' => [], 'visits' => []];
        $names = array_values(array_filter($terms, fn($t) => !self::isDocNumber($t)));
        $docs = array_values(array_filter($terms, fn($t) => self::isDocNumber($t)));
        $lim = self::ROWS;

        foreach ($names as $t) {
            $ctx['contacts'] = array_merge($ctx['contacts'], $this->rows(
                "SELECT id, first_name, last_name, email, phone FROM contacts WHERE first_name LIKE ? OR last_name LIKE ? ORDER BY id DESC LIMIT {$lim}",
                [$t . '%', $t . '%']));
            $ctx['companies'] = array_merge($ctx['companies'], $this->rows(
                "SELECT id, company_name FROM companies WHERE company_name LIKE ? ORDER BY id DESC LIMIT 4", ['%' . $t . '%']));
            // A bare number is a house number (start of the address); a word can be anywhere in it.
            $like = preg_match('/^\d+$/', $t) ? $t . ' %' : '%' . $t . '%';
            $ctx['properties'] = array_merge($ctx['properties'], $this->rows(
                "SELECT id, address, city FROM properties WHERE address LIKE ? ORDER BY id DESC LIMIT 4", [$like]));
        }
        foreach (['contacts', 'companies', 'properties'] as $k) $ctx[$k] = self::uniqueById($ctx[$k], $k === 'contacts' ? 8 : 6);

        $amt = "COALESCE(NULLIF(q.total_amount, 0), q.amount, 0)";
        $qCols = "q.id, q.quote_number, q.title, q.status, {$amt} AS amount, q.created_at, q.sent_at, q.viewed_at, q.accepted_at, q.valid_until, q.contact_id, q.company_id, q.property_id";
        $iCols = "i.id, i.invoice_number, i.status, i.total, i.balance_due, i.issue_date, i.due_date, i.paid_at, i.contact_id, i.company_id, i.property_id";

        foreach ($docs as $d) {
            $ctx['quotes'] = array_merge($ctx['quotes'], $this->rows("SELECT {$qCols} FROM quotes q WHERE q.quote_number = ?", [$d]));
            $ctx['invoices'] = array_merge($ctx['invoices'], $this->rows("SELECT {$iCols} FROM invoices i WHERE i.invoice_number = ?", [$d]));
        }

        $cIds = array_map(fn($r) => (int)$r['id'], $ctx['contacts']);
        $coIds = array_map(fn($r) => (int)$r['id'], $ctx['companies']);
        $pIds = array_map(fn($r) => (int)$r['id'], $ctx['properties']);
        [$where, $args] = self::ownerFilter($cIds, $coIds, $pIds);
        if ($where !== '') {
            $ctx['quotes'] = array_merge($ctx['quotes'], $this->rows(
                "SELECT {$qCols} FROM quotes q WHERE " . str_replace('#', 'q', $where) . " ORDER BY q.id DESC LIMIT 8", $args));
            $ctx['invoices'] = array_merge($ctx['invoices'], $this->rows(
                "SELECT {$iCols} FROM invoices i WHERE " . str_replace('#', 'i', $where) . " ORDER BY i.id DESC LIMIT 8", $args));
        }
        $ctx['quotes'] = self::uniqueById($ctx['quotes'], 8);
        $ctx['invoices'] = self::uniqueById($ctx['invoices'], 8);

        // Visits at the matched properties and the ones on those quotes/invoices.
        foreach (array_merge($ctx['quotes'], $ctx['invoices']) as $r) if (!empty($r['property_id'])) $pIds[] = (int)$r['property_id'];
        $pIds = array_slice(array_values(array_unique($pIds)), 0, 8);
        if ($pIds) {
            $in = implode(',', array_fill(0, count($pIds), '?'));
            $vCols = "v.id, v.scheduled_date, v.status, p.service_type, p.property_id";
            $next = $this->rows("SELECT {$vCols} FROM job_visits v JOIN job_plans p ON p.id = v.plan_id
                                 WHERE p.property_id IN ({$in}) AND v.scheduled_date >= ? AND v.status = 'scheduled'
                                 ORDER BY v.scheduled_date LIMIT 4", array_merge($pIds, [$this->today()]));
            $last = $this->rows("SELECT {$vCols} FROM job_visits v JOIN job_plans p ON p.id = v.plan_id
                                 WHERE p.property_id IN ({$in}) AND v.scheduled_date <= ? AND v.status = 'completed'
                                 ORDER BY v.scheduled_date DESC LIMIT 4", array_merge($pIds, [$this->today()]));
            $ctx['visits'] = array_merge($last, $next);
            // The visits' properties, so their addresses can be named.
            $have = array_map(fn($r) => (int)$r['id'], $ctx['properties']);
            $missing = array_values(array_diff($pIds, $have));
            if ($missing) {
                $in2 = implode(',', array_fill(0, count($missing), '?'));
                $ctx['properties'] = array_merge($ctx['properties'], $this->rows("SELECT id, address, city FROM properties WHERE id IN ({$in2})", $missing));
            }
        }
        return $ctx;
    }

    /** One guarded read: a column missing on this schema drops that piece, never the answer. */
    private function rows(string $sql, array $args): array
    {
        try {
            $s = $this->db->prepare($sql);
            $s->execute($args);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Charlie ask context: ' . $e->getMessage());
            return [];
        }
    }

    // ── Pure (unit tested) ───────────────────────────────────────────────────

    public static function cleanQuestion(string $q): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $q)), 0, self::QUESTION_MAX);
    }

    /**
     * Names, streets and document numbers in the question, at most MAX_TERMS.
     * "Has Linda's quote been sent?" → ['Linda']; "is INV-2026-0042 paid" → ['INV-2026-0042'].
     * Capitalised words win when there are any; otherwise every word that isn't a stop word.
     * @return string[]
     */
    public static function terms(string $q): array
    {
        $docs = [];
        if (preg_match_all('/\b(?:QUO|INV|JOB)-\d{4}-\d{3,6}\b/i', $q, $m)) {
            foreach ($m[0] as $d) $docs[] = strtoupper($d);
            $q = preg_replace('/\b(?:QUO|INV|JOB)-\d{4}-\d{3,6}\b/i', ' ', $q);
        }
        preg_match_all("/[\\p{L}\\d][\\p{L}\\d'’\\-]*/u", $q, $m);
        $caps = [];
        $all = [];
        foreach ($m[0] as $w) {
            $w = preg_replace("/['’]s$/u", '', $w);
            $w = trim($w, "'’-");
            $lower = mb_strtolower($w);
            if (mb_strlen($w) < 3 && !preg_match('/^\d+$/', $w)) continue;
            if (in_array($lower, self::STOP, true)) continue;
            if (preg_match('/^\d+$/', $w) && mb_strlen($w) < 2) continue;
            $all[$lower] = $w;
            if (preg_match('/^\p{Lu}/u', $w) || preg_match('/\d/', $w)) $caps[$lower] = $w;
        }
        $words = array_values($caps ?: $all);
        return array_slice(array_merge(array_values(array_unique($docs)), $words), 0, self::MAX_TERMS);
    }

    public static function isDocNumber(string $t): bool
    {
        return (bool)preg_match('/^(?:QUO|INV|JOB)-\d{4}-\d{3,6}$/', $t);
    }

    /**
     * WHERE clause matching rows owned by any of the ids ('#' stands for the table alias).
     * @return array{0: string, 1: int[]}
     */
    public static function ownerFilter(array $contactIds, array $companyIds, array $propertyIds): array
    {
        $parts = [];
        $args = [];
        foreach (['contact_id' => $contactIds, 'company_id' => $companyIds, 'property_id' => $propertyIds] as $col => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids) continue;
            $parts[] = "#.{$col} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge($args, $ids);
        }
        return [$parts ? '(' . implode(' OR ', $parts) . ')' : '', $args];
    }

    public static function uniqueById(array $rows, int $max): array
    {
        $out = [];
        foreach ($rows as $r) {
            $id = (int)($r['id'] ?? 0);
            if ($id && !isset($out[$id])) $out[$id] = $r;
        }
        return array_slice(array_values($out), 0, $max);
    }

    /** @return array<string, int> how many of each kind matched */
    public static function matched(array $ctx): array
    {
        $out = [];
        foreach (['contacts', 'companies', 'properties', 'quotes', 'invoices', 'visits'] as $k) $out[$k] = count((array)($ctx[$k] ?? []));
        if (!empty($ctx['facts'])) $out['facts'] = count((array)$ctx['facts']);
        return $out;
    }

    public static function buildPrompt(string $question, array $ctx, string $today): string
    {
        $records = [];
        foreach ($ctx as $k => $rows) {
            if (!$rows) continue;
            $records[$k] = $k === 'facts' ? array_values($rows) : array_map(fn($r) => array_filter($r, fn($v) => $v !== null && $v !== ''), $rows);
        }
        return "Today is {$today}.\n\nThe owner asks: \"{$question}\"\n\nCRM records that matched (contact_id / company_id / property_id link them):\n"
            . json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function buildRequest(string $prompt): array
    {
        return [
            'model'      => self::MODEL,
            'max_tokens' => 600,
            'system'     => [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
            'messages'   => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]]],
        ];
    }

    /** The answer's text blocks, joined and trimmed. */
    public static function textOf(array $resp): string
    {
        $t = '';
        foreach ((array)($resp['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') $t .= (string)($b['text'] ?? '');
        }
        return trim($t);
    }

    public static function quoteList(array $terms): string
    {
        return implode(' or ', array_map(fn($t) => '"' . $t . '"', $terms));
    }

    public static function capLeft(int $cap, int $used): int
    {
        return max(0, $cap - max(0, $used));
    }

    public static function canCall(int $cap, int $used): bool
    {
        return self::capLeft($cap, $used) > 0;
    }

    public static function cost(int $in, int $out): float
    {
        return round($in / 1e6 * self::PRICE_IN + $out / 1e6 * self::PRICE_OUT, 5);
    }
}
