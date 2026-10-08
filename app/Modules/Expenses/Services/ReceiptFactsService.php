<?php
/**
 * ReceiptFactsService — what is PRINTED on a receipt, kept as facts (receipt_facts, migration 1227).
 *
 * From the OCR text: the printed date, the time (one till time, or a scale ticket's Time In /
 * Time Out pair), the ticket / invoice / transaction / receipt / order / auth number, the card's
 * last 4 and brand, the till / terminal id and the store number.
 *
 * Who uses the facts:
 *   DuplicateReceiptService — same vendor + same ticket number = the same receipt (certain);
 *       same vendor/date/total but a different ticket number or printed time = two receipts
 *       (the pair is dismissed for good, "different ticket/time", so Penny stops asking);
 *       nothing printed to compare = the old total + date rule.
 *   BankImportService candidate matching — card last 4 / printed date / printed time raise the
 *       confidence and say why. Nothing is ever recorded automatically from it.
 *   StopEvidenceService (Otto) — printed times from here first, the OCR text only as fallback.
 *       The time parser itself lives here now; StopEvidenceService::parseTimes() calls it.
 *   Job attribution — forExpense() is the read API (printed time + crew / truck GPS).
 *   Penny's card + the receipts page — line(): "12:22–12:39 · ticket 43176009 · ••1234".
 *
 * Written whenever OCR text is saved (expenses.php create / update / rescan, expense-save.php,
 * process_ocr_queue) and by backfill() (penny_prepare cron, and /crm/api/receipt-facts.php):
 *   (a) receipts with OCR text but no facts row are parsed;
 *   (b) receipts never OCR'd (empty raw_ocr_json) that have a photo are queued for OCR
 *       (expense_ocr_jobs with expense_id) — at most REOCR_DAILY_CAP a day;
 *   (c) a finished job's text is copied onto its receipt. An approved / sent receipt only gets
 *       the text (never its total, date or vendor); a waiting one also gets its EMPTY fields.
 *
 * Pure statics are unit tested. No namespace / no autoloader in production: require_once and `new`.
 */
class ReceiptFactsService
{
    public const SOURCE = 'ocr';
    /** Receipts parsed per backfill run (parsing is cheap — no API). */
    public const PARSE_PER_RUN = 40;
    /** Receipts queued for OCR per run, and per day (each is one OCR call). */
    public const REOCR_PER_RUN = 10;
    public const REOCR_DAILY_CAP = 40;
    /** Printed times this far apart are two different receipts (OCR misreads a digit). */
    public const TIME_APART_MIN = 2;
    public const WAITING = ['draft', 'pending_approval'];
    /** Re-OCR only plain images: the worker re-encodes the file and converts HEIC in place. */
    public const REOCR_MIMES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    private const DOC_LABELS = [
        // kind => label regex (in order of trust: a ticket number beats an auth code)
        'ticket'      => '(?:scale\s*)?ticket\s*(?:#|no\.?|num(?:ber)?)?',
        'invoice'     => 'inv(?:oice)?\s*(?:#|no\.?|num(?:ber)?)?',
        'transaction' => '(?:trans(?:action)?\.?\s*(?:#|no\.?|id|num(?:ber)?)?|trn\s*#?)',
        'receipt'     => '(?:receipt|rcpt)\s*(?:#|no\.?|num(?:ber)?)',
        'order'       => 'order\s*(?:#|no\.?|num(?:ber)?)',
        'auth'        => 'auth(?:orization)?\.?\s*(?:#|no\.?|code|num(?:ber)?)?',
    ];

