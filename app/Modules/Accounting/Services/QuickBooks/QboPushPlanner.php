<?php
/**
 * QboPushPlanner — CRM records → QuickBooks payloads, and the rules that block one (2026-10-07).
 *
 * Phase 2 design, built now and unit-tested, executed only by QboPushService behind the
 * ops_settings flag qbo_push_enabled (OFF). Everything here is pure: rows in, payload (or a
 * reason it must not go) out. Field names follow the entity references read on
 * developer.intuit.com 2026-10-07 (Purchase, Invoice, Payment, Deposit, JournalEntry,
 * "Automated sales tax (non-US locales)"):
 *
 *   Expense (approved, with its expense_line_allocations)  → Purchase
 *       PaymentType Cash|Check|CreditCard, AccountRef = the bank / credit-card account paid from,
 *       EntityRef {type: Vendor}, Line[] AccountBasedExpenseLineDetail {AccountRef, TaxCodeRef,
 *       BillableStatus NotBillable}, GlobalTaxCalculation TaxExcluded (Canada: net + tax code,
 *       QBO computes the tax itself and returns TxnTaxDetail), DocNumber 'EXP-<id>', PrivateNote.
 *       The receipt image follows as an Attachable (QboApiClient::upload).
 *   Invoice (+ invoice_items)                               → Invoice
 *       CustomerRef, Line[] SalesItemLineDetail {ItemRef, Qty, UnitPrice, TaxCodeRef},
 *       GlobalTaxCalculation TaxExcluded, TxnDate, DueDate, PrivateNote (CRM number),
 *       DocNumber only when the file has CustomTxnNumbers on.
 *   invoice_payment_allocations / stripe payment            → Payment
 *       CustomerRef, TotalAmt, TxnDate, Line[{Amount, LinkedTxn[{TxnId, TxnType: Invoice}]}],
 *       DepositToAccountRef = the bank account (or Undeposited Funds, then a Deposit groups them).
 *
 * Guards (block, never bend):
 *   - dated on/before the file's BookCloseDate            → blocked (closed period)
 *   - dated before PUSH_FROM (FY2026 opening; 2025 is filed & locked)  → blocked
 *   - CRM account with no confirmed QBO twin              → blocked (map it first)
 *   - meals: Canada allows a 50 % ITC — the code posts the full GST with the mapped meals code
 *     and flags it; the accountant's year-end adjustment (or a dedicated QBO meals tax code,
 *     UNVERIFIED whether the Canadian file ships one) handles the half. Noted in PrivateNote.
 */
declare(strict_types=1);

class QboPushPlanner
{
    /** FY2026 opening — nothing before this is ever pushed (FY2025 filed, migration 1237). */
    public const PUSH_FROM = '2026-01-01';
    public const DOC_MAX = 21;

    /** CRM expenses.payment_method → Purchase.PaymentType (Intuit allows Cash | Check | CreditCard). */
    public static function paymentType(?string $crmMethod): string
    {
        $m = strtolower((string)$crmMethod);
        if (strpos($m, 'card') !== false || strpos($m, 'visa') !== false || strpos($m, 'master') !== false || $m === 'cc' || $m === 'credit') return 'CreditCard';
        if (strpos($m, 'cheque') !== false || strpos($m, 'check') !== false) return 'Check';
        return 'Cash';   // debit, e-transfer, cash, unknown: paid straight from the bank account
    }

    /**
     * Why a record dated $date must not be written. Null = allowed.
     */
    public static function dateBlock(string $date, ?string $bookCloseDate): ?string
    {
        if ($date < self::PUSH_FROM) {
            return "Dated {$date}: before the FY2026 opening (" . self::PUSH_FROM . "). FY2025 is filed and locked; it is never pushed.";
        }
        if ($bookCloseDate !== null && $bookCloseDate !== '' && $date <= $bookCloseDate) {
            return "Dated {$date}: QuickBooks books are closed through {$bookCloseDate}. Ask the accountant; the sync never writes into a closed period.";
        }
        return null;
    }

