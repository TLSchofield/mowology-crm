<?php
/**
 * JobberCsv — reads Jobber's CSV exports (2026-10-07). Pure: no DB.
 *
 * Three exports, told apart by their headers (Jobber's column sets vary between accounts and
 * versions, so every column is found by its header, never by position — and Tim can re-point a
 * field on the import page):
 *   invoices      Reports → Invoices ("Invoice #, Client name, Client email, Client phone, Service
 *                 street, Service city, Service province, Service ZIP, Issued date, Due date, Job #s,
 *                 Status, Total ($), Balance ($)"; other accounts add Subtotal / Tax / Paid date /
 *                 Line items)
 *   transactions  Reports → Transaction List: a "Totals" preamble, then "Client name, Date, Type
 *                 (Invoice | Payment | Deposit), Total $ (payments negative), Tip $, Note, Cheque #,
 *                 Invoice # ("11830, 11786"), Method, Transaction ID (ch_…), Transaction #,
 *                 Confirmation #, Job #, Postal code, Paid - Tax $, Refunded on"
 *   card          Jobber Payments → Transaction List: "Client name, Date, Time, Type, Paid with,
 *                 Paid through, Total $, Tip $, Fee $, Note, Card ending #, Card type, Invoice #,
 *                 Quote #, Payout # (po_…)" and a trailing "Report totals:" row
 *
 * Values: "$1,234.56" / "-1,721.66" / "(12.00)" → floats; "-" and "" → null; dates "Mar 01, 2026",
 * "2026-03-01", "03/01/2026" → Y-m-d.
 */
declare(strict_types=1);

class JobberCsv
{
    public const GST_RATE = 0.05;

    /** kind => field => [label, header synonyms (normalised), required?] */
    public const FIELDS = [
        'invoices' => [
            'invoice_number'   => ['Invoice #', ['invoice', 'invoice number', 'invoice no', 'invoice num'], true],
            'client_name'      => ['Client name', ['client name', 'client', 'customer', 'customer name'], true],
            'client_email'     => ['Client email', ['client email', 'email', 'client e mail'], false],
            'client_phone'     => ['Client phone', ['client phone', 'phone', 'client phone number'], false],
            'service_street'   => ['Service street', ['service street', 'property', 'property address', 'service address', 'street', 'address'], false],
            'service_city'     => ['Service city', ['service city', 'city', 'property city'], false],
            'service_province' => ['Service province', ['service province', 'province', 'state', 'service state'], false],
            'service_postal'   => ['Service ZIP', ['service zip', 'service postal code', 'zip', 'postal code', 'service zip code'], false],
            'subject'          => ['Subject', ['subject', 'title', 'invoice subject'], false],
            'created_date'     => ['Created date', ['created date', 'created', 'date created'], false],
            'issued_date'      => ['Issued date', ['issued date', 'issued', 'issue date', 'date issued', 'invoice date'], true],
            'due_date'         => ['Due date', ['due date', 'due'], false],
            'job_numbers'      => ['Job #s', ['job s', 'jobs', 'job', 'job numbers', 'job number'], false],
            'status'           => ['Status', ['status', 'invoice status'], true],
            'subtotal'         => ['Subtotal', ['subtotal', 'sub total', 'pre tax total'], false],
            'tax'              => ['Tax', ['tax', 'gst', 'taxes', 'tax amount', 'gst hst'], false],
            'total'            => ['Total ($)', ['total', 'invoice total', 'total amount'], true],
            'balance'          => ['Balance ($)', ['balance', 'balance due', 'amount owing', 'outstanding'], false],
            'paid_date'        => ['Paid date', ['paid date', 'marked paid', 'date paid', 'paid on'], false],
            'line_items'       => ['Line items', ['line items', 'items', 'line item'], false],
        ],
        'transactions' => [
            'client_name'     => ['Client name', ['client name', 'client'], true],
            'date'            => ['Date', ['date', 'transaction date'], true],
            'type'            => ['Type', ['type', 'transaction type'], true],
            'total'           => ['Total $', ['total', 'amount'], true],
            'tip'             => ['Tip $', ['tip'], false],
            'note'            => ['Note', ['note', 'notes', 'memo'], false],
            'cheque_no'       => ['Cheque #', ['cheque', 'check', 'cheque number', 'check number'], false],
            'invoice_numbers' => ['Invoice #', ['invoice', 'invoices', 'invoice number'], false],
            'method'          => ['Method', ['method', 'payment method'], false],
            'charge_id'       => ['Transaction ID', ['transaction id'], false],
            'transaction_no'  => ['Transaction #', ['transaction'], false],
            'confirmation_no' => ['Confirmation #', ['confirmation', 'confirmation number', 'reference'], false],
            'job_number'      => ['Job #', ['job', 'job number'], false],
            'postal_code'     => ['Postal code', ['postal code', 'zip'], false],
            'ex_tax'          => ['Paid - Tax $', ['paid tax', 'paid minus tax', 'amount excluding tax', 'paid excl tax'], false],
            'refunded_on'     => ['Refunded on', ['refunded on', 'refunded'], false],
        ],
        'card' => [
            'client_name'     => ['Client name', ['client name', 'client'], true],
            'date'            => ['Date', ['date'], true],
            'time'            => ['Time', ['time'], false],
            'type'            => ['Type', ['type'], true],
            'total'           => ['Total $', ['total', 'amount'], true],
            'tip'             => ['Tip $', ['tip'], false],
            'fee'             => ['Fee $', ['fee', 'fees'], true],
            'note'            => ['Note', ['note'], false],
            'invoice_numbers' => ['Invoice #', ['invoice', 'invoices'], false],
            'quote_number'    => ['Quote #', ['quote', 'quote number', 'estimate'], false],
            'payout_id'       => ['Payout #', ['payout', 'payout id', 'payout number'], true],
        ],
    ];