    private PDO $db;
    private ?bool $ready = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure parsing (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** Raw OCR as stored (plain text, Vision JSON, or {text, parsed}) → text. Same rules as ocrTextFromStored(). */
    public static function ocrText(?string $raw): string
    {
        if ($raw === null) return '';
        $t = ltrim($raw);
        if ($t === '' || ($t[0] !== '{' && $t[0] !== '[')) return $raw;
        $d = json_decode($t, true);
        if (!is_array($d)) return '';
        if (!empty($d['fullTextAnnotation']['text'])) return (string)$d['fullTextAnnotation']['text'];
        if (!empty($d['responses'][0]['fullTextAnnotation']['text'])) return (string)$d['responses'][0]['fullTextAnnotation']['text'];
        if (!empty($d['text']) && is_string($d['text'])) return $d['text'];
        return '';
    }

    /** "09:49", "9:49 AM", "1:15" (no am/pm, before 6 = afternoon) → 'H:i', or null. */
    public static function hm(string $h, string $m, ?string $ampm): ?string
    {
        $h = (int)$h; $m = (int)$m;
        if ($m > 59) return null;
        if ($ampm !== null && $ampm !== '') {
            if ($h < 1 || $h > 12) return null;
            $pm = stripos($ampm, 'p') !== false;
            $h = $h % 12 + ($pm ? 12 : 0);
        } else {
            if ($h > 23) return null;
            if ($h < 6) $h += 12;   // nobody buys mulch at 3 am: an un-suffixed 1:15 is 13:15
        }
        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Printed times and dates on a receipt (moved here from StopEvidenceService, unchanged).
     * @return array{times: list<string>, in: ?string, out: ?string, dates: list<string>}
     *   times 'H:i' (every time printed, in/out included), dates 'Y-m-d' candidates (MM/DD and DD/MM)
     */
    public static function parseTimes(string $text): array
    {
        $tm = '(\d{1,2})[:.](\d{2})(?::\d{2})?\s*([AaPp]\.?\s?[Mm]\.?)?';
        $in = $out = null;
        if (preg_match('/time\s*in\b\s*[:\-]?\s*' . $tm . '/i', $text, $m)) $in = self::hm($m[1], $m[2], $m[3] ?? null);
        if (preg_match('/time\s*out\b\s*[:\-]?\s*' . $tm . '/i', $text, $m)) $out = self::hm($m[1], $m[2], $m[3] ?? null);
        $times = [];
        if (preg_match_all('/(?<![\d:])(\d{1,2}):(\d{2})(?::\d{2})?(?![\d:])\s*([AaPp]\.?\s?[Mm]\.?(?![a-z]))?/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $t = self::hm($m[1], $m[2], $m[3] ?? null);
                if ($t !== null) $times[$t] = true;
            }
        }
        foreach ([$in, $out] as $t) if ($t !== null) $times[$t] = true;

        $t = array_keys($times);
        sort($t);
        return ['times' => $t, 'in' => $in, 'out' => $out, 'dates' => self::dates($text)];
    }

    /** Date candidates 'Y-m-d' in the text (MM/DD and DD/MM both offered when both are valid). */
    public static function dates(string $text): array
    {
        $dates = [];
        $add = function (int $y, int $mo, int $d) use (&$dates) {
            if ($y < 100) $y += 2000;
            if (checkdate($mo, $d, $y)) $dates[sprintf('%04d-%02d-%02d', $y, $mo, $d)] = true;
        };
        if (preg_match_all('/\b(20\d{2})[\-\/.](\d{1,2})[\-\/.](\d{1,2})\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) $add((int)$m[1], (int)$m[2], (int)$m[3]);
        }
        if (preg_match_all('/(?<![\d\/\-.])(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4}|\d{2})(?![\d\/\-])/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $add((int)$m[3], (int)$m[1], (int)$m[2]);   // MM/DD/YY
                $add((int)$m[3], (int)$m[2], (int)$m[1]);   // DD/MM/YY
            }
        }
        $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
        if (preg_match_all('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+(\d{1,2}),?\s+(20\d{2})\b/i', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) $add((int)$m[3], $months[strtolower($m[1])], (int)$m[2]);
        }
        return array_keys($dates);
    }

    /**
     * Everything printed that we keep. $expenseDate picks between MM/DD and DD/MM readings.
     * @return array{printed_date: ?string, time_first: ?string, time_last: ?string, doc_number: ?string,
     *               doc_kind: ?string, card_last4: ?string, card_brand: ?string, terminal: ?string, store_number: ?string}
     */
    public static function parse(string $text, ?string $expenseDate = null): array
    {
        $out = ['printed_date' => null, 'time_first' => null, 'time_last' => null, 'doc_number' => null, 'doc_kind' => null,
                'card_last4' => null, 'card_brand' => null, 'terminal' => null, 'store_number' => null];
        if (trim($text) === '') return $out;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text)), fn($l) => $l !== ''));

        // Date
        $out['printed_date'] = self::pickDate(self::dates($text), $expenseDate);

        // Time: Time In / Time Out, else the time printed beside the date, else the first time printed.
        $p = self::parseTimes($text);
        if ($p['in'] !== null || $p['out'] !== null) {
            $out['time_first'] = $p['in'] ?? $p['out'];
            $out['time_last'] = $p['in'] !== null ? $p['out'] : null;
        } elseif ($p['times']) {
            $out['time_first'] = self::tillTime($lines);
        }