    /**
     * Expense → Purchase.
     * $ctx: book_close_date, paid_from_qbo_id (bank/CC Account.Id), vendor_qbo_id, account_map
     *       [crm chart id => qbo Account.Id], category_accounts [accounting_category => crm chart id],
     *       tax_gst, tax_gst_pst, tax_exempt (TaxCode ids), multicurrency (bool), currency.
     * @return array{ok:bool, payload?:array, blocked?:string, warnings:array, hash?:string}
     */
    public static function purchaseFromExpense(array $expense, array $lines, array $ctx): array
    {
        $warn = [];
        $date = substr((string)($expense['expense_date'] ?? ''), 0, 10);
        if ($b = self::dateBlock($date, $ctx['book_close_date'] ?? null)) return ['ok' => false, 'blocked' => $b, 'warnings' => $warn];
        if (($expense['status'] ?? '') !== 'approved' && empty($expense['forwarded_to_accounting'])) {
            return ['ok' => false, 'blocked' => 'Not approved yet — Penny / Tim approve first, then it can go.', 'warnings' => $warn];
        }
        if (empty($ctx['paid_from_qbo_id'])) {
            return ['ok' => false, 'blocked' => 'No QuickBooks account mapped for the bank / card this was paid from (' . ($expense['payment_method'] ?? 'unknown') . ').', 'warnings' => $warn];
        }
        if (empty($ctx['vendor_qbo_id'])) {
            return ['ok' => false, 'blocked' => 'Vendor "' . ($expense['vendor_name'] ?? $expense['vendor_name_raw'] ?? '?') . '" has no QuickBooks twin yet (created on first push).', 'warnings' => $warn];
        }
        if (!$lines) {
            $lines = [[
                'label' => $expense['description'] ?? 'Expense', 'accounting_category' => $expense['accounting_category'] ?? '',
                'net_amount' => (float)($expense['amount'] ?? 0), 'gst_amount' => (float)($expense['tax_amount'] ?? 0),
                'pst_amount' => (float)($expense['pst_amount'] ?? 0), 'pst_taxable' => ((float)($expense['pst_amount'] ?? 0)) > 0 ? 1 : 0,
                'is_stock' => 0, 'job_id' => $expense['job_id'] ?? null,
            ]];
        }
        $out = [];
        $net = 0.0;
        foreach ($lines as $i => $l) {
            $cat = (string)($l['accounting_category'] ?? '');
            $crmAcct = $ctx['category_accounts'][$cat] ?? ($ctx['category_accounts'][strtolower($cat)] ?? null);
            if (!empty($l['is_stock']) && !empty($ctx['stock_account_id'])) $crmAcct = $ctx['stock_account_id'];
            $qboAcct = $crmAcct !== null ? ($ctx['account_map'][(int)$crmAcct] ?? null) : null;
            if ($qboAcct === null) {
                return ['ok' => false, 'blocked' => 'Line ' . ($i + 1) . ' (' . ($cat ?: 'no category') . '): the CRM account has no confirmed QuickBooks account. Map it on Settings → QuickBooks.', 'warnings' => $warn];
            }
            $gst = (float)($l['gst_amount'] ?? 0);
            $pst = (float)($l['pst_amount'] ?? 0);
            $taxCode = $gst <= 0.0 ? ($ctx['tax_exempt'] ?? null) : ((!empty($l['pst_taxable']) || $pst > 0.0) ? ($ctx['tax_gst_pst'] ?? null) : ($ctx['tax_gst'] ?? null));
            if ($taxCode === null) {
                return ['ok' => false, 'blocked' => 'No QuickBooks tax code chosen for ' . ($gst <= 0.0 ? 'exempt' : ($pst > 0.0 ? 'GST + PST' : 'GST only')) . ' lines. Pick the tax codes on Settings → QuickBooks.', 'warnings' => $warn];
            }
            $amount = round((float)($l['net_amount'] ?? 0), 2);
            $net += $amount;
            $desc = trim((string)($l['label'] ?? ''));
            if (strtolower($cat) === 'meals') {
                $warn[] = 'Meals line: Canada allows only a 50 % input tax credit on meals — QBO posts the full GST unless the file has a meals tax code. Flagged in the private note for the accountant.';
                $desc .= ' [meals — 50% ITC]';
            }
            $out[] = [
                'DetailType' => 'AccountBasedExpenseLineDetail',
                'Amount'     => $amount,
                'Description'=> $desc !== '' ? substr($desc, 0, 4000) : null,
                'AccountBasedExpenseLineDetail' => [
                    'AccountRef'     => ['value' => (string)$qboAcct],
                    'TaxCodeRef'     => ['value' => (string)$taxCode],
                    'BillableStatus' => 'NotBillable',
                ],
            ];
        }
        $expected = round((float)($expense['amount'] ?? 0), 2);
        if ($lines && abs($net - $expected) > 0.011 && $expected > 0) {
            $warn[] = 'Lines add to ' . number_format($net, 2) . ' but the expense net is ' . number_format($expected, 2) . ' — ExpenseGate should have caught this; check the split.';
        }
        $note = 'CRM expense #' . (int)$expense['id'] . ' · ' . trim((string)($expense['description'] ?? ''));
        if (!empty($expense['job_id'])) $note .= ' · job ' . (int)$expense['job_id'];
        $payload = [
            'PaymentType' => self::paymentType($expense['payment_method'] ?? null),
            'AccountRef'  => ['value' => (string)$ctx['paid_from_qbo_id']],
            'EntityRef'   => ['value' => (string)$ctx['vendor_qbo_id'], 'type' => 'Vendor'],
            'TxnDate'     => $date,
            'DocNumber'   => substr('EXP-' . (int)$expense['id'], 0, self::DOC_MAX),
            'PrivateNote' => substr($note, 0, 4000),
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line'        => $out,
        ];
        if (!empty($ctx['multicurrency'])) $payload['CurrencyRef'] = ['value' => $ctx['currency'] ?? 'CAD'];
        return ['ok' => true, 'payload' => $payload, 'warnings' => $warn, 'hash' => self::hash($payload)];
    }

