<?php
/**
 * QboSyncService — QuickBooks Online, step 2 (plan 2026-10-20): send an Ittai invoice to QuickBooks,
 * with its customer and its payments. One way: Ittai → QuickBooks. Nothing is read back except ids.
 *
 *   customer   the invoice's client (clients.display_name, else the invoice's bill-to name): an
 *              existing QuickBooks customer with that name is used; otherwise one is created.
 *   item       QuickBooks invoice lines point at an ITEM, and the item carries the income account.
 *              One Service item per Ittai income account ("Ittai: Lawn Care Services"), pointing at
 *              the account the owner mapped. Created once, remembered.
 *   invoice    one line per Ittai income account, the same revenue split Ittai's own ledger uses
 *              (LedgerAccountMap::invoiceSplits); TaxCodeRef = the mapped GST code when the invoice
 *              charged GST, else the mapped exempt code; GlobalTaxCalculation TaxExcluded. After
 *              sending, QuickBooks' TotalAmt is compared with Ittai's total: a difference is kept as
 *              'mismatch' and said, never hidden.
 *   payment    each invoice_payment_allocations row, applied to that invoice.
 *
 * Every create carries a fixed request id (ittai-<site>-<kind>-<id>): Intuit answers a repeat with the
 * first result, so a retry never makes a second copy. qbo_links remembers what each thing became.
 *
 * SANDBOX ONLY until the owner switches live sends on (ops_settings qbo_live_sends = 1): a production
 * company is refused here, whatever the caller asks.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QboConnectionService.php';
if (!class_exists('LedgerAccountMap')) require_once dirname(__DIR__, 2) . '/Accounting/Services/LedgerAccountMap.php';

class QboSyncService
{
    public const DUPLICATE_NAME = '6240';
    /** "Lawn Care Services (Ittai)". Never a colon: in QuickBooks "A:B" makes B a sub-item of A (seen
     *  live 2026-10-20 — "Ittai: Lawn Care" became item "Lawn Care" under a parent "Ittai"). */
    public const ITEM_SUFFIX = ' (Ittai)';

    private QboConnectionService $conn;
    private PDO $db;
    private int $siteId;
    /** @var callable(array $invoiceRow, float $net): list<array{0:string,1:float}> */
    private $splits;
    /** @var callable(): int */
    private $clock;

    public function __construct(QboConnectionService $conn, ?callable $splits = null, ?callable $clock = null)
    {
        $this->conn = $conn;
        $this->db = $conn->db();
        $this->siteId = $conn->siteId();
        $this->splits = $splits ?? function (array $row, float $net) {
            $map = new LedgerAccountMap($this->db);
            return $map->ready() ? $map->invoiceSplits($row, $net) : [['4900', $net]];
        };
        $this->clock = $clock ?? 'time';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure builders (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    public static function requestId(int $siteId, string $kind, string $id): string
    {
        return substr('ittai-' . $siteId . '-' . $kind . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $id), 0, 50);
    }

    /**
     * Pure (tested): the request id for the next try. A try QuickBooks REFUSED gets a new id (Intuit
     * replays the first answer for a repeated id, so a fixed retry would get the old refusal back for
     * ever); a timeout / outage keeps the SAME id (it may have been created — the replay says so).
     */
    public static function nextRequestId(string $base, ?array $prev): string
    {
        // 'held' too: a held invoice was refused, or never created (then a new id costs nothing).
        if (!$prev || !in_array($prev['status'] ?? '', ['refused', 'held'], true)) return $prev['request_id'] ?? $base;
        $n = preg_match('/-r(\d+)$/', (string)$prev['request_id'], $m) ? (int)$m[1] + 1 : 1;
        return $base . '-r' . $n;
    }

    /** QuickBooks DisplayName: 100 characters, no tabs/newlines, never blank. */
    public static function displayName(?string $client, ?string $billTo, int $invoiceId): string
    {
        $n = trim((string)preg_replace('/\s+/', ' ', (string)($client ?: $billTo)));
        return mb_substr($n !== '' ? $n : 'Ittai customer (invoice ' . $invoiceId . ')', 0, 100);
    }

    public static function customerBody(string $name, ?string $email, ?array $addr = null, ?string $phone = null): array
    {
        $b = ['DisplayName' => $name];
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) $b['PrimaryEmailAddr'] = ['Address' => $email];
        if ($addr) $b['BillAddr'] = $addr;
        if ($phone !== null && trim($phone) !== '') $b['PrimaryPhone'] = ['FreeFormNumber' => mb_substr(trim($phone), 0, 30)];
        return $b;
    }

    /**
     * Pure (tested): the customer's billing address for QuickBooks — the invoice's billing address,
     * else the property it was for. Null when there is no street.
     */
    public static function billAddr(array $inv): ?array
    {
        $pick = fn(string $a, string $c, string $pr, string $pc) => trim((string)($inv[$a] ?? '')) !== ''
            ? array_filter(['Line1' => mb_substr(trim((string)$inv[$a]), 0, 500), 'City' => trim((string)($inv[$c] ?? '')),
                            'CountrySubDivisionCode' => trim((string)($inv[$pr] ?? '')), 'PostalCode' => trim((string)($inv[$pc] ?? '')),
                            'Country' => 'Canada'], fn($v) => $v !== '')
            : null;
        return $pick('billing_address', 'billing_city', 'billing_province', 'billing_postal_code')
            ?? $pick('property_address', 'property_city', 'property_province', 'property_postal');
    }

    /** Pure: "Expenses:Fuel" → "Expenses - Fuel" (a colon is a sub-item in QuickBooks). */
    public static function noColon(string $s): string
    {
        return trim((string)preg_replace('/\s+/', ' ', preg_replace('/\s*:\s*/', ' - ', $s)));
    }

    public static function itemBody(string $accountName, string $qboAccountId): array
    {
        $name = self::noColon($accountName);
        return ['Name' => mb_substr($name, 0, 100 - strlen(self::ITEM_SUFFIX)) . self::ITEM_SUFFIX, 'Type' => 'Service', 'IncomeAccountRef' => ['value' => $qboAccountId]];
    }

    /**
     * @param list<array{item:string, amount:float, description:string}> $lines
     */
    public static function invoiceBody(array $inv, string $customerId, array $lines, string $taxCodeId): array
    {
        $out = [];
        foreach ($lines as $l) {
            $amt = round((float)$l['amount'], 2);
            $out[] = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount' => $amt,
                'Description' => mb_substr($l['description'], 0, 4000),
                'SalesItemLineDetail' => ['ItemRef' => ['value' => $l['item']], 'Qty' => 1, 'UnitPrice' => $amt, 'TaxCodeRef' => ['value' => $taxCodeId]],
            ];
        }
        $b = [
            'CustomerRef' => ['value' => $customerId],
            'Line' => $out,
            'GlobalTaxCalculation' => 'TaxExcluded',
            'PrivateNote' => mb_substr('From Ittai, invoice ' . ($inv['invoice_number'] ?? $inv['id']), 0, 4000),
        ];
        if (!empty($inv['invoice_number'])) $b['DocNumber'] = mb_substr((string)$inv['invoice_number'], 0, 21);
        $date = substr((string)($inv['issue_date'] ?: ($inv['invoice_date'] ?: $inv['created_at'])), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $b['TxnDate'] = $date;
        if (!empty($inv['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$inv['due_date'])) $b['DueDate'] = substr((string)$inv['due_date'], 0, 10);
        return $b;
    }

    public static function paymentBody(string $customerId, string $invoiceQboId, float $amount, string $date, ?string $ref): array
    {
        $b = [
            'CustomerRef' => ['value' => $customerId],
            'TotalAmt' => round($amount, 2),
            'TxnDate' => substr($date, 0, 10),
            'Line' => [['Amount' => round($amount, 2), 'LinkedTxn' => [['TxnId' => $invoiceQboId, 'TxnType' => 'Invoice']]]],
        ];
        if ($ref !== null && trim($ref) !== '') $b['PaymentRefNum'] = mb_substr(trim($ref), 0, 21);
        return $b;
    }

    public const GST_RATE = 0.05;

    /**
     * Pure (tested): can this invoice go up as "lines × one tax code"? Null = yes; else why not.
     * Held for the owner (never sent wrong): a total that isn't subtotal + tax (a discount or credit),
     * GST that isn't 5% of the subtotal (mixed taxable / exempt lines), a split that doesn't add up.
     * @param list<array{0:string,1:float}> $splits
     */
    public static function cannotSend(float $subtotal, float $tax, float $total, array $splits, float $rate = self::GST_RATE): ?string
    {
        if (!self::sameMoney($subtotal + $tax, $total)) {
            return sprintf('its total ($%s) is not subtotal + GST ($%s + $%s) — a discount or credit. Enter it in QuickBooks by hand.',
                           number_format($total, 2), number_format($subtotal, 2), number_format($tax, 2));
        }
        if ($tax > 0 && abs(round($subtotal * $rate, 2) - $tax) > 0.011) {
            return sprintf('its tax ($%s) is not %s%% of $%s — some lines are exempt. Enter it in QuickBooks by hand.',
                           number_format($tax, 2), rtrim(rtrim(number_format($rate * 100, 2), '0'), '.'), number_format($subtotal, 2));
        }
        $sum = 0.0;
        foreach ($splits as [, $amt]) $sum += round((float)$amt, 2);
        if (!self::sameMoney($sum, $subtotal)) {
            return sprintf('its revenue split adds to $%s, not the subtotal $%s.', number_format($sum, 2), number_format($subtotal, 2));
        }
        return null;
    }

    /**
     * Pure (tested): invoices.tax_rate as a fraction. Stored as 0.05 on live invoices (checked
     * 2026-10-20), but a percent (5) is read the same; none → 5%.
     */
    public static function rateOf($stored): float
    {
        $r = (float)$stored;
        if ($r <= 0) return self::GST_RATE;
        return $r > 1 ? $r / 100 : $r;
    }

    /** Pure: the same money to the cent? */
    public static function sameMoney(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────────

    /** Refuse a live company until the owner switches live sends on. */
    public function guard(): ?string
    {
        $c = $this->conn->company();
        if (!$c) return 'Not connected to QuickBooks.';
        try {
            $this->db->query('SELECT 1 FROM qbo_links LIMIT 0');
        } catch (Throwable $e) {
            return 'Run migration 1383 (QuickBooks links) first.';
        }
        if ($c['environment'] !== 'sandbox' && $this->setting('qbo_live_sends') !== '1') {
            return 'Sending to a live QuickBooks company is not switched on yet (test company only for now).';
        }
        return null;
    }

    /**
     * Send one invoice (and its customer, items and payments).
     * @return array{ok:bool, message:string, invoice_qbo_id?:string, payments?:int}
     */
    public function sendInvoice(int $invoiceId): array
    {
        if (($g = $this->guard()) !== null) return ['ok' => false, 'message' => $g];
        // One send at a time for this business (the 15-minute run and a press on the page could
        // otherwise both see "not sent yet" and both create — Codex review 2026-10-20).
        if (!$this->lock()) return ['ok' => false, 'message' => 'Another QuickBooks send is running. Try again in a minute.'];
        try {
            return $this->sendInvoiceLocked($invoiceId);
        } finally {
            $this->unlock();
        }
    }

    private function lockName(): string { return 'ittai_qbo_' . $this->siteId; }

    private function lock(): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;   // tests (SQLite): one process
        return (int)$this->db->query("SELECT GET_LOCK(" . $this->db->quote($this->lockName()) . ", 20)")->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        try { $this->db->query("SELECT RELEASE_LOCK(" . $this->db->quote($this->lockName()) . ")"); } catch (Throwable $e) { /* released at disconnect */ }
    }

    private function sendInvoiceLocked(int $invoiceId): array
    {
        // Who the customer is (live 2026-10-20: 269 of 445 invoices since April have no client row and
        // 372 no bill-to name — most carry only a contact): client → contact → company → property.
        $s = $this->db->prepare("SELECT i.*, c.display_name AS client_name, c.billing_email AS client_email,
                                        TRIM(CONCAT_WS(' ', ct.first_name, ct.last_name)) AS contact_name, ct.email AS contact_email,
                                        COALESCE(NULLIF(ct.phone, ''), ct.mobile) AS contact_phone,
                                        p.city AS property_city, p.province AS property_province, p.postal_code AS property_postal,
                                        co.company_name, co.billing_email AS company_email, p.address AS property_address
                                 FROM invoices i
                                 LEFT JOIN clients c ON c.id = i.client_id
                                 LEFT JOIN contacts ct ON ct.id = i.contact_id
                                 LEFT JOIN companies co ON co.id = i.company_id
                                 LEFT JOIN properties p ON p.id = i.property_id
                                 WHERE i.id = ?");
        $s->execute([$invoiceId]);
        $inv = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return ['ok' => false, 'message' => 'No invoice #' . $invoiceId . '.'];
        $status = strtolower(trim((string)$inv['status']));
        $already = $this->link('invoice', (string)$invoiceId);
        if ($already && $already['qbo_id'] && in_array($status, ['draft', 'cancelled', 'canceled', 'void', ''], true)) {
            // Voided in Ittai after it was sent: the QuickBooks invoice stays linked (never erased, so it is
            // never created twice); no payments are sent to it; the page asks the owner to void it there.
            return ['ok' => false, 'message' => ($inv['invoice_number'] ?: '#' . $invoiceId) . ' was ' . $status . ' in Ittai after it went to QuickBooks — void it in QuickBooks too.',
                    'invoice_qbo_id' => (string)$already['qbo_id'], 'payments' => 0];
        }
        if (in_array($status, ['draft', 'cancelled', 'canceled', 'void', ''], true)) {
            return ['ok' => false, 'message' => 'Invoice ' . $inv['invoice_number'] . ' is ' . ($status ?: 'not issued') . ' — only issued invoices go to QuickBooks.'];
        }
        $label = (string)($inv['invoice_number'] ?: '#' . $invoiceId);

        $existing = $this->link('invoice', (string)$invoiceId);
        if ($existing && $existing['qbo_id']) {
            if ($existing['status'] === 'mismatch') {
                return ['ok' => false, 'message' => $label . ' is in QuickBooks but its total differs (' . $existing['message'] . ') — fix it there; payments are held.',
                        'invoice_qbo_id' => (string)$existing['qbo_id'], 'payments' => 0];
            }
            $pay = $this->sendPayments($inv, (string)$existing['qbo_id'], (float)$existing['qbo_total']);
            return ['ok' => true, 'message' => $label . ' was already in QuickBooks.' . $pay['say'], 'invoice_qbo_id' => (string)$existing['qbo_id'], 'payments' => $pay['sent']];
        }

        $maps = $this->conn->mappings();
        $gst = round((float)($inv['tax_amount'] ?? 0), 2);
        $taxKey = $gst > 0 ? 'gst' : 'exempt';
        $taxCode = $maps['tax_code'][$taxKey]['qbo_id'] ?? null;
        if (!$taxCode) return ['ok' => false, 'message' => 'Pick the QuickBooks tax code for "' . QboConnectionService::TAX_KEYS[$taxKey] . '" first (QuickBooks page → Tax codes).'];

        $net = round((float)($inv['subtotal'] ?? 0), 2);
        $splits = ($this->splits)($inv, $net);
        $ittaiTotal = round((float)($inv['total'] ?: $inv['total_amount']), 2);
        // The invoice's own rate — tenant-neutral: 13% HST in Ontario, 5% GST in BC.
        $rate = self::rateOf($inv['tax_rate'] ?? null);
        if (($why = self::cannotSend($net, $gst, $ittaiTotal, $splits, $rate)) !== null) {
            return ['ok' => false, 'message' => $label . ' was not sent: ' . $why];
        }
        $lines = [];
        foreach ($splits as [$code, $amt]) {
            if (round((float)$amt, 2) == 0.0) continue;
            $item = $this->ensureItem((string)$code, $maps);
            if (!$item['ok']) return ['ok' => false, 'message' => $label . ': ' . $item['message']];
            $lines[] = ['item' => $item['qbo_id'], 'amount' => (float)$amt, 'description' => $this->lineText($invoiceId, (string)$code)];
        }
        if (!$lines) return ['ok' => false, 'message' => $label . ' has no amount to send.'];

        $cust = $this->ensureCustomer($inv);
        if (!$cust['ok']) return ['ok' => false, 'message' => $label . ': ' . $cust['message']];

        $rid = self::nextRequestId(self::requestId($this->siteId, 'inv', (string)$invoiceId), $existing);
        $r = $this->conn->client()->create('Invoice', self::invoiceBody($inv, $cust['qbo_id'], $lines, $taxCode), $rid);
        if (!$r['ok']) {
            $this->saveLink('invoice', (string)$invoiceId, null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
            return ['ok' => false, 'message' => $label . ' was not sent: ' . $r['error']];
        }
        $q = (array)($r['data']['Invoice'] ?? []);
        $qTotal = round((float)($q['TotalAmt'] ?? 0), 2);
        $match = self::sameMoney($qTotal, $ittaiTotal);
        $note = $match ? null : sprintf('QuickBooks total $%s vs Ittai $%s — check the tax code and lines.', number_format($qTotal, 2), number_format($ittaiTotal, 2));
        $this->saveLink('invoice', (string)$invoiceId, (string)($q['Id'] ?? ''), (string)($q['SyncToken'] ?? ''), $rid, $match ? 'sent' : 'mismatch', $qTotal, $note);
        // Never apply payments to an invoice whose QuickBooks total is wrong (Codex review 2026-10-20).
        $pay = $match ? $this->sendPayments($inv, (string)($q['Id'] ?? ''), $qTotal) : ['sent' => 0, 'say' => ' Payments are held until the totals agree.'];
        return ['ok' => $match, 'message' => $label . ' sent to QuickBooks' . ($match ? ' — totals match ($' . number_format($qTotal, 2) . ').' : '. ' . $note) . $pay['say'],
                'invoice_qbo_id' => (string)($q['Id'] ?? ''), 'payments' => $pay['sent']];
    }

    /** @return array{sent:int, say:string} */
    /**
     * Every recorded payment on the invoice, as Ittai's ledger counts them (InvoicePaymentPlanner):
     * hand-recorded allocations AND succeeded Stripe payments (live: 248 Stripe-paid invoices have no
     * allocation). @return list<array{key:string, amount:float, date:string, ref:?string}>
     */
    public function paymentsOf(int $invoiceId): array
    {
        $out = [];
        $s = $this->db->prepare('SELECT id, amount, payment_date, created_at, reference FROM invoice_payment_allocations WHERE invoice_id = ? AND amount > 0 ORDER BY id');
        $s->execute([$invoiceId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $out[] = ['key' => 'alloc:' . $p['id'], 'amount' => round((float)$p['amount'], 2),
                      'date' => (string)($p['payment_date'] ?: substr((string)$p['created_at'], 0, 10)), 'ref' => $p['reference'] ?? null];
        }
        try {
            $s = $this->db->prepare("SELECT id, amount_cents, created_at, stripe_charge_id FROM stripe_payments WHERE invoice_id = ? AND status = 'succeeded' ORDER BY id");
            $s->execute([$invoiceId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = ['key' => 'stripe:' . $p['id'], 'amount' => round((int)$p['amount_cents'] / 100, 2),
                          'date' => substr((string)$p['created_at'], 0, 10), 'ref' => 'Stripe'];
            }
        } catch (Throwable $e) { /* no Stripe table: allocations only */ }
        return $out;
    }

    private function sendPayments(array $inv, string $invoiceQboId, float $invoiceTotal): array
    {
        if ($invoiceQboId === '') return ['sent' => 0, 'say' => ''];
        // The payment's customer is the one QuickBooks has on THAT invoice (never a fresh guess).
        $q = $this->conn->client()->query('SELECT * FROM Invoice WHERE Id = ' . QboClient::quote($invoiceQboId));
        $custId = $q['ok'] ? (string)($q['data']['QueryResponse']['Invoice'][0]['CustomerRef']['value'] ?? '') : '';
        if ($custId === '') return ['sent' => 0, 'say' => ' Payments not sent: the invoice could not be read back from QuickBooks.'];
        $cust = ['qbo_id' => $custId];
        $sent = 0; $failed = []; $held = 0;
        $rows = $this->paymentsOf((int)$inv['id']);
        // What is already applied in QuickBooks: a payment is never sent past the invoice's total.
        $applied = 0.0;
        foreach ($rows as $p) {
            $l = $this->link('payment', $p['key']);
            if ($l && $l['qbo_id']) $applied += round((float)$l['qbo_total'], 2);
        }
        foreach ($rows as $p) {
            $l = $this->link('payment', $p['key']);
            if ($l && $l['qbo_id']) continue;
            if ($applied + $p['amount'] > round($invoiceTotal, 2) + 0.005) {
                // Remembered, so the automatic run does not pick this invoice up again every 15 minutes.
                // sync_token = the amount: a corrected amount makes the automatic run look at it again.
                $this->saveLink('payment', $p['key'], null, number_format($p['amount'], 2, '.', ''), self::requestId($this->siteId, 'pay', str_replace(':', '', $p['key'])), 'held', null,
                                'would pay more than the invoice in QuickBooks');
                $held++;
                continue;
            }
            $rid = self::nextRequestId(self::requestId($this->siteId, 'pay', str_replace(':', '', $p['key'])), $l);
            $r = $this->conn->client()->create('Payment', self::paymentBody((string)$cust['qbo_id'], $invoiceQboId, $p['amount'], $p['date'], $p['ref']), $rid);
            if ($r['ok']) {
                $q = (array)($r['data']['Payment'] ?? []);
                $this->saveLink('payment', $p['key'], (string)($q['Id'] ?? ''), (string)($q['SyncToken'] ?? ''), $rid, 'sent', round((float)($q['TotalAmt'] ?? 0), 2), null);
                $applied += $p['amount'];
                $sent++;
            } else {
                $this->saveLink('payment', $p['key'], null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
                $failed[] = $r['error'];
            }
        }
        // Paid in Ittai beyond the recorded payments (an old direct "paid" mark, or account credit —
        // not cash): said, never guessed (InvoicePaymentPlanner's 'direct' remainder).
        $recorded = 0.0;
        foreach ($rows as $p) $recorded += $p['amount'];
        $rest = round((float)($inv['amount_paid'] ?? 0) - $recorded, 2);
        $say = ($sent ? ' ' . $sent . ' payment' . ($sent === 1 ? '' : 's') . ' applied.' : '')
             . ($rest > 0.005 ? sprintf(' $%s marked paid in Ittai has no payment record (an older entry or account credit) — enter it in QuickBooks by hand.', number_format($rest, 2)) : '')
             . ($failed ? ' ' . count($failed) . ' payment(s) not sent: ' . $failed[0] : '')
             . ($held ? ' ' . $held . ' payment(s) held: they would pay more than the invoice.' : '');
        return ['sent' => $sent, 'say' => $say];
    }

    /**
     * Pure (tested): who the invoice's customer is — [key, name, email]. Client, then contact, then
     * company, then the property; never a key shared by unrelated invoices (an empty name used to
     * hash to one "Ittai customer" for all of them).
     * @return array{0:string, 1:string, 2:?string}
     */
    public static function customerOf(array $inv): array
    {
        $n = fn($v) => trim((string)preg_replace('/\s+/', ' ', (string)$v));
        if (!empty($inv['client_id']) && $n($inv['client_name'] ?? '') !== '') {
            return ['client:' . (int)$inv['client_id'], $n($inv['client_name']), $inv['client_email'] ?? null];
        }
        if (!empty($inv['contact_id']) && $n($inv['contact_name'] ?? '') !== '') {
            return ['contact:' . (int)$inv['contact_id'], $n($inv['contact_name']), $inv['contact_email'] ?? null];
        }
        if (!empty($inv['company_id']) && $n($inv['company_name'] ?? '') !== '') {
            return ['company:' . (int)$inv['company_id'], $n($inv['company_name']), $inv['company_email'] ?? null];
        }
        if ($n($inv['bill_to_name'] ?? '') !== '') {
            return ['billto:' . substr(sha1(strtolower($n($inv['bill_to_name']))), 0, 20), $n($inv['bill_to_name']), $inv['sent_to_email'] ?? null];
        }
        if (!empty($inv['property_id']) && $n($inv['property_address'] ?? '') !== '') {
            return ['property:' . (int)$inv['property_id'], $n($inv['property_address']), $inv['sent_to_email'] ?? null];
        }
        return ['invoice:' . (int)$inv['id'], 'Ittai customer (invoice ' . (int)$inv['id'] . ')', $inv['sent_to_email'] ?? null];
    }

    private function customerKey(array $inv): string
    {
        return self::customerOf($inv)[0];
    }

    /** @return array{ok:bool, qbo_id?:string, message?:string} */
    private function ensureCustomer(array $inv): array
    {
        $key = $this->customerKey($inv);
        $l = $this->link('client', $key);
        if ($l && $l['qbo_id']) return ['ok' => true, 'qbo_id' => (string)$l['qbo_id']];
        [, $who, $email] = self::customerOf($inv);
        $name = self::displayName($who, null, (int)$inv['id']);
        $c = $this->conn->client();
        // An existing QuickBooks customer with this name is the same client: use it, never a duplicate.
        $found = $c->query('SELECT Id, DisplayName FROM Customer WHERE DisplayName = ' . QboClient::quote($name));
        if (!$found['ok']) return ['ok' => false, 'message' => $found['error']];
        $hit = $found['data']['QueryResponse']['Customer'][0]['Id'] ?? null;
        if ($hit) {
            $this->saveLink('client', $key, (string)$hit, null, 'found', 'sent', null, 'matched by name');
            return ['ok' => true, 'qbo_id' => (string)$hit];
        }
        $rid = self::nextRequestId(self::requestId($this->siteId, 'cust', $key), $l);
        $addr = self::billAddr($inv);
        $phone = $inv['contact_phone'] ?? null;
        $r = $c->create('Customer', self::customerBody($name, $email, $addr, $phone), $rid);
        if (!$r['ok'] && ($r['code'] ?? '') === self::DUPLICATE_NAME) {
            // The name is taken by a supplier or employee in QuickBooks (names are unique across all three).
            $r = $c->create('Customer', self::customerBody(mb_substr($name, 0, 89) . ' (customer)', $email, $addr, $phone), $rid . '-c');
        }
        if (!$r['ok']) {
            $this->saveLink('client', $key, null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
            return ['ok' => false, 'message' => 'the customer could not be made: ' . $r['error']];
        }
        $id = (string)($r['data']['Customer']['Id'] ?? '');
        $this->saveLink('client', $key, $id, (string)($r['data']['Customer']['SyncToken'] ?? ''), $rid, 'sent', null, null);
        return ['ok' => true, 'qbo_id' => $id];
    }

    /** @return array{ok:bool, qbo_id?:string, message?:string} */
    private function ensureItem(string $code, array $maps): array
    {
        $acct = $maps['account'][$code]['qbo_id'] ?? null;
        // Keyed by the account AND its current mapping: a remap gets an item for the new account
        // (Codex review 2026-10-20 — the old item kept posting to the old account).
        $key = $code . ':' . (string)$acct;
        $l = $this->link('income_item', $key);
        if ($acct && $l && $l['qbo_id']) return ['ok' => true, 'qbo_id' => (string)$l['qbo_id']];
        $s = $this->db->prepare('SELECT name FROM chart_of_accounts WHERE code = ?');
        $s->execute([$code]);
        $name = (string)($s->fetchColumn() ?: $code);
        if (!$acct) return ['ok' => false, 'message' => 'map Ittai account ' . $code . ' ' . $name . ' to a QuickBooks income account first (QuickBooks page → Accounts).'];
        $c = $this->conn->client();
        $body = self::itemBody($name, (string)$acct);
        $found = $c->query('SELECT Id, IncomeAccountRef FROM Item WHERE Name = ' . QboClient::quote($body['Name']));
        $row = $found['ok'] ? ($found['data']['QueryResponse']['Item'][0] ?? null) : null;
        if ($row && (string)($row['IncomeAccountRef']['value'] ?? '') === (string)$acct) {
            $this->saveLink('income_item', $key, (string)$row['Id'], null, 'found', 'sent', null, 'matched by name and account');
            return ['ok' => true, 'qbo_id' => (string)$row['Id']];
        }
        if ($row) {
            // Same name, another account: a second item named for this account, never the wrong one.
            $tag = ' · ' . self::noColon((string)($maps['account'][$code]['qbo_name'] ?? $acct));
            $body['Name'] = mb_substr(self::noColon($name), 0, 100 - strlen(self::ITEM_SUFFIX) - strlen($tag)) . $tag . self::ITEM_SUFFIX;
        }
        $rid = self::nextRequestId(self::requestId($this->siteId, 'item', $code . '-' . $acct), $l);
        $r = $c->create('Item', $body, $rid);
        if (!$r['ok']) {
            $this->saveLink('income_item', $key, null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
            return ['ok' => false, 'message' => 'the QuickBooks item for ' . $name . ' could not be made: ' . $r['error']];
        }
        $id = (string)($r['data']['Item']['Id'] ?? '');
        $this->saveLink('income_item', $key, $id, (string)($r['data']['Item']['SyncToken'] ?? ''), $rid, 'sent', null, null);
        return ['ok' => true, 'qbo_id' => $id];
    }

    /** The invoice's own line titles for one account, so the QuickBooks line says what the work was. */
    private function lineText(int $invoiceId, string $code): string
    {
        $s = $this->db->prepare('SELECT COALESCE(NULLIF(title, \'\'), description) FROM invoice_line_items WHERE invoice_id = ? ORDER BY sort_order, id LIMIT 6');
        $s->execute([$invoiceId]);
        $t = array_values(array_filter(array_map(fn($v) => trim((string)$v), $s->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        return $t ? implode('; ', $t) : 'Account ' . $code;
    }

    // ─────────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────────
    // Automatic sending (owner switch: ops_settings qbo_auto_send = 1, qbo_auto_from = Y-m-d)
    // ─────────────────────────────────────────────────────────────────────────

    public const AUTO_BATCH = 20;

    /** @return array{on:bool, from:?string} */
    public function auto(): array
    {
        $from = $this->setting('qbo_auto_from');
        return ['on' => $this->setting('qbo_auto_send') === '1' && $from !== null, 'from' => $from];
    }

    /** The owner turns automatic sending on (from a date: history is not dumped in) or off. */
    public function setAuto(bool $on, ?string $from): bool
    {
        if ($on && ($from === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from))) return false;
        $this->saveSetting('qbo_auto_send', $on ? '1' : '0', 'QuickBooks: send issued invoices and their payments by themselves (QboSyncService::runQueue)');
        if ($on) $this->saveSetting('qbo_auto_from', $from, 'QuickBooks: only invoices issued on or after this date are sent automatically');
        return true;
    }

    /**
     * What the next run sends: issued invoices on/after the start date not yet in QuickBooks (a held
     * one only once it has changed since), and invoices already there with a payment not yet sent.
     * @return list<int> invoice ids
     */
    public function due(int $limit = self::AUTO_BATCH): array
    {
        $c = $this->conn->company();
        $a = $this->auto();
        if (!$c || !$a['on']) return [];
        $realm = $c['realm_id'];
        $s = $this->db->prepare("SELECT i.id FROM invoices i
            LEFT JOIN qbo_links l ON l.site_id = ? AND l.realm_id = ? AND l.entity_type = 'invoice' AND l.entity_id = i.id
            WHERE LOWER(COALESCE(i.status, '')) NOT IN ('draft', 'cancelled', 'canceled', 'void', '')
              AND COALESCE(i.issue_date, i.invoice_date) >= ?
              AND (l.id IS NULL OR (l.qbo_id IS NULL AND (l.status <> 'held' OR COALESCE(l.sync_token, '') <> COALESCE(i.updated_at, ''))))
            ORDER BY i.id LIMIT " . max(1, min(100, $limit)));
        $s->execute([$this->siteId, $realm, $a['from']]);
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
        // Payments arrived after the invoice was sent (hand-recorded or Stripe).
        $p = $this->db->prepare("SELECT x.invoice_id, x.amt, lp.status AS lstatus, lp.sync_token AS ltoken FROM (
                SELECT invoice_id, CONCAT('alloc:', id) AS k, amount AS amt FROM invoice_payment_allocations WHERE amount > 0
                UNION ALL SELECT invoice_id, CONCAT('stripe:', id), amount_cents / 100 FROM stripe_payments WHERE status = 'succeeded') x
            JOIN invoices iv ON iv.id = x.invoice_id AND LOWER(COALESCE(iv.status, '')) NOT IN ('draft', 'cancelled', 'canceled', 'void', '')
            JOIN qbo_links li ON li.site_id = ? AND li.realm_id = ? AND li.entity_type = 'invoice' AND li.entity_id = x.invoice_id AND li.qbo_id IS NOT NULL AND li.status = 'sent'
            LEFT JOIN qbo_links lp ON lp.site_id = li.site_id AND lp.realm_id = li.realm_id AND lp.entity_type = 'payment' AND lp.entity_id = x.k
            WHERE lp.qbo_id IS NULL
            LIMIT 500");
        $p->execute([$this->siteId, $realm]);
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            // A held payment comes back only when its amount has changed (compared here, not in SQL:
            // number formats differ between databases).
            if ($r['lstatus'] === 'held' && (string)$r['ltoken'] === number_format((float)$r['amt'], 2, '.', '')) continue;
            $ids[] = (int)$r['invoice_id'];
        }
        return array_slice(array_values(array_unique($ids)), 0, $limit);
    }

    /**
     * One automatic run (Penny's 15-minute job). Stops at the first sign-in problem.
     * @return array{sent:int, held:int, failed:int, notes:list<string>, stopped:?string}
     */
    public function runQueue(int $limit = self::AUTO_BATCH): array
    {
        $out = ['sent' => 0, 'held' => 0, 'failed' => 0, 'notes' => [], 'stopped' => null];
        if (!$this->auto()['on']) return $out;
        if (($g = $this->guard()) !== null) { $out['stopped'] = $g; return $out; }
        foreach ($this->due($limit) as $id) {
            $r = $this->sendInvoice($id);
            if (!empty($r['invoice_qbo_id']) && $r['ok']) { $out['sent']++; continue; }
            if (strpos($r['message'], 'Not connected') !== false || strpos($r['message'], 'Connect again') !== false) {
                $out['stopped'] = $r['message'];
                break;
            }
            $l = $this->link('invoice', (string)$id);
            if (!empty($l['qbo_id'])) {
                // In QuickBooks already (voided / changed since, or totals differ): never overwrite its link.
                if (count($out['notes']) < 5) $out['notes'][] = $r['message'];
                continue;
            }
            if (empty($r['invoice_qbo_id']) && ($this->isHold($r['message']) || ($l['status'] ?? '') === 'refused')) {
                // Held for the owner (QuickBooks said no, or it can't go as lines × one tax): not tried
                // again every 15 minutes, only once the invoice changes. The last request id is kept, so
                // the next try gets a fresh one (nextRequestId).
                $u = $this->db->prepare('SELECT updated_at FROM invoices WHERE id = ?');
                $u->execute([$id]);
                $this->saveLink('invoice', (string)$id, null, (string)($u->fetchColumn() ?: ''),
                                (string)($l['request_id'] ?? self::requestId($this->siteId, 'inv', (string)$id)), 'held', null, $r['message']);
                $out['held']++;
            } else {
                $out['failed']++;
            }
            if (count($out['notes']) < 5) $out['notes'][] = $r['message'];
        }
        return $out;
    }

    /** Pure: a message that means "a person has to look at this invoice" (not a passing failure). */
    public function isHold(string $msg): bool
    {
        foreach (['was not sent: its', 'map Ittai account', 'Pick the QuickBooks tax code', 'has no amount', 'only issued invoices'] as $k) {
            if (strpos($msg, $k) !== false) return true;
        }
        return false;
    }

    /**
     * For the page: invoices held, failed, totals that differ, and invoices changed in Ittai after they
     * were sent (QuickBooks is not edited by Ittai yet — the owner fixes those there).
     */
    public function problems(int $limit = 25): array
    {
        $c = $this->conn->company();
        if (!$c) return [];
        $s = $this->db->prepare("SELECT l.status, l.message, l.qbo_total, i.invoice_number, i.status AS inv_status,
                                        COALESCE(NULLIF(i.total, 0), i.total_amount) AS ittai_total
                                 FROM qbo_links l JOIN invoices i ON i.id = l.entity_id
                                 WHERE l.site_id = ? AND l.realm_id = ? AND l.entity_type = 'invoice'
                                   AND (l.status IN ('held', 'refused', 'error', 'mismatch')
                                        OR (l.qbo_id IS NOT NULL AND (ABS(COALESCE(NULLIF(i.total, 0), i.total_amount) - l.qbo_total) >= 0.005
                                            OR LOWER(COALESCE(i.status, '')) IN ('void', 'cancelled', 'canceled'))))
                                 ORDER BY l.id DESC LIMIT " . max(1, min(100, $limit)));
        $s->execute([$this->siteId, $c['realm_id']]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            if (in_array($r['status'], ['sent', 'mismatch'], true) && in_array(strtolower((string)$r['inv_status']), ['void', 'cancelled', 'canceled'], true)) {
                $why = 'Voided in Ittai after it was sent — void it in QuickBooks too.';
            } elseif ($r['status'] === 'sent') {
                $why = sprintf('Changed in Ittai after it was sent ($%s now, $%s in QuickBooks) — update it in QuickBooks.',
                               number_format((float)$r['ittai_total'], 2), number_format((float)$r['qbo_total'], 2));
            } else {
                $why = (string)$r['message'];
            }
            $out[] = ['invoice' => (string)$r['invoice_number'], 'status' => (string)$r['status'], 'why' => $why];
        }
        $h = $this->db->prepare("SELECT p.entity_id, p.message, p.sync_token FROM qbo_links p
                                 WHERE p.site_id = ? AND p.realm_id = ? AND p.entity_type = 'payment' AND p.qbo_id IS NULL AND p.status IN ('held', 'refused', 'error')
                                 ORDER BY p.id DESC LIMIT 10");
        $h->execute([$this->siteId, $c['realm_id']]);
        foreach ($h->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = ['invoice' => 'Payment ' . $r['entity_id'] . ($r['sync_token'] ? ' ($' . $r['sync_token'] . ')' : ''), 'status' => 'held',
                      'why' => (string)$r['message'] . ' — check the payments on that invoice.'];
        }
        return $out;
    }

    private function saveSetting(string $key, ?string $value, string $desc): void
    {
        $u = $this->db->prepare('UPDATE ops_settings SET setting_value = ? WHERE setting_key = ?');
        $u->execute([$value, $key]);
        if ($u->rowCount() === 0 && $this->setting($key) === null) {
            $this->db->prepare('INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)')->execute([$key, $value, $desc]);
        }
    }

    /** What was sent, newest first (for the page). */
    public function recent(int $limit = 20): array
    {
        $c = $this->conn->company();
        if (!$c) return [];
        $s = $this->db->prepare("SELECT l.entity_type, l.entity_id, l.qbo_id, l.status, l.qbo_total, l.message, l.sent_at, i.invoice_number
                                 FROM qbo_links l LEFT JOIN invoices i ON l.entity_type = 'invoice' AND i.id = l.entity_id
                                 WHERE l.site_id = ? AND l.realm_id = ? AND l.entity_type IN ('invoice', 'payment') ORDER BY l.id DESC LIMIT " . max(1, min(100, $limit)));
        $s->execute([$this->siteId, $c['realm_id']]);
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function link(string $type, string $id): ?array
    {
        $c = $this->conn->company();
        if (!$c) return null;
        $s = $this->db->prepare('SELECT * FROM qbo_links WHERE site_id = ? AND realm_id = ? AND entity_type = ? AND entity_id = ?');
        $s->execute([$this->siteId, $c['realm_id'], $type, $id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function saveLink(string $type, string $id, ?string $qboId, ?string $token, string $rid, string $status, ?float $total, ?string $msg): void
    {
        $c = $this->conn->company();
        if (!$c) return;
        $now = date('Y-m-d H:i:s', ($this->clock)());
        $vals = [$qboId ?: null, $token ?: null, $rid, $status, $total, $msg !== null ? mb_substr($msg, 0, 500) : null, $now];
        $u = $this->db->prepare('UPDATE qbo_links SET qbo_id = ?, sync_token = ?, request_id = ?, status = ?, qbo_total = ?, message = ?, sent_at = ?
                                 WHERE site_id = ? AND realm_id = ? AND entity_type = ? AND entity_id = ?');
        $u->execute(array_merge($vals, [$this->siteId, $c['realm_id'], $type, $id]));
        if ($u->rowCount() === 0 && !$this->link($type, $id)) {
            $this->db->prepare('INSERT INTO qbo_links (qbo_id, sync_token, request_id, status, qbo_total, message, sent_at, site_id, realm_id, entity_type, entity_id)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                     ->execute(array_merge($vals, [$this->siteId, $c['realm_id'], $type, $id]));
        }
    }

    private function setting(string $key): ?string
    {
        try {
            $s = $this->db->prepare('SELECT setting_value FROM ops_settings WHERE setting_key = ?');
            $s->execute([$key]);
            $v = $s->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (Throwable $e) {
            return null;
        }
    }
}
