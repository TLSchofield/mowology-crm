<?php
/**
 * CreditCardPayableAuditService — why 2400 Credit Card Payable shows a DEBIT balance
 * (2026-10-07: $39,110.95 debit on a liability). REPORT ONLY — nothing is booked.
 *
 * What should post to 2400:
 *   credits — card purchases: receipts paid by card (journal source 'expense', funding
 *             2400 via LedgerSyncService::fundingAccountFor) and card-statement charges
 *             (bank_import rows from a credit-card statement whose session account is 2400);
 *   debits  — card payments from the bank (bank_import 'transfer' rows on 2400, the
 *             "pay credit card" line) and card-statement refunds.
 * A debit balance means more card PAYMENTS were booked than card PURCHASES: purchases
 * missing (card statements never imported, card receipts not booked as card), payments
 * double-booked (overlapping statements, 2026-10-05), or non-card payments misfiled on
 * 2400 (Wave PYRL, TD ON-LINE LOANS — fixed 1212–1214, 1221).
 *
 * report() returns: the journal balance; journal debits/credits by source and by month;
 * the bank-list rows on 2400 by month and kind; rows that look misfiled (payee doesn't
 * look like a card payment), likely duplicate payoffs, and which card statements were
 * imported. Portable SQL (MySQL 5.7, SQLite in tests).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class CreditCardPayableAuditService
{
    public const CODE = '2400';
    /** A bank line paying a card says so. */
    public const CARD_PAYMENT_RE = '/\bVISA\b|MASTER\s?CARD|\bMC\b|\bAMEX\b|AMERICAN EXPRESS|CREDIT CARD|\bCC PAYMENT|CARD PAYMENT|PAYMENT\s*-?\s*THANK YOU|PAYMENT RECEIVED|\bCAPITAL ONE\b|\bTD VISA\b|\bAEROPLAN\b|\bINFINITE\b|\bCASH BACK\b/i';
    /** Payees known NOT to be card payments. */
    public const NOT_CARD_RE = '/\bPYRL\b|PAYROLL|\bLOANS?\b|MORTGAGE|\bCRA\b|RECEIVER GENERAL|\bGST\b|\bWCB\b|WORKSAFE|INSURANCE|\bICBC\b|HYDRO|FORTIS|TELUS|ROGERS|SHAW|\bRENT\b|\bLEASE\b|\bPAD\b|E-?TRANSFER|ETRANSFER|\bCHQ\b|CHEQUE|\bWAGES?\b|\bATM\b|WITHDRAWAL/i';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function report(): array
    {
        $acct = $this->db->prepare("SELECT id, code, name FROM chart_of_accounts WHERE code = ? LIMIT 1");
        $acct->execute([self::CODE]);
        $a = $acct->fetch(PDO::FETCH_ASSOC);
        if (!$a) return ['ok' => false, 'message' => 'No 2400 account in the chart.'];
        $id = (int)$a['id'];

        $journal = [];
        try {
            $s = $this->db->prepare("
                SELECT je.source_type, SUBSTR(je.entry_date, 1, 7) AS month,
                       SUM(jl.debit) AS debit, SUM(jl.credit) AS credit, COUNT(*) AS n
                FROM journal_lines jl JOIN journal_entries je ON je.id = jl.entry_id
                WHERE jl.account_id = ?
                GROUP BY je.source_type, SUBSTR(je.entry_date, 1, 7)
                ORDER BY month, je.source_type
            ");
            $s->execute([$id]);
            $journal = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no journal */ }

        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.type, t.amount, t.description, t.reference_type, t.status,
                   t.bank_account_id, t.import_session_id
            FROM accounting_transactions t
            WHERE t.account_id = ? AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([$id]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);

        $sessions = [];
        try {
            $sessions = $this->db->query("
                SELECT id, filename, bank_name, account_name, bank_account_id, date_from, date_to, status, row_count, imported_count
                FROM bank_import_sessions ORDER BY date_from, id
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* no sessions */ }

        // Expenses paid by card that never reached the journal (receipts are the purchase side of 2400).
        $cardExpenses = null;
        try {
            $cardExpenses = $this->db->query("
                SELECT SUBSTR(e.expense_date, 1, 7) AS month, COUNT(*) AS n, SUM(e.total) AS total,
                       SUM(CASE WHEN EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_type = 'expense' AND je.source_id = e.id) THEN 1 ELSE 0 END) AS posted
                FROM expenses e
                WHERE (LOWER(COALESCE(e.payment_method, '')) LIKE '%card%' OR LOWER(COALESCE(e.payment_method, '')) LIKE '%credit%')
                  AND e.status IN ('approved', 'forwarded')
                GROUP BY SUBSTR(e.expense_date, 1, 7) ORDER BY month
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { /* schema differs */ }

        return ['ok' => true, 'account' => $a] + self::analyse($journal, $rows, $sessions, $id) + ['card_expenses' => $cardExpenses];
    }

    /**
     * Pure: everything but the queries.
     * @param array $journal  source_type, month, debit, credit, n
     * @param array $rows     accounting_transactions on 2400
     * @param array $sessions bank_import_sessions
     */
    public static function analyse(array $journal, array $rows, array $sessions, int $accountId): array
    {
        // Journal: by source, by month, balance (debit − credit; a liability should be ≤ 0).
        $bySource = []; $byMonth = []; $dr = 0.0; $cr = 0.0;
        foreach ($journal as $j) {
            $src = (string)$j['source_type'];
            $d = round((float)$j['debit'], 2); $c = round((float)$j['credit'], 2);
            $bySource[$src] = ['source' => $src, 'debit' => round(($bySource[$src]['debit'] ?? 0) + $d, 2),
                               'credit' => round(($bySource[$src]['credit'] ?? 0) + $c, 2), 'lines' => ($bySource[$src]['lines'] ?? 0) + (int)$j['n']];
            $mo = (string)$j['month'];
            $byMonth[$mo]['month'] = $mo;
            $byMonth[$mo]['debit'] = round(($byMonth[$mo]['debit'] ?? 0) + $d, 2);
            $byMonth[$mo]['credit'] = round(($byMonth[$mo]['credit'] ?? 0) + $c, 2);
            $byMonth[$mo]['by_source'][$src] = round(($byMonth[$mo]['by_source'][$src] ?? 0) + $d - $c, 2);
            $dr += $d; $cr += $c;
        }
        ksort($byMonth);
        $run = 0.0;
        foreach ($byMonth as &$m) { $run += $m['debit'] - $m['credit']; $m['net'] = round($m['debit'] - $m['credit'], 2); $m['running'] = round($run, 2); }
        unset($m);
        foreach ($bySource as &$b) $b['net'] = round($b['debit'] - $b['credit'], 2);
        unset($b);

        // Which sessions are card statements.
        $cardSessions = [];
        foreach ($sessions as $s) {
            if (self::isCardSession($s, $accountId)) $cardSessions[(int)$s['id']] = $s;
        }

        // Bank list rows on 2400.
        $kinds = []; $rowMonths = []; $suspects = []; $seen = []; $dupes = [];
        foreach ($rows as $r) {
            $amount = round((float)$r['amount'], 2);
            $kind = self::kindOf($r, $cardSessions);
            $kinds[$kind]['kind'] = $kind;
            $kinds[$kind]['n'] = ($kinds[$kind]['n'] ?? 0) + 1;
            $kinds[$kind]['total'] = round(($kinds[$kind]['total'] ?? 0) + $amount, 2);
            $mo = substr((string)$r['transaction_date'], 0, 7);
            $rowMonths[$mo]['month'] = $mo;
            $rowMonths[$mo][$kind] = round(($rowMonths[$mo][$kind] ?? 0) + $amount, 2);
            if ($kind === 'card_payment_from_bank' || $kind === 'other') {
                $why = self::misfiledReason((string)$r['description']);
                if ($why) $suspects[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => $amount,
                                         'description' => (string)$r['description'], 'type' => $r['type'], 'why' => $why];
                $key = substr((string)$r['transaction_date'], 0, 10) . '|' . number_format($amount, 2, '.', '');
                if (isset($seen[$key])) $dupes[] = ['ids' => [$seen[$key], (int)$r['id']], 'date' => substr($key, 0, 10), 'amount' => $amount, 'description' => (string)$r['description']];
                else $seen[$key] = (int)$r['id'];
            }
        }
        ksort($rowMonths);
        usort($suspects, fn($a, $b) => $b['amount'] <=> $a['amount']);

        $purchaseCredits = round(($bySource['expense']['credit'] ?? 0) + self::cardStatementCredit($journal), 2);
        $balance = round($dr - $cr, 2);
        return [
            'balance'          => $balance,                 // > 0 = debit (wrong side for a liability)
            'balance_side'     => $balance > 0.005 ? 'debit' : ($balance < -0.005 ? 'credit' : 'zero'),
            'journal_debit'    => round($dr, 2),
            'journal_credit'   => round($cr, 2),
            'by_source'        => array_values($bySource),
            'by_month'         => array_values($byMonth),
            'bank_rows'        => ['by_kind' => array_values($kinds), 'by_month' => array_values($rowMonths), 'count' => count($rows)],
            'card_statements'  => ['imported' => (bool)$cardSessions, 'sessions' => array_values(array_map(fn($s) => [
                                        'id' => (int)$s['id'], 'filename' => $s['filename'], 'bank' => $s['bank_name'], 'account' => $s['account_name'],
                                        'from' => $s['date_from'], 'to' => $s['date_to'], 'status' => $s['status']], $cardSessions))],
            'suspects'         => array_slice($suspects, 0, 100),
            'suspects_total'   => round(array_sum(array_column($suspects, 'amount')), 2),
            'duplicates'       => $dupes,
            'duplicates_total' => round(array_sum(array_column($dupes, 'amount')), 2),
            'say'              => self::say($balance, (bool)$cardSessions, $bySource, $suspects, $dupes),
            'purchase_credits' => $purchaseCredits,
        ];
    }

    /** Is a bank-import session a credit-card statement? */
    public static function isCardSession(array $s, int $accountId): bool
    {
        if ((int)($s['bank_account_id'] ?? 0) === $accountId) return true;
        $t = ($s['bank_name'] ?? '') . ' ' . ($s['account_name'] ?? '') . ' ' . ($s['filename'] ?? '');
        return (bool)preg_match('/CREDIT\s*CARD|\bVISA\b|MASTER\s?CARD|\bAMEX\b|\b_cc\b|\bCC\b/i', $t);
    }

    /** card_payment_from_bank | card_statement_payment | card_statement_charge | manual | other */
    public static function kindOf(array $r, array $cardSessions): string
    {
        if (($r['reference_type'] ?? '') !== 'bank_import') return 'manual';
        $fromCard = isset($cardSessions[(int)($r['import_session_id'] ?? 0)]);
        if ($fromCard) return ($r['type'] ?? '') === 'transfer' ? 'card_statement_payment' : 'card_statement_charge';
        return ($r['type'] ?? '') === 'transfer' ? 'card_payment_from_bank' : 'other';
    }

    /** Why a bank row on 2400 looks misfiled; null when it reads like a card payment. */
    public static function misfiledReason(string $description): ?string
    {
        if (preg_match(self::NOT_CARD_RE, $description, $m)) return 'payee "' . trim($m[0]) . '" is not a card payment';
        if (!preg_match(self::CARD_PAYMENT_RE, $description)) return 'nothing in the description says credit card';
        return null;
    }

    private static function cardStatementCredit(array $journal): float
    {
        $c = 0.0;
        foreach ($journal as $j) if (($j['source_type'] ?? '') === 'bank_import') $c += (float)$j['credit'];
        return $c;
    }

    private static function say(float $balance, bool $cardStatements, array $bySource, array $suspects, array $dupes): string
    {
        $m = fn($v) => '$' . number_format(abs($v), 2);
        if ($balance <= 0.005) return '2400 is on the credit side (' . $m($balance) . ' owed on cards) — as a liability should be.';
        $paid = $bySource['bank_import']['debit'] ?? 0.0;
        $bought = ($bySource['expense']['credit'] ?? 0.0) + ($bySource['bank_import']['credit'] ?? 0.0);
        $out = '2400 shows a ' . $m($balance) . ' DEBIT: ' . $m($paid) . ' of card payments booked against only ' . $m($bought) . ' of card purchases.';
        $out .= $cardStatements ? '' : ' No credit-card statement has been imported, so card purchases only reach 2400 when a receipt is marked paid by card.';
        if ($suspects) $out .= ' ' . count($suspects) . ' line(s) on 2400 don\'t look like card payments (' . $m(array_sum(array_column($suspects, 'amount'))) . ').';
        if ($dupes) $out .= ' ' . count($dupes) . ' possible duplicate payoff(s) (' . $m(array_sum(array_column($dupes, 'amount'))) . ').';
        return $out;
    }
}
