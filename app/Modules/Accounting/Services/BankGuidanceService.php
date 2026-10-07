<?php
/**
 * BankGuidanceService — Penny explains a bank line she can't place, on demand.
 *
 * The bank-line card (penny-bank.js) shows "Ask Penny for guidance" on a line with no
 * confident suggestion. Only that click calls Claude; nothing is filed — the owner still
 * presses Approve. Cost control:
 *   - cache: a payee already explained (same BankImportService::descriptionKey, so
 *     "Point of sale TLNK/0534703381" and "…/0598812234" are one payee) is reused for
 *     free and labelled "from earlier" — unless the owner typed a note (new facts → ask);
 *   - a daily cap (ops_settings penny_guidance_daily_cap, default 30 calls);
 *   - every call's tokens and cost are kept in bank_guidance (migration 1211).
 * The answer is strict JSON, checked here: an account code that isn't in the chart is
 * rejected, never shown. Approving after asking keeps the guidance on the decision
 * (bank_line_reviews.guidance_json) and learns the payee's friendly name
 * (bank_payee_names); rule learning happens in BankDeskService::decide as for any line.
 *
 * Claude is called over HTTP the way ReceiptBookkeeperService does (no SDK on the
 * FTP-deployed host). No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/BankImportService.php';

class BankGuidanceService
{
    public const API_URL    = 'https://api.anthropic.com/v1/messages';
    public const MODEL      = 'claude-sonnet-5-5';
    /** USD per million tokens (Claude Sonnet list prices). */
    public const PRICE_IN   = 3.0;
    public const PRICE_OUT  = 15.0;
    public const CAP_KEY    = 'penny_guidance_daily_cap';
    public const DEFAULT_CAP = 30;
    public const NOTE_MAX   = 300;
    /** Never suggested (Other Services, Miscellaneous) — same as BankDeskService::DEFAULT_CODES. */
    public const DEFAULT_CODES = ['4900', '6900'];

    /** Tim's bookkeeping rules, given to Claude word for word. */
    public const RULES = [
        'Diesel is for the Dodge Ram truck: 6100 Fuel & Vehicle.',
        'Gas (petrol) under $50 is for the landscaping equipment (mowers, trimmers, blowers), not the truck.',
        'TD ON-LINE LOANS is the RAM 3500HD car loan: the principal goes to 2610 Loan Payable — RAM, the interest part to 6810 Interest Expense.',
        'Meals go to 6850 Meals & Entertainment; only 50% counts for GST (ITC) and income tax.',
        'Never use the default accounts 4900 Other Services or 6900 Miscellaneous — if you can\'t tell, say so and ask.',
    ];

    public const SYSTEM_PROMPT = <<<'TXT'
You are Penny, the bookkeeper for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC (owner Tim). Bank statements are imported; you help Tim file a line the import couldn't place.

Work out who the payee is from the raw bank description (statement abbreviations: TLNK = TransLink, PTS = point of sale, etc.), what the money was for, and which account in the given chart it belongs on. Use Tim's rules, his past decisions for the same payee, the other lines and receipts from the same week, and his note — his note is the strongest evidence.

GST: say whether there's GST to claim back (an input tax credit). Many things carry no GST: transit fares, insurance, bank fees, loan principal and interest, payroll, government fees.

