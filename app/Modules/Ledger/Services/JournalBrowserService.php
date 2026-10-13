<?php
declare(strict_types=1);

/**
 * JournalBrowserService — the Ledger's journal: every entry, filterable by date range, account,
 * source type, kind, user and text; one entry's lines, reversal links and source document
 * (Accountant's Ledger step 1, 2026-10-13).
 *
 * Read-only. Source documents (what journal_entries.source_type/source_id point at, verified against
 * production 2026-10-13):
 *   invoice            invoices.id                    payment       invoices.id (paid with no separate record)
 *   payment_allocation invoice_payment_allocations.id stripe_payment stripe_payments.id
 *   expense            expenses.id (+ receipt photo)  bank_import / bank_deposit  accounting_transactions.id
 *   bank_opening       chart_of_accounts.id           jobber_invoice / jobber_carryover  jobber_invoices.id
 *   client_credit      client_credits.id              adjusting     journal_entries.id (the entry reversed)
 *   fy_opening / opening  the year (signed statements) manual        none
 *
 * Receipt photos use the existing signed links (ReceiptImageLinks, 15 minutes); the caller passes the
 * signing key so this class needs no secret.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class JournalBrowserService
{
    public const PER_PAGE = [50, 100, 200];
    public const KINDS = ['system', 'manual', 'aje', 'rje', 'reversal'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Clean the query string into filters; anything unreadable is dropped. Pure. */
    public static function normalizeFilters(array $q): array
    {
        $date = static function ($v): ?string {
            $v = is_string($v) ? trim($v) : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        };
        $f = [
            'from'    => $date($q['from'] ?? null),
            'to'      => $date($q['to'] ?? null),
            'account' => (isset($q['account']) && preg_match('/^[A-Za-z0-9\-]{1,20}$/', (string)$q['account'])) ? (string)$q['account'] : null,
            'source'  => (isset($q['source']) && preg_match('/^[a-z_]{1,30}$/', (string)$q['source'])) ? (string)$q['source'] : null,
            'kind'    => (isset($q['kind']) && in_array($q['kind'], self::KINDS, true)) ? (string)$q['kind'] : null,
            'user'    => (isset($q['user']) && ctype_digit((string)$q['user']) && (int)$q['user'] > 0) ? (int)$q['user'] : null,
            'q'       => (isset($q['q']) && is_string($q['q']) && trim($q['q']) !== '') ? mb_substr(trim($q['q']), 0, 100) : null,
            'page'    => max(1, (int)($q['page'] ?? 1)),
            'per'     => in_array((int)($q['per'] ?? 0), self::PER_PAGE, true) ? (int)$q['per'] : 50,
        ];
        if ($f['from'] && $f['to'] && $f['from'] > $f['to']) [$f['from'], $f['to']] = [$f['to'], $f['from']];
        return $f;
    }

    /**
     * WHERE clause + parameters for the filters (table alias je). Pure.
     * @return array{0: string, 1: array}
     */
    public static function whereFor(array $f, bool $hasKind = true): array
    {
        $w = ['1 = 1']; $p = [];
        if (!empty($f['from'])) { $w[] = 'je.entry_date >= ?'; $p[] = $f['from']; }
        if (!empty($f['to']))   { $w[] = 'je.entry_date <= ?'; $p[] = $f['to']; }
        if (!empty($f['source'])) { $w[] = 'je.source_type = ?'; $p[] = $f['source']; }
        if (!empty($f['kind']) && $hasKind) { $w[] = 'je.entry_kind = ?'; $p[] = $f['kind']; }
        if (!empty($f['user'])) { $w[] = 'je.created_by = ?'; $p[] = (int)$f['user']; }
        if (!empty($f['account'])) {
            $w[] = 'EXISTS (SELECT 1 FROM journal_lines fl JOIN chart_of_accounts fa ON fa.id = fl.account_id WHERE fl.entry_id = je.id AND fa.code = ?)';
            $p[] = $f['account'];
        }
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$f['q']) . '%';
            $text = '(je.memo LIKE ? OR EXISTS (SELECT 1 FROM journal_lines tl WHERE tl.entry_id = je.id AND tl.description LIKE ?)';
            $p[] = $like; $p[] = $like;
            $qv = ltrim((string)$f['q'], '#');
            if (ctype_digit($qv)) { $text .= ' OR je.id = ? OR je.source_id = ?'; $p[] = (int)$qv; $p[] = (int)$qv; }
            elseif (preg_match('/^-?\d+(\.\d{1,2})?$/', str_replace([',', '$'], '', $qv))) {
                $text .= ' OR EXISTS (SELECT 1 FROM journal_lines al WHERE al.entry_id = je.id AND (al.debit = ? OR al.credit = ?))';
                $amt = abs((float)str_replace([',', '$'], '', $qv)); $p[] = $amt; $p[] = $amt;
            }
            $w[] = $text . ')';
        }
        return [implode(' AND ', $w), $p];
    }

    private function hasKindColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try { $has = $this->db->query("SELECT entry_kind FROM journal_entries LIMIT 1") !== false; }
            catch (Throwable $e) { $has = false; }
        }
        return $has;
    }

    /** A page of entries, newest first, with totals per entry. */
    public function search(array $f): array
    {
        $hasKind = $this->hasKindColumn();
        [$where, $params] = self::whereFor($f, $hasKind);
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM journal_entries je WHERE $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f['page'] - 1) * $f['per'];
        $kindCol = $hasKind ? 'je.entry_kind' : "'system'";
        $sql = "SELECT je.id, je.entry_date, je.memo, je.source_type, je.source_id, $kindCol AS entry_kind, je.status,
                       je.is_adjusting, je.reversed_by_entry_id, je.created_by, je.proposed_by, je.created_at,
                       u.full_name AS created_by_name,
                       (SELECT COALESCE(SUM(l.debit), 0) FROM journal_lines l WHERE l.entry_id = je.id) AS amount,
                       (SELECT COUNT(*) FROM journal_lines l WHERE l.entry_id = je.id) AS line_count,
                       (SELECT GROUP_CONCAT(DISTINCT a.code ORDER BY a.code SEPARATOR ' ') FROM journal_lines l
                          JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.entry_id = je.id) AS accounts
                FROM journal_entries je
                LEFT JOIN users u ON u.id = je.created_by
                WHERE $where
                ORDER BY je.entry_date DESC, je.id DESC
                LIMIT " . (int)$f['per'] . " OFFSET " . (int)$offset;
        $st = $this->db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['display_kind'] = self::displayKind($r);
        unset($r);
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int)ceil($total / $f['per']))];
    }

    /**
     * Every line of every entry matching the filters, in entry then line order, one query, no cap
     * (review 2026-10-13: the CSV stopped at 20,000 entries and ran a query per entry). $each gets one
     * row per line: entry header fields + code, account_name, gifi_code, debit, credit, description,
     * display_kind. On MySQL the result is read unbuffered, so a whole year never sits in memory.
     * @return int lines passed to $each
     */
    public function streamLines(array $f, callable $each): int
    {
        $hasKind = $this->hasKindColumn();
        [$where, $params] = self::whereFor($f, $hasKind);
        $kindCol = $hasKind ? 'je.entry_kind' : "'system'";
        $gifiCol = $this->hasGifiColumn() ? 'a.gifi_code' : 'NULL';
        $sql = "SELECT je.id, je.entry_date, $kindCol AS entry_kind, je.source_type, je.source_id, je.memo, je.created_by, je.proposed_by,
                       je.created_at, je.reversed_by_entry_id, u.full_name AS created_by_name,
                       a.code, a.name AS account_name, $gifiCol AS gifi_code, jl.debit, jl.credit, jl.description
                FROM journal_entries je
                JOIN journal_lines jl ON jl.entry_id = je.id
                JOIN chart_of_accounts a ON a.id = jl.account_id
                LEFT JOIN users u ON u.id = je.created_by
                WHERE $where
                ORDER BY je.id, jl.id";
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $n = 0;
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $r['display_kind'] = self::displayKind($r);
                $each($r);
                $n++;
            }
            $st->closeCursor();
        } finally {
            if ($mysql) $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
        return $n;
    }

    private function hasGifiColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try { $has = $this->db->query("SELECT gifi_code FROM chart_of_accounts LIMIT 1") !== false; }
            catch (Throwable $e) { $has = false; }
        }
        return $has;
    }

    /**
     * What to call an entry in the journal. A reversal entry and an entry that HAS been reversed are
     * both marked; AJE / RJE come from entry_kind. Pure.
     */
    public static function displayKind(array $e): string
    {
        $k = (string)($e['entry_kind'] ?? 'system');
        if ($k === 'aje') return 'AJE';
        if ($k === 'rje') return 'RJE';
        if ($k === 'reversal' || ($e['source_type'] ?? '') === 'adjusting') return 'Reversal';
        if ($k === 'manual') return 'Manual';
        return 'System';
    }

    /** Filter dropdown values: accounts, source types, users who made entries. */
    public function facets(): array
    {
        return [
            'accounts' => $this->db->query("SELECT code, name FROM chart_of_accounts ORDER BY code")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [],
            'sources'  => $this->db->query("SELECT source_type, COUNT(*) FROM journal_entries GROUP BY source_type ORDER BY source_type")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [],
            'users'    => $this->db->query("SELECT DISTINCT u.id, u.full_name FROM journal_entries je JOIN users u ON u.id = je.created_by ORDER BY u.full_name")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [],
        ];
    }

    /** One entry: header, lines, reversal links and its source document. Null when it doesn't exist. */
    public function entry(int $id, ?string $receiptKey = null): ?array
    {
        $kindCol = $this->hasKindColumn() ? 'je.entry_kind' : "'system'";
        $st = $this->db->prepare("SELECT je.*, $kindCol AS entry_kind, u.full_name AS created_by_name
                                  FROM journal_entries je LEFT JOIN users u ON u.id = je.created_by WHERE je.id = ?");
        $st->execute([$id]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) return null;
        $e['display_kind'] = self::displayKind($e);

        $cols = "jl.id, jl.debit, jl.credit, jl.gst_amount, jl.pst_amount, jl.description, a.code, a.name AS account_name";
        try {
            $l = $this->db->prepare("SELECT $cols, a.gifi_code FROM journal_lines jl JOIN chart_of_accounts a ON a.id = jl.account_id
                                     WHERE jl.entry_id = ? ORDER BY (jl.debit > 0) DESC, jl.id");
            $l->execute([$id]);
        } catch (Throwable $ex) {   // before migration 1355 there is no gifi_code
            $l = $this->db->prepare("SELECT $cols, NULL AS gifi_code FROM journal_lines jl JOIN chart_of_accounts a ON a.id = jl.account_id
                                     WHERE jl.entry_id = ? ORDER BY (jl.debit > 0) DESC, jl.id");
            $l->execute([$id]);
        }
        $e['lines'] = $l->fetchAll(PDO::FETCH_ASSOC);

        // Reversal links both ways.
        $e['reversed_by'] = $e['reversed_by_entry_id'] ? $this->brief((int)$e['reversed_by_entry_id']) : null;
        $e['reverses'] = ($e['source_type'] === 'adjusting' && $e['source_id']) ? $this->brief((int)$e['source_id']) : null;

        $e['source'] = $this->source((string)$e['source_type'], $e['source_id'] !== null ? (int)$e['source_id'] : null, $receiptKey);
        return $e;
    }

    private function brief(int $id): ?array
    {
        $st = $this->db->prepare("SELECT id, entry_date, memo, created_at FROM journal_entries WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function one(string $sql, array $p): ?array
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($p);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The source document behind an entry.
     * @return array{type: string, title: string, found: bool, fields: array, items?: array, image_url?: ?string, invoice_id?: int}
     */
    public function source(string $type, ?int $id, ?string $receiptKey = null): array
    {
        $none = ['type' => $type, 'title' => 'No source document', 'found' => false, 'fields' => []];
        switch ($type) {
            case 'invoice':
            case 'payment':
                return $id ? $this->invoiceSource($id, $type === 'payment' ? 'Payment recorded on the invoice (no separate payment record)' : null) : $none;
            case 'payment_allocation':
                $a = $id ? $this->one("SELECT * FROM invoice_payment_allocations WHERE id = ?", [$id]) : null;
                if (!$a) return ['type' => $type, 'title' => "Payment #$id — record no longer exists", 'found' => false, 'fields' => []];
                $inv = $this->invoiceSource((int)$a['invoice_id'], null);
                $inv['title'] = 'Payment on ' . $inv['title'];
                $inv['fields'] = array_merge([
                    'Paid'      => $a['payment_date'] ?? null,
                    'Amount'    => $a['amount'],
                    'Method'    => $a['method'] ?? null,
                    'Reference' => $a['reference'] ?? null,
                ], $inv['fields']);
                if (!empty($a['transaction_id'])) $inv['bank_line'] = $this->bankLine((int)$a['transaction_id']);
                return $inv;
            case 'stripe_payment':
                $sp = $id ? $this->one("SELECT id, invoice_id, amount_cents, payment_method_type, status, webhook_received_at, created_at FROM stripe_payments WHERE id = ?", [$id]) : null;
                if (!$sp) return ['type' => $type, 'title' => "Stripe payment #$id — record no longer exists", 'found' => false, 'fields' => []];
                $inv = $this->invoiceSource((int)$sp['invoice_id'], null);
                $inv['title'] = 'Stripe payment on ' . $inv['title'];
                $inv['fields'] = array_merge(['Amount' => round((int)$sp['amount_cents'] / 100, 2), 'Method' => $sp['payment_method_type'],
                                              'Status' => $sp['status'], 'Received' => $sp['webhook_received_at'] ?: $sp['created_at']], $inv['fields']);
                return $inv;
            case 'expense':
                return $id ? $this->expenseSource($id, $receiptKey) : $none;
            case 'bank_import':
            case 'bank_deposit':
                if (!$id) return $none;
                $b = $this->bankLine($id);
                return $b ?? ['type' => $type, 'title' => "Bank line #$id — the line was removed (rolled-back or re-imported statement)", 'found' => false, 'fields' => []];
            case 'jobber_invoice':
            case 'jobber_carryover':
                $j = $id ? $this->one("SELECT jobber_number, client_name, subject, issued_date, due_date, status, subtotal, tax, total, balance, paid_date
                                        FROM jobber_invoices WHERE id = ?", [$id]) : null;
                if (!$j) return ['type' => $type, 'title' => "Jobber invoice #$id — not found", 'found' => false, 'fields' => []];
                return ['type' => $type, 'title' => 'Jobber invoice #' . $j['jobber_number'], 'found' => true, 'fields' => [
                    'Client' => $j['client_name'], 'For' => $j['subject'], 'Issued' => $j['issued_date'], 'Due' => $j['due_date'],
                    'Status' => $j['status'], 'Subtotal' => $j['subtotal'], 'Tax' => $j['tax'], 'Total' => $j['total'],
                    'Balance' => $j['balance'], 'Paid' => $j['paid_date'], 'Source' => 'Imported from Jobber (2026 Jobber-era record)',
                ]];
            case 'client_credit':
                $c = $id ? $this->one("SELECT type, amount, invoice_id, source_note, created_at FROM client_credits WHERE id = ?", [$id]) : null;
                if (!$c) return ['type' => $type, 'title' => "Client credit #$id — not found", 'found' => false, 'fields' => []];
                return ['type' => $type, 'title' => 'Client credit #' . $id, 'found' => true, 'invoice_id' => $c['invoice_id'] ? (int)$c['invoice_id'] : null,
                        'fields' => ['Kind' => $c['type'], 'Amount' => $c['amount'], 'Note' => $c['source_note'], 'Recorded' => $c['created_at']]];
            case 'bank_opening':
                $a = $id ? $this->one("SELECT code, name FROM chart_of_accounts WHERE id = ?", [$id]) : null;
                return ['type' => $type, 'title' => 'Opening balance for ' . ($a ? $a['code'] . ' ' . $a['name'] : "account #$id"), 'found' => (bool)$a,
                        'fields' => ['Basis' => 'Bank statement opening balance (bank balance check)']];
            case 'fy_opening':
            case 'opening':
                return ['type' => $type, 'title' => 'Opening balances for FY' . ($type === 'fy_opening' ? ((int)$id + 1) : (string)$id), 'found' => true,
                        'fields' => ['Basis' => 'The signed financial statements for the year before']];
            case 'adjusting':
                return ['type' => $type, 'title' => "Reversal of entry #$id", 'found' => true, 'fields' => []];
            case 'manual':
                return ['type' => $type, 'title' => 'Manual entry — the memo is its explanation', 'found' => true, 'fields' => []];
        }
        return ['type' => $type, 'title' => ucfirst(str_replace('_', ' ', $type)) . ($id ? " #$id" : ''), 'found' => false, 'fields' => []];
    }

    private function invoiceSource(int $id, ?string $note): array
    {
        $i = $this->one("SELECT id, invoice_number, invoice_date, issue_date, due_date, bill_to_name, subtotal, tax_amount, total, total_amount,
                                amount_paid, status, paid_at
                         FROM invoices WHERE id = ?", [$id]);
        if (!$i) return ['type' => 'invoice', 'title' => "Invoice #$id — deleted", 'found' => false, 'fields' => []];
        $items = [];
        try {
            $st = $this->db->prepare("SELECT description, quantity, unit_price, amount FROM invoice_items WHERE invoice_id = ? ORDER BY id");
            $st->execute([$id]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $items = []; }
        $fields = [
            'Bill to'  => $i['bill_to_name'],
            'Issued'   => $i['issue_date'] ?: $i['invoice_date'],
            'Due'      => $i['due_date'],
            'Status'   => $i['status'],
            'Subtotal' => $i['subtotal'],
            'GST'      => $i['tax_amount'],
            'Total'    => $i['total'] ?? $i['total_amount'],
            'Paid'     => $i['amount_paid'],
            'Paid on'  => $i['paid_at'],
        ];
        if ($note) $fields = ['Note' => $note] + $fields;
        return ['type' => 'invoice', 'title' => 'Invoice ' . ($i['invoice_number'] ?: "#$id"), 'found' => true,
                'invoice_id' => (int)$i['id'], 'fields' => $fields, 'items' => $items];
    }

    private function expenseSource(int $id, ?string $receiptKey): array
    {
        $x = $this->one("SELECT e.*, v.name AS vendor_name FROM expenses e LEFT JOIN vendors v ON v.id = e.vendor_id WHERE e.id = ?", [$id]);
        if (!$x) return ['type' => 'expense', 'title' => "Receipt #$id — deleted", 'found' => false, 'fields' => []];
        $img = null;
        if (!empty($x['receipt_media_id']) && $receiptKey) {
            $exp = time() + 900;   // ReceiptImageLinks::TTL
            $sig = hash_hmac('sha256', (int)$x['receipt_media_id'] . '.' . $exp, $receiptKey);
            $img = '/api/expenses/receipt-image?m=' . (int)$x['receipt_media_id'] . '&e=' . $exp . '&s=' . $sig;
        }
        $fields = [
            'Vendor'   => $x['vendor_name'] ?: ($x['vendor_name_raw'] ?? null),
            'Date'     => $x['expense_date'] ?? null,
            'Category' => $x['accounting_category'] ?? null,
            'Before tax' => $x['amount'] ?? null,
            'GST'      => $x['gst_amount'] ?? null,
            'PST'      => $x['pst_amount'] ?? null,
            'Paid with' => $x['payment_method'] ?? null,
            'Status'   => $x['status'] ?? null,
            'Description' => $x['description'] ?? null,
            'Notes'    => $x['notes'] ?? null,
        ];
        return ['type' => 'expense', 'title' => 'Receipt #' . $id, 'found' => true, 'fields' => $fields,
                'image_url' => $img, 'has_photo' => !empty($x['receipt_media_id']), 'penny' => $this->pennyNotes($id)];
    }

    /** Penny's latest read of a receipt (field: value — her reason), from expense_suggestions; null when she never read it. */
    private function pennyNotes(int $expenseId): ?string
    {
        $r = $this->one("SELECT suggestion_json, status FROM expense_suggestions
                         WHERE expense_id = ? AND source = 'live' ORDER BY id DESC LIMIT 1", [$expenseId]);
        if (!$r) return null;
        return self::pennySummary((string)$r['suggestion_json'], (string)$r['status']);
    }

    /** "Category: Meals (reason); GST: 0.42 (reason)" from a suggestion_json. Pure. */
    public static function pennySummary(string $json, string $status = ''): ?string
    {
        $d = json_decode($json, true);
        if (!is_array($d)) return null;
        $parts = [];
        foreach ($d as $field => $v) {
            if (!is_array($v) || !array_key_exists('value', $v) || is_array($v['value'])) continue;
            $txt = ucfirst(str_replace('_', ' ', (string)$field)) . ': ' . (string)$v['value'];
            if (!empty($v['reason']) && is_string($v['reason'])) $txt .= ' (' . $v['reason'] . ')';
            $parts[] = $txt;
            if (count($parts) >= 6) break;
        }
        if (!$parts) return null;
        return mb_substr(implode('; ', $parts) . ($status !== '' ? " — $status" : ''), 0, 700);
    }

    private function bankLine(int $txId): ?array
    {
        $t = $this->one("SELECT at.id, at.transaction_date, at.description, at.amount, at.type, at.payment_reference, at.notes,
                                at.bank_account_id, ba.code AS bank_code, ba.name AS bank_name, ca.code AS cat_code, ca.name AS cat_name
                         FROM accounting_transactions at
                         LEFT JOIN chart_of_accounts ba ON ba.id = at.bank_account_id
                         LEFT JOIN chart_of_accounts ca ON ca.id = at.account_id
                         WHERE at.id = ?", [$txId]);
        if (!$t) return null;
        $row = $this->one("SELECT r.raw_amount, r.description, s.filename, s.bank_name, s.date_from, s.date_to, s.status
                           FROM bank_import_rows r JOIN bank_import_sessions s ON s.id = r.session_id
                           WHERE r.transaction_id = ? ORDER BY r.id DESC LIMIT 1", [$txId]);
        $fields = [
            'Date'        => $t['transaction_date'],
            'Statement'   => $row ? trim(($row['bank_name'] ?? '') . ' ' . ($row['filename'] ?? '')) : 'no statement line on file',
            'Bank wording' => $row['description'] ?? $t['description'],
            'Amount on statement' => $row['raw_amount'] ?? $t['amount'],
            'Account'     => $t['bank_code'] ? $t['bank_code'] . ' ' . $t['bank_name'] : null,
            'Filed to'    => $t['cat_code'] ? $t['cat_code'] . ' ' . $t['cat_name'] : null,
            'Reference'   => $t['payment_reference'],
        ];
        return ['type' => 'bank_line', 'title' => 'Bank line #' . $txId, 'found' => true, 'fields' => $fields];
    }
}
