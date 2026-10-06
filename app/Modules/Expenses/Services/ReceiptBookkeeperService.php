<?php
/**
 * ReceiptBookkeeperService — the AI bookkeeper for receipts.
 *
 * For one receipt it gathers what a bookkeeper would look at — the photo, the text,
 * what the reader captured, this vendor's recent approved receipts, the owner's rules
 * (ReceiptBookkeeperRules), the category list and the jobs scheduled around the
 * purchase — asks Claude for category, asset tag (truck/equipment), job, GST/PST
 * split and line items with a reason and confidence for each, then runs hard checks
 * (sums, tax rates, category list, owner's firm rules) over the answer.
 *
 * Suggestions are stored in expense_suggestions (migration 1125). The owner decides
 * on the review screen; that decision is the scorecard and the worked examples for
 * the next receipt. Nothing here changes an expense.
 *
 * Backtest mode hides an approved receipt's final values from the context and scores
 * the suggestion against them — accuracy is measured before any of this is shown.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 * Claude is called over HTTP like ReceiptLlmExtractor (no SDK on the FTP-deployed host).
 */

require_once __DIR__ . '/ReceiptBookkeeperRules.php';
require_once dirname(__DIR__) . '/ExpenseConstants.php';

class ReceiptBookkeeperService
{
    public const API_URL       = 'https://api.anthropic.com/v1/messages';
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    public const EFFORT        = 'medium';
    public const HISTORY_LIMIT = 8;
    public const MAX_IMAGE_BYTES = 3_500_000;   // base64 grows ~33%; API cap is 5 MB

    private PDO $db;
    /** @var callable|null fn(array $requestBody): array{code: int, body: string} — injected in tests */
    private $transport;

    public function __construct(PDO $db, ?callable $transport = null)
    {
        $this->db = $db;
        $this->transport = $transport;
    }