        // Document number
        foreach (self::DOC_LABELS as $kind => $label) {
            foreach ($lines as $l) {
                if (preg_match('/(?<![a-z])' . $label . '[ \t]*[:#\-]?[ \t]*([A-Z0-9][A-Z0-9\-\/]*\d[A-Z0-9\-]*|\d+)(?![A-Za-z0-9])/i', $l, $m)) {
                    $num = strtoupper(rtrim(preg_replace('#/.*$#', '', $m[1]), '-'));
                    if (strlen(preg_replace('/\D/', '', $num)) < 3 || strlen($num) > 40) continue;
                    if (preg_match('/^\d{1,2}[\/\-]\d{1,2}/', $m[1])) continue;   // a date, not a number
                    $out['doc_number'] = $num;
                    $out['doc_kind'] = $kind;
                    break 2;
                }
            }
        }

        // Card last 4 (cashier / employee ids are masked too — skip those lines)
        $cardPatterns = [
            '/(?:\*|X|x|•){2,}[ \t]*(\d{4})(?!\d)/u',
            '/ending[ \t]+(?:in[ \t]+)?[:#]?[ \t]*(\d{4})(?!\d)/i',
            '/card[ \t]*(?:#|no\.?|number)?[ \t]*[:#]?[ \t]*[\*xX\.•]*[ \t]*(\d{4})(?!\d)/iu',
        ];
        foreach ($cardPatterns as $pat) {
            foreach ($lines as $l) {
                if (preg_match('/^(?:cashier|employee|clerk|server|associate|operator|emp)\b/i', $l)) continue;
                if (preg_match($pat, $l, $m)) { $out['card_last4'] = $m[1]; break 2; }
            }
        }

        // Card brand
        $brands = ['visa' => '\bvisa\b', 'mastercard' => '\bmaster\s?card\b|\bmc\b', 'amex' => '\bamex\b|american\s+express',
                   'discover' => '\bdiscover\b', 'debit' => '\binterac\b|\bdebit\b'];
        foreach ($brands as $brand => $re) {
            if (preg_match('/' . $re . '/i', $text)) { $out['card_brand'] = $brand; break; }
        }

        // Till / register / terminal
        foreach ($lines as $l) {
            if (preg_match('/\b(?:terminal|term(?:inal)?[ \t]*id|trm|till|register|reg|lane|pos)\b[ \t]*(?:#|id|no\.?)?[ \t]*[:#]?[ \t]*([A-Z0-9\-]*\d[A-Z0-9\-]*)/i', $l, $m)) {
                $out['terminal'] = strtoupper(substr($m[1], 0, 30));
                break;
            }
        }

