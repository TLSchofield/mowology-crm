<?php
/**
 * OttoManualService — "Ask Otto to read the manual", on click only.
 *
 * Service intervals stay Tim's numbers (migration 1156: none are seeded). This helps him
 * get them out of the manual: Tim uploads the owner's manual (PDF) or a photo of its
 * maintenance table for one machine; Otto sends it to Claude and gets back the routine
 * tasks with their intervals, each quoting the manual's words and page. Nothing is
 * written to equipment_service_intervals until Tim confirms each line (confirm()), and he
 * can change the numbers first. An interval without a quote is dropped — never invented.
 *
 * Cost control, as Penny's bank guidance (BankGuidanceService): only a click calls Claude;
 * a daily cap (ops_settings otto_manual_daily_cap, default 10); every call's tokens and
 * cost are kept in equipment_manual_reads (migration 1225).
 *
 * Claude is called over HTTP (no SDK on the FTP-deployed host); the transport is injectable
 * so tests never call the API. No namespace / no autoloader: require_once and `new`.
 */
require_once __DIR__ . '/EquipmentService.php';

class OttoManualService
{
    public const API_URL     = 'https://api.anthropic.com/v1/messages';
    public const MODEL       = 'claude-sonnet-5-5';
    public const PRICE_IN    = 3.0;     // USD per million tokens (Sonnet list price)
    public const PRICE_OUT   = 15.0;
    public const CAP_KEY     = 'otto_manual_daily_cap';
    public const DEFAULT_CAP = 10;
    public const MAX_PDF     = 20 * 1024 * 1024;
    public const MAX_IMAGE   = 5 * 1024 * 1024;
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const SYSTEM_PROMPT = <<<TXT
You read equipment manuals for a small landscaping company's operations lead (Otto).
From the manual or maintenance-table photo you are given, list the ROUTINE MAINTENANCE tasks
and how often the manual says to do them. Rules:
- Only what the document says. Do not add tasks, intervals or advice from general knowledge.
  If the document gives no maintenance schedule, return an empty list and say so in note.
- every_hours: hours of use (0 when the manual gives no hours). every_days: calendar days
  (0 when none). Convert only plain calendar words: daily 1, weekly 7, monthly 30,
  every 3 months 90, every 6 months 180, yearly/annually/each season 365. "Before each use"
  or "after each use" is every_days 1.
- quote: the manual's own words for that line (short), and page: the printed page number or
  the PDF page, as shown. Every item must have a quote.
- task: short, e.g. "Sharpen blade", "Change engine oil", "Clean air filter", "Replace spark plug".
TXT;

    private PDO $db;
    /** @var callable|null fn(array $body): array{code: int, body: string} */
    private $transport;
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
            $this->db->query("SELECT 1 FROM equipment_manual_reads LIMIT 1");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function hasKey(): bool
    {
        return $this->transport !== null || (defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '');
    }

    // ── Cap ─────────────────────────────────────────────────────────────────