    public function ready(): bool
    {
        if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
            return false;
        }
        try {
            return $this->db->query("SHOW TABLES LIKE 'expense_suggestions'")->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function model(): string
    {
        return defined('RECEIPT_BOOKKEEPER_MODEL') && RECEIPT_BOOKKEEPER_MODEL !== '' ? RECEIPT_BOOKKEEPER_MODEL : self::DEFAULT_MODEL;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Suggest
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Make and store one suggestion. $source 'backtest' hides the final values.
     * @return array the stored expense_suggestions row (decoded), or ['error' => ...]
     */
    public function suggest(int $expenseId, string $source = 'live', bool $withImage = true): array
    {
        if (!$this->ready()) {
            return ['error' => 'Bookkeeper not ready (needs ANTHROPIC_API_KEY and migration 1125)'];
        }
        $ctx = $this->buildContext($expenseId, $source === 'backtest');
        if (isset($ctx['error'])) {
            return $ctx;
        }

        $image = $withImage ? $this->loadImage($ctx['media_path'] ?? null) : null;
        $body  = self::buildRequest($ctx['prompt'], $image, self::model(), self::EFFORT);
        $res   = $this->send($body);

        $parsed = null;
        $error  = null;
        if ($res['code'] !== 200) {
            $error = 'HTTP ' . $res['code'];
        } else {
            $resp = json_decode($res['body'], true) ?: [];
            if (($resp['stop_reason'] ?? '') === 'refusal') {
                $error = 'Declined: ' . ($resp['stop_details']['category'] ?? 'unspecified');
            } elseif (($resp['stop_reason'] ?? '') === 'max_tokens') {
                $error = 'Answer cut off (max_tokens)';
            } else {
                foreach ($resp['content'] ?? [] as $block) {
                    if (($block['type'] ?? '') === 'text') {
                        $parsed = json_decode($block['text'], true);
                    }
                }
                if (!is_array($parsed)) $error = 'Unparseable answer';
            }
            $usage = $resp['usage'] ?? [];
        }

        $checked = is_array($parsed) ? self::check($parsed, $ctx) : ['suggestion' => [], 'checks' => []];
        $pv = $this->hasPromptVersion();
        $this->db->prepare("
            INSERT INTO expense_suggestions
                (expense_id, source, model, " . ($pv ? 'prompt_version, ' : '') . "used_image, current_json, suggestion_json, checks_json,
                 status, input_tokens, output_tokens, error)
            VALUES (?, ?, ?, " . ($pv ? '?, ' : '') . "?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute(array_merge([$expenseId, $source, self::model()], $pv ? [self::promptVersion()] : [], [
            $image ? 1 : 0,
            json_encode($ctx['current']), json_encode($checked['suggestion']), json_encode($checked['checks']),
            $error ? 'error' : ($source === 'backtest' ? 'scored' : 'pending'),
            isset($usage) ? (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) : null,
            isset($usage) ? (int)($usage['output_tokens'] ?? 0) : null,
            $error ? substr($error, 0, 255) : null,
        ]));

        return [
            'id'         => (int)$this->db->lastInsertId(),
            'expense_id' => $expenseId,
            'suggestion' => $checked['suggestion'],
            'checks'     => $checked['checks'],
            'error'      => $error,
        ];
    }

    private function hasPromptVersion(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = $this->db->query("SHOW COLUMNS FROM expense_suggestions LIKE 'prompt_version'")->rowCount() > 0;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
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
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: 2023-06-01',
                // Server-side refusal fallback, routed by refusal category.
                'anthropic-beta: server-side-fallback-2026-07-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            error_log('ReceiptBookkeeper curl: ' . curl_error($ch));
        }
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Request
    // ─────────────────────────────────────────────────────────────────────────

    public const SYSTEM_PROMPT = <<<'TXT'
You are the bookkeeper for Mowology, a 3-person landscaping and property-maintenance business in Vancouver, BC. Crew photograph receipts on their phones; you prepare each one for the owner to approve.

For the receipt you're given, decide:
- vendor: the business that issued it, as printed on the receipt (correct the reader when it named the wrong business).
- accounting_category: one of the listed categories.
- asset_tag: "truck" or "equipment" when the cost belongs to the Dodge Ram truck or to the landscaping equipment (mowers, trimmers, blowers); "stock" when it's shop stock — bought to keep on hand, not for one job (then job is null); "none" otherwise. Fuel always gets a tag. If the vendor's history shows the owner booking similar items as stock, follow that.
- job: the job (plan_id from the candidates) the purchase was for, or null when it wasn't for one specific job (shop supplies, fuel, office) or you can't tell. Materials are almost never carried for two days: candidates whose source is 'where the truck/crew went' are where the truck or crew actually stopped after the purchase that day — prefer the first of those over the planned schedule.
- subtotal, gst, pst, total as printed on the receipt. In BC, GST is 5% and PST is 7%; some items carry only GST (e.g. food, some services), some neither. Read the printed amounts — never compute, round or adjust an amount yourself. If a figure isn't printed, use 0 for a tax, or the nearest printed figure for the total, and say in your reason what was missing; if the reader's value differs from the print, give the printed value. Code checks every amount against the receipt text.
- line_items: each purchased item as printed, with its amount. Omit non-items (subtotals, tax lines, payment lines, store messages).

The owner's rules are given as rule_hits. A "firm" rule is how the owner books it — follow it. A "soft" rule is a strong hint you may overrule when the receipt clearly says otherwise; say why.

The vendor's recent approved receipts show how the owner books this vendor — follow that pattern unless this receipt is clearly different.

For every decision give a short reason the owner can check at a glance ("Diesel — owner's rule: truck", "Same as the last 6 Home Depot receipts", "Bought 08:05, next stop 08:45 at 2492 W 8th"), and a confidence: high when the receipt or a firm rule settles it, medium when it's the usual pattern, low when you're guessing. Say low rather than guess confidently. Put anything the owner should look at in notes.
TXT;

    /** JSON schema for the answer (structured output). */
    public static function schema(): array
    {
        $conf = ['type' => 'string', 'enum' => ['high', 'medium', 'low']];
        $field = fn(array $valueSchema) => [
            'type' => 'object',
            'properties' => ['value' => $valueSchema, 'reason' => ['type' => 'string'], 'confidence' => $conf],
            'required' => ['value', 'reason', 'confidence'],
            'additionalProperties' => false,
        ];
        $amount = $field(['type' => 'number']);
        return [
            'type' => 'object',
            'properties' => [
                'vendor'              => $field(['type' => 'string']),
                'accounting_category' => $field(['type' => 'string', 'enum' => array_values(EXPENSE_ACCOUNTING_CATEGORIES)]),
                'asset_tag'           => $field(['type' => 'string', 'enum' => ['truck', 'equipment', 'stock', 'none']]),
                'job'                 => $field(['anyOf' => [['type' => 'integer'], ['type' => 'null']]]),
                'subtotal'            => $amount,
                'gst'                 => $amount,
                'pst'                 => $amount,
                'total'               => $amount,
                'line_items'          => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name'   => ['type' => 'string'],
                            'amount' => ['type' => 'number'],
                        ],
                        'required' => ['name', 'amount'],
                        'additionalProperties' => false,
                    ],
                ],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['vendor', 'accounting_category', 'asset_tag', 'job', 'subtotal', 'gst', 'pst', 'total', 'line_items', 'notes'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array      $prompt Context for the user turn
     * @param array|null $image  ['media_type' => ..., 'data' => base64]
     */
    public static function buildRequest(array $prompt, ?array $image, string $model, string $effort): array
    {
        $content = [];
        if ($image) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['media_type'], 'data' => $image['data']]];
        }
        $content[] = ['type' => 'text', 'text' => "Receipt to prepare:\n" . json_encode($prompt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];

        return [
            'model'         => $model,
            'max_tokens'    => 16000,
            'fallbacks'     => 'default',
            'system'        => [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'messages'      => [['role' => 'user', 'content' => $content]],
        ];
    }

    private function loadImage(?string $path): ?array
    {
        if (!$path || !is_file($path) || filesize($path) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $type = mime_content_type($path) ?: '';
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return null;   // HEIC etc. — text only
        }
        return ['media_type' => $type, 'data' => base64_encode((string)file_get_contents($path))];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Context
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Everything the bookkeeper sees for one receipt. With $hideFinal (backtest) the
     * owner's approved category, tag, job and tax split are left out, and vendor
     * history is limited to receipts approved before this one.
     */
    public function buildContext(int $expenseId, bool $hideFinal): array
    {
        require_once APP_ROOT . '/Services/Receipts/ReceiptLearning.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptParser.php';
        require_once APP_ROOT . '/Services/Receipts/ReceiptSmartMatch.php';

        $stmt = $this->db->prepare("
            SELECT e.*, v.name AS vendor_name, m.file_path AS media_file_path
            FROM expenses e
            LEFT JOIN vendors v ON v.id = e.vendor_id
            LEFT JOIN media_assets m ON m.id = e.receipt_media_id
            WHERE e.id = ?
        ");
        $stmt->execute([$expenseId]);
        $e = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            return ['error' => 'Expense not found'];
        }

        $ocrText = ocrTextFromStored($e['raw_ocr_json'] ?? null);
        $vendor  = $e['vendor_name'] ?: ($e['vendor_name_raw'] ?: null);
        $vendorId = $e['vendor_id'] !== null ? (int)$e['vendor_id'] : null;

        // What the reader captured: the stored baseline, else a fresh parse of the text.
        $captured = captureBaseline($e['ocr_parsed_json'] ?? null);
        if (!$captured && $ocrText !== '') {
            $captured = parseReceiptText($ocrText, null, getVendorLineItemProfile($vendorId));
        }
        $captured = $captured ?: [];

        $itemStmt = $this->db->prepare("SELECT name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id");
        $itemStmt->execute([$expenseId]);
        $keptItems = $itemStmt->fetchAll(PDO::FETCH_COLUMN);
        $itemNames = $hideFinal
            ? array_map(fn($i) => (string)($i['name'] ?? ''), $captured['line_items'] ?? [])
            : $keptItems;

        $date = $e['expense_date'] ?: ($captured['date'] ?? null);
        $time = $captured['time'] ?? (preg_match('/\R?/', $ocrText) ? extractPurchaseTime(preg_split('/\R/', $ocrText)) : null);

        $current = [
            'vendor'              => $vendor,
            'date'                => $date,
            'time'                => $time,
            'subtotal'            => $hideFinal ? ($captured['subtotal'] ?? null) : $e['amount'],
            'gst'                 => $hideFinal ? ($captured['gst'] ?? null) : $e['gst_amount'],
            'pst'                 => $hideFinal ? ($captured['pst'] ?? null) : $e['pst_amount'],
            'total'               => $hideFinal ? ($captured['total'] ?? null) : $e['total'],
            'accounting_category' => $hideFinal ? ($captured['accounting_category'] ?? null) : $e['accounting_category'],
            'asset_tag'           => $hideFinal ? null : ($e['asset_tag'] ?? null),
            'job_id'              => $hideFinal ? null : ($e['job_id'] ? (int)$e['job_id'] : null),
            'line_items'          => $itemNames,
        ];

        $prompt = [
            'vendor'              => $vendor,
            'date'                => $date,
            'time_printed'        => $time,
            'receipt_text'        => mb_substr($ocrText, 0, 12000),
            'reader_captured'     => array_intersect_key($current, array_flip(['subtotal', 'gst', 'pst', 'total', 'accounting_category', 'line_items'])),
            'rule_hits'           => ReceiptBookkeeperRules::evaluate(['total' => $current['total'] ?? $e['total']], $ocrText, $itemNames),
            'vendor_history'      => $vendorId ? $this->vendorHistory($vendorId, $expenseId, $hideFinal ? $date : null) : [],
            'job_candidates'      => $this->jobCandidates($e, $date, $time),
            'categories'          => array_values(EXPENSE_ACCOUNTING_CATEGORIES),
        ];
        if (!$hideFinal && ($e['notes'] ?? '') !== '') {
            $prompt['crew_notes'] = mb_substr((string)$e['notes'], 0, 500);
        }

        return [
            'expense'    => $e,
            'current'    => $current,
            'prompt'     => $prompt,
            'media_path' => !empty($e['media_file_path']) ? PUBLIC_ROOT . $e['media_file_path'] : null,
        ];
    }

    /** How the owner booked this vendor recently (approved/sent only). */
    private function vendorHistory(int $vendorId, int $exceptId, ?string $before): array
    {
        $tag = $this->hasColumn('expenses', 'asset_tag') ? 'e.asset_tag' : 'NULL AS asset_tag';
        $sql = "
            SELECT e.id, e.expense_date, e.total, e.gst_amount, e.pst_amount, e.accounting_category, {$tag},
                   jp.title AS job_title
            FROM expenses e
            LEFT JOIN job_plans jp ON jp.id = e.job_id
            WHERE e.vendor_id = ? AND e.id <> ? AND e.status IN ('approved', 'forwarded')"
            . ($before ? " AND e.expense_date < ?" : '') . "
            ORDER BY e.expense_date DESC, e.id DESC
            LIMIT " . self::HISTORY_LIMIT;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($before ? [$vendorId, $exceptId, $before] : [$vendorId, $exceptId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = $this->db->prepare("SELECT name FROM expense_line_items WHERE expense_id = ? ORDER BY sort_order, id LIMIT 6");
        foreach ($rows as &$r) {
            $items->execute([(int)$r['id']]);
            $r['items'] = $items->fetchAll(PDO::FETCH_COLUMN);
            unset($r['id']);
        }
        return $rows;
    }

    private function jobCandidates(array $e, ?string $date, ?string $time): array
    {
        $out = [];
        // Where the truck and crew actually went after the purchase, that day — materials
        // are almost never carried for two days, so this beats the planned schedule.
        if ($date) {
            try {
                require_once __DIR__ . '/ReceiptTrailService.php';
                $at = $date . ' ' . ($time ?: '05:00') . ':00';
                foreach ((new ReceiptTrailService($this->db))->candidates($at, (int)($e['created_by'] ?? 0) ?: null) as $t) {
                    $out[$t['plan_id']] = [
                        'plan_id'      => $t['plan_id'],
                        'job'          => self::withoutHouseNumber($t['job']),
                        'service_type' => null,
                        'why'          => [$t['why']],
                        'source'       => 'where the truck/crew went',
                    ];
                }
            } catch (Throwable $ex) {
                error_log('Bookkeeper trail candidates: ' . $ex->getMessage());
            }
        }
        try {
            $lat = is_numeric($e['receipt_lat'] ?? null) && (float)$e['receipt_lat'] != 0.0 ? (float)$e['receipt_lat'] : null;
            $lng = is_numeric($e['receipt_lng'] ?? null) && (float)$e['receipt_lng'] != 0.0 ? (float)$e['receipt_lng'] : null;
            foreach (suggestJobFromSchedule((int)($e['created_by'] ?? 0), $lat, $lng, $date, $time) as $s) {
                $id = (int)$s['plan_id'];
                if (isset($out[$id])) {
                    $out[$id]['why'] = array_merge($out[$id]['why'], $s['match_reasons'] ?? []);
                    $out[$id]['service_type'] = $s['service_type'] ?? null;
                    continue;
                }
                $out[$id] = [
                    'plan_id'      => $id,
                    'job'          => self::withoutHouseNumber(trim(($s['plan_title'] ?? '') . ' — ' . ($s['address'] ?? ''))),
                    'service_type' => $s['service_type'] ?? null,
                    'why'          => $s['match_reasons'] ?? [],
                    'source'       => 'schedule',
                ];
            }
        } catch (Throwable $ex) {
            error_log('Bookkeeper schedule candidates: ' . $ex->getMessage());
        }
        return array_values($out);
    }

    /**
     * Send the AI the least it needs (bookkeeping-agent guardrail: data stays in Canada,
     * only minimum fields to the model): a job label keeps the street but not the house
     * or unit number — "Lawn care — 2492 W 8th Ave" → "Lawn care — W 8th Ave". Pure.
     */
    public static function withoutHouseNumber(string $job): string
    {
        $parts = explode(' — ', $job, 2);
        if (count($parts) < 2) return $job;
        $addr = preg_replace('/^\s*(#?\s*\d+[A-Za-z]?\s*[-–]\s*)?\d+[A-Za-z]?\b\s*,?\s*/', '', $parts[1]);
        $addr = preg_replace('/\b(unit|suite|apt)\.?\s*#?\s*\w+\s*,?\s*/i', '', $addr);
        return $parts[0] . ' — ' . trim($addr);
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $k = "$table.$column";
        if (!isset($cache[$k])) {
            try {
                $cache[$k] = $this->db->query("SHOW COLUMNS FROM `$table` LIKE " . $this->db->quote($column))->rowCount() > 0;
            } catch (Throwable $e) {
                $cache[$k] = false;
            }
        }
        return $cache[$k];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Hard checks (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Apply the checks the model can't be trusted with: arithmetic, BC tax rates,
     * the category list, the owner's firm rules, and the job candidates.
     *
     * @return array{suggestion: array, checks: list<array{check: string, ok: bool, message: string}>}
     */
    public static function check(array $s, array $ctx): array
    {
        $checks = [];
        $add = function (string $check, bool $ok, string $message) use (&$checks) {
            $checks[] = compact('check', 'ok', 'message');
        };
        $num = fn($f) => isset($s[$f]['value']) && is_numeric($s[$f]['value']) ? round((float)$s[$f]['value'], 2) : null;

        // Category must be one of the owner's.
        $cat = $s['accounting_category']['value'] ?? null;
        $add('category_known', in_array($cat, EXPENSE_ACCOUNTING_CATEGORIES, true), $cat ? "Category: {$cat}" : 'No category');

        // Owner's firm rules win over the model.
        foreach ($ctx['prompt']['rule_hits'] ?? [] as $hit) {
            if ($hit['strength'] !== 'firm') continue;
            $field = $hit['field'];
            $have  = $s[$field]['value'] ?? null;
            if ($have !== $hit['value']) {
                $s[$field] = ['value' => $hit['value'], 'reason' => $hit['reason'] . ' (owner\'s rule)', 'confidence' => 'high'];
                $add('owner_rule', true, "Applied owner's rule: {$hit['reason']}");
            }
        }
        if (($s['accounting_category']['value'] ?? null) === 'Fuel' && ($s['asset_tag']['value'] ?? 'none') === 'none') {
            $add('fuel_tagged', false, 'Fuel without a truck/equipment tag');
        }

        // Amounts add up, and taxes look like BC rates.
        [$sub, $gst, $pst, $tot] = [$num('subtotal'), $num('gst'), $num('pst'), $num('total')];
        if ($sub !== null && $gst !== null && $pst !== null && $tot !== null) {
            $add('sum', abs($sub + $gst + $pst - $tot) <= 0.03, sprintf('Subtotal %.2f + GST %.2f + PST %.2f vs total %.2f', $sub, $gst, $pst, $tot));
            if ($gst > 0 && $sub > 0) {
                $add('gst_rate', abs($gst - $sub * 0.05) <= max(0.06, $sub * 0.002), sprintf('GST %.2f vs 5%% of %.2f', $gst, $sub));
            }
            if ($pst > 0 && $sub > 0) {
                $add('pst_rate', $pst <= $sub * 0.07 + 0.06, sprintf('PST %.2f vs at most 7%% of %.2f', $pst, $sub));
            }
        }

        // Line items add up to the subtotal (warning only — receipts list fees, deposits).
        $items = $s['line_items'] ?? [];
        if ($items && $sub !== null) {
            $sum = round(array_sum(array_map(fn($i) => (float)($i['amount'] ?? 0), $items)), 2);
            $add('items_sum', abs($sum - $sub) <= 0.05, sprintf('Items %.2f vs subtotal %.2f', $sum, $sub));
        }

        // The job must be one of the scheduled candidates.
        $job = $s['job']['value'] ?? null;
        if ($job !== null) {
            $ids = array_map(fn($c) => (int)$c['plan_id'], $ctx['prompt']['job_candidates'] ?? []);
            if (!in_array((int)$job, $ids, true)) {
                $add('job_candidate', false, "Job {$job} isn't on the schedule around the purchase — cleared");
                $s['job'] = ['value' => null, 'reason' => 'Suggested job was not on the schedule that day', 'confidence' => 'low'];
            }
        }

        // Amounts must be read, never worked out (guardrail: the AI does no arithmetic that
        // lands in the books). A total / GST / PST / subtotal that doesn't appear on the
        // receipt text is flagged for the owner to check.
        $text = (string)($ctx['prompt']['receipt_text'] ?? '');
        if ($text !== '') {
            foreach (['total' => 'Total', 'subtotal' => 'Subtotal', 'gst' => 'GST', 'pst' => 'PST'] as $f => $label) {
                $v = $num($f);
                if ($v !== null && $v > 0 && !self::printed($v, $text)) {
                    $add('printed', false, sprintf("%s %.2f isn't printed on the receipt — check it", $label, $v));
                }
            }
        }

        return ['suggestion' => $s, 'checks' => $checks];
    }

    /** Is this amount printed on the receipt ("12.40", "12,40", "$12.4" all count)? Pure. */
    public static function printed(float $amount, string $text): bool
    {
        $t = preg_replace('/(\d),(\d{2})(?!\d)/', '$1.$2', $text);           // 12,40 → 12.40
        $full = number_format($amount, 2, '.', '');
        $short = rtrim(rtrim($full, '0'), '.');
        // The number must stand on its own: not part of a longer number, and a short
        // form ("12.4", "12") may not be the start of a longer decimal ("12.40").
        if (preg_match('/(?<![0-9.])' . preg_quote($full, '/') . '(?![0-9])/', $t)) return true;
        return $short !== $full && preg_match('/(?<![0-9.])' . preg_quote($short, '/') . '(?![0-9]|\.[0-9])/', $t) === 1;
    }

    /** Which instructions produced a suggestion — recorded with every one (audit trail). */
    public static function promptVersion(): string
    {
        return substr(sha1(self::SYSTEM_PROMPT . json_encode(self::schema())), 0, 10);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Backtest scoring (pure)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Score a suggestion against the owner's final values for an approved receipt.
     * Fields the owner never set (no tag on pre-1125 receipts, no job) are 'n/a'.
     *
     * @return array<string, bool|string> field => true (match) | false | 'n/a'
     */
    public static function score(array $s, array $final, array $finalItems): array
    {
        $amt = fn($a, $b) => $a !== null && $b !== null && abs((float)$a - (float)$b) <= 0.02;
        $out = [
            'accounting_category' => empty($final['accounting_category']) ? 'n/a'
                : (($s['accounting_category']['value'] ?? null) === $final['accounting_category']),
            'asset_tag' => empty($final['asset_tag']) ? 'n/a'
                : (($s['asset_tag']['value'] ?? null) === $final['asset_tag']),
            'job' => empty($final['job_id']) ? 'n/a'
                : ((int)($s['job']['value'] ?? 0) === (int)$final['job_id']),
            'total'    => $amt($s['total']['value'] ?? null, $final['total'] ?? null),
            'gst'      => $amt($s['gst']['value'] ?? null, $final['gst_amount'] ?? null),
            'pst'      => $amt($s['pst']['value'] ?? null, $final['pst_amount'] ?? null),
            'subtotal' => $amt($s['subtotal']['value'] ?? null, $final['amount'] ?? null),
        ];

        if (!$finalItems) {
            $out['line_items'] = 'n/a';
        } else {
            $norm = fn($n) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$n));
            $suggested = array_map(fn($i) => $norm($i['name'] ?? ''), $s['line_items'] ?? []);
            $hit = 0;
            foreach ($finalItems as $name) {
                $f = $norm($name);
                foreach ($suggested as $k => $g) {
                    if ($f !== '' && $g !== '' && (str_contains($g, $f) || str_contains($f, $g))) {
                        $hit++;
                        unset($suggested[$k]);
                        break;
                    }
                }
            }
            $out['line_items'] = $hit / count($finalItems) >= 0.8;
        }
        return $out;
    }
}