        // Store number: "Store #7054" anywhere, else "#7054" in the header lines
        if (preg_match('/\b(?:store|str)\b[ \t]*(?:#|no\.?|num(?:ber)?)?[ \t]*[:#]?[ \t]*(\d{2,6})\b/i', $text, $m)) {
            $out['store_number'] = $m[1];
        } else {
            foreach (array_slice($lines, 0, 4) as $l) {
                if (preg_match('/#[ \t]?(\d{3,6})\b/', $l, $m)) { $out['store_number'] = $m[1]; break; }
            }
        }
        return $out;
    }

    /** One date among the candidates: the only one, else the expense's own date, else the closest to it. */
    public static function pickDate(array $candidates, ?string $expenseDate): ?string
    {
        if (!$candidates) return null;
        if (count($candidates) === 1) return $candidates[0];
        if ($expenseDate === null || $expenseDate === '') return null;
        $expenseDate = substr($expenseDate, 0, 10);
        if (in_array($expenseDate, $candidates, true)) return $expenseDate;
        $best = null; $bestD = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $d = abs(strtotime($c) - strtotime($expenseDate));
            if ($d < $bestD) { $bestD = $d; $best = $c; }
        }
        return $bestD <= 7 * 86400 ? $best : null;
    }

    /** The till time: the time on the line with the date, else the first time printed. */
    private static function tillTime(array $lines): ?string
    {
        $re = '/(?<![\d:])(\d{1,2}):(\d{2})(?::\d{2})?(?![\d:])\s*([AaPp]\.?\s?[Mm]\.?(?![a-z]))?/';
        $first = null;
        foreach ($lines as $l) {
            if (!preg_match($re, $l, $m)) continue;
            $t = self::hm($m[1], $m[2], $m[3] ?? null);
            if ($t === null) continue;
            if ($first === null) $first = $t;
            if (self::dates($l)) return $t;
        }
        return $first;
    }

    /** Minutes since midnight for 'H:i'. */
    private static function minutes(?string $hm): ?int
    {
        if ($hm === null || !preg_match('/^(\d{2}):(\d{2})$/', $hm, $m)) return null;
        return (int)$m[1] * 60 + (int)$m[2];
    }

    private static function normDoc(?string $d): string
    {
        return ltrim(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$d)), '0');
    }

    /** Same length and one character different — an OCR misread, not proof of anything. */
    private static function nearMiss(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) return false;
        $diff = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) if ($a[$i] !== $b[$i]) $diff++;
        return $diff <= 1;
    }

    /**
     * Two receipts the total+date rule paired: are they the same piece of paper?
     *   same  — the same ticket / invoice / transaction number;
     *   different — different numbers (not a one-digit misread), a different printed date, or
     *               printed times TIME_APART_MIN+ minutes apart;
     *   unknown — nothing printed to compare (the old rule decides).
     * @return array{verdict: string, why: string}
     */
    public static function duplicateVerdict(?array $a, ?array $b): array
    {
        if (!$a || !$b) return ['verdict' => 'unknown', 'why' => ''];
        $da = self::normDoc($a['doc_number'] ?? null);
        $db = self::normDoc($b['doc_number'] ?? null);
        if ($da !== '' && $da === $db) {
            return ['verdict' => 'same', 'why' => 'same ' . self::kindWord($a['doc_kind'] ?? null) . ' ' . $a['doc_number']];
        }
        if ($da !== '' && $db !== '' && !self::nearMiss($da, $db)) {
            return ['verdict' => 'different', 'why' => 'different ticket/time: ' . self::kindWord($a['doc_kind'] ?? null) . ' '
                    . $a['doc_number'] . ' vs ' . $b['doc_number']];
        }
        if (!empty($a['printed_date']) && !empty($b['printed_date']) && $a['printed_date'] !== $b['printed_date']) {
            return ['verdict' => 'different', 'why' => 'different ticket/time: printed ' . $a['printed_date'] . ' vs ' . $b['printed_date']];
        }
        foreach (['time_first', 'time_last'] as $k) {
            $ta = self::minutes($a[$k] ?? null);
            $tb = self::minutes($b[$k] ?? null);
            if ($ta !== null && $tb !== null && abs($ta - $tb) >= self::TIME_APART_MIN) {
                return ['verdict' => 'different', 'why' => 'different ticket/time: printed ' . $a[$k] . ' vs ' . $b[$k]];
            }
        }
        return ['verdict' => 'unknown', 'why' => ''];
    }

    private static function kindWord(?string $kind): string
    {
        return $kind === 'transaction' ? 'transaction' : ($kind ?: 'number');
    }

    /** The compact line for cards: "12:22–12:39 · ticket 43176009 · ••1234". '' when nothing is known. */
    public static function line(?array $f): string
    {
        if (!$f) return '';
        $parts = [];
        if (!empty($f['time_first'])) {
            $parts[] = $f['time_first'] . (!empty($f['time_last']) ? '–' . $f['time_last'] : '');
        }
        if (!empty($f['doc_number'])) $parts[] = self::kindWord($f['doc_kind'] ?? null) . ' ' . $f['doc_number'];
        if (!empty($f['card_last4'])) $parts[] = '••' . $f['card_last4'];
        return implode(' · ', $parts);
    }

    /**
     * Extra confidence for a receipt ↔ bank line pair from what is printed. Never a match on its own.
     * @param ?string $accountNumber the bank line's account (chart_of_accounts.account_number), digits
     * @return array{bonus: int, reasons: list<string>}
     */
    public static function bankSignal(?array $f, string $txDate, string $txDescription, ?string $accountNumber, ?string $expenseDate = null): array
    {
        $bonus = 0; $reasons = [];
        if (!$f) return ['bonus' => 0, 'reasons' => []];
        $last4 = $f['card_last4'] ?? null;
        if ($last4) {
            $acct = preg_replace('/\D/', '', (string)$accountNumber);
            if (($acct !== '' && strlen($acct) >= 4 && substr($acct, -4) === $last4)
                || preg_match('/(?<!\d)' . preg_quote($last4, '/') . '(?!\d)/', $txDescription)) {
                $bonus += 15;
                $reasons[] = 'Paid with card ••' . $last4 . ' — this account';
            }
        }
        $txDate = substr($txDate, 0, 10);
        if (!empty($f['printed_date']) && $f['printed_date'] === $txDate && substr((string)$expenseDate, 0, 10) !== $txDate) {
            $bonus += 10;
            $reasons[] = 'Date printed on the receipt matches the bank date';
        }
        if (!empty($f['time_first']) && preg_match('/(?<![\d:])(\d{1,2}):(\d{2})(?![\d:])/', $txDescription, $m)) {
            $bt = (int)$m[1] * 60 + (int)$m[2];
            $rt = self::minutes($f['time_first']);
            if ($rt !== null && abs($bt - $rt) <= 10) {
                $bonus += 10;
                $reasons[] = 'Printed time ' . $f['time_first'] . ' matches the bank line';
            }
        }
        return ['bonus' => $bonus, 'reasons' => $reasons];
    }

    /**
     * What OCR may write onto a receipt that was never read. Approved / sent / rejected: the text
     * only. Waiting: the text, plus total / date / vendor where the receipt's own is EMPTY.
     * @param array $expense expenses row (status, raw_ocr_json, total, expense_date, vendor_name_raw)
     * @param array $job     expense_ocr_jobs row (parsed_raw_text, parsed_total, parsed_date, parsed_vendor)
     * @return array<string, mixed> column => value
     */
    public static function fillFromOcr(array $expense, array $job): array
    {
        $text = trim((string)($job['parsed_raw_text'] ?? ''));
        if ($text === '' || trim((string)($expense['raw_ocr_json'] ?? '')) !== '') return [];
        $out = ['raw_ocr_json' => (string)$job['parsed_raw_text']];
        if (!in_array($expense['status'] ?? '', self::WAITING, true)) return $out;
        if ((float)($expense['total'] ?? 0) == 0.0 && (float)($job['parsed_total'] ?? 0) > 0) {
            $out['total'] = round((float)$job['parsed_total'], 2);
        }
        if (empty($expense['expense_date']) && !empty($job['parsed_date'])) {
            $out['expense_date'] = (string)$job['parsed_date'];
        }
        if (trim((string)($expense['vendor_name_raw'] ?? '')) === '' && trim((string)($job['parsed_vendor'] ?? '')) !== '') {
            $out['vendor_name_raw'] = mb_substr(trim((string)$job['parsed_vendor']), 0, 255);
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Storage
    // ─────────────────────────────────────────────────────────────────────────

    /** Migration 1227 has run. */
    public function ready(): bool
    {
        if ($this->ready !== null) return $this->ready;
        try {
            $this->db->query("SELECT expense_id FROM receipt_facts LIMIT 1");
            $this->ready = true;
        } catch (Throwable $e) {
            $this->ready = false;
        }
        return $this->ready;
    }

    /** Facts for one receipt, or null (none parsed yet / no migration). The job-attribution read API. */
    public function forExpense(int $expenseId): ?array
    {
        return $this->forExpenses([$expenseId])[$expenseId] ?? null;
    }

    /** @return array<int, array> expense id => facts row */
    public function forExpenses(array $expenseIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $expenseIds), fn($i) => $i > 0)));
        if (!$ids || !$this->ready()) return [];
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $s = $this->db->prepare("SELECT * FROM receipt_facts WHERE expense_id IN ({$in})");
                $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $r['expense_id'] = (int)$r['expense_id'];
                    $out[$r['expense_id']] = $r;
                }
            }
        } catch (Throwable $e) {
            error_log('ReceiptFacts forExpenses: ' . $e->getMessage());
        }
        return $out;
    }

    /** Store facts (replaces the row). */
    public function write(int $expenseId, array $f): bool
    {
        if ($expenseId <= 0 || !$this->ready()) return false;
        $this->db->prepare("
            REPLACE INTO receipt_facts
              (expense_id, printed_date, time_first, time_last, doc_number, doc_kind, card_last4, card_brand, terminal, store_number, parsed_at, source)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $expenseId, $f['printed_date'] ?? null, $f['time_first'] ?? null, $f['time_last'] ?? null,
            isset($f['doc_number']) ? substr((string)$f['doc_number'], 0, 40) : null, $f['doc_kind'] ?? null,
            $f['card_last4'] ?? null, $f['card_brand'] ?? null,
            isset($f['terminal']) ? substr((string)$f['terminal'], 0, 30) : null,
            isset($f['store_number']) ? substr((string)$f['store_number'], 0, 12) : null,
            date('Y-m-d H:i:s'), self::SOURCE,
        ]);
        return true;
    }

    /** Parse the receipt's stored OCR text and store its facts. Nothing stored when it has no text. */
    public function refresh(int $expenseId): ?array
    {
        if ($expenseId <= 0 || !$this->ready()) return null;
        $s = $this->db->prepare("SELECT raw_ocr_json, expense_date FROM expenses WHERE id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) return null;
        $text = self::ocrText($e['raw_ocr_json'] ?? null);
        if (trim($text) === '') return null;
        $f = self::parse($text, $e['expense_date'] ?? null);
        $this->write($expenseId, $f);
        return $f;
    }

    /** For page/API hooks: refresh() that never throws (a facts failure must never fail a save). */
    public static function refreshQuietly(PDO $db, int $expenseId): void
    {
        try {
            (new self($db))->refresh($expenseId);
        } catch (Throwable $e) {
            error_log('ReceiptFacts refresh #' . $expenseId . ': ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Backfill
    // ─────────────────────────────────────────────────────────────────────────

    /** Receipts with OCR text and no facts row, newest first. */
    public function unparsedIds(int $limit): array
    {
        $limit = max(1, min(500, $limit));
        return array_map('intval', $this->db->query("
            SELECT e.id FROM expenses e
            LEFT JOIN receipt_facts rf ON rf.expense_id = e.id
            WHERE rf.expense_id IS NULL AND e.raw_ocr_json IS NOT NULL AND e.raw_ocr_json <> '' AND e.status <> 'rejected'
            ORDER BY e.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Receipts never OCR'd that have a photo and were never queued: [{id, media_id, user_id}]. */
    public function unreadCandidates(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $in = implode(',', array_map(fn($m) => $this->db->quote($m), self::REOCR_MIMES));
        $rows = $this->db->query("
            SELECT e.id, e.receipt_media_id AS media_id, e.created_by AS user_id
            FROM expenses e
            JOIN media_assets m ON m.id = e.receipt_media_id
            WHERE (e.raw_ocr_json IS NULL OR e.raw_ocr_json = '')
              AND e.receipt_media_id IS NOT NULL AND e.status <> 'rejected'
              AND LOWER(m.mime_type) IN ({$in})
              AND NOT EXISTS (SELECT 1 FROM expense_ocr_jobs j WHERE j.expense_id = e.id)
            ORDER BY e.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'media_id' => (int)$r['media_id'], 'user_id' => (int)($r['user_id'] ?? 0)], $rows);
    }

    /** Receipts queued for OCR by the backfill today (the daily cap). */
    public function queuedToday(): int
    {
        $s = $this->db->prepare("SELECT COUNT(*) FROM expense_ocr_jobs WHERE expense_id IS NOT NULL AND created_at >= ?");
        $s->execute([date('Y-m-d 00:00:00')]);
        return (int)$s->fetchColumn();
    }

    /** Queue never-read receipts for the OCR worker (process_ocr_queue). @return list<int> expense ids */
    public function requeueUnread(int $limit = self::REOCR_PER_RUN, bool $dryRun = false): array
    {
        $room = min($limit, self::REOCR_DAILY_CAP - $this->queuedToday());
        if ($room <= 0) return [];
        $ids = [];
        $ins = $this->db->prepare("INSERT INTO expense_ocr_jobs (media_id, expense_id, user_id, status, created_at) VALUES (?, ?, ?, 'pending', ?)");
        foreach ($this->unreadCandidates($room) as $c) {
            if (!$dryRun) $ins->execute([$c['media_id'], $c['id'], $c['user_id'], date('Y-m-d H:i:s')]);
            $ids[] = $c['id'];
        }
        return $ids;
    }

    /**
     * Copy finished OCR jobs onto receipts that have no text (fillFromOcr rules), then parse facts.
     * @return list<int> expense ids updated
     */
    public function adoptFinishedJobs(int $limit = 40, ?int $jobId = null): array
    {
        $limit = max(1, min(200, $limit));
        $sql = "
            SELECT j.id AS job_id, j.expense_id, j.parsed_raw_text, j.parsed_total, j.parsed_date, j.parsed_vendor,
                   e.status, e.raw_ocr_json, e.total, e.expense_date, e.vendor_name_raw
            FROM expense_ocr_jobs j
            JOIN expenses e ON e.id = j.expense_id
            WHERE j.status = 'complete' AND j.expense_id IS NOT NULL
              AND j.parsed_raw_text IS NOT NULL AND j.parsed_raw_text <> ''
              AND (e.raw_ocr_json IS NULL OR e.raw_ocr_json = '')" . ($jobId ? ' AND j.id = ?' : '') . "
            ORDER BY j.id
            LIMIT {$limit}";
        $s = $this->db->prepare($sql);
        $s->execute($jobId ? [$jobId] : []);
        $done = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $fill = self::fillFromOcr($r, $r);
            if (!$fill) continue;
            $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($fill)));
            // Guard in SQL too: only while the receipt still has no text.
            $u = $this->db->prepare("UPDATE expenses SET {$sets} WHERE id = ? AND (raw_ocr_json IS NULL OR raw_ocr_json = '')");
            $u->execute(array_merge(array_values($fill), [(int)$r['expense_id']]));
            if ($u->rowCount() > 0) {
                $this->refresh((int)$r['expense_id']);
                $done[] = (int)$r['expense_id'];
            }
        }
        return $done;
    }

    /**
     * One backfill batch: adopt finished OCR, parse receipts without facts, queue unread ones.
     * @return array{ready: bool, adopted: list<int>, parsed: int, queued: list<int>, dry_run: bool}
     */
    public function backfill(int $parseLimit = self::PARSE_PER_RUN, int $requeueLimit = self::REOCR_PER_RUN, bool $dryRun = false): array
    {
        $res = ['ready' => $this->ready(), 'adopted' => [], 'parsed' => 0, 'queued' => [], 'dry_run' => $dryRun];
        if (!$res['ready']) return $res;
        try {
            if (!$dryRun) $res['adopted'] = $this->adoptFinishedJobs($parseLimit);
        } catch (Throwable $e) {
            error_log('ReceiptFacts adopt: ' . $e->getMessage());
        }
        $ids = $this->unparsedIds($parseLimit);
        if ($dryRun) {
            $res['parsed'] = count($ids);
        } else {
            foreach ($ids as $id) {
                try {
                    if ($this->refresh($id) !== null) $res['parsed']++;
                } catch (Throwable $e) {
                    error_log('ReceiptFacts backfill #' . $id . ': ' . $e->getMessage());
                }
            }
        }
        try {
            if ($requeueLimit > 0) $res['queued'] = $this->requeueUnread($requeueLimit, $dryRun);
        } catch (Throwable $e) {
            error_log('ReceiptFacts requeue: ' . $e->getMessage());   // no expense_ocr_jobs table here
        }
        return $res;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bank matching
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Raise the confidence of bank ↔ receipt candidates with printed facts, and say why.
     * $rows are BankImportService candidate rows; $idKey names the expense id ('expense_id'), or
     * pass $expenseId when the rows are bank lines for one receipt.
     */
    public function boostBankCandidates(array $rows, ?int $transactionId = null, ?int $expenseId = null): array
    {
        if (!$rows || !$this->ready()) return $rows;
        try {
            $acct = function (?int $txId): array {
                $s = $this->db->prepare("SELECT at.transaction_date, at.description, coa.account_number
                                         FROM accounting_transactions at
                                         LEFT JOIN chart_of_accounts coa ON coa.id = at.bank_account_id
                                         WHERE at.id = ?");
                $s->execute([(int)$txId]);
                return $s->fetch(PDO::FETCH_ASSOC) ?: [];
            };
            if ($transactionId) {
                $tx = $acct($transactionId);
                if (!$tx) return $rows;
                $facts = $this->forExpenses(array_column($rows, 'expense_id'));
                foreach ($rows as &$r) {
                    $sig = self::bankSignal($facts[(int)$r['expense_id']] ?? null, (string)$tx['transaction_date'],
                                            (string)$tx['description'], $tx['account_number'] ?? null, $r['date'] ?? null);
                    if ($sig['bonus']) {
                        $r['confidence'] = min(100, (int)$r['confidence'] + $sig['bonus']);
                        $r['reasons'] = array_merge($r['reasons'] ?? [], $sig['reasons']);
                    }
                }
                unset($r);
            } elseif ($expenseId) {
                $f = $this->forExpense($expenseId);
                if (!$f) return $rows;
                $s = $this->db->prepare("SELECT expense_date FROM expenses WHERE id = ?");
                $s->execute([$expenseId]);
                $expDate = (string)$s->fetchColumn();
                foreach ($rows as &$r) {
                    $tx = $acct((int)$r['transaction_id']);
                    $sig = self::bankSignal($f, (string)($tx['transaction_date'] ?? $r['date']), (string)($tx['description'] ?? ''),
                                            $tx['account_number'] ?? null, $expDate);
                    if ($sig['bonus']) {
                        $r['confidence'] = min(100, (int)$r['confidence'] + $sig['bonus']);
                        $r['reasons'] = array_merge($r['reasons'] ?? [], $sig['reasons']);
                    }
                }
                unset($r);
            }
            usort($rows, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        } catch (Throwable $e) {
            error_log('ReceiptFacts bank boost: ' . $e->getMessage());
        }
        return $rows;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Duplicates
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Other live receipts of the same vendor carrying the same document number (any date, any total).
     * @param array $e expenses row with id, vendor_id, vendor_name_raw, vendor_name
     */
    public function sameDocNumber(array $e, ?array $facts = null): array
    {
        $facts = $facts ?? $this->forExpense((int)$e['id']);
        $doc = $facts['doc_number'] ?? null;
        if (!$doc || strlen(self::normDoc($doc)) < 4) return [];
        $s = $this->db->prepare("
            SELECT x.id, x.expense_date, x.total, x.status, x.vendor_id, x.vendor_name_raw, x.receipt_media_id, v.name AS vendor_name
            FROM receipt_facts rf
            JOIN expenses x ON x.id = rf.expense_id
            LEFT JOIN vendors v ON v.id = x.vendor_id
            WHERE rf.doc_number = ? AND x.id <> ? AND x.status <> 'rejected'
            LIMIT 20
        ");
        $s->execute([$doc, (int)$e['id']]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!self::sameVendor($e, $r)) continue;
            $r['receipt_path'] = $r['receipt_media_id'] ? '/crm/api/serve-receipt.php?id=' . (int)$r['receipt_media_id'] : null;
            $out[] = $r;
        }
        return $out;
    }

    public static function sameVendor(array $a, array $b): bool
    {
        if (!empty($a['vendor_id']) && !empty($b['vendor_id'])) return (int)$a['vendor_id'] === (int)$b['vendor_id'];
        $sq = fn($r) => preg_replace('/[^a-z0-9]/', '', strtolower((string)(($r['vendor_name'] ?? '') ?: ($r['vendor_name_raw'] ?? ''))));
        $x = $sq($a); $y = $sq($b);
        return $x !== '' && $x === $y;
    }

    /**
     * Sort duplicate candidates with the printed facts. Pure.
     * @param array $mine       waiting receipts checked (rows with id, receipt_media_id)
     * @param array $candidates expense id => candidate rows
     * @param array $facts      expense id => facts row
     * @return array{candidates: array, dismiss: list<array{0:int,1:int,2:string}>, why: array<string,string>}
     *   dismiss: pairs to remember as "not a duplicate" (a, b, reason); why: pair key "min-max" => label
     */
    public static function sortCandidates(array $mine, array $candidates, array $facts): array
    {
        $dismiss = []; $why = [];
        $media = [];
        foreach ($mine as $e) $media[(int)$e['id']] = (int)($e['receipt_media_id'] ?? 0);
        foreach ($candidates as $id => $rows) {
            $kept = [];
            foreach ($rows as $c) {
                $cid = (int)$c['id'];
                $key = min($id, $cid) . '-' . max($id, $cid);
                $samePhoto = !empty($media[$id]) && $media[$id] === (int)($c['receipt_media_id'] ?? 0);
                $v = $samePhoto ? ['verdict' => 'unknown', 'why' => ''] : self::duplicateVerdict($facts[$id] ?? null, $facts[$cid] ?? null);
                if ($v['verdict'] === 'different') {
                    $dismiss[$key] = [min($id, $cid), max($id, $cid), $v['why']];
                    continue;
                }
                if ($v['verdict'] === 'same') $why[$key] = $v['why'];
                $kept[] = $c;
            }
            $candidates[$id] = $kept;
        }
        return ['candidates' => $candidates, 'dismiss' => array_values($dismiss), 'why' => $why];
    }
}