Answer in Penny's voice — plain, short, friendly, first person, to Tim:
- merchant: the payee's real name ("TransLink").
- what_it_is: one sentence on who they are ("TLNK is TransLink (bus/SkyTrain).").
- reason: one or two sentences on why it goes where you'd file it, using the amount and context ("$3.50 is one adult fare, probably getting to a job without the truck.").
- account_code: a code from the chart, exactly as given. Never invent one.
- confidence: 0 to 1. Below 0.6 when you're guessing; then ask in question_if_unsure.
- split: only when one line must be split across accounts (a loan payment: principal + interest); amounts add up to the line. Otherwise an empty list.
TXT;

    private PDO $db;
    /** @var callable|null fn(array $requestBody): array{code: int, body: string} — injected in tests */
    private $transport;
    /** @var string|null Y-m-d, injected in tests */
    private ?string $today;

    public function __construct(PDO $db, ?callable $transport = null, ?string $today = null)
    {
        $this->db = $db;
        $this->transport = $transport;
        $this->today = $today;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM bank_guidance LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A key to call with (an injected transport stands in for the API in tests). */
    private function hasKey(): bool
    {
        return $this->transport !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cap
    // ─────────────────────────────────────────────────────────────────────────

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

    /** Claude calls made today (reuse "from earlier" is free and doesn't count). */
    public function callsToday(): int
    {
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM bank_guidance WHERE source = 'claude' AND created_at >= ?");
            $s->execute([($this->today ?? date('Y-m-d')) . ' 00:00:00']);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return PHP_INT_MAX;   // can't count → don't spend
        }
    }

    /** For the card: {cap, used, left, ready}. */
    public function status(): array
    {
        if (!$this->ready()) return ['ready' => false, 'cap' => 0, 'used' => 0, 'left' => 0];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        return ['ready' => $this->hasKey(), 'cap' => $cap, 'used' => min($used, $cap), 'left' => self::capLeft($cap, $used)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ask
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guidance for one bank line. Reuses an earlier explanation of the same payee for free
     * (when no note is given); otherwise calls Claude if today's cap allows.
     * @return array{ok: bool, message?: string, guidance?: array, account_id?: int, say?: string, from_earlier?: bool, guidance_id?: int, left: int, capped?: bool}
     */
    public function guide(int $transactionId, string $note, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1211', 'left' => 0];
        $note = self::cleanNote($note);
        $s = $this->db->prepare("SELECT id, transaction_date, type, amount, description FROM accounting_transactions WHERE id = ? AND reference_type = 'bank_import'");
        $s->execute([$transactionId]);
        $tx = $s->fetch(PDO::FETCH_ASSOC);
        if (!$tx) return ['ok' => false, 'message' => 'Bank line not found', 'left' => $this->status()['left']];

        $key = self::payeeKey((string)$tx['description']);
        $chart = $this->chart();

        // 1. Free: this payee was explained before.
        if ($note === '' && $key !== '') {
            $c = $this->db->prepare("SELECT guidance_json FROM bank_guidance
                                     WHERE payee_key = ? AND guidance_json IS NOT NULL AND error IS NULL
                                     ORDER BY id DESC LIMIT 1");
            $c->execute([$key]);
            $cached = json_decode((string)$c->fetchColumn(), true);
            if (is_array($cached)) {
                $v = self::validate($cached, $chart, (float)$tx['amount']);
                if ($v['ok']) {
                    $id = $this->record($transactionId, $key, 'cache', null, $v['guidance'], null, 0, 0, null, $userId);
                    return $this->answer($v['guidance'], $chart, true, $id);
                }
            }
        }

        // 2. A Claude call: key, cap.
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up, so I can\'t look this one up.', 'left' => 0];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        if (!self::canCall($cap, $used)) {
            return ['ok' => false, 'capped' => true, 'left' => 0,
                    'message' => "That's today's {$cap} guidance asks used up. Pick the account yourself, or ask me tomorrow."];
        }

        $prompt = self::buildPrompt($tx, $chart, self::RULES, $this->similarDecisions($key, $transactionId), $this->sameWeek($tx), $note,
                                    $this->knownName($key));
        $res = $this->send(self::buildRequest($prompt));

        $error = null;
        $parsed = null;
        $usage = [];
        if ($res['code'] !== 200) {
            $error = 'HTTP ' . $res['code'];
        } else {
            $resp = json_decode($res['body'], true) ?: [];
            $usage = $resp['usage'] ?? [];
            if (($resp['stop_reason'] ?? '') === 'refusal') {
                $error = 'Declined: ' . ($resp['stop_details']['category'] ?? 'unspecified');
            } elseif (($resp['stop_reason'] ?? '') === 'max_tokens') {
                $error = 'Answer cut off (max_tokens)';
            } else {
                foreach ($resp['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text') $parsed = json_decode((string)$block['text'], true);
                }
                if (!is_array($parsed)) $error = 'Unparseable answer';
            }
        }
        $in = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
        $out = (int)($usage['output_tokens'] ?? 0);

        $v = null;
        if (!$error) {
            $v = self::validate($parsed, $chart, (float)$tx['amount']);
            if (!$v['ok']) $error = 'Invalid answer: ' . implode('; ', $v['errors']);
        }
        $id = $this->record($transactionId, $key, 'claude', $note, $error ? null : $v['guidance'], self::MODEL, $in, $out, $error, $userId);
        if ($error) {
            error_log('BankGuidance tx ' . $transactionId . ': ' . $error);
            return ['ok' => false, 'message' => 'I couldn\'t work this one out just now (' . $error . '). Pick the account yourself, or try again.',
                    'left' => self::capLeft($cap, $used + 1)];
        }
        return $this->answer($v['guidance'], $chart, false, $id);
    }

    private function answer(array $g, array $chart, bool $fromEarlier, int $id): array
    {
        $acct = $chart[$g['account_code']];
        return ['ok' => true, 'guidance' => $g, 'account_id' => (int)$acct['id'], 'say' => self::say($g, $chart),
                'from_earlier' => $fromEarlier, 'guidance_id' => $id, 'left' => $this->status()['left']];
    }

    private function record(int $txId, string $key, string $source, ?string $note, ?array $g, ?string $model, int $in, int $out, ?string $error, int $userId): int
    {
        $this->db->prepare("INSERT INTO bank_guidance (transaction_id, payee_key, source, note, guidance_json, model, input_tokens, output_tokens, cost_usd, error, requested_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$txId, $key, $source, $note !== '' ? $note : null, $g ? json_encode($g, JSON_UNESCAPED_UNICODE) : null, $model,
                      $source === 'claude' ? $in : 0, $source === 'claude' ? $out : 0, self::cost($in, $out),
                      $error ? substr($error, 0, 255) : null, $userId ?: null, date('Y-m-d H:i:s')]);
        return (int)$this->db->lastInsertId();
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
            CURLOPT_TIMEOUT        => 90,
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
            error_log('BankGuidance curl: ' . curl_error($ch));
        }
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context
    // ─────────────────────────────────────────────────────────────────────────

    /** code => {id, code, name, type}, active accounts only. */
    public function chart(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, code, name, type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[(string)$a['code']] = $a;
        }
        return $out;
    }

    /** The last 3 decisions on lines from the same payee. */
    private function similarDecisions(string $key, int $exceptTx): array
    {
        if ($key === '') return [];
        $out = [];
        try {
            $rows = $this->db->query("SELECT t.id, t.transaction_date, t.amount, t.description, c.code, c.name
                                      FROM bank_line_reviews r
                                      JOIN accounting_transactions t ON t.id = r.transaction_id
                                      JOIN chart_of_accounts c ON c.id = r.final_account_id
                                      ORDER BY r.decided_at DESC, r.id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                if ((int)$r['id'] === $exceptTx || self::payeeKey((string)$r['description']) !== $key) continue;
                $out[] = ['date' => $r['transaction_date'], 'amount' => (float)$r['amount'], 'description' => $r['description'],
                          'filed_to' => $r['code'] . ' ' . $r['name']];
                if (count($out) >= 3) break;
            }
        } catch (Throwable $e) { /* no reviews yet */ }
        return $out;
    }

    /** Other bank lines and receipts within 3 days either side (vehicle repairs, etc.). */
    private function sameWeek(array $tx): array
    {
        $from = date('Y-m-d', strtotime($tx['transaction_date'] . ' -3 days'));
        $to   = date('Y-m-d', strtotime($tx['transaction_date'] . ' +3 days'));
        $out = ['bank_lines' => [], 'receipts' => []];
        try {
            $s = $this->db->prepare("SELECT t.transaction_date, t.type, t.amount, t.description, c.code, c.name
                                     FROM accounting_transactions t LEFT JOIN chart_of_accounts c ON c.id = t.account_id
                                     WHERE t.reference_type = 'bank_import' AND t.transaction_date BETWEEN ? AND ? AND t.id <> ?
                                     ORDER BY t.transaction_date, t.id LIMIT 25");
            $s->execute([$from, $to, (int)$tx['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['bank_lines'][] = ['date' => $r['transaction_date'], 'amount' => ($r['type'] === 'income' ? '+' : '-') . number_format((float)$r['amount'], 2),
                                        'description' => $r['description'], 'filed_to' => $r['code'] ? $r['code'] . ' ' . $r['name'] : null];
            }
        } catch (Throwable $e) { /* one source of two */ }
        try {
            $s = $this->db->prepare("SELECT e.expense_date, e.total, e.accounting_category, e.vendor_name_raw, e.description
                                     FROM expenses e WHERE e.expense_date BETWEEN ? AND ? AND e.status <> 'rejected'
                                     ORDER BY e.expense_date LIMIT 15");
            $s->execute([$from, $to]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out['receipts'][] = ['date' => $r['expense_date'], 'total' => (float)$r['total'], 'vendor' => $r['vendor_name_raw'],
                                      'category' => $r['accounting_category'], 'description' => $r['description']];
            }
        } catch (Throwable $e) { /* one source of two */ }
        return $out;
    }

    private function knownName(string $key): ?string
    {
        if ($key === '') return null;
        try {
            $s = $this->db->prepare("SELECT display_name FROM bank_payee_names WHERE payee_key = ?");
            $s->execute([$key]);
            $n = $s->fetchColumn();
            return $n !== false ? (string)$n : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Add the learned friendly name (payee_name) to the card's lines. */
    public function decorate(array $lines): array
    {
        if (!$lines || !$this->ready()) return $lines;
        try {
            $names = $this->db->query("SELECT payee_key, display_name FROM bank_payee_names")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $names = [];
        }
        foreach ($lines as &$l) {
            $l['payee_name'] = $names[self::payeeKey((string)($l['description'] ?? ''))] ?? null;
        }
        unset($l);
        return $lines;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Learning (after the owner approves)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The owner approved a line after asking: keep the guidance on the decision and
     * learn the payee's name. Rule learning already ran in BankDeskService::decide.
     */
    public function recordDecision(int $transactionId, int $guidanceId, int $userId): bool
    {
        if (!$this->ready() || $guidanceId <= 0) return false;
        $s = $this->db->prepare("SELECT payee_key, guidance_json FROM bank_guidance WHERE id = ? AND transaction_id = ? AND guidance_json IS NOT NULL");
        $s->execute([$guidanceId, $transactionId]);
        $g = $s->fetch(PDO::FETCH_ASSOC);
        if (!$g) return false;
        try {
            $this->db->prepare("UPDATE bank_line_reviews SET guidance_json = ? WHERE transaction_id = ?")
               ->execute([$g['guidance_json'], $transactionId]);
        } catch (Throwable $e) {
            error_log('BankGuidance: guidance_json not saved (migration 1211?): ' . $e->getMessage());
        }
        $merchant = self::cleanName((string)(json_decode((string)$g['guidance_json'], true)['merchant'] ?? ''));
        if ($merchant !== '' && $g['payee_key'] !== '') {
            $this->db->prepare("DELETE FROM bank_payee_names WHERE payee_key = ?")->execute([$g['payee_key']]);
            $this->db->prepare("INSERT INTO bank_payee_names (payee_key, display_name, taught_by) VALUES (?, ?, ?)")
               ->execute([$g['payee_key'], $merchant, $userId ?: null]);
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** The cache key: "Point of sale TLNK/0534703381" → "point sale tlnk" (reference numbers dropped). */
    public static function payeeKey(string $description): string
    {
        return BankImportService::descriptionKey($description);
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

    public static function cleanNote(string $note): string
    {
        $note = trim(preg_replace('/\s+/', ' ', $note));
        return mb_substr($note, 0, self::NOTE_MAX);
    }

    public static function cleanName(string $name): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $name)), 0, 120);
    }

    /**
     * The user turn: the line, the chart, the rules, past decisions, the week around it, the note.
     * @param array $tx {transaction_date, type, amount, description}
     */
    public static function buildPrompt(array $tx, array $chart, array $rules, array $similar, array $week, string $note, ?string $knownName = null): string
    {
        $line = [
            'description' => (string)$tx['description'],
            'amount'      => round((float)$tx['amount'], 2),
            'date'        => (string)$tx['transaction_date'],
            'direction'   => ($tx['type'] ?? '') === 'income' ? 'money in (credit)' : 'money out (debit)',
        ];
        if ($knownName) $line['payee_known_as'] = $knownName;
        $accounts = array_map(fn($a) => $a['code'] . ' | ' . $a['name'] . ' | ' . $a['type'], array_values($chart));
        $parts = [
            "Bank line to explain:\n" . json_encode($line, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            "Tim's note: " . ($note !== '' ? '"' . $note . '"' : '(none)'),
            "Tim's bookkeeping rules:\n- " . implode("\n- ", $rules),
            "Chart of accounts (code | name | type) — account_code must be one of these:\n" . implode("\n", $accounts),
            "Tim's last decisions for this payee:\n" . ($similar ? json_encode($similar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '(none yet)'),
            "Same week (3 days either side):\n" . json_encode($week, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
        return implode("\n\n", $parts);
    }

    /** JSON schema for the answer (structured output). */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'merchant'     => ['type' => 'string'],
                'what_it_is'   => ['type' => 'string'],
                'account_code' => ['type' => 'string'],
                'confidence'   => ['type' => 'number'],
                'reason'       => ['type' => 'string'],
                'gst'          => [
                    'type' => 'object',
                    'properties' => ['chargeable' => ['type' => 'boolean'], 'note' => ['type' => 'string']],
                    'required' => ['chargeable', 'note'],
                    'additionalProperties' => false,
                ],
                'split' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['account_code' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'why' => ['type' => 'string']],
                        'required' => ['account_code', 'amount', 'why'],
                        'additionalProperties' => false,
                    ],
                ],
                'question_if_unsure' => ['type' => 'string'],
            ],
            'required' => ['merchant', 'what_it_is', 'account_code', 'confidence', 'reason', 'gst', 'split', 'question_if_unsure'],
            'additionalProperties' => false,
        ];
    }

    public static function buildRequest(string $prompt): array
    {
        return [
            'model'         => self::MODEL,
            'max_tokens'    => 2000,
            'system'        => [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'messages'      => [['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]]],
        ];
    }

    /**
     * Check Claude's answer against the chart. An account code not in the chart (or a
     * default account) is rejected; a split must use real codes and add up to the line.
     * @param array $chart code => {id, code, name, type}
     * @return array{ok: bool, guidance: array, errors: string[]}
     */
    public static function validate($g, array $chart, float $amount): array
    {
        $errors = [];
        if (!is_array($g)) return ['ok' => false, 'guidance' => [], 'errors' => ['not an object']];
        $code = trim((string)($g['account_code'] ?? ''));
        if ($code === '' || !isset($chart[$code])) {
            $errors[] = 'account_code ' . ($code === '' ? '(empty)' : $code) . ' is not in the chart';
        } elseif (in_array($code, self::DEFAULT_CODES, true)) {
            $errors[] = 'account_code ' . $code . ' is a default account';
        }
        $str = fn($k, $max = 400) => mb_substr(trim((string)($g[$k] ?? '')), 0, $max);
        $out = [
            'merchant'     => self::cleanName((string)($g['merchant'] ?? '')),
            'what_it_is'   => $str('what_it_is'),
            'account_code' => $code,
            'confidence'   => max(0.0, min(1.0, is_numeric($g['confidence'] ?? null) ? (float)$g['confidence'] : 0.0)),
            'reason'       => $str('reason', 600),
            'gst'          => ['chargeable' => (bool)($g['gst']['chargeable'] ?? false), 'note' => mb_substr(trim((string)($g['gst']['note'] ?? '')), 0, 300)],
            'split'        => [],
            'question_if_unsure' => $str('question_if_unsure', 300),
        ];
        if ($out['what_it_is'] === '' && $out['reason'] === '') $errors[] = 'no explanation';
        $split = is_array($g['split'] ?? null) ? $g['split'] : [];
        if ($split) {
            $sum = 0.0;
            foreach ($split as $p) {
                $pc = trim((string)($p['account_code'] ?? ''));
                if (!isset($chart[$pc])) { $errors[] = 'split account_code ' . ($pc === '' ? '(empty)' : $pc) . ' is not in the chart'; continue; }
                $amt = round((float)($p['amount'] ?? 0), 2);
                $sum += $amt;
                $out['split'][] = ['account_code' => $pc, 'amount' => $amt, 'why' => mb_substr(trim((string)($p['why'] ?? '')), 0, 200)];
            }
            if (!$errors && abs($sum - abs($amount)) > 0.02) {
                $errors[] = 'split adds up to ' . number_format($sum, 2) . ', not ' . number_format(abs($amount), 2);
            }
        }
        return ['ok' => !$errors, 'guidance' => $out, 'errors' => $errors];
    }

    /** Penny's sentence for the card. */
    public static function say(array $g, array $chart): string
    {
        $a = $chart[$g['account_code']] ?? null;
        $t = [];
        if ($g['what_it_is'] !== '') $t[] = self::sentence($g['what_it_is']);
        if ($g['reason'] !== '') $t[] = self::sentence($g['reason']);
        if ($a) $t[] = 'I\'d file it to ' . $a['code'] . ' ' . $a['name'] . '.';
        if ($g['split']) {
            $t[] = 'Split it: ' . implode(', ', array_map(fn($p) => '$' . number_format($p['amount'], 2) . ' to ' . $p['account_code'] .
                   (isset($chart[$p['account_code']]) ? ' ' . $chart[$p['account_code']]['name'] : '') . ($p['why'] !== '' ? ' (' . $p['why'] . ')' : ''), $g['split'])) . '.';
        }
        $gstNote = rtrim($g['gst']['note'], '. ');
        $t[] = ($g['gst']['chargeable'] ? 'There\'s GST to claim' : 'No GST to claim') . ($gstNote !== '' ? ': ' . self::lower($gstNote) : '') . '.';
        if ($g['confidence'] < 0.6 && $g['question_if_unsure'] !== '') $t[] = 'I\'m not sure, though — ' . self::lower(self::sentence($g['question_if_unsure'], '?'));
        return implode(' ', $t);
    }

    /** Lower-case the first letter mid-sentence, but not an acronym ("GST", "TD"). */
    private static function lower(string $s): string
    {
        return preg_match('/^[A-Z][a-z]/', $s) ? lcfirst($s) : $s;
    }

    private static function sentence(string $s, string $end = '.'): string
    {
        $s = trim($s);
        return preg_match('/[.!?]$/', $s) ? $s : $s . $end;
    }
}