    /** Header normalised for matching: lowercase words only ("Total ($)" → "total", "Job #s" → "job s"). */
    public static function normHeader(string $h): string
    {
        $h = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h)));
        $h = preg_replace('/[^a-z0-9]+/', ' ', $h);
        return trim((string)$h);
    }

    /**
     * Parse CSV text: BOM stripped, a preamble skipped (the first row that carries at least two
     * known headers is the header row), blank and totals rows dropped.
     * @return array{kind:?string, headers:list<string>, rows:list<list<string>>, skipped:int}
     */
    public static function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", (string)$content);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $all = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($row === [null]) continue;
            $all[] = array_map(fn($c) => trim((string)$c), $row);
        }
        fclose($fh);

        $headerAt = null;
        $kind = null;
        foreach ($all as $i => $row) {
            if ($i > 40) break;
            $k = self::detectKind($row);
            if ($k !== null) { $headerAt = $i; $kind = $k; break; }
        }
        if ($headerAt === null) return ['kind' => null, 'headers' => $all[0] ?? [], 'rows' => [], 'skipped' => count($all)];
        $headers = $all[$headerAt];
        $rows = [];
        $skipped = $headerAt;
        for ($i = $headerAt + 1; $i < count($all); $i++) {
            $r = $all[$i];
            if (count(array_filter($r, fn($c) => $c !== '')) === 0 || stripos($r[0] ?? '', 'report totals') === 0) { $skipped++; continue; }
            $rows[] = $r;
        }
        return ['kind' => $kind, 'headers' => $headers, 'rows' => $rows, 'skipped' => $skipped];
    }

    /** Which export a header row belongs to, or null. Pure. */
    public static function detectKind(array $headers): ?string
    {
        $n = array_map([self::class, 'normHeader'], $headers);
        $has = fn(string $h) => in_array($h, $n, true);
        if ($has('payout') || ($has('fee') && $has('paid with'))) return 'card';
        if ($has('type') && ($has('method') || $has('paid tax')) && $has('date')) return 'transactions';
        if ($has('invoice') && ($has('balance') || $has('issued date') || $has('status')) && $has('total')) return 'invoices';
        return null;
    }

    /**
     * field => header index (or null), from the headers; $override (field => header text) wins.
     * Exact synonym first, then "header starts with synonym". Each header is used once.
     */
    public static function guessMapping(string $kind, array $headers, array $override = []): array
    {
        $norm = array_map([self::class, 'normHeader'], $headers);
        $used = [];
        $map = [];
        foreach (self::FIELDS[$kind] as $field => [$label, $syn]) {
            $map[$field] = null;
            if (array_key_exists($field, $override)) {
                $o = $override[$field];
                if ($o === null || $o === '') continue;
                $idx = array_search((string)$o, $headers, true);
                if ($idx === false) $idx = array_search(self::normHeader((string)$o), $norm, true);
                if ($idx !== false) { $map[$field] = (int)$idx; $used[(int)$idx] = true; }
            }
        }
        foreach (self::FIELDS[$kind] as $field => [$label, $syn]) {
            if ($map[$field] !== null || array_key_exists($field, $override)) continue;
            foreach ($syn as $s) {
                foreach ($norm as $i => $h) {
                    if (!isset($used[$i]) && $h === $s) { $map[$field] = $i; $used[$i] = true; break 2; }
                }
            }
        }
        foreach (self::FIELDS[$kind] as $field => [$label, $syn]) {
            if ($map[$field] !== null || array_key_exists($field, $override)) continue;
            foreach ($syn as $s) {
                if (strlen($s) < 4) continue;
                foreach ($norm as $i => $h) {
                    if (!isset($used[$i]) && strpos($h, $s) === 0) { $map[$field] = $i; $used[$i] = true; break 2; }
                }
            }
        }
        return $map;
    }

    /** Required fields with no column. */
    public static function missing(string $kind, array $map): array
    {
        $out = [];
        foreach (self::FIELDS[$kind] as $field => [$label, $syn, $req]) if ($req && $map[$field] === null) $out[] = $label;
        return $out;
    }

    public static function money(?string $v): ?float
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '-' || $v === '—') return null;
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) { $neg = true; $v = $m[1]; }
        if (strpos($v, '-') !== false) $neg = true;
        $v = preg_replace('/[^0-9.]/', '', $v);
        if ($v === '' || !is_numeric($v)) return null;
        return round(($neg ? -1 : 1) * (float)$v, 2);
    }

    public static function date(?string $v): ?string
    {
        $v = trim((string)$v);
        if ($v === '' || $v === '-' || $v === '—') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m)) {
            // Jobber writes month first.
            return checkdate((int)$m[1], (int)$m[2], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
        }
        $ts = strtotime(str_replace(',', '', $v));
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    public static function status(?string $v): string
    {
        $s = strtolower(trim((string)$v));
        if ($s === '') return 'unknown';
        if (strpos($s, 'bad') === 0) return 'bad_debt';
        if (strpos($s, 'past due') !== false || strpos($s, 'overdue') !== false) return 'past_due';
        if (strpos($s, 'draft') !== false) return 'draft';
        if (strpos($s, 'void') !== false || strpos($s, 'cancel') !== false) return 'void';
        if (strpos($s, 'paid') === 0) return 'paid';
        if (strpos($s, 'awaiting') !== false || strpos($s, 'sent') !== false || strpos($s, 'open') !== false || strpos($s, 'due') !== false) return 'open';
        return 'unknown';
    }

    public static function province(?string $v): ?string
    {
        $s = strtolower(preg_replace('/[^a-z]/i', '', (string)$v));
        if ($s === '') return null;
        if (in_array($s, ['bc', 'britishcolumbia', 'britishcolumbiabc'], true)) return 'BC';
        if (in_array($s, ['ab', 'alberta'], true)) return 'AB';
        if (in_array($s, ['on', 'ontario'], true)) return 'ON';
        return mb_substr(trim((string)$v), 0, 50);
    }

    /** "11830, 11786" / "#11830 and #11786" → ['11830', '11786']. */
    public static function invoiceNumbers(?string $v): array
    {
        preg_match_all('/[A-Za-z]*-?\d+/', (string)$v, $m);
        return array_values(array_unique(array_map(fn($x) => ltrim($x, '#'), $m[0])));
    }

    private static function cell(array $row, array $map, string $field): string
    {
        $i = $map[$field] ?? null;
        return $i === null ? '' : trim((string)($row[$i] ?? ''));
    }

    private static function blankToNull(string $v): ?string
    {
        return ($v === '' || $v === '-' || $v === '—') ? null : $v;
    }

    /**
     * One invoice row. GST: the Tax column when there is one, else Total − Subtotal, else
     * Total × 5/105 (tax_source 'computed' — flagged on the page).
     * @return array|null null when it has no invoice number
     */
    public static function invoiceRow(array $row, array $map): ?array
    {
        $num = ltrim(self::cell($row, $map, 'invoice_number'), '#');
        if ($num === '') return null;
        $total = self::money(self::cell($row, $map, 'total')) ?? 0.0;
        $sub = self::money(self::cell($row, $map, 'subtotal'));
        $tax = self::money(self::cell($row, $map, 'tax'));
        if ($tax !== null) { $src = 'csv'; $sub = $sub ?? round($total - $tax, 2); }
        elseif ($sub !== null) { $src = 'csv'; $tax = round($total - $sub, 2); }
        else { $src = 'computed'; [$sub, $tax] = self::splitGst($total); }
        $statusRaw = self::cell($row, $map, 'status');
        $status = self::status($statusRaw);
        $balance = self::money(self::cell($row, $map, 'balance'));
        if ($balance === null) $balance = $status === 'paid' ? 0.0 : $total;
        return [
            'jobber_number' => mb_substr($num, 0, 40),
            'client_name' => self::blankToNull(mb_substr(self::cell($row, $map, 'client_name'), 0, 200)),
            'client_email' => self::blankToNull(mb_substr(strtolower(self::cell($row, $map, 'client_email')), 0, 255)),
            'client_phone' => self::blankToNull(mb_substr(self::cell($row, $map, 'client_phone'), 0, 50)),
            'service_street' => self::blankToNull(mb_substr(self::cell($row, $map, 'service_street'), 0, 255)),
            'service_city' => self::blankToNull(mb_substr(self::cell($row, $map, 'service_city'), 0, 100)),
            'service_province' => self::province(self::blankToNull(self::cell($row, $map, 'service_province'))),
            'service_postal' => self::blankToNull(mb_substr(strtoupper(self::cell($row, $map, 'service_postal')), 0, 20)),
            'subject' => self::blankToNull(mb_substr(self::cell($row, $map, 'subject'), 0, 255)),
            'job_numbers' => self::blankToNull(mb_substr(self::cell($row, $map, 'job_numbers'), 0, 255)),
            'issued_date' => self::date(self::cell($row, $map, 'issued_date')) ?? self::date(self::cell($row, $map, 'created_date')),
            'due_date' => self::date(self::cell($row, $map, 'due_date')),
            'status' => $status,
            'status_raw' => self::blankToNull(mb_substr($statusRaw, 0, 60)),
            'subtotal' => round((float)$sub, 2), 'tax' => round((float)$tax, 2), 'tax_source' => $src,
            'total' => round($total, 2), 'balance' => round($balance, 2),
            'paid_date' => self::date(self::cell($row, $map, 'paid_date')),
            'line_items' => self::blankToNull(self::cell($row, $map, 'line_items')),
        ];
    }

    /** [subtotal, gst] from a GST-inclusive total (5/105). */
    public static function splitGst(float $total): array
    {
        $gst = round($total * self::GST_RATE / (1 + self::GST_RATE), 2);
        return [round($total - $gst, 2), $gst];
    }

    /**
     * One Transaction List row: an invoice (→ GST split for that invoice) or a payment / deposit.
     * @return array{type:string, ...}|null
     */
    public static function transactionRow(array $row, array $map): ?array
    {
        $type = strtolower(self::cell($row, $map, 'type'));
        $date = self::date(self::cell($row, $map, 'date'));
        $total = self::money(self::cell($row, $map, 'total'));
        if ($date === null || $total === null || $type === '') return null;
        $exTax = self::money(self::cell($row, $map, 'ex_tax'));
        $nums = self::invoiceNumbers(self::cell($row, $map, 'invoice_numbers'));
        if ($type === 'invoice') {
            return ['type' => 'invoice', 'date' => $date, 'invoice_number' => $nums[0] ?? null,
                    'total' => abs($total), 'ex_tax' => $exTax === null ? null : abs($exTax),
                    'client_name' => self::blankToNull(self::cell($row, $map, 'client_name'))];
        }
        if ($type !== 'payment' && $type !== 'deposit') return null;
        $note = self::cell($row, $map, 'note');
        $quote = null;
        if (preg_match('/(?:estimate|quote)\s*#\s*(\d+)/i', $note, $m)) $quote = $m[1];
        return [
            'type' => $type === 'deposit' ? 'deposit' : 'payment',
            'kind' => $type === 'deposit' ? 'deposit' : 'payment',
            'client_name' => self::blankToNull(mb_substr(self::cell($row, $map, 'client_name'), 0, 200)),
            'payment_date' => $date,
            'amount' => abs($total),
            'amount_ex_tax' => $exTax === null ? null : abs($exTax),
            'tip' => abs((float)(self::money(self::cell($row, $map, 'tip')) ?? 0)),
            'method' => self::blankToNull(mb_substr(self::cell($row, $map, 'method'), 0, 30)),
            'cheque_no' => self::blankToNull(mb_substr(self::cell($row, $map, 'cheque_no'), 0, 40)),
            'stripe_charge_id' => self::blankToNull(mb_substr(self::cell($row, $map, 'charge_id'), 0, 80)),
            'transaction_no' => self::blankToNull(mb_substr(self::cell($row, $map, 'transaction_no'), 0, 80)),
            'confirmation_no' => self::blankToNull(mb_substr(self::cell($row, $map, 'confirmation_no'), 0, 80)),
            'invoice_numbers' => $nums ? mb_substr(implode(', ', $nums), 0, 255) : null,
            'quote_number' => $quote,
            'job_number' => self::blankToNull(mb_substr(self::cell($row, $map, 'job_number'), 0, 40)),
            'postal_code' => self::blankToNull(mb_substr(self::cell($row, $map, 'postal_code'), 0, 20)),
            'note' => self::blankToNull(mb_substr($note, 0, 255)),
            'refunded_on' => self::date(self::cell($row, $map, 'refunded_on')),
            'payout_id' => null, 'fee' => 0.0,
        ];
    }

    /** One Jobber Payments (card) row. */
    public static function cardRow(array $row, array $map): ?array
    {
        $type = strtolower(self::cell($row, $map, 'type'));
        $date = self::date(self::cell($row, $map, 'date'));
        $total = self::money(self::cell($row, $map, 'total'));
        if ($date === null || $total === null || !in_array($type, ['payment', 'deposit'], true)) return null;
        $nums = self::invoiceNumbers(self::cell($row, $map, 'invoice_numbers'));
        $quote = self::cell($row, $map, 'quote_number');
        return [
            'type' => $type, 'kind' => $type,
            'client_name' => self::blankToNull(mb_substr(self::cell($row, $map, 'client_name'), 0, 200)),
            'payment_date' => $date,
            'amount' => abs($total),
            'amount_ex_tax' => null,
            'tip' => abs((float)(self::money(self::cell($row, $map, 'tip')) ?? 0)),
            'fee' => abs((float)(self::money(self::cell($row, $map, 'fee')) ?? 0)),
            'method' => 'Jobber Payments',
            'cheque_no' => null, 'stripe_charge_id' => null, 'transaction_no' => null, 'confirmation_no' => null,
            'invoice_numbers' => $nums ? mb_substr(implode(', ', $nums), 0, 255) : null,
            'quote_number' => $quote !== '' ? ltrim($quote, '#') : null,
            'job_number' => null, 'postal_code' => null,
            'note' => self::blankToNull(mb_substr(self::cell($row, $map, 'note'), 0, 255)),
            'refunded_on' => null,
            'payout_id' => self::blankToNull(mb_substr(self::cell($row, $map, 'payout_id'), 0, 80)),
        ];
    }

    /** Stable key for a payment row; $n distinguishes identical rows in one export. */
    public static function paymentKey(array $p, int $n = 0): string
    {
        return sha1(implode('|', [$p['kind'], $p['payment_date'], number_format((float)$p['amount'], 2, '.', ''),
                                  strtolower((string)$p['client_name']), (string)$p['invoice_numbers'], (string)$p['quote_number'], $n]));
    }
}
