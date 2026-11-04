<?php
/**
 * QboExpenseSyncService — QuickBooks Online, step 3 (plan approved 2026-10-20): an approved Ittai receipt
 * goes to QuickBooks as a Purchase, with its supplier and its photo attached.
 *
 * Sent the way Ittai BOOKED it: the receipt's posted journal entry (source_type 'expense') decides
 * everything, so QuickBooks agrees with Ittai's ledger to the cent:
 *   the one CREDIT line   = what it was paid from (1010 chequing, 2400 card) → the mapped QuickBooks
 *                           Bank / Credit Card account (owner's 'pay_account' mapping);
 *   DEBIT expense / 1500  = the Purchase lines (account mapping), one per account;
 *   DEBIT 2210            = GST claimed → the mapped GST code on every line (TaxExcluded), checked to be
 *                           the rate × the lines before anything is created.
 * Proven 2026-10-20: a Purchase from Chequing with the bank line's date, amount and payee was offered by
 * the QuickBooks bank feed as "1 match found" — matched, never added twice.
 *
 * Held for the owner (never sent wrong), listed with the reason:
 *   paid personally (credit 130x, Due from Shareholder) · GST not the full rate (meals at 50%, mixed lines)
 *   · a line on any other kind of account (revenue, shareholder) · not posted in Ittai yet · lines that
 *   don't add up to what was paid.
 *
 * Off until the owner switches receipts on (ops_settings qbo_auto_receipts = 1, same start date as
 * invoices). Same request-id, lock, held and live-company rules as QboSyncService.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/QboSyncService.php';

class QboExpenseSyncService extends QboSyncService
{
    public const GST_CLAIMED = '2210';
    /** BC PST, only to recognise a receipt whose PST was left off (a hint for the owner, never booked). */
    public const BC_PST_RATE = 0.07;
    /** @var callable(int $mediaId): ?string  absolute path of a receipt photo */
    private $media;

    public function __construct(QboConnectionService $conn, ?callable $clock = null, ?callable $media = null)
    {
        parent::__construct($conn, null, $clock);
        $this->media = $media ?? function (int $mediaId) {
            if (!function_exists('resolveMediaFilePath')) require_once dirname(__DIR__, 3) . '/Services/Receipts/ReceiptService.php';
            return resolveMediaFilePath($mediaId, $this->db);
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The posted journal lines → what goes to QuickBooks, or why it is held.
     * BC PST is booked INSIDE the expense debit (journal_lines.pst_amount, live: 77 receipts in 2026) and
     * is a cost GST is not charged on: each account gives a taxable part (debit − PST, GST code) and a PST
     * part (exempt code), so QuickBooks' total is what was paid to the cent.
     * @param list<array{code:string, type:string, debit:float, credit:float, pst?:float}> $lines
     * @return array{hold:?string, pay?:string, lines?:list<array{code:string, amount:float, pst:float}>, gst?:float, total?:float}
     */
    public static function translate(array $lines, float $rate = self::GST_RATE): array
    {
        // A refund moves money back INTO the bank / card (a debit there): not a purchase.
        foreach ($lines as $l) {
            if (round((float)$l['debit'], 2) > 0 && $l['type'] !== 'expense' && !in_array((string)$l['code'], [self::GST_CLAIMED, '1500'], true)
                && !preg_match('/^130\d$/', (string)$l['code']) && in_array($l['type'], ['asset', 'liability'], true)
                && array_filter($lines, fn($x) => round((float)$x['credit'], 2) > 0 && $x['type'] === 'expense')) {
                return ['hold' => 'it is a refund (money back from the supplier) — enter it in QuickBooks as a supplier credit by hand.'];
            }
        }
        $credits = array_values(array_filter($lines, fn($l) => round((float)$l['credit'], 2) > 0));
        if (count($credits) !== 1) return ['hold' => 'it was paid from ' . count($credits) . ' accounts in Ittai — enter it in QuickBooks by hand.'];
        $pay = (string)$credits[0]['code'];
        if (preg_match('/^130\d$/', $pay)) return ['hold' => 'it was paid personally (Due from Shareholder) — that needs the owner\'s account in QuickBooks; enter it by hand for now.'];
        $total = round((float)$credits[0]['credit'], 2);
        $by = []; $pst = []; $gst = 0.0;
        foreach ($lines as $l) {
            $d = round((float)$l['debit'], 2);
            if ($d <= 0) continue;
            $code = (string)$l['code'];
            if ($code === self::GST_CLAIMED) { $gst += $d; continue; }
            if ($l['type'] !== 'expense' && $code !== '1500') {
                return ['hold' => 'it is booked to ' . $code . ' (' . $l['type'] . ') in Ittai, not an expense — check it in Ittai first.'];
            }
            $p = min($d, max(0.0, round((float)($l['pst'] ?? 0), 2)));
            $by[$code] = round(($by[$code] ?? 0) + $d - $p, 2);
            $pst[$code] = round(($pst[$code] ?? 0) + $p, 2);
        }
        if (!$by) return ['hold' => 'it has no expense lines in Ittai.'];
        $net = round(array_sum($by), 2);
        $pstAll = round(array_sum($pst), 2);
        if (!self::sameMoney($net + $pstAll + $gst, $total)) {
            return ['hold' => sprintf('its lines ($%s + GST $%s) do not add up to what was paid ($%s).', number_format($net + $pstAll, 2), number_format($gst, 2), number_format($total, 2))];
        }
        if ($gst > 0 && abs(round($net * $rate, 2) - $gst) > 0.011) {
            // PST left off the receipt in Ittai (live 2026-10-20: 8 receipts): GST fits a smaller base and
            // what is left over is 7% of it. Said as the data fix it is, not as "mixed lines".
            // If the line is goods + 7% PST, the goods are net ÷ 1.07 — and their GST must be the claim.
            $base = round($net / (1 + self::BC_PST_RATE), 2);
            $left = round($net - $base, 2);
            if ($pstAll <= 0 && $left > 0 && abs(round($base * $rate, 2) - $gst) <= 0.011) {
                return ['hold' => sprintf('PST seems to be missing: the $%s left is %s%% of $%s. Add the PST on the receipt in Ittai and it will go.',
                                          number_format($left, 2), rtrim(rtrim(number_format(self::BC_PST_RATE * 100, 2), '0'), '.'), number_format($base, 2))];
            }
            return ['hold' => sprintf('its GST claim ($%s) is not %s%% of $%s — meals (50%% claim) or mixed lines. Enter it in QuickBooks by hand.',
                                      number_format($gst, 2), rtrim(rtrim(number_format($rate * 100, 2), '0'), '.'), number_format($net, 2))];
        }
        $out = [];
        foreach ($by as $code => $amt) $out[] = ['code' => (string)$code, 'amount' => $amt, 'pst' => $pst[$code] ?? 0.0];
        return ['hold' => null, 'pay' => $pay, 'lines' => $out, 'gst' => round($gst, 2), 'total' => $total];
    }

    /**
     * @param list<array{account:string, amount:float, pst?:float, description:string}> $lines
     */
    public static function purchaseBody(string $payAccountId, string $payAccountType, string $vendorId, string $date, array $lines, string $taxCodeId, string $note, ?string $exemptCodeId = null): array
    {
        $out = [];
        $line = fn(string $acct, float $amt, string $desc, string $code) => ['DetailType' => 'AccountBasedExpenseLineDetail', 'Amount' => round($amt, 2),
            'Description' => mb_substr($desc, 0, 4000),
            'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => $acct], 'TaxCodeRef' => ['value' => $code]]];
        foreach ($lines as $l) {
            if (round((float)$l['amount'], 2) > 0) $out[] = $line($l['account'], (float)$l['amount'], $l['description'], $taxCodeId);
            if (round((float)($l['pst'] ?? 0), 2) > 0) {
                // PST: a cost on the same account, no GST on it (BC PST is not recoverable).
                $out[] = $line($l['account'], (float)$l['pst'], 'PST — ' . $l['description'], $exemptCodeId ?? $taxCodeId);
            }
        }
        return [
            'PaymentType' => $payAccountType === 'Credit Card' ? 'CreditCard' : 'Cash',   // debit / e-transfer from a bank = Cash
            'AccountRef' => ['value' => $payAccountId],
            'TxnDate' => substr($date, 0, 10),
            'EntityRef' => ['value' => $vendorId, 'type' => 'Vendor'],
            'GlobalTaxCalculation' => 'TaxExcluded',
            'PrivateNote' => mb_substr($note, 0, 4000),
            'Line' => $out,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Sending
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{ok:bool, message:string, purchase_qbo_id?:string} */
    public function sendExpense(int $expenseId): array
    {
        if (($g = $this->guard()) !== null) return ['ok' => false, 'message' => $g];
        if (!$this->lock()) return ['ok' => false, 'message' => 'Another QuickBooks send is running. Try again in a minute.'];
        try {
            return $this->sendExpenseLocked($expenseId);
        } finally {
            $this->unlock();
        }
    }

    private function sendExpenseLocked(int $expenseId): array
    {
        $s = $this->db->prepare("SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?");
        $s->execute([$expenseId]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) return ['ok' => false, 'message' => 'No receipt #' . $expenseId . '.'];
        $label = 'Receipt #' . $expenseId;
        $existing = $this->link('expense', (string)$expenseId);
        if ($existing && $existing['qbo_id']) return ['ok' => true, 'message' => $label . ' was already in QuickBooks.', 'purchase_qbo_id' => (string)$existing['qbo_id']];
        if (!in_array((string)$e['status'], ['approved', 'forwarded'], true)) return ['ok' => false, 'message' => $label . ' is not approved — only approved receipts go to QuickBooks.'];

        $j = $this->db->prepare("SELECT je.id, je.entry_date, c.code, c.type, jl.debit, jl.credit, jl.description, jl.pst_amount
            FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id JOIN chart_of_accounts c ON c.id = jl.account_id
            WHERE je.source_type = 'expense' AND je.source_id = ? AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL ORDER BY je.id DESC, jl.id");
        $j->execute([$expenseId]);
        $rows = $j->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return ['ok' => false, 'message' => $label . ' was not sent: it is not in Ittai\'s books yet (the nightly update posts it).'];
        $entryId = $rows[0]['id'];
        $rows = array_values(array_filter($rows, fn($r) => $r['id'] === $entryId));    // the live entry only
        $t = self::translate(array_map(fn($r) => ['code' => (string)$r['code'], 'type' => (string)$r['type'], 'debit' => (float)$r['debit'], 'credit' => (float)$r['credit'],
                                                  'pst' => (float)($r['pst_amount'] ?? 0)], $rows),
                             $this->rate());
        if ($t['hold'] !== null) return ['ok' => false, 'message' => $label . ' was not sent: ' . $t['hold']];

        $maps = $this->conn->mappings();
        $pay = $maps['pay_account'][$t['pay']] ?? null;
        if (!$pay) return ['ok' => false, 'message' => $label . ' was not sent: map Ittai account ' . $t['pay'] . ' (what it was paid from) to a QuickBooks bank or card account first (QuickBooks page → Paid from).'];
        $taxKey = $t['gst'] > 0 ? 'gst' : 'exempt';
        $taxCode = $maps['tax_code'][$taxKey]['qbo_id'] ?? null;
        $exempt = $maps['tax_code']['exempt']['qbo_id'] ?? null;
        $hasPst = array_sum(array_column($t['lines'], 'pst')) > 0;
        if (!$taxCode || ($hasPst && !$exempt)) {
            $k = !$taxCode ? $taxKey : 'exempt';
            return ['ok' => false, 'message' => $label . ' was not sent: pick the QuickBooks tax code for "' . QboConnectionService::TAX_KEYS[$k] . '" first.'];
        }
        $qLines = [];
        foreach ($t['lines'] as $l) {
            $acct = $maps['account'][$l['code']]['qbo_id'] ?? null;
            if (!$acct) return ['ok' => false, 'message' => $label . ' was not sent: map Ittai account ' . $l['code'] . ' to a QuickBooks account first (QuickBooks page → Accounts).'];
            $qLines[] = ['account' => (string)$acct, 'amount' => $l['amount'], 'pst' => $l['pst'], 'description' => trim((string)($e['description'] ?: $e['accounting_category'] ?: 'Receipt'))];
        }

        $vendor = $this->ensureVendor($e);
        if (!$vendor['ok']) return ['ok' => false, 'message' => $label . ': ' . $vendor['message']];

        $payType = $this->accountType((string)$pay['qbo_id']);
        // The receipt's own date (what the bank line shows), not when it was posted (Codex review 2026-10-20).
        $date = (string)($e['expense_date'] ?: $rows[0]['entry_date']);
        $body = self::purchaseBody((string)$pay['qbo_id'], $payType, $vendor['qbo_id'], $date, $qLines, (string)$taxCode,
                                   'From Ittai, receipt #' . $expenseId . ($e['vendor_name_raw'] ? ' — ' . $e['vendor_name_raw'] : ''), $exempt ? (string)$exempt : null);
        $rid = self::nextRequestId(self::requestId($this->siteId, 'exp', (string)$expenseId), $existing);
        $r = $this->conn->client()->create('Purchase', $body, $rid);
        if (!$r['ok']) {
            $this->saveLink('expense', (string)$expenseId, null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
            return ['ok' => false, 'message' => $label . ' was not sent: ' . $r['error']];
        }
        $q = (array)($r['data']['Purchase'] ?? []);
        $qTotal = round((float)($q['TotalAmt'] ?? 0), 2);
        $match = self::sameMoney($qTotal, $t['total']);
        $note = $match ? null : sprintf('QuickBooks total $%s vs Ittai $%s — check the tax code.', number_format($qTotal, 2), number_format($t['total'], 2));
        $this->saveLink('expense', (string)$expenseId, (string)($q['Id'] ?? ''), (string)($q['SyncToken'] ?? ''), $rid, $match ? 'sent' : 'mismatch', $qTotal, $note);
        $att = $this->attachPhoto($e, (string)($q['Id'] ?? ''));
        return ['ok' => $match, 'message' => $label . ' sent to QuickBooks' . ($match ? ' — $' . number_format($qTotal, 2) . ' matches.' : '. ' . $note) . $att,
                'purchase_qbo_id' => (string)($q['Id'] ?? '')];
    }

    /** The receipt photo on the Purchase (best effort: a failed upload is said, the Purchase stays). */
    private function attachPhoto(array $e, string $purchaseId): string
    {
        if ($purchaseId === '' || empty($e['receipt_media_id'])) return '';
        $done = $this->link('attachment', 'exp:' . $e['id']);
        if ($done && $done['qbo_id']) return '';
        $path = ($this->media)((int)$e['receipt_media_id']);
        if (!$path || !is_readable($path) || filesize($path) > 20 * 1024 * 1024) return ' The photo could not be attached (file not found).';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/tiff' => 'tif', 'application/pdf' => 'pdf'];
        $mime = (string)(@mime_content_type($path) ?: '');
        if (!isset($ext[$mime])) return ' The photo was not attached: QuickBooks does not take ' . ($mime ?: 'that file type') . ' — attach it there by hand.';
        $name = 'receipt-' . (int)$e['id'] . '.' . $ext[$mime];
        $r = $this->conn->client()->attach('Purchase', $purchaseId, $name, $mime, (string)file_get_contents($path));
        $this->saveLink('attachment', 'exp:' . $e['id'], $r['ok'] ? (string)($r['data']['Id'] ?? '') : null, null, 'upload', $r['ok'] ? 'sent' : 'error', null, $r['ok'] ? null : $r['error']);
        return $r['ok'] ? ' Photo attached.' : ' The photo was not attached: ' . $r['error'];
    }

    /** @return array{ok:bool, qbo_id?:string, message?:string} */
    private function ensureVendor(array $e): array
    {
        $name = self::noColonName((string)($e['vendor_name'] ?: $e['vendor_name_raw'] ?: 'Unknown supplier'));
        $key = !empty($e['vendor_id']) ? 'vendor:' . (int)$e['vendor_id'] : 'raw:' . substr(sha1(strtolower($name)), 0, 20);
        $l = $this->link('vendor', $key);
        if ($l && $l['qbo_id']) return ['ok' => true, 'qbo_id' => (string)$l['qbo_id']];
        $c = $this->conn->client();
        $found = $c->query('SELECT Id FROM Vendor WHERE DisplayName = ' . QboClient::quote($name));
        if (!$found['ok']) return ['ok' => false, 'message' => $found['error']];
        $hit = $found['data']['QueryResponse']['Vendor'][0]['Id'] ?? null;
        if ($hit) {
            $this->saveLink('vendor', $key, (string)$hit, null, 'found', 'sent', null, 'matched by name');
            return ['ok' => true, 'qbo_id' => (string)$hit];
        }
        $rid = self::nextRequestId(self::requestId($this->siteId, 'vend', str_replace(':', '', $key)), $l);
        $r = $c->create('Vendor', ['DisplayName' => $name], $rid);
        if (!$r['ok'] && ($r['code'] ?? '') === self::DUPLICATE_NAME) {
            // The name is a customer's (or an employee's) in QuickBooks: names are unique across all three.
            $r = $c->create('Vendor', ['DisplayName' => mb_substr($name, 0, 89) . ' (supplier)'], $rid . '-s');
        }
        if (!$r['ok']) {
            $this->saveLink('vendor', $key, null, null, $rid, !empty($r['refused']) ? 'refused' : 'error', null, $r['error']);
            return ['ok' => false, 'message' => 'the supplier could not be made: ' . $r['error']];
        }
        $id = (string)($r['data']['Vendor']['Id'] ?? '');
        $this->saveLink('vendor', $key, $id, (string)($r['data']['Vendor']['SyncToken'] ?? ''), $rid, 'sent', null, null);
        return ['ok' => true, 'qbo_id' => $id];
    }

    /** Pure: a QuickBooks display name (100 characters; a colon would nest it). */
    public static function noColonName(string $n): string
    {
        return mb_substr(self::noColon(trim((string)preg_replace('/\s+/', ' ', $n))), 0, 100);
    }

    private function accountType(string $qboId): string
    {
        $s = $this->db->prepare("SELECT detail FROM qbo_remote_items WHERE site_id = ? AND kind = 'account' AND qbo_id = ?");
        $s->execute([$this->siteId, $qboId]);
        return trim(explode(' ·', (string)$s->fetchColumn())[0]);
    }

    private function rate(): float
    {
        return self::rateOf($this->setting('qbo_purchase_tax_rate') ?? self::GST_RATE);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Automatic (same start date as invoices; its own switch)
    // ─────────────────────────────────────────────────────────────────────────

    public function receiptsOn(): bool
    {
        return $this->auto()['on'] && $this->setting('qbo_auto_receipts') === '1';
    }

    public function setReceipts(bool $on): void
    {
        $this->saveSetting('qbo_auto_receipts', $on ? '1' : '0', 'QuickBooks: send approved receipts as expenses too (QboExpenseSyncService)');
    }

    /** Approved, posted receipts on/after the start date not yet in QuickBooks (a held one only once changed). @return list<int> */
    public function dueExpenses(int $limit = self::AUTO_BATCH): array
    {
        $c = $this->conn->company();
        if (!$c || !$this->receiptsOn()) return [];
        $s = $this->db->prepare("SELECT e.id FROM expenses e
            LEFT JOIN qbo_links l ON l.site_id = ? AND l.realm_id = ? AND l.entity_type = 'expense' AND l.entity_id = e.id
            WHERE e.status IN ('approved', 'forwarded') AND e.expense_date >= ?
              AND EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'expense' AND je.source_id = e.id AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL)
              AND (l.id IS NULL OR (l.qbo_id IS NULL AND (l.status <> 'held' OR COALESCE(l.sync_token, '') <> COALESCE(e.updated_at, ''))))
            ORDER BY e.expense_date, e.id LIMIT " . max(1, min(100, $limit)));
        $s->execute([$this->siteId, $c['realm_id'], $this->auto()['from']]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @return array{sent:int, held:int, failed:int, notes:list<string>, stopped:?string} */
    public function runExpenses(int $limit = self::AUTO_BATCH): array
    {
        $out = ['sent' => 0, 'held' => 0, 'failed' => 0, 'notes' => [], 'stopped' => null];
        if (!$this->receiptsOn()) return $out;
        if (($g = $this->guard()) !== null) { $out['stopped'] = $g; return $out; }
        foreach ($this->dueExpenses($limit) as $id) {
            $r = $this->sendExpense($id);
            if (!empty($r['purchase_qbo_id'])) { $r['ok'] ? $out['sent']++ : $out['failed']++; continue; }
            if (strpos($r['message'], 'Not connected') !== false || strpos($r['message'], 'Connect again') !== false) { $out['stopped'] = $r['message']; break; }
            $l = $this->link('expense', (string)$id);
            if (strpos($r['message'], 'was not sent:') !== false || ($l['status'] ?? '') === 'refused') {
                $u = $this->db->prepare('SELECT updated_at FROM expenses WHERE id = ?');
                $u->execute([$id]);
                $this->saveLink('expense', (string)$id, null, (string)($u->fetchColumn() ?: ''),
                                (string)($l['request_id'] ?? self::requestId($this->siteId, 'exp', (string)$id)), 'held', null, $r['message']);
                $out['held']++;
            } else {
                $out['failed']++;
            }
            if (count($out['notes']) < 5) $out['notes'][] = $r['message'];
        }
        return $out;
    }

    /** Held / failed receipts, and sent ones changed or reversed in Ittai since (Codex review 2026-10-20). */
    public function expenseProblems(int $limit = 25): array
    {
        $c = $this->conn->company();
        if (!$c) return [];
        $s = $this->db->prepare("SELECT l.entity_id, l.status, l.message, l.qbo_id, l.qbo_total,
                (SELECT SUM(jl.credit) FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id
                  WHERE je.source_type = 'expense' AND je.source_id = l.entity_id AND je.status = 'posted' AND je.reversed_by_entry_id IS NULL) AS now_total
            FROM qbo_links l
            WHERE l.site_id = ? AND l.realm_id = ? AND l.entity_type = 'expense'
            ORDER BY l.id DESC LIMIT 300");
        $s->execute([$this->siteId, $c['realm_id']]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $label = 'Receipt #' . $r['entity_id'];
            if (in_array($r['status'], ['held', 'refused', 'error', 'mismatch'], true)) {
                $out[] = ['invoice' => $label, 'status' => $r['status'], 'why' => (string)$r['message']];
            } elseif ($r['qbo_id'] && $r['now_total'] === null) {
                $out[] = ['invoice' => $label, 'status' => 'changed', 'why' => 'Taken out of Ittai\'s books after it was sent — delete or void the expense in QuickBooks too.'];
            } elseif ($r['qbo_id'] && !self::sameMoney((float)$r['now_total'], (float)$r['qbo_total'])) {
                $out[] = ['invoice' => $label, 'status' => 'changed', 'why' => sprintf('Changed in Ittai after it was sent ($%s now, $%s in QuickBooks) — update it in QuickBooks.',
                                                                                  number_format((float)$r['now_total'], 2), number_format((float)$r['qbo_total'], 2))];
            }
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