    /**
     * Invoice → Invoice. $ctx: book_close_date, customer_qbo_id, item_qbo_id (the default service item),
     * tax_gst, tax_gst_pst, tax_exempt, custom_txn_numbers (bool), multicurrency, currency.
     */
    public static function invoiceFromCrm(array $inv, array $items, array $ctx): array
    {
        $warn = [];
        $date = substr((string)($inv['issue_date'] ?? ''), 0, 10);
        if ($b = self::dateBlock($date, $ctx['book_close_date'] ?? null)) return ['ok' => false, 'blocked' => $b, 'warnings' => $warn];
        if (in_array((string)($inv['status'] ?? ''), ['draft', 'cancelled', 'void'], true)) {
            return ['ok' => false, 'blocked' => 'Invoice is ' . $inv['status'] . ' — only sent / paid invoices go to QuickBooks.', 'warnings' => $warn];
        }
        if (empty($ctx['customer_qbo_id'])) return ['ok' => false, 'blocked' => 'The client has no QuickBooks Customer yet (created on first push).', 'warnings' => $warn];
        if (empty($ctx['item_qbo_id']))     return ['ok' => false, 'blocked' => 'No QuickBooks service Item chosen for invoice lines. Pick one on Settings → QuickBooks.', 'warnings' => $warn];
        $taxRate = (float)($inv['tax_rate'] ?? 0);
        $taxCode = $taxRate <= 0.0 ? ($ctx['tax_exempt'] ?? null) : ($taxRate > 0.06 ? ($ctx['tax_gst_pst'] ?? null) : ($ctx['tax_gst'] ?? null));
        if ($taxCode === null) return ['ok' => false, 'blocked' => 'No QuickBooks tax code chosen for ' . ($taxRate <= 0 ? 'exempt' : ($taxRate > 0.06 ? 'GST + PST' : 'GST')) . ' sales.', 'warnings' => $warn];
        if (!$items) $items = [['description' => 'Services', 'quantity' => 1, 'unit_price' => (float)($inv['subtotal'] ?? 0), 'amount' => (float)($inv['subtotal'] ?? 0)]];
        $lines = [];
        foreach ($items as $it) {
            $qty = (float)($it['quantity'] ?? 1) ?: 1.0;
            $amount = round((float)($it['amount'] ?? ($qty * (float)($it['unit_price'] ?? 0))), 2);
            $line = [
                'DetailType' => 'SalesItemLineDetail',
                'Amount'     => $amount,
                'Description'=> substr(trim((string)($it['description'] ?? '')), 0, 4000) ?: null,
                'SalesItemLineDetail' => [
                    'ItemRef'    => ['value' => (string)($ctx['item_qbo_id'])],
                    'Qty'        => $qty,
                    'UnitPrice'  => round($amount / $qty, 4),
                    'TaxCodeRef' => ['value' => (string)$taxCode],
                ],
            ];
            if (!empty($it['service_date'])) $line['SalesItemLineDetail']['ServiceDate'] = substr((string)$it['service_date'], 0, 10);
            $lines[] = $line;
        }
        $payload = [
            'CustomerRef' => ['value' => (string)$ctx['customer_qbo_id']],
            'TxnDate'     => $date,
            'DueDate'     => !empty($inv['due_date']) ? substr((string)$inv['due_date'], 0, 10) : null,
            'PrivateNote' => substr('CRM ' . ($inv['invoice_number'] ?? ('invoice #' . (int)$inv['id'])), 0, 4000),
            'GlobalTaxCalculation' => 'TaxExcluded',
            'Line'        => $lines,
        ];
        if (!empty($ctx['custom_txn_numbers']) && !empty($inv['invoice_number'])) {
            $payload['DocNumber'] = substr((string)$inv['invoice_number'], 0, self::DOC_MAX);
        } else {
            $warn[] = 'QuickBooks numbers the invoice itself (CustomTxnNumbers is off); the CRM number is in the private note.';
        }
        if (!empty($ctx['multicurrency'])) $payload['CurrencyRef'] = ['value' => $ctx['currency'] ?? 'CAD'];
        $payload = array_filter($payload, static fn($v) => $v !== null);
        return ['ok' => true, 'payload' => $payload, 'warnings' => $warn, 'hash' => self::hash($payload)];
    }

