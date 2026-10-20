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
    public const ITEM_PREFIX = 'Ittai: ';

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
        if (!$prev || ($prev['status'] ?? '') !== 'refused') return $prev['request_id'] ?? $base;
        $n = preg_match('/-r(\d+)$/', (string)$prev['request_id'], $m) ? (int)$m[1] + 1 : 1;
        return $base . '-r' . $n;
    }

    /** QuickBooks DisplayName: 100 characters, no tabs/newlines, never blank. */
    public static function displayName(?string $client, ?string $billTo, int $invoiceId): string
    {
        $n = trim((string)preg_replace('/\s+/', ' ', (string)($client ?: $billTo)));
        return mb_substr($n !== '' ? $n : 'Ittai customer (invoice ' . $invoiceId . ')', 0, 100);
    }

    public static function customerBody(string $name, ?string $email): array
    {
        $b = ['DisplayName' => $name];
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) $b['PrimaryEmailAddr'] = ['Address' => $email];
        return $b;
    }

    public static function itemBody(string $accountName, string $qboAccountId): array
    {
        return ['Name' => mb_substr(self::ITEM_PREFIX . $accountName, 0, 100), 'Type' => 'Service', 'IncomeAccountRef' => ['value' => $qboAccountId]];
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
        $s = $this->db->prepare('SELECT i.*, c.display_name AS client_name, c.billing_email AS client_email
                                 FROM invoices i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?');
        $s->execute([$invoiceId]);
        $inv = $s->fetch(PDO::FETCH_ASSOC);
        if (!$inv) return ['ok' => false, 'message' => 'No invoice #' . $invoiceId . '.'];
        $status = strtolower(trim((string)$inv['status']));
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
        // The invoice's own rate (invoices.tax_rate, a percent) — tenant-neutral: 13% HST in Ontario, 5% GST in BC.
        $rate = (float)($inv['tax_rate'] ?? 0) > 0 ? (float)$inv['tax_rate'] / 100 : self::GST_RATE;
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
    private function sendPayments(array $inv, string $invoiceQboId, float $invoiceTotal): array
    {
        $cust = $this->link('client', $this->customerKey($inv));
        if (!$cust || !$cust['qbo_id'] || $invoiceQboId === '') return ['sent' => 0, 'say' => ''];
        $s = $this->db->prepare('SELECT id, amount, payment_date, created_at, reference FROM invoice_payment_allocations WHERE invoice_id = ? AND amount > 0 ORDER BY id');
        $s->execute([(int)$inv['id']]);
        $sent = 0; $failed = []; $held = 0;
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        // What is already applied in QuickBooks: a payment is never sent past the invoice's total.
        $applied = 0.0;
        foreach ($rows as $p) {
            $l = $this->link('payment', (string)$p['id']);
            if ($l && $l['qbo_id']) $applied += round((float)$l['qbo_total'], 2);
        }
        foreach ($rows as $p) {
            $l = $this->link('payment', (string)$p['id']);
            if ($l && $l['qbo_id']) continue;
            if ($applied + round((float)$p['amount'], 2) > round($invoiceTotal, 2) + 0.005) { $held++; continue; }
            $rid = self::nextRequestId(self::requestId($this->siteId, 'pay', (string)$p['id']), $l);
            $date = (string)($p['payment_date'] ?: substr((string)$p['created_at'], 0, 10));
            $r = $this->conn->client()->create('Payment', self::paymentBody((string)$cust['qbo_id'], $invoiceQboId, (float)$p['amount'], $date, $p['reference'] ?? null), $rid);
            if ($r['ok']) {
                $q = (array)($r['data']['Payment'] ?? []);
                $this->saveLink('payment', (string)$p['id'], (string)($q['Id'] ?? ''), (string)($q['SyncToken'] ?? ''), $rid, 'sent', round((float)($q['TotalAmt'] ?? 0), 2), null);
                $applied += round((float)$p['amount'], 2);
                $sent++;
            } else {
                $this->saveLink('payment', (string)$p['id'], null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
                $failed[] = $r['error'];
            }
        }
        $say = ($sent ? ' ' . $sent . ' payment' . ($sent === 1 ? '' : 's') . ' applied.' : '')
             . ($failed ? ' ' . count($failed) . ' payment(s) not sent: ' . $failed[0] : '')
             . ($held ? ' ' . $held . ' payment(s) held: they would pay more than the invoice.' : '');
        return ['sent' => $sent, 'say' => $say];
    }

    private function customerKey(array $inv): string
    {
        return !empty($inv['client_id']) ? (string)(int)$inv['client_id'] : 'billto:' . substr(sha1(strtolower(trim((string)$inv['bill_to_name']))), 0, 20);
    }

    /** @return array{ok:bool, qbo_id?:string, message?:string} */
    private function ensureCustomer(array $inv): array
    {
        $key = $this->customerKey($inv);
        $l = $this->link('client', $key);
        if ($l && $l['qbo_id']) return ['ok' => true, 'qbo_id' => (string)$l['qbo_id']];
        $name = self::displayName($inv['client_name'] ?? null, $inv['bill_to_name'] ?? null, (int)$inv['id']);
        $c = $this->conn->client();
        // An existing QuickBooks customer with this name is the same client: use it, never a duplicate.
        $found = $c->query('SELECT Id, DisplayName FROM Customer WHERE DisplayName = ' . QboClient::quote($name));
        if (!$found['ok']) return ['ok' => false, 'message' => $found['error']];
        $hit = $found['data']['QueryResponse']['Customer'][0]['Id'] ?? null;
        if ($hit) {
            $this->saveLink('client', $key, (string)$hit, null, 'found', 'sent', null, 'matched by name');
            return ['ok' => true, 'qbo_id' => (string)$hit];
        }
        $email = $inv['client_email'] ?? $inv['sent_to_email'] ?? null;
        $rid = self::nextRequestId(self::requestId($this->siteId, 'cust', $key), $l);
        $r = $c->create('Customer', self::customerBody($name, $email), $rid);
        if (!$r['ok'] && ($r['code'] ?? '') === self::DUPLICATE_NAME) {
            // The name is taken by a supplier or employee in QuickBooks (names are unique across all three).
            $r = $c->create('Customer', self::customerBody(mb_substr($name, 0, 89) . ' (customer)', $email), $rid . '-c');
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
            $body['Name'] = mb_substr(self::ITEM_PREFIX . $name . ' · ' . ($maps['account'][$code]['qbo_name'] ?? $acct), 0, 100);
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
