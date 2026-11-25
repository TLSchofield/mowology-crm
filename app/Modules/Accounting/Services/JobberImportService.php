<?php
/**
 * JobberImportService — Jobber's exports in as HISTORY (2026-10-07).
 *
 * Reads the three CSV exports JobberCsv understands and stores them:
 *   invoices      → jobber_invoices (one row per Jobber invoice #, re-import updates it)
 *   transactions  → jobber_payments (payments / quote deposits) + the GST split of 2026 invoices
 *                   ("Paid - Tax $" on the Invoice rows → tax_source 'transactions')
 *   card          → jobber_payments payout_id + fee (Jobber Payments / Stripe), same row as the
 *                   Transaction List's payment (same payment_key)
 *
 * Jobber invoices are NEVER written to `invoices`, so AccountingService::syncFromInvoices and
 * LedgerSyncService never post income for them. The ledger side (2026 only) is
 * JobberLedgerService; this class never touches the journal or accounting_transactions.
 *
 * Client matching (every row is matched to the CRM before anything is written; the preview shows
 * how): service address → property (its site contact / company), then email, then phone, then
 * name (a contact's full name, or a company name; "VR1450 C/o Colyvan Pacific …" also tries the
 * part after "c/o"). Unmatched clients are listed; contacts are created only for the clients Tim
 * chooses on the preview (none / recent: invoiced since 2025 or still owing / all) — never silently.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
declare(strict_types=1);

require_once __DIR__ . '/JobberCsv.php';

class JobberImportService
{
    public const RECENT_FROM = '2025-01-01';
    public const FY_START = '2026-01-01';

    private PDO $db;
    private array $colCache = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function ready(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM jobber_invoices LIMIT 0");
            $this->db->query("SELECT 1 FROM jobber_payments LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE — client matching
    // ══════════════════════════════════════════════════════════════════════════

    private const STREET_WORDS = [
        'avenue' => 'ave', 'av' => 'ave', 'street' => 'st', 'road' => 'rd', 'drive' => 'dr', 'boulevard' => 'blvd',
        'place' => 'pl', 'crescent' => 'cres', 'court' => 'ct', 'lane' => 'ln', 'highway' => 'hwy', 'terrace' => 'terr',
        'west' => 'w', 'east' => 'e', 'north' => 'n', 'south' => 's', 'way' => 'way', 'square' => 'sq', 'parkway' => 'pkwy',
    ];

    /** "998 West 19th Avenue" → "998 w 19th ave"; "#101 - 1234 Main St." → "101 1234 main st". Pure. */
    public static function normAddress(?string $a): string
    {
        $a = strtolower((string)$a);
        $a = preg_replace('/[^a-z0-9 ]+/', ' ', $a);
        $out = [];
        foreach (preg_split('/\s+/', trim((string)$a)) as $w) {
            if ($w === '' || $w === 'unit' || $w === 'suite') continue;
            $out[] = self::STREET_WORDS[$w] ?? $w;
        }
        return implode(' ', $out);
    }

    /** The street part only (drops a leading unit number): "101 1234 main st" → "1234 main st". */
    public static function streetKey(string $norm): string
    {
        $p = explode(' ', $norm);
        if (count($p) >= 3 && ctype_digit($p[0]) && ctype_digit($p[1])) array_shift($p);
        return implode(' ', $p);
    }

    public static function normName(?string $n): string
    {
        $n = strtolower((string)$n);
        $n = preg_replace('/[^a-z0-9 ]+/', ' ', $n);
        return trim((string)preg_replace('/\s+/', ' ', (string)$n));
    }

    public static function phone10(?string $p): ?string
    {
        $d = preg_replace('/\D/', '', (string)$p);
        return strlen((string)$d) >= 10 ? substr((string)$d, -10) : null;
    }

    /**
     * Lookup tables for matching. Pure.
     * @param array $contacts   [id, first_name, last_name, email, phone, mobile?]
     * @param array $companies  [id, company_name, billing_email?, billing_phone?, primary_contact_id?, billing_contact_id?]
     * @param array $properties [id, address, site_contact_id?, company_id? (from company_properties)]
     */
    public static function buildIndex(array $contacts, array $companies, array $properties): array
    {
        $ix = ['addr' => [], 'street' => [], 'email' => [], 'phone' => [], 'name' => [], 'company_name' => [],
               'company_email' => [], 'company_phone' => [], 'company_contact' => []];
        $put = function (string $k, string $key, int $id) use (&$ix) {
            if ($key === '') return;
            if (!isset($ix[$k][$key])) $ix[$k][$key] = $id;
            elseif ($ix[$k][$key] !== $id) $ix[$k][$key] = -1;   // ambiguous
        };
        foreach ($contacts as $c) {
            $id = (int)$c['id'];
            $put('email', strtolower(trim((string)($c['email'] ?? ''))), $id);
            foreach (['phone', 'mobile'] as $f) { $p = self::phone10($c[$f] ?? null); if ($p) $put('phone', $p, $id); }
            $put('name', self::normName(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))), $id);
        }
        foreach ($companies as $co) {
            $id = (int)$co['id'];
            $put('company_name', self::normName($co['company_name'] ?? ''), $id);
            $put('company_email', strtolower(trim((string)($co['billing_email'] ?? ''))), $id);
            $p = self::phone10($co['billing_phone'] ?? null);
            if ($p) $put('company_phone', $p, $id);
            $cid = (int)($co['billing_contact_id'] ?? 0) ?: (int)($co['primary_contact_id'] ?? 0);
            if ($cid) $ix['company_contact'][$id] = $cid;
        }
        foreach ($properties as $p) {
            $n = self::normAddress($p['address'] ?? '');
            if ($n === '') continue;
            $row = ['property_id' => (int)$p['id'], 'contact_id' => (int)($p['site_contact_id'] ?? 0) ?: null,
                    'company_id' => (int)($p['company_id'] ?? 0) ?: null];
            if (!isset($ix['addr'][$n])) $ix['addr'][$n] = $row;
            $s = self::streetKey($n);
            if (!isset($ix['street'][$s])) $ix['street'][$s] = $row; elseif ($ix['street'][$s]['property_id'] !== $row['property_id']) $ix['street'][$s] = ['ambiguous' => true];
        }
        return $ix;
    }

    /**
     * Match one Jobber client to the CRM. Pure.
     * @param array $row client_name, client_email, client_phone, service_street
     * @return array{contact_id:?int, company_id:?int, property_id:?int, how:string}
     */
    public static function matchClient(array $ix, array $row): array
    {
        $out = ['contact_id' => null, 'company_id' => null, 'property_id' => null, 'how' => 'none'];
        $ok = fn($v) => is_int($v) && $v > 0;
        $addr = self::normAddress($row['service_street'] ?? '');
        if ($addr !== '') {
            $p = $ix['addr'][$addr] ?? null;
            if (!$p) { $s = $ix['street'][self::streetKey($addr)] ?? null; if ($s && empty($s['ambiguous'])) $p = $s; }
            if ($p) {
                $out['property_id'] = $p['property_id'];
                $out['contact_id'] = $p['contact_id'];
                $out['company_id'] = $p['company_id'];
                $out['how'] = 'address';
            }
        }
        // Who pays: email, phone, then name — they win over the property's site contact.
        $email = strtolower(trim((string)($row['client_email'] ?? '')));
        $phone = self::phone10($row['client_phone'] ?? null);
        $name = self::normName($row['client_name'] ?? '');
        $alt = '';
        if (preg_match('/\bc\s*\/\s*o\b(.*)$/i', (string)($row['client_name'] ?? ''), $m)) $alt = self::normName($m[1]);
        $before = $out['how'];
        if ($email !== '' && $ok($ix['email'][$email] ?? null)) { $out['contact_id'] = $ix['email'][$email]; $out['how'] = $before === 'address' ? 'address' : 'email'; }
        elseif ($email !== '' && $ok($ix['company_email'][$email] ?? null)) { $out['company_id'] = $ix['company_email'][$email]; $out['how'] = $before === 'address' ? 'address' : 'company'; }
        elseif ($phone && $ok($ix['phone'][$phone] ?? null)) { $out['contact_id'] = $ix['phone'][$phone]; $out['how'] = $before === 'address' ? 'address' : 'phone'; }
        elseif ($phone && $ok($ix['company_phone'][$phone] ?? null)) { $out['company_id'] = $ix['company_phone'][$phone]; $out['how'] = $before === 'address' ? 'address' : 'company'; }
        elseif ($name !== '' && $ok($ix['name'][$name] ?? null)) { $out['contact_id'] = $ix['name'][$name]; $out['how'] = $before === 'address' ? 'address' : 'name'; }
        elseif ($name !== '' && $ok($ix['company_name'][$name] ?? null)) { $out['company_id'] = $ix['company_name'][$name]; $out['how'] = $before === 'address' ? 'address' : 'company'; }
        elseif ($alt !== '' && $ok($ix['company_name'][$alt] ?? null)) { $out['company_id'] = $ix['company_name'][$alt]; $out['how'] = $before === 'address' ? 'address' : 'company'; }
        if ($out['contact_id'] === null && $out['company_id'] !== null) $out['contact_id'] = $ix['company_contact'][$out['company_id']] ?? null;
        return $out;
    }

    /** The key one unmatched client is grouped under (so one contact is proposed per client). Pure. */
    public static function clientKey(array $row): string
    {
        $e = strtolower(trim((string)($row['client_email'] ?? '')));
        if ($e !== '') return 'e:' . $e;
        $p = self::phone10($row['client_phone'] ?? null);
        if ($p) return 'p:' . $p;
        return 'n:' . self::normName($row['client_name'] ?? '');
    }

    /**
     * Spread one payment over the invoices it names: each invoice up to its total, in the order
     * given, the rest on the last. Pure.
     * @param array $invoices list of [number, total]
     * @return array<string, float> number => amount
     */
    public static function allocate(float $amount, array $invoices): array
    {
        $out = [];
        $left = round($amount, 2);
        $n = count($invoices);
        foreach (array_values($invoices) as $i => [$num, $total]) {
            $take = $i === $n - 1 ? $left : round(min($left, max(0.0, (float)$total)), 2);
            if (abs($take) < 0.005 && $i < $n - 1) continue;
            $out[(string)$num] = round(($out[(string)$num] ?? 0) + $take, 2);
            $left = round($left - $take, 2);
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PREVIEW / IMPORT
    // ══════════════════════════════════════════════════════════════════════════

    /** Parse + map + normalise. @return array{kind:?string, headers:array, map:array, missing:array, rows:array, bad:int, skipped:int} */
    public function read(string $content, array $override = []): array
    {
        $p = JobberCsv::parse($content);
        if ($p['kind'] === null) {
            return ['kind' => null, 'headers' => $p['headers'], 'map' => [], 'missing' => ['a Jobber Invoices, Transaction List or Jobber Payments export'],
                    'rows' => [], 'bad' => 0, 'skipped' => $p['skipped']];
        }
        $map = JobberCsv::guessMapping($p['kind'], $p['headers'], $override);
        $missing = JobberCsv::missing($p['kind'], $map);
        $fn = ['invoices' => 'invoiceRow', 'transactions' => 'transactionRow', 'card' => 'cardRow'][$p['kind']];
        $rows = []; $bad = 0;
        if (!$missing) {
            foreach ($p['rows'] as $r) {
                $x = JobberCsv::$fn($r, $map);
                if ($x === null) { $bad++; continue; }
                $rows[] = $x;
            }
        }
        return ['kind' => $p['kind'], 'headers' => $p['headers'], 'map' => $map, 'missing' => $missing, 'rows' => $rows, 'bad' => $bad, 'skipped' => $p['skipped']];
    }

    /** What an import would do. Read only. */
    public function preview(string $content, array $override = []): array
    {
        $r = $this->read($content, $override);
        $out = ['kind' => $r['kind'], 'headers' => $r['headers'], 'missing' => $r['missing'], 'bad' => $r['bad'], 'skipped' => $r['skipped'],
                'rows' => count($r['rows']), 'fields' => [], 'samples' => array_slice($r['rows'], 0, 8), 'ready' => $this->ready()];
        if ($r['kind'] !== null) {
            foreach (JobberCsv::FIELDS[$r['kind']] as $f => [$label, $syn, $req]) {
                $i = $r['map'][$f] ?? null;
                $out['fields'][] = ['field' => $f, 'label' => $label, 'required' => $req, 'header' => $i === null ? null : $r['headers'][$i]];
            }
        }
        if ($r['missing'] || !$r['rows']) return $out;
        if ($r['kind'] === 'invoices') $out += $this->invoicePreview($r['rows']);
        else $out += $this->paymentPreview($r['kind'], $r['rows']);
        return $out;
    }

    private function invoicePreview(array $rows): array
    {
        $ix = $this->index();
        $how = []; $years = []; $status = []; $unmatched = [];
        $open = ['past_due' => ['count' => 0, 'total' => 0.0], 'paid_with_balance' => ['count' => 0, 'total' => 0.0], 'open' => ['count' => 0, 'total' => 0.0]];
        $y2026 = ['count' => 0, 'total' => 0.0, 'computed_tax' => 0];
        $existing = $this->existingNumbers();
        $new = 0;
        foreach ($rows as $row) {
            $m = self::matchClient($ix, $row);
            $how[$m['how']] = ($how[$m['how']] ?? 0) + 1;
            $y = substr((string)$row['issued_date'], 0, 4) ?: 'undated';
            $years[$y] = ($years[$y] ?? 0) + 1;
            $status[$row['status']] = ($status[$row['status']] ?? 0) + 1;
            if (!isset($existing[$row['jobber_number']])) $new++;
            if ($row['balance'] > 0.005 && !in_array($row['status'], ['draft', 'bad_debt', 'void'], true)) {
                $k = $row['status'] === 'past_due' ? 'past_due' : ($row['status'] === 'paid' ? 'paid_with_balance' : 'open');
                $open[$k]['count']++; $open[$k]['total'] = round($open[$k]['total'] + $row['balance'], 2);
            }
            if ($y === '2026' && !in_array($row['status'], ['draft', 'void'], true)) {
                $y2026['count']++; $y2026['total'] = round($y2026['total'] + $row['total'], 2);
                if ($row['tax_source'] === 'computed') $y2026['computed_tax']++;
            }
            if ($m['contact_id'] === null && $m['company_id'] === null) {
                $k = self::clientKey($row);
                $u = $unmatched[$k] ?? ['key' => $k, 'name' => $row['client_name'], 'email' => $row['client_email'], 'phone' => $row['client_phone'],
                                        'address' => $row['service_street'], 'invoices' => 0, 'last' => null, 'owing' => 0.0, 'recent' => false];
                $u['invoices']++;
                if ((string)$row['issued_date'] > (string)$u['last']) $u['last'] = $row['issued_date'];
                if (!in_array($row['status'], ['draft', 'bad_debt', 'void'], true)) $u['owing'] = round($u['owing'] + $row['balance'], 2);
                $u['recent'] = $u['recent'] || (string)$row['issued_date'] >= self::RECENT_FROM || $u['owing'] > 0.005;
                $unmatched[$k] = $u;
            }
        }
        ksort($years);
        usort($unmatched, fn($a, $b) => [$b['recent'], (string)$b['last']] <=> [$a['recent'], (string)$a['last']]);
        return [
            'match' => $how, 'years' => $years, 'status' => $status, 'open' => $open, 'issued_2026' => $y2026,
            'new' => $new, 'update' => count($rows) - $new,
            'unmatched' => ['clients' => count($unmatched), 'recent' => count(array_filter($unmatched, fn($u) => $u['recent'])),
                            'list' => array_slice(array_values($unmatched), 0, 150)],
        ];
    }

    private function paymentPreview(string $kind, array $rows): array
    {
        $types = []; $methods = []; $sum = 0.0; $fees = 0.0; $payouts = []; $in2026 = 0;
        $numbers = $this->existingNumbers();
        $missingInv = [];
        foreach ($rows as $row) {
            $types[$row['type']] = ($types[$row['type']] ?? 0) + 1;
            if ($row['type'] === 'invoice') {
                if ($row['invoice_number'] !== null && !isset($numbers[$row['invoice_number']])) $missingInv[$row['invoice_number']] = true;
                continue;
            }
            $methods[(string)$row['method']] = ($methods[(string)$row['method']] ?? 0) + 1;
            $sum += $row['amount']; $fees += $row['fee'];
            if ($row['payout_id']) $payouts[$row['payout_id']] = true;
            if ($row['payment_date'] >= self::FY_START) $in2026++;
            foreach (JobberCsv::invoiceNumbers($row['invoice_numbers']) as $n) if (!isset($numbers[$n])) $missingInv[$n] = true;
        }
        return ['types' => $types, 'methods' => $methods, 'received' => round($sum, 2), 'fees' => round($fees, 2),
                'payouts' => count($payouts), 'in_2026' => $in2026, 'invoices_not_imported' => count($missingInv),
                'invoices_not_imported_sample' => array_slice(array_keys($missingInv), 0, 20)];
    }

    /**
     * Store an export. $create = 'none' | 'recent' | 'all' (contacts for unmatched clients, invoices
     * export only). Idempotent: re-importing updates the same rows.
     */
    public function import(string $content, array $override, string $create, int $userId, string $filename = ''): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1239 first.'];
        $r = $this->read($content, $override);
        if ($r['kind'] === null || $r['missing']) return ['ok' => false, 'message' => 'Columns missing: ' . implode(', ', $r['missing'])];
        if (!$r['rows']) return ['ok' => false, 'message' => 'No rows to import.'];
        $batch = 'jbi-' . date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 6);
        $now = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            if ($r['kind'] === 'invoices') $res = $this->importInvoices($r['rows'], $create, $userId, $batch, $now);
            else $res = $this->importPayments($r['kind'], $r['rows'], $batch, $now);
            $mapText = [];
            foreach ($r['map'] as $f => $i) $mapText[] = $f . '=' . ($i === null ? '' : $r['headers'][$i]);
            $this->db->prepare("INSERT INTO jobber_import_batches (batch_id, filename, row_count, inserted, updated, created_contacts, mapping, created_by, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([$batch, mb_substr($r['kind'] . ': ' . $filename, 0, 255), count($r['rows']), $res['inserted'], $res['updated'],
                          $res['created_contacts'] ?? 0, implode("\n", $mapText), $userId, $now]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        $res['ok'] = true;
        $res['batch_id'] = $batch;
        $res['message'] = sprintf('%s: %d new, %d updated%s.', ['invoices' => 'Invoices', 'transactions' => 'Transaction list', 'card' => 'Jobber Payments'][$r['kind']],
            $res['inserted'], $res['updated'], !empty($res['created_contacts']) ? ', ' . $res['created_contacts'] . ' contacts created' : '')
            . (!empty($res['tax_updated']) ? ' GST split from Jobber for ' . $res['tax_updated'] . ' invoices.' : '');
        return $res;
    }

    private function importInvoices(array $rows, string $create, int $userId, string $batch, string $now): array
    {
        $ix = $this->index();
        $existing = $this->existingRows();
        // Which unmatched clients get a contact.
        $want = [];
        if ($create === 'recent' || $create === 'all') {
            $agg = [];
            foreach ($rows as $row) {
                $m = self::matchClient($ix, $row);
                if ($m['contact_id'] !== null || $m['company_id'] !== null) continue;
                $k = self::clientKey($row);
                $owing = !in_array($row['status'], ['draft', 'bad_debt', 'void'], true) && $row['balance'] > 0.005;
                $agg[$k] = ($agg[$k] ?? false) || (string)$row['issued_date'] >= self::RECENT_FROM || $owing;
                if (!isset($want[$k])) $want[$k] = $row;
            }
            if ($create === 'recent') $want = array_filter($want, fn($row, $k) => $agg[$k], ARRAY_FILTER_USE_BOTH);
        }
        $created = [];
        foreach ($want as $k => $row) $created[$k] = $this->createContact($row);

        $ins = $this->db->prepare("INSERT INTO jobber_invoices (jobber_number, contact_id, company_id, property_id, match_how, client_name, client_email,
                client_phone, service_street, service_city, service_province, service_postal, subject, job_numbers, issued_date, due_date, status, status_raw,
                subtotal, tax, tax_source, total, balance, paid_date, line_items, batch_id, imported_by, imported_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd = $this->db->prepare("UPDATE jobber_invoices SET contact_id = ?, company_id = ?, property_id = ?, match_how = ?, client_name = ?, client_email = ?,
                client_phone = ?, service_street = ?, service_city = ?, service_province = ?, service_postal = ?, subject = ?, job_numbers = ?, issued_date = ?,
                due_date = ?, status = ?, status_raw = ?, subtotal = ?, tax = ?, tax_source = ?, total = ?, balance = ?, paid_date = ?, line_items = ?,
                batch_id = ?, updated_at = ? WHERE id = ?");
        $inserted = 0; $updated = 0;
        foreach ($rows as $row) {
            $m = self::matchClient($ix, $row);
            if ($m['contact_id'] === null && $m['company_id'] === null && isset($created[self::clientKey($row)])) {
                $m['contact_id'] = $created[self::clientKey($row)];
                $m['how'] = 'created';
            }
            $old = $existing[$row['jobber_number']] ?? null;
            // Keep Jobber's own GST split (Transaction List) over a computed one.
            if ($old && $old['tax_source'] === 'transactions' && $row['tax_source'] === 'computed' && abs((float)$old['total'] - $row['total']) < 0.005) {
                $row['subtotal'] = (float)$old['subtotal']; $row['tax'] = (float)$old['tax']; $row['tax_source'] = 'transactions';
            }
            // A contact Tim linked by hand (or an earlier match) is kept when this run finds none.
            if ($old && $m['contact_id'] === null && $m['company_id'] === null && $m['property_id'] === null) {
                $m = ['contact_id' => $old['contact_id'] !== null ? (int)$old['contact_id'] : null, 'company_id' => $old['company_id'] !== null ? (int)$old['company_id'] : null,
                      'property_id' => $old['property_id'] !== null ? (int)$old['property_id'] : null, 'how' => (string)$old['match_how']];
            }
            $vals = [$m['contact_id'], $m['company_id'], $m['property_id'], $m['how'], $row['client_name'], $row['client_email'], $row['client_phone'],
                     $row['service_street'], $row['service_city'], $row['service_province'], $row['service_postal'], $row['subject'], $row['job_numbers'],
                     $row['issued_date'], $row['due_date'], $row['status'], $row['status_raw'], $row['subtotal'], $row['tax'], $row['tax_source'],
                     $row['total'], $row['balance'], $row['paid_date'], $row['line_items']];
            if ($old) {
                $upd->execute(array_merge($vals, [$batch, $now, (int)$old['id']]));
                $updated++;
            } else {
                $ins->execute(array_merge([$row['jobber_number']], $vals, [$batch, $userId ?: null, $now, $now]));
                $existing[$row['jobber_number']] = ['id' => (int)$this->db->lastInsertId(), 'tax_source' => $row['tax_source'], 'total' => $row['total'],
                                                    'subtotal' => $row['subtotal'], 'tax' => $row['tax'], 'contact_id' => $m['contact_id'],
                                                    'company_id' => $m['company_id'], 'property_id' => $m['property_id'], 'match_how' => $m['how']];
                $inserted++;
            }
        }
        // Payments imported before their invoices: give them the invoice's contact.
        $this->fillPaymentContacts();
        return ['inserted' => $inserted, 'updated' => $updated, 'created_contacts' => count(array_filter($created))];
    }

    private function importPayments(string $kind, array $rows, string $batch, string $now): array
    {
        $byNumber = [];
        foreach ($this->db->query("SELECT id, jobber_number, contact_id, total, tax_source FROM jobber_invoices")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byNumber[(string)$r['jobber_number']] = $r;
        }
        $have = [];
        foreach ($this->db->query("SELECT id, payment_key FROM jobber_payments")->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(string)$r['payment_key']] = (int)$r['id'];
        $ix = null;
        $taxUpd = $this->db->prepare("UPDATE jobber_invoices SET subtotal = ?, tax = ?, tax_source = 'transactions', updated_at = ? WHERE id = ?");
        $ins = $this->db->prepare("INSERT INTO jobber_payments (payment_key, kind, client_name, contact_id, payment_date, amount, amount_ex_tax, tip, method, cheque_no,
                stripe_charge_id, transaction_no, confirmation_no, invoice_numbers, quote_number, payout_id, fee, job_number, postal_code, note, refunded_on, batch_id, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $updTx = $this->db->prepare("UPDATE jobber_payments SET kind = ?, client_name = ?, amount_ex_tax = ?, tip = ?, method = ?, cheque_no = ?, stripe_charge_id = ?,
                transaction_no = ?, confirmation_no = ?, invoice_numbers = ?, quote_number = ?, job_number = ?, postal_code = ?, note = ?, refunded_on = ?,
                contact_id = COALESCE(contact_id, ?) WHERE id = ?");
        $updCard = $this->db->prepare("UPDATE jobber_payments SET payout_id = ?, fee = ?, method = COALESCE(method, 'Jobber Payments'),
                quote_number = COALESCE(quote_number, ?), contact_id = COALESCE(contact_id, ?) WHERE id = ?");
        $inserted = 0; $updated = 0; $taxUpdated = 0;
        $seen = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'invoice') {
                $inv = $row['invoice_number'] !== null ? ($byNumber[$row['invoice_number']] ?? null) : null;
                if ($inv && $row['ex_tax'] !== null && abs((float)$inv['total'] - $row['total']) < 0.005) {
                    $taxUpd->execute([round($row['ex_tax'], 2), round($row['total'] - $row['ex_tax'], 2), $now, (int)$inv['id']]);
                    $taxUpdated++;
                }
                continue;
            }
            $base = JobberCsv::paymentKey($row, 0);
            $n = $seen[$base] ?? 0;
            $seen[$base] = $n + 1;
            $key = JobberCsv::paymentKey($row, $n);
            $contact = null;
            foreach (JobberCsv::invoiceNumbers($row['invoice_numbers']) as $num) {
                if (!empty($byNumber[$num]['contact_id'])) { $contact = (int)$byNumber[$num]['contact_id']; break; }
            }
            if ($contact === null && $row['client_name']) {
                $ix = $ix ?? $this->index();
                $m = self::matchClient($ix, ['client_name' => $row['client_name']]);
                $contact = $m['contact_id'];
            }
            if (isset($have[$key])) {
                if ($kind === 'card') $updCard->execute([$row['payout_id'], $row['fee'], $row['quote_number'], $contact, $have[$key]]);
                else $updTx->execute([$row['kind'], $row['client_name'], $row['amount_ex_tax'], $row['tip'], $row['method'], $row['cheque_no'], $row['stripe_charge_id'],
                                      $row['transaction_no'], $row['confirmation_no'], $row['invoice_numbers'], $row['quote_number'], $row['job_number'],
                                      $row['postal_code'], $row['note'], $row['refunded_on'], $contact, $have[$key]]);
                $updated++;
                continue;
            }
            $ins->execute([$key, $row['kind'], $row['client_name'], $contact, $row['payment_date'], $row['amount'], $row['amount_ex_tax'], $row['tip'],
                           $row['method'], $row['cheque_no'], $row['stripe_charge_id'], $row['transaction_no'], $row['confirmation_no'], $row['invoice_numbers'],
                           $row['quote_number'], $row['payout_id'], $row['fee'], $row['job_number'], $row['postal_code'], $row['note'], $row['refunded_on'], $batch, $now]);
            $have[$key] = (int)$this->db->lastInsertId();
            $inserted++;
        }
        return ['inserted' => $inserted, 'updated' => $updated, 'tax_updated' => $taxUpdated];
    }

    private function fillPaymentContacts(): void
    {
        $nums = [];
        foreach ($this->db->query("SELECT jobber_number, contact_id FROM jobber_invoices WHERE contact_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $nums[(string)$r['jobber_number']] = (int)$r['contact_id'];
        }
        $u = $this->db->prepare("UPDATE jobber_payments SET contact_id = ? WHERE id = ?");
        foreach ($this->db->query("SELECT id, invoice_numbers FROM jobber_payments WHERE contact_id IS NULL AND invoice_numbers IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            foreach (JobberCsv::invoiceNumbers($p['invoice_numbers']) as $n) {
                if (isset($nums[$n])) { $u->execute([$nums[$n], (int)$p['id']]); break; }
            }
        }
    }

    private function createContact(array $row): ?int
    {
        $name = trim((string)$row['client_name']);
        if ($name === '') return null;
        $parts = preg_split('/\s+/', $name);
        $first = count($parts) > 1 && !preg_match('/\b(c\/o|ltd|inc|strata|vr\d+|bcs\d+|management|holdings)\b/i', $name) ? array_shift($parts) : $name;
        $last = $first === $name ? null : implode(' ', $parts);
        $cols = ['first_name', 'last_name', 'email', 'phone', 'notes', 'is_active'];
        $vals = [mb_substr($first, 0, 100), $last !== null ? mb_substr($last, 0, 100) : null, $row['client_email'], $row['client_phone'],
                 'Created from the Jobber invoice import ' . date('Y-m-d') . ($row['service_street'] ? ' — service address ' . $row['service_street'] : ''), 1];
        $this->db->prepare("INSERT INTO contacts (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute($vals);
        return (int)$this->db->lastInsertId();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // READS
    // ══════════════════════════════════════════════════════════════════════════

    /** Matching tables from the CRM. */
    public function index(): array
    {
        $cc = $this->columns('contacts');
        $sel = ['id', 'first_name', 'last_name', 'email', 'phone'];
        if (in_array('mobile', $cc, true)) $sel[] = 'mobile';
        $where = in_array('merged_into_id', $cc, true) ? ' WHERE (merged_into_id IS NULL OR merged_into_id = 0)' : '';
        $contacts = $this->db->query("SELECT " . implode(', ', $sel) . " FROM contacts" . $where . " ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $companies = [];
        $co = $this->columns('companies');
        if ($co) {
            $sel = array_values(array_intersect(['id', 'company_name', 'billing_email', 'billing_phone', 'primary_contact_id', 'billing_contact_id'], $co));
            $companies = $this->db->query("SELECT " . implode(', ', $sel) . " FROM companies ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        }
        $properties = [];
        $pc = $this->columns('properties');
        if ($pc) {
            $sel = array_values(array_intersect(['id', 'address', 'site_contact_id'], $pc));
            $properties = $this->db->query("SELECT " . implode(', ', array_map(fn($c) => 'p.' . $c, $sel)) . " FROM properties p ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC);
            if ($this->columns('company_properties')) {
                $own = [];
                foreach ($this->db->query("SELECT company_id, property_id FROM company_properties ORDER BY is_primary DESC, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if (!isset($own[(int)$r['property_id']])) $own[(int)$r['property_id']] = (int)$r['company_id'];
                }
                foreach ($properties as &$p) $p['company_id'] = $own[(int)$p['id']] ?? null;
                unset($p);
            }
        }
        return self::buildIndex($contacts, $companies, $properties);
    }

    /** jobber_number => true */
    private function existingNumbers(): array
    {
        try {
            return array_fill_keys(array_map('strval', $this->db->query("SELECT jobber_number FROM jobber_invoices")->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function existingRows(): array
    {
        $out = [];
        foreach ($this->db->query("SELECT id, jobber_number, tax_source, total, subtotal, tax, contact_id, company_id, property_id, match_how FROM jobber_invoices")
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(string)$r['jobber_number']] = $r;
        return $out;
    }

    /** The page's summary of what is imported. */
    public function summary(): array
    {
        if (!$this->ready()) return ['ready' => false];
        $q = fn(string $sql) => $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $years = [];
        foreach ($q("SELECT SUBSTR(issued_date, 1, 4) AS y, COUNT(*) AS n, SUM(total) AS t FROM jobber_invoices GROUP BY SUBSTR(issued_date, 1, 4) ORDER BY y") as $r) {
            $years[] = ['year' => $r['y'] ?: 'undated', 'count' => (int)$r['n'], 'total' => round((float)$r['t'], 2)];
        }
        $status = [];
        foreach ($q("SELECT status, COUNT(*) AS n FROM jobber_invoices GROUP BY status") as $r) $status[$r['status']] = (int)$r['n'];
        $match = [];
        foreach ($q("SELECT COALESCE(match_how, 'none') AS h, COUNT(*) AS n FROM jobber_invoices GROUP BY COALESCE(match_how, 'none')") as $r) $match[$r['h']] = (int)$r['n'];
        $pay = $q("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS t, COALESCE(SUM(fee), 0) AS f, COUNT(DISTINCT payout_id) AS po FROM jobber_payments")[0];
        $batches = $q("SELECT batch_id, filename, row_count, inserted, updated, created_contacts, created_at FROM jobber_import_batches ORDER BY id DESC LIMIT 10");
        return [
            'ready' => true, 'years' => $years, 'status' => $status, 'match' => $match,
            'payments' => ['count' => (int)$pay['n'], 'total' => round((float)$pay['t'], 2), 'fees' => round((float)$pay['f'], 2), 'payouts' => (int)$pay['po']],
            'batches' => $batches,
            'ar_check' => $this->receivableCheck(),
            'open' => $this->openInvoices(),
        ];
    }

    /**
     * Jobber's receivable at Dec 31, 2025: what 2025-and-earlier invoices still owe now, plus the
     * 2026 payments applied to them — against the filed $51,948.
     */
    public function receivableCheck(string $asOf = '2025-12-31'): array
    {
        $inv = [];
        foreach ($this->db->query("SELECT jobber_number, total, balance, status FROM jobber_invoices WHERE issued_date IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $inv[(string)$r['jobber_number']] = $r;
        }
        $s = $this->db->prepare("SELECT payment_date, amount, invoice_numbers FROM jobber_payments WHERE kind = 'payment' AND payment_date > ?");
        $s->execute([$asOf]);
        $paidAfter = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $nums = JobberCsv::invoiceNumbers($p['invoice_numbers']);
            $list = array_map(fn($n) => [$n, isset($inv[$n]) ? (float)$inv[$n]['total'] : 0.0], $nums);
            foreach (self::allocate((float)$p['amount'], $list) as $n => $a) $paidAfter[$n] = ($paidAfter[$n] ?? 0) + $a;
        }
        $q = $this->db->prepare("SELECT jobber_number, balance, status FROM jobber_invoices WHERE issued_date <= ?");
        $q->execute([$asOf]);
        $owing = 0.0; $paidLater = 0.0; $bad = 0.0; $count = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $n = (string)$r['jobber_number'];
            if (in_array($r['status'], ['draft', 'void'], true)) continue;
            if ($r['status'] === 'bad_debt') { $bad += (float)$r['balance']; continue; }
            $b = (float)$r['balance'] + ($paidAfter[$n] ?? 0);
            if ($b > 0.005) $count++;
            $owing += (float)$r['balance'];
            $paidLater += $paidAfter[$n] ?? 0;
        }
        $total = round($owing + $paidLater, 2);
        return ['as_of' => $asOf, 'still_owing' => round($owing, 2), 'paid_in_2026' => round($paidLater, 2), 'total' => $total,
                'invoices' => $count, 'bad_debt_excluded' => round($bad, 2), 'filed' => 51948.00, 'difference' => round($total - 51948.00, 2)];
    }

    /** Invoices with a balance (not drafts, not bad debt), split the way Tim asked. */
    public function openInvoices(): array
    {
        $rows = $this->db->query("SELECT id, jobber_number, client_name, contact_id, property_id, service_street, issued_date, status, total, balance, crm_invoice_id
                                  FROM jobber_invoices WHERE balance > 0.005 AND status NOT IN ('draft', 'bad_debt', 'void') ORDER BY issued_date, jobber_number")
                         ->fetchAll(PDO::FETCH_ASSOC);
        $out = ['past_due' => [], 'paid_with_balance' => [], 'open' => []];
        $crm = [];
        $ids = array_filter(array_map(fn($r) => (int)$r['crm_invoice_id'], $rows));
        if ($ids) {
            foreach ($this->db->query("SELECT id, invoice_number FROM invoices WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetchAll(PDO::FETCH_ASSOC) as $i) {
                $crm[(int)$i['id']] = (string)$i['invoice_number'];
            }
        }
        foreach ($rows as $r) {
            $k = $r['status'] === 'past_due' ? 'past_due' : ($r['status'] === 'paid' ? 'paid_with_balance' : 'open');
            $out[$k][] = ['id' => (int)$r['id'], 'number' => (string)$r['jobber_number'], 'client' => (string)$r['client_name'],
                          'contact_id' => $r['contact_id'] !== null ? (int)$r['contact_id'] : null, 'address' => (string)$r['service_street'],
                          'issued' => $r['issued_date'], 'total' => round((float)$r['total'], 2), 'balance' => round((float)$r['balance'], 2),
                          'in_opening' => (string)$r['issued_date'] <= '2025-12-31',
                          'crm_invoice' => $r['crm_invoice_id'] ? ($crm[(int)$r['crm_invoice_id']] ?? '#' . $r['crm_invoice_id']) : null];
        }
        return $out;
    }

    /** Timeline rows for a contact (its own invoices and those at its properties). */
    public function forContact(int $contactId, int $limit = 100): array
    {
        if (!$this->ready()) return [];
        $props = [];
        try {
            $s = $this->db->prepare("SELECT id FROM properties WHERE site_contact_id = ?");
            $s->execute([$contactId]);
            $props = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { /* no properties */ }
        $where = 'contact_id = ?' . ($props ? ' OR property_id IN (' . implode(',', $props) . ')' : '');
        $s = $this->db->prepare("SELECT id, jobber_number, issued_date, status, total, balance, service_street FROM jobber_invoices
                                 WHERE $where ORDER BY issued_date DESC LIMIT " . max(1, min(500, $limit)));
        $s->execute([$contactId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function forProperty(int $propertyId, int $limit = 100): array
    {
        if (!$this->ready()) return [];
        $s = $this->db->prepare("SELECT id, jobber_number, client_name, issued_date, status, total, balance FROM jobber_invoices
                                 WHERE property_id = ? ORDER BY issued_date DESC LIMIT " . max(1, min(500, $limit)));
        $s->execute([$propertyId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** "Jobber invoice #1234 — $456.84 — paid" */
    public static function timelineTitle(array $r): string
    {
        $st = ['paid' => 'paid', 'past_due' => 'past due', 'bad_debt' => 'bad debt', 'draft' => 'draft', 'open' => 'awaiting payment', 'void' => 'void'][$r['status']] ?? (string)$r['status'];
        $t = 'Jobber invoice #' . $r['jobber_number'] . ' — $' . number_format((float)$r['total'], 2) . ' — ' . $st;
        if ((float)$r['balance'] > 0.005 && $r['status'] !== 'paid') $t .= ' ($' . number_format((float)$r['balance'], 2) . ' owing)';
        return $t;
    }

    private function columns(string $table): array
    {
        if (isset($this->colCache[$table])) return $this->colCache[$table];
        $cols = [];
        try {
            $cols = array_map(fn($r) => (string)$r['Field'], $this->db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            try {
                $cols = array_map(fn($r) => (string)$r['name'], $this->db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC));
            } catch (Throwable $e2) { $cols = []; }
        }
        return $this->colCache[$table] = $cols;
    }
}