    /**
     * One recorded payment → Payment applied to its invoice.
     * $alloc: amount, payment_date, method, reference. $ctx: book_close_date, customer_qbo_id,
     * invoice_qbo_id, deposit_to_qbo_id (bank; null = Undeposited Funds).
     */
    public static function paymentFromAllocation(array $alloc, array $ctx): array
    {
        $date = substr((string)($alloc['payment_date'] ?? ''), 0, 10);
        if ($b = self::dateBlock($date, $ctx['book_close_date'] ?? null)) return ['ok' => false, 'blocked' => $b, 'warnings' => []];
        if (empty($ctx['customer_qbo_id'])) return ['ok' => false, 'blocked' => 'The client has no QuickBooks Customer yet.', 'warnings' => []];
        if (empty($ctx['invoice_qbo_id']))  return ['ok' => false, 'blocked' => 'The invoice is not in QuickBooks yet — it goes first, then its payments.', 'warnings' => []];
        $amount = round((float)($alloc['amount'] ?? 0), 2);
        if ($amount <= 0) return ['ok' => false, 'blocked' => 'Zero or negative payment — refunds are a separate design.', 'warnings' => []];
        $payload = [
            'CustomerRef'   => ['value' => (string)$ctx['customer_qbo_id']],
            'TotalAmt'      => $amount,
            'TxnDate'       => $date,
            'PaymentRefNum' => !empty($alloc['reference']) ? substr((string)$alloc['reference'], 0, 21) : null,
            'PrivateNote'   => substr('CRM payment ' . ($alloc['method'] ?? '') . ' #' . (int)($alloc['id'] ?? 0), 0, 4000),
            'Line'          => [[
                'Amount'    => $amount,
                'LinkedTxn' => [['TxnId' => (string)$ctx['invoice_qbo_id'], 'TxnType' => 'Invoice']],
            ]],
        ];
        if (!empty($ctx['deposit_to_qbo_id'])) $payload['DepositToAccountRef'] = ['value' => (string)$ctx['deposit_to_qbo_id']];
        $payload = array_filter($payload, static fn($v) => $v !== null);
        return ['ok' => true, 'payload' => $payload, 'warnings' => [], 'hash' => self::hash($payload)];
    }

    /** Stable content hash — the same payload twice is a no-op, a change is an update. */
    public static function hash(array $payload): string
    {
        return sha1(json_encode(self::sortDeep($payload)));
    }

    private static function sortDeep(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) $a[$k] = self::sortDeep($v);
        }
        return $a;
    }

    /** A requestid Intuit accepts (≤ 50 chars), stable per CRM record + attempt. */
    public static function requestId(string $crmType, int $crmId, string $hash): string
    {
        return substr($crmType . '-' . $crmId . '-' . substr($hash, 0, 12), 0, 50);
    }
}