    public function dailyCap(): int
    {
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
            $s->execute([self::CAP_KEY]);
            $v = $s->fetchColumn();
            if ($v !== false && is_numeric($v)) return max(0, (int)$v);
        } catch (Throwable $e) { /* default */ }
        return self::DEFAULT_CAP;
    }

    public function callsToday(): int
    {
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM equipment_manual_reads WHERE source = 'claude' AND created_at >= ?");
            $s->execute([($this->today ?? date('Y-m-d')) . ' 00:00:00']);
            return (int)$s->fetchColumn();
        } catch (Throwable $e) {
            return PHP_INT_MAX;   // can't count → don't spend
        }
    }

    public function status(): array
    {
        if (!$this->ready()) return ['ready' => false, 'cap' => 0, 'used' => 0, 'left' => 0];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        return ['ready' => $this->hasKey(), 'cap' => $cap, 'used' => min($used, $cap), 'left' => max(0, $cap - $used)];
    }

    // ── Read ────────────────────────────────────────────────────────────────

    /**
     * @param string $bytes  the manual (PDF) or a photo of its maintenance table
     * @return array{ok: bool, message: string, read_id?: int, intervals?: array, left?: int, capped?: bool}
     */
    public function read(int $equipmentId, string $bytes, string $mime, string $fileName, ?int $mediaId, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Needs migration 1225.'];
        $s = $this->db->prepare("SELECT * FROM equipment WHERE id = ?");
        $s->execute([$equipmentId]);
        $eq = $s->fetch(PDO::FETCH_ASSOC);
        if (!$eq) return ['ok' => false, 'message' => 'Machine not found.'];
        $isPdf = $mime === 'application/pdf';
        if (!$isPdf && !in_array($mime, self::IMAGE_TYPES, true)) return ['ok' => false, 'message' => 'Send a PDF manual or a JPEG/PNG photo of the maintenance table.'];
        if (strlen($bytes) > ($isPdf ? self::MAX_PDF : self::MAX_IMAGE)) return ['ok' => false, 'message' => $isPdf ? 'That PDF is over 20 MB.' : 'That photo is over 5 MB.'];
        if (!$this->hasKey()) return ['ok' => false, 'message' => 'No Anthropic key set up, so I can\'t read manuals yet.'];
        $cap = $this->dailyCap();
        $used = $this->callsToday();
        if ($used >= $cap) return ['ok' => false, 'capped' => true, 'left' => 0, 'message' => "That's today's {$cap} manual reads used up. Try again tomorrow."];

        $res = $this->send(self::buildRequest($eq, base64_encode($bytes), $mime));
        $error = null; $parsed = null; $usage = [];
        if ($res['code'] !== 200) {
            $error = 'HTTP ' . $res['code'];
        } else {
            $resp = json_decode($res['body'], true) ?: [];
            $usage = $resp['usage'] ?? [];
            $stop = $resp['stop_reason'] ?? '';
            if ($stop === 'refusal') $error = 'Declined';
            elseif ($stop === 'max_tokens') $error = 'Answer cut off (max_tokens)';
            else {
                foreach ($resp['content'] ?? [] as $b) if (($b['type'] ?? '') === 'text') $parsed = json_decode((string)$b['text'], true);
                if (!is_array($parsed)) $error = 'Unparseable answer';
            }
        }
        $in = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
        $out = (int)($usage['output_tokens'] ?? 0);
        $v = $error ? ['intervals' => [], 'dropped' => 0, 'note' => ''] : self::validate($parsed);
        $now = ($this->today ?? date('Y-m-d')) . ' ' . date('H:i:s');
        $this->db->prepare("INSERT INTO equipment_manual_reads (equipment_id, media_id, file_name, source, proposals_json, note, model, input_tokens, output_tokens, cost_usd, error, requested_by, created_at)
                            VALUES (?, ?, ?, 'claude', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
             ->execute([$equipmentId, $mediaId, mb_substr($fileName, 0, 255), json_encode($v['intervals'], JSON_UNESCAPED_UNICODE),
                        $v['note'] !== '' ? mb_substr($v['note'], 0, 500) : null, self::MODEL, $in, $out, self::cost($in, $out),
                        $error ? mb_substr($error, 0, 255) : null, $userId ?: null, $now]);
        $id = (int)$this->db->lastInsertId();
        $left = max(0, $cap - $used - 1);
        if ($error) {
            error_log('OttoManual equipment ' . $equipmentId . ': ' . $error);
            return ['ok' => false, 'left' => $left, 'message' => 'I couldn\'t read that one (' . $error . '). Try a clearer photo of the maintenance page.'];
        }
        $n = count($v['intervals']);
        return ['ok' => true, 'read_id' => $id, 'intervals' => $v['intervals'], 'left' => $left,
                'message' => $n ? "I found {$n} maintenance interval" . ($n === 1 ? '' : 's') . ' in the manual. Confirm each one you want me to watch.'
                                : 'I didn\'t find a maintenance schedule in that.' . ($v['note'] !== '' ? ' ' . $v['note'] : '')];
    }

    private function send(array $body): array
    {
        if ($this->transport) return ($this->transport)($body);
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) error_log('OttoManual curl: ' . curl_error($ch));
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$raw];
    }

    // ── Confirm, one line at a time ─────────────────────────────────────────

    /** Pending interval proposals per machine: equipment_id => [{read_id, index, task, every_hours, every_days, page, quote}] */
    public function pending(): array
    {
        if (!$this->ready()) return [];
        $out = [];
        $rows = $this->db->query("SELECT id, equipment_id, proposals_json FROM equipment_manual_reads WHERE error IS NULL AND proposals_json IS NOT NULL ORDER BY id DESC LIMIT 50")
                         ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            foreach (json_decode((string)$r['proposals_json'], true) ?: [] as $i => $p) {
                if (($p['status'] ?? 'pending') !== 'pending') continue;
                $out[(int)$r['equipment_id']][] = ['read_id' => (int)$r['id'], 'index' => $i] + $p;
            }
        }
        return $out;
    }

    /** Save one interval (Tim may have changed the numbers) and mark it done. */
    public function confirm(int $readId, int $index, array $in, int $userId): array
    {
        [$row, $items] = $this->loadRead($readId);
        if (!$row || !isset($items[$index])) return ['ok' => false, 'message' => 'That suggestion is gone.'];
        if (($items[$index]['status'] ?? 'pending') !== 'pending') return ['ok' => false, 'message' => 'Already decided.'];
        $task = trim((string)($in['task'] ?? $items[$index]['task']));
        $h = array_key_exists('every_hours', $in) ? $in['every_hours'] : $items[$index]['every_hours'];
        $d = array_key_exists('every_days', $in) ? $in['every_days'] : $items[$index]['every_days'];
        $r = (new EquipmentService($this->db))->saveInterval([
            'equipment_id' => (int)$row['equipment_id'], 'task' => $task,
            'every_hours' => $h !== null && (float)$h > 0 ? (float)$h : '', 'every_days' => $d !== null && (int)$d > 0 ? (int)$d : '',
        ]);
        if (!$r['ok']) return $r;
        $items[$index]['status'] = 'saved';
        $items[$index]['saved_by'] = $userId ?: null;
        $this->saveItems($readId, $items);
        return ['ok' => true, 'message' => 'Saved: ' . $task . '. I\'ll tell you when it\'s due.'];
    }

    public function skip(int $readId, int $index): array
    {
        [$row, $items] = $this->loadRead($readId);
        if (!$row || !isset($items[$index])) return ['ok' => false, 'message' => 'That suggestion is gone.'];
        $items[$index]['status'] = 'skipped';
        $this->saveItems($readId, $items);
        return ['ok' => true, 'message' => 'Skipped.'];
    }

    private function loadRead(int $id): array
    {
        $s = $this->db->prepare("SELECT * FROM equipment_manual_reads WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return [$row ?: null, $row ? (json_decode((string)$row['proposals_json'], true) ?: []) : []];
    }

    private function saveItems(int $id, array $items): void
    {
        $this->db->prepare("UPDATE equipment_manual_reads SET proposals_json = ? WHERE id = ?")->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $id]);
    }

    // ── Pure (unit-tested) ──────────────────────────────────────────────────

    public static function cost(int $in, int $out): float
    {
        return round($in / 1e6 * self::PRICE_IN + $out / 1e6 * self::PRICE_OUT, 5);
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intervals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'task' => ['type' => 'string'], 'every_hours' => ['type' => 'number'], 'every_days' => ['type' => 'integer'],
                            'page' => ['type' => 'string'], 'quote' => ['type' => 'string'],
                        ],
                        'required' => ['task', 'every_hours', 'every_days', 'page', 'quote'],
                        'additionalProperties' => false,
                    ],
                ],
                'note' => ['type' => 'string'],
            ],
            'required' => ['intervals', 'note'],
            'additionalProperties' => false,
        ];
    }

    public static function buildRequest(array $eq, string $base64, string $mime): array
    {
        $doc = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]];
        $what = trim(implode(' ', array_filter([(string)($eq['make'] ?? ''), (string)($eq['model'] ?? ''), '(' . ($eq['equipment_class'] ?? 'machine') . ')'])));
        $prompt = "Machine: " . ($eq['name'] ?? '') . ($what !== '' ? " — {$what}" : '') . ', ' . ($eq['power_source'] ?? '') . " powered.\n"
                . "List the routine maintenance intervals this document gives for it, quoting it and giving the page.";
        return [
            'model'         => self::MODEL,
            'max_tokens'    => 3000,
            'system'        => [['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cache_control' => ['type' => 'ephemeral']]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            'messages'      => [['role' => 'user', 'content' => [$doc, ['type' => 'text', 'text' => $prompt]]]],
        ];
    }

    /**
     * Keep only lines that quote the manual and give hours or days in a sane range.
     * @return array{intervals: array, dropped: int, note: string}
     */
    public static function validate($g): array
    {
        $out = []; $dropped = 0;
        if (!is_array($g)) return ['intervals' => [], 'dropped' => 0, 'note' => ''];
        foreach (($g['intervals'] ?? []) as $it) {
            if (!is_array($it)) { $dropped++; continue; }
            $task = mb_substr(trim((string)($it['task'] ?? '')), 0, 60);
            $h = round((float)($it['every_hours'] ?? 0), 1);
            $d = (int)($it['every_days'] ?? 0);
            $quote = mb_substr(trim((string)($it['quote'] ?? '')), 0, 300);
            if ($task === '' || $quote === '' || ($h <= 0 && $d <= 0) || $h > 5000 || $d > 1100 || $h < 0 || $d < 0) { $dropped++; continue; }
            $out[] = ['task' => $task, 'every_hours' => $h > 0 ? $h : null, 'every_days' => $d > 0 ? $d : null,
                      'page' => mb_substr(trim((string)($it['page'] ?? '')), 0, 20), 'quote' => $quote, 'status' => 'pending'];
        }
        return ['intervals' => $out, 'dropped' => $dropped, 'note' => mb_substr(trim((string)($g['note'] ?? '')), 0, 500)];
    }

    /** "every 25 h or 30 days" */
    public static function every(?float $h, ?int $d): string
    {
        $p = [];
        if ($h) $p[] = rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.') . ' h';
        if ($d) $p[] = $d === 365 ? 'a year' : ($d === 1 ? 'day' : $d . ' days');
        return $p ? 'every ' . implode(' or ', $p) : '';
    }
}
