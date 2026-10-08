<?php
/**
 * PaymentMatchService — "payments reconciled against invoices" (2026-10-07).
 *
 * Every unmatched 2026 bank deposit is matched to the invoice(s) it paid — CRM invoices AND the
 * imported Jobber invoices / payments (migration 1239) — or left for Tim with the closest
 * candidates. Every open invoice shows whether money for it is sitting in the bank.
 *
 * Candidates (one list, five kinds):
 *   crm_rec   a payment recorded on a CRM invoice with no bank deposit linked (Record Payment, e-Transfer panel)
 *   crm_paid  a CRM invoice marked paid the old way (no allocation rows, not card)
 *   crm_open  a CRM invoice still owing (partials allowed)
 *   jb_pay    an unbooked 2026 Jobber payment, grouped the way it reaches the bank (JobberLedgerService::units:
 *             a card payout, one e-Transfer, a client's same-day cheques, a PAD)
 *   jb_inv    a Jobber invoice still owing in the export, not recreated in the CRM (paid after the cutover,
 *             never recorded in Jobber — the Dorset pre-authorized credits)
 *
 * Matching, deposits oldest first, a candidate claimed by at most one deposit (an open invoice's balance
 * by several, as partials):
 *   1. one candidate of exactly the amount (named payers first, then the whole pool)
 *   2. a subset of ONE payer's candidates adding up to the cent (bounded search, up to 10 items) — the
 *      memo names the payer / property manager / strata plan → everything it names is one pool; else per
 *      payer, and Jobber payments also per day (a mobile deposit of several clients' cheques)
 *   3. partials: a named payer's open invoice receiving less than it owes (several deposits → one invoice)
 * Payer identity: memo words, strata plan numbers (BCS 4079, VR1540, LMS2881), the e-Transfer sender →
 * payer pairs the owner has taught (etransfer_sender_payers, migration 1132), CRM bill-to / company /
 * contact / property names, Jobber client names, Jobber e-Transfer confirmation numbers.
 * Confidence: high (named + exact + one answer), medium (exact + one answer, payer not named — or named
 * with interchangeable answers), low (several payers fit, partials, wide date gap). Never auto-books.
 *
 * Booking reuses the existing paths so the books stay consistent:
 *   crm_rec  → IncomeCleanupService 'link_payments'    (InvoiceReconciliationService::linkAllocationsToDeposit)
 *   crm_paid → IncomeCleanupService 'already_recorded' (markDepositAlreadyRecorded)
 *   crm_open → IncomeCleanupService 'record_payment'   (InvoiceReconciliationService::attach)
 *   jobber   → JobberLedgerService::bookMatched        (FY2025 part settles the opening receivable — never 2026 income)
 * Each approval is logged in payment_match_log (migration 1260) and undone through the same path.
 * Stripe payouts after the cutover are left to the income clean-up (StripePayoutService) — untouched here.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/InvoiceReconciliationService.php';
require_once __DIR__ . '/IncomeCleanupService.php';
require_once __DIR__ . '/JobberCsv.php';
require_once __DIR__ . '/JobberImportService.php';
require_once __DIR__ . '/JobberLedgerService.php';

class PaymentMatchService
{
    public const FY_START = '2026-01-01';
    public const FY_END   = '2026-12-31';
    public const CUTOVER  = IncomeCleanupService::CUTOVER;
    public const CENT = 0.005;

    /** Days before / after the deposit a candidate's date may fall (payment / paid date, or issue date for open invoices). */
    public const WINDOWS = [
        'crm_rec'  => [-21, 60],
        'crm_paid' => [-21, 60],
        'crm_open' => [-150, 7],
        'jb_pay'   => [-30, 30],
        'jb_inv'   => [-300, 7],
    ];
    public const RANK = ['crm_rec' => 0, 'jb_pay' => 1, 'crm_paid' => 2, 'crm_open' => 3, 'jb_inv' => 4];
    public const MAX_ITEMS = 10;
    public const POOL = 40;
    public const BUDGET = 150000;
    public const SNAPSHOT_HOURS = 4;

    public const OUTCOMES = ['high', 'medium', 'low', 'needs_you', 'stripe', 'skipped'];
    public const PROPOSED = ['high', 'medium', 'low'];

    /** Memo / name words that identify nobody. */
    private const GENERIC = [
        'realty', 'strata', 'plan', 'owners', 'owner', 'the', 'and', 'misc', 'payment', 'payments', 'credit', 'preauthorized', 'preauth',
        'pad', 'eft', 'bill', 'deposit', 'mobile', 'branch', 'atm', 'cheque', 'cheq', 'chq', 'from', 'corp', 'inc', 'ltd', 'limited',
        'management', 'mgmt', 'property', 'properties', 'group', 'services', 'service', 'company', 'association', 'society', 'council',
        'account', 'online', 'transfer', 'interac', 'etransfer', 'tfr', 'ref', 'reference', 'vancouver', 'canada', 'bank', 'mowology',
        'timatmowology', 'landscaping', 'ltee', 'trust', 'holdings', 'rental', 'rentals', 'homes', 'home', 'real', 'estate', 'c/o',
    ];
    private const CARD_METHODS = ['stripe', 'credit_card', 'card', 'credit'];

    protected PDO $db;
    private ?LedgerService $ledger;

    public function __construct(PDO $db, ?LedgerService $ledger = null)
    {
        $this->db = $db;
        $this->ledger = $ledger;
    }

    public function ready(): bool
    {
        return $this->has("SELECT 1 FROM payment_match_log LIMIT 0");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // SCAN (read only)
    // ══════════════════════════════════════════════════════════════════════════

    /** Everything the page shows. Never writes. */
    public function scan(): array
    {
        $t0 = microtime(true);
        $data = $this->load();
        $lines = self::match($data['deposits'], $data['candidates'], $data['aliases'], [
            'cutover' => self::CUTOVER, 'locked' => $data['locked'], 'skipped' => $data['skipped'],
            'bbc' => $data['bbc'], 'bank_sync' => $data['bank_sync'],
        ]);
        $open = self::openCoverage($data['candidates'], $lines);
        return [
            'ok' => true, 'ready' => $this->ready(), 'fy_start' => self::FY_START, 'cutover' => self::CUTOVER,
            'summary' => self::summarise($lines, $open, $data['candidates']),
            'lines' => $lines,
            'open' => $open,
            'candidates' => array_values(array_map([self::class, 'publicCandidate'], $data['candidates'])),
            'log' => $this->recentLog(40),
            'ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
    }

    /** The dry run: counts and $ by outcome on the live data, nothing written. */
    public function dryRun(): array
    {
        $s = $this->scan();
        $sample = [];
        foreach ($s['lines'] as $l) {
            if (count($sample[$l['outcome']] ?? []) >= 8) continue;
            $sample[$l['outcome']][] = ['id' => $l['id'], 'date' => $l['date'], 'amount' => $l['amount'], 'description' => mb_substr($l['description'], 0, 60),
                                        'source' => $l['source'], 'action' => $l['action'], 'targets' => array_column($l['targets'], 'number'),
                                        'fy2025_part' => $l['fy2025_part'], 'why' => $l['why'], 'blocked' => $l['blocked']];
        }
        return ['ok' => true, 'dry_run' => true, 'written' => 0, 'ready' => $s['ready'], 'ms' => $s['ms'],
                'summary' => $s['summary'], 'sample' => $sample];
    }

    /** Penny's card line, from the snapshot (refreshed when older than SNAPSHOT_HOURS). null = nothing to say / not ready. */
    public function cardLine(bool $refresh = true): ?array
    {
        if (!$this->ready()) return null;
        $row = null;
        try {
            $row = $this->db->query("SELECT * FROM payment_match_snapshot WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
        $stale = !$row || strtotime((string)$row['computed_at']) < time() - self::SNAPSHOT_HOURS * 3600;
        if ($stale && $refresh) {
            try {
                $row = $this->snapshot($this->scan()['summary']);
            } catch (Throwable $e) {
                error_log('[payment-match] card snapshot: ' . $e->getMessage());
            }
        }
        if (!$row || (int)$row['waiting'] === 0) return null;
        return ['waiting' => (int)$row['waiting'], 'waiting_total' => round((float)$row['waiting_total'], 2),
                'high' => (int)$row['high'], 'high_total' => round((float)$row['high_total'], 2),
                'needs_you' => (int)$row['needs_you'], 'computed_at' => (string)$row['computed_at'],
                'url' => '/crm/accounting/payment-match.php'];
    }

    /** Store the card counts (a cache — the page and the card refresh it). */
    public function snapshot(array $summary): array
    {
        $row = ['id' => 1, 'computed_at' => date('Y-m-d H:i:s'),
                'waiting' => (int)$summary['proposed']['count'], 'waiting_total' => (float)$summary['proposed']['total'],
                'high' => (int)$summary['by_outcome']['high']['count'], 'high_total' => (float)$summary['by_outcome']['high']['total'],
                'needs_you' => (int)$summary['by_outcome']['needs_you']['count'], 'needs_you_total' => (float)$summary['by_outcome']['needs_you']['total']];
        try {
            $this->db->prepare("DELETE FROM payment_match_snapshot WHERE id = 1")->execute();
            $this->db->prepare("INSERT INTO payment_match_snapshot (id, computed_at, waiting, waiting_total, high, high_total, needs_you, needs_you_total)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute(array_values($row));
        } catch (Throwable $e) { /* before migration 1260 */ }
        return $row;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // LOAD — portable SQL (MySQL 5.7 + SQLite in tests); windows applied in PHP
    // ══════════════════════════════════════════════════════════════════════════

    protected function load(): array
    {
        $poolFrom = date('Y-m-d', strtotime(self::FY_START) - 320 * 86400);

        // ── Bank deposits: 2026 income lines not tied to anything yet ──
        $s = $this->db->prepare("
            SELECT t.id, t.transaction_date, t.amount, t.gst_amount, t.description, t.status
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.type = 'income' AND t.amount > 0.005
              AND (t.status IS NULL OR t.status <> 'void')
              AND t.matched_invoice_id IS NULL AND t.matched_expense_id IS NULL
              AND t.transaction_date BETWEEN ? AND ?
            ORDER BY t.transaction_date, t.id
        ");
        $s->execute([self::FY_START, self::FY_END . ' 23:59:59']);
        $allocated = $this->idSet("SELECT DISTINCT transaction_id FROM invoice_payment_allocations WHERE transaction_id IS NOT NULL");
        $cleaned   = $this->idSet("SELECT DISTINCT transaction_id FROM income_cleanup_log WHERE status = 'booked'");
        $jobbered  = $this->idSet("SELECT DISTINCT transaction_id FROM jobber_ledger_log WHERE undone_at IS NULL AND transaction_id IS NOT NULL");
        $ours      = $this->idSet("SELECT DISTINCT transaction_id FROM payment_match_log WHERE status = 'booked'");
        $deposits = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            if (isset($allocated[$id]) || isset($cleaned[$id]) || isset($jobbered[$id]) || isset($ours[$id])) continue;
            $deposits[] = ['id' => $id, 'date' => substr((string)$r['transaction_date'], 0, 10), 'amount' => round((float)$r['amount'], 2),
                           'gst_amount' => round((float)($r['gst_amount'] ?? 0), 2), 'description' => (string)$r['description']];
        }
        $bankSync = $this->idSet("SELECT DISTINCT source_id FROM journal_entries WHERE source_type = 'bank_import' AND reversed_by_entry_id IS NULL AND source_id IS NOT NULL");
        $bbc = [];
        try {
            foreach ($this->db->query("SELECT id, source_id FROM journal_entries WHERE source_type = 'bank_deposit' AND reversed_by_entry_id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bbc[(int)$r['source_id']] = (int)$r['id'];
            }
        } catch (Throwable $e) { /* no journal */ }

        $cands = [];

        // ── CRM: payments recorded with no deposit linked ──
        try {
            $q = $this->db->prepare("
                SELECT a.id, a.invoice_id, a.amount, a.payment_date, a.method, a.reference,
                       i.invoice_number, i.bill_to_name, co.company_name, ct.first_name, ct.last_name, p.property_name
                FROM invoice_payment_allocations a
                JOIN invoices i ON i.id = a.invoice_id
                LEFT JOIN companies co ON co.id = i.company_id
                LEFT JOIN contacts ct ON ct.id = i.contact_id
                LEFT JOIN properties p ON p.id = i.property_id
                WHERE a.transaction_id IS NULL AND a.amount > 0 AND a.payment_date >= ?
                ORDER BY a.payment_date, a.id
            ");
            $q->execute([$poolFrom]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array(strtolower((string)$r['method']), self::CARD_METHODS, true)) continue;   // card money arrives in a Stripe payout
                $names = self::names([$r['bill_to_name'], $r['company_name'], trim($r['first_name'] . ' ' . $r['last_name']), $r['property_name']]);
                $cands['crm_rec:' . $r['id']] = self::cand('crm_rec', (int)$r['id'], (string)$r['invoice_number'], $names, (float)$r['amount'],
                    substr((string)$r['payment_date'], 0, 10), ['invoice_id' => (int)$r['invoice_id'], 'issued' => null, 'method' => (string)$r['method']]);
            }
        } catch (Throwable $e) {
            error_log('[payment-match] recorded payments: ' . $e->getMessage());
        }

        // ── CRM: invoices paid the old way, and invoices still owing ──
        $claimedByCleanup = [];
        try {
            foreach ($this->db->query("SELECT invoice_ids FROM income_cleanup_log WHERE status = 'booked'")->fetchAll(PDO::FETCH_COLUMN) as $csv) {
                foreach (IncomeCleanupService::ids((string)$csv) as $iid) $claimedByCleanup[$iid] = true;
            }
        } catch (Throwable $e) { /* before migration 1228 */ }
        $stripeCol = $this->has("SELECT 1 FROM stripe_payments LIMIT 0")
            ? "(SELECT COUNT(*) FROM stripe_payments sp WHERE sp.invoice_id = i.id AND sp.status = 'succeeded')" : "0";
        $q = $this->db->prepare("
            SELECT i.id, i.invoice_number, i.status, i.total, i.amount_paid, i.balance_due, i.payment_method, i.paid_at, i.updated_at,
                   COALESCE(i.issue_date, i.invoice_date) AS issued, i.stripe_charge_id,
                   i.bill_to_name, co.company_name, ct.first_name, ct.last_name, p.property_name,
                   (SELECT COUNT(*) FROM invoice_payment_allocations a WHERE a.invoice_id = i.id) AS alloc_count,
                   (SELECT COUNT(*) FROM accounting_transactions t WHERE t.reference_type = 'bank_import' AND t.matched_invoice_id = i.id) AS claimed,
                   {$stripeCol} AS stripe_paid
            FROM invoices i
            LEFT JOIN companies co ON co.id = i.company_id
            LEFT JOIN contacts ct ON ct.id = i.contact_id
            LEFT JOIN properties p ON p.id = i.property_id
            WHERE (i.status = 'paid' AND COALESCE(i.paid_at, i.updated_at) >= ?)
               OR (i.balance_due > 0.005 AND i.status IN ('sent', 'viewed', 'partial', 'overdue'))
            ORDER BY i.id
        ");
        $q->execute([$poolFrom]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $names = self::names([$r['bill_to_name'], $r['company_name'], trim($r['first_name'] . ' ' . $r['last_name']), $r['property_name']]);
            $isCard = in_array(strtolower((string)$r['payment_method']), self::CARD_METHODS, true) || !empty($r['stripe_charge_id']) || (int)$r['stripe_paid'] > 0;
            $issued = substr((string)$r['issued'], 0, 10);
            if ($r['status'] === 'paid') {
                if ($isCard || (int)$r['alloc_count'] > 0 || (int)$r['claimed'] > 0 || isset($claimedByCleanup[(int)$r['id']])) continue;
                $cands['crm_paid:' . $r['id']] = self::cand('crm_paid', (int)$r['id'], (string)$r['invoice_number'], $names, (float)$r['amount_paid'],
                    substr((string)($r['paid_at'] ?: $r['updated_at']), 0, 10), ['invoice_id' => (int)$r['id'], 'issued' => $issued]);
            } else {
                if (isset($claimedByCleanup[(int)$r['id']]) && (float)$r['balance_due'] <= self::CENT) continue;
                $cands['crm_open:' . $r['id']] = self::cand('crm_open', (int)$r['id'], (string)$r['invoice_number'], $names, (float)$r['balance_due'],
                    $issued, ['invoice_id' => (int)$r['id'], 'issued' => $issued, 'partial_ok' => true, 'total' => round((float)$r['total'], 2)]);
            }
        }

        // ── Jobber: unbooked 2026 payments (as they reach the bank) and invoices still owing ──
        $jl = $this->jobber();
        if ($jl->ready()) {
            $jInv = [];
            foreach ($this->jobberInvoices() as $inv) $jInv[(string)$inv['jobber_number']] = $inv;
            $pays = $jl->openPayments();
            $payById = [];
            foreach ($pays as $p) $payById[(int)$p['id']] = $p;
            foreach (JobberLedgerService::units($pays) as $u) {
                $pre = 0.0; $nums = []; $names = $u['clients'];
                foreach ($u['ids'] as $pid) {
                    $p = $payById[$pid];
                    $list = [];
                    foreach (JobberCsv::invoiceNumbers($p['invoice_numbers'] ?? '') as $n) {
                        $nums[] = '#' . $n;
                        if (isset($jInv[$n])) {
                            $list[] = [$n, (float)$jInv[$n]['total']];
                            $names = array_merge($names, $jInv[$n]['names']);
                        }
                    }
                    if (($p['kind'] ?? 'payment') === 'deposit' || !$list) continue;
                    foreach (JobberImportService::allocate((float)$p['amount'], $list) as $n => $amt) {
                        if ((string)$jInv[$n]['issued_date'] < self::FY_START) $pre += $amt;
                    }
                }
                $label = 'Jobber ' . ($nums ? implode(', ', array_slice(array_unique($nums), 0, 4)) : ($u['payout'] ? 'payout ' . $u['payout'] : 'payment'));
                $cands['jb_pay:' . $u['key']] = self::cand('jb_pay', 0, $label, self::names($names), (float)$u['net'], $u['date'], [
                    'payment_ids' => array_map('intval', $u['ids']), 'method' => $u['method'], 'confirmation' => $u['confirmation'],
                    'payout' => $u['payout'], 'pre2026' => round($pre, 2), 'gross' => $u['gross'], 'fee' => $u['fee'], 'unit' => $u['key'],
                    'issued' => null, 'deposit_kind' => $u['deposit_kind']]);
            }
            $left = $jl->remainingWithoutPayment();
            foreach ($jInv as $n => $inv) {
                if (!empty($inv['crm_invoice_id']) || in_array($inv['status'], ['draft', 'void'], true)) continue;
                $rem = round((float)$inv['balance'] - ($left[(int)$inv['id']] ?? 0.0), 2);
                if ($rem <= self::CENT) continue;
                $issued = substr((string)$inv['issued_date'], 0, 10);
                if ($issued === '' || $issued < $poolFrom) continue;
                $cands['jb_inv:' . $inv['id']] = self::cand('jb_inv', (int)$inv['id'], 'Jobber #' . $n, $inv['names'], $rem, $issued, [
                    'issued' => $issued, 'partial_ok' => true, 'total' => round((float)$inv['total'], 2), 'jobber_status' => (string)$inv['status']]);
            }
        }

        return ['deposits' => $deposits, 'candidates' => $cands, 'aliases' => $this->aliases(), 'locked' => $this->lockedMonths(),
                'skipped' => $this->skippedMap(), 'bbc' => $bbc, 'bank_sync' => $bankSync];
    }

    /** jobber_invoices with every name that identifies the payer (client name, CRM company + contact). */
    private function jobberInvoices(): array
    {
        try {
            $rows = $this->db->query("
                SELECT j.id, j.jobber_number, j.client_name, j.contact_id, j.issued_date, j.status, j.total, j.balance, j.crm_invoice_id,
                       co.company_name, ct.first_name, ct.last_name
                FROM jobber_invoices j
                LEFT JOIN companies co ON co.id = j.company_id
                LEFT JOIN contacts ct ON ct.id = j.contact_id
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) $r['names'] = self::names([$r['client_name'], $r['company_name'], trim($r['first_name'] . ' ' . $r['last_name'])]);
        unset($r);
        return $rows;
    }

    /** e-Transfer sender → payer pairs the owner taught (migration 1132): list of [sender_name, payer_name]. */
    private function aliases(): array
    {
        try {
            return array_map(fn($r) => [(string)$r['sender_name'], (string)$r['payer_name']],
                $this->db->query("SELECT sender_name, payer_name FROM etransfer_sender_payers WHERE sender_name IS NOT NULL AND payer_name IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function skippedMap(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT transaction_id, note FROM payment_match_log WHERE status = 'skipped' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['transaction_id']] = (string)$r['note'];
            }
        } catch (Throwable $e) { /* before migration 1260 */ }
        return $out;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE — identity, subset search, matching (unit tested)
    // ══════════════════════════════════════════════════════════════════════════

    /** A candidate row. Pure. */
    public static function cand(string $kind, int $id, string $number, array $names, float $amount, string $date, array $extra = []): array
    {
        $key = $kind === 'jb_pay' ? 'jb_pay:' . ($extra['unit'] ?? $id) : $kind . ':' . $id;
        return $extra + [
            'key' => $key, 'kind' => $kind, 'family' => in_array($kind, ['jb_pay', 'jb_inv'], true) ? 'jobber' : $kind,
            'id' => $id, 'number' => $number, 'names' => $names, 'payer' => $names[0] ?? '', 'group' => self::groupKey($names),
            'amount' => round($amount, 2), 'date' => $date, 'partial_ok' => false, 'pre2026' => 0.0,
            'strata' => self::strataIds(implode(' ', $names)),
        ];
    }

    /** Non-empty distinct names. Pure. */
    public static function names(array $list): array
    {
        $out = [];
        foreach ($list as $n) {
            $n = trim((string)$n);
            if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
        }
        return $out;
    }

    /** Strata plan numbers in a text: "BCS 4079", "VR-1540", "Strata Plan LMS2881" → ['BCS4079', …]. Pure. */
    public static function strataIds(string $text): array
    {
        preg_match_all('/\b(BCS|LMS|VR|VIS|NW|EPS|KAS|NES|BCP|LMP|VAS)\s*-?\s*(\d{2,5})\b/i', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $x) $out[] = strtoupper($x[1]) . (int)$x[2];
        return array_values(array_unique($out));
    }

    /** The key a payer's candidates are grouped under: a strata plan, else the first distinctive name. Pure. */
    public static function groupKey(array $names): string
    {
        $s = self::strataIds(implode(' ', $names));
        if ($s) return 'SP:' . $s[0];
        foreach ($names as $n) {
            $k = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$n));
            if ($k !== '') return 'N:' . $k;
        }
        return '';
    }

    /** Distinctive words of a bank memo (InvoiceReconciliationService::bankMemoWords minus words that name nobody). Pure. */
    public static function memoWords(string $memo): array
    {
        return array_values(array_filter(InvoiceReconciliationService::bankMemoWords($memo), fn($w) => !in_array($w, self::GENERIC, true)));
    }

    /**
     * What a deposit says about who paid. Pure.
     * @param array $aliases list of [sender_name, payer_name] (etransfer_sender_payers)
     */
    public static function identity(string $memo, array $aliases = []): array
    {
        $words = self::memoWords($memo);
        $also = [];
        if ($words) {
            foreach ($aliases as [$sender, $payer]) {
                if ($sender !== '' && $payer !== '' && InvoiceReconciliationService::payerMatchesWords($sender, $words)) $also[] = $payer;
            }
        }
        preg_match_all('/\d{6,}/', $memo, $digits);
        return ['words' => $words, 'strata' => self::strataIds($memo), 'aliases' => array_values(array_unique($also)), 'digits' => $digits[0] ?? []];
    }

    /** Why this candidate is the payer the deposit names, or null. Pure. */
    public static function named(array $ident, array $c): ?string
    {
        if (!empty($c['confirmation'])) {
            $conf = preg_replace('/\D/', '', (string)$c['confirmation']);
            foreach ($ident['digits'] as $d) {
                if (strlen($conf) >= 6 && (strpos($d, $conf) !== false || (strlen($d) >= 10 && strpos($conf, $d) !== false))) return 'e-Transfer ref ' . $c['confirmation'];
            }
        }
        if ($ident['strata'] && array_intersect($ident['strata'], $c['strata'])) return 'strata ' . implode(', ', array_intersect($ident['strata'], $c['strata']));
        if ($ident['words']) {
            foreach ($c['names'] as $n) {
                $distinct = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($n)) ?: [], fn($w) => $w !== '' && !in_array($w, self::GENERIC, true)));
                if (!$distinct) continue;
                if (InvoiceReconciliationService::payerMatchesWords(implode(' ', $distinct), $ident['words'])) return 'the memo names ' . $n;
            }
        }
        foreach ($ident['aliases'] as $payer) {
            foreach ($c['names'] as $n) {
                if (InvoiceReconciliationService::namesAgree($payer, $n) || strcasecmp(trim($payer), trim($n)) === 0) return 'this sender pays for ' . $n . ' (taught)';
            }
        }
        return null;
    }

    /**
     * Subsets of $cents adding up to $target exactly — the fewest items first, earliest indices first.
     * Bounded: at most $maxItems in a set, $budget search steps in all, $want answers. Pure.
     * @param int[] $cents positive amounts in cents (pass oldest first)
     * @return list<list<int>> index lists
     */
    public static function subsets(array $cents, int $target, int $maxItems = self::MAX_ITEMS, int $budget = self::BUDGET, int $want = 2): array
    {
        $cents = array_values($cents);
        $n = count($cents);
        if ($n === 0 || $target <= 0) return [];
        $suffix = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) $suffix[$i] = $suffix[$i + 1] + $cents[$i];
        if ($suffix[0] < $target) return [];
        $found = [];
        $steps = 0;
        for ($size = 1; $size <= min($maxItems, $n); $size++) {
            $dfs = function (int $start, int $left, int $sum, array $chosen) use (&$dfs, &$found, &$steps, $cents, $target, $suffix, $n, $want, $budget): void {
                if (count($found) >= $want || $steps > $budget) return;
                if ($left === 0) {
                    if ($sum === $target) $found[] = $chosen;
                    return;
                }
                for ($i = $start; $i <= $n - $left; $i++) {
                    if (++$steps > $budget) return;
                    $s = $sum + $cents[$i];
                    if ($s > $target) continue;
                    if ($s + $suffix[$i + 1] < $target) break;   // even everything after can't reach it
                    $chosen[] = $i;
                    $dfs($i + 1, $left - 1, $s, $chosen);
                    array_pop($chosen);
                    if (count($found) >= $want) return;
                }
            };
            $dfs(0, $size, 0, []);
            if ($found || $steps > $budget) break;
        }
        return $found;
    }

    /** Two answers with the same amounts (2 × $402.02 out of three $402.02 invoices) are the same answer. Pure. */
    public static function interchangeable(array $answers, array $cents): bool
    {
        if (count($answers) < 2) return true;
        $sig = function (array $idx) use ($cents) { $v = array_map(fn($i) => $cents[$i], $idx); sort($v); return implode(',', $v); };
        $first = $sig($answers[0]);
        foreach ($answers as $a) if ($sig($a) !== $first) return false;
        return true;
    }

    /**
     * Match every deposit. Pure.
     * @param array $deposits   id, date, amount, gst_amount, description
     * @param array $cands      key => candidate (cand())
     * @param array $aliases    list of [sender_name, payer_name]
     * @param array $opts       cutover, locked ['YYYY-MM'], skipped [txId => note], bbc [txId => entry], bank_sync [txId => true]
     * @return list<array> one line per deposit
     */
    public static function match(array $deposits, array $cands, array $aliases = [], array $opts = []): array
    {
        $cutover = (string)($opts['cutover'] ?? self::CUTOVER);
        $locked = array_flip($opts['locked'] ?? []);
        $skipped = $opts['skipped'] ?? [];
        usort($deposits, fn($a, $b) => [$a['date'], (int)$a['id']] <=> [$b['date'], (int)$b['id']]);
        $left = [];                // candidate key => amount still available
        foreach ($cands as $k => $c) $left[$k] = $c['amount'];
        $lines = []; $ident = [];
        foreach ($deposits as $d) {
            $id = (int)$d['id'];
            $ident[$id] = self::identity((string)$d['description'], $aliases);
            $lines[$id] = self::line($d, $locked, $skipped);
            if ($lines[$id]['outcome'] === 'skipped') continue;
            if ($d['date'] >= $cutover && preg_match('/\bSTRIPE\b/i', (string)$d['description'])) {
                $lines[$id]['outcome'] = 'stripe';
                $lines[$id]['why'] = 'Stripe payout after the cutover — the income clean-up books it from Stripe itself. Not touched here.';
            }
        }
        // Same day, same amount, same memo: a batch (Dorset's pre-authorized credits) — which twin takes which item doesn't matter.
        $twins = [];
        foreach ($deposits as $d) {
            $k = $d['date'] . '|' . number_format((float)$d['amount'], 2, '.', '') . '|' . implode(' ', $ident[(int)$d['id']]['words']);
            $twins[$k] = ($twins[$k] ?? 0) + 1;
        }
        $twinsOf = fn(array $d) => $twins[$d['date'] . '|' . number_format((float)$d['amount'], 2, '.', '') . '|' . implode(' ', $ident[(int)$d['id']]['words'])] ?? 1;
        $open = function (int $id) use (&$lines): bool { return $lines[$id]['outcome'] === 'needs_you' && !$lines[$id]['targets']; };
        $avail = function (array $c, array $d) use (&$left): bool {
            if ($left[$c['key']] <= self::CENT) return false;
            return self::inWindow($c, $d['date']);
        };

        // 1. One candidate of exactly the amount — named payers across all deposits first.
        foreach ([true, false] as $namedPass) {
            foreach ($deposits as $d) {
                $id = (int)$d['id'];
                if (!$open($id)) continue;
                $hits = [];
                foreach ($cands as $k => $c) {
                    if (!$avail($c, $d) || abs($left[$k] - $d['amount']) >= self::CENT) continue;
                    $why = self::named($ident[$id], $c);
                    if ($namedPass && $why === null) continue;
                    $hits[] = $c + ['_why' => $why];
                }
                if (!$hits) continue;
                usort($hits, fn($a, $b) => self::rankKey($a, $d) <=> self::rankKey($b, $d));
                $best = $hits[0];
                $others = array_values(array_filter($hits, fn($h) => $h['key'] !== $best['key']));
                if ($namedPass) {
                    $sameKind = !array_filter($others, fn($h) => $h['_why'] !== null && $h['group'] !== $best['group']);
                    $conf = $sameKind || count($hits) <= $twinsOf($d) ? 'high' : 'medium';
                    if (self::gap($best, $d) > 14 && $best['kind'] !== 'crm_open' && $best['kind'] !== 'jb_inv') $conf = 'medium';
                } else {
                    $groups = array_unique(array_map(fn($h) => $h['group'] . '|' . $h['family'], $hits));
                    if (count($hits) === 1) $conf = 'medium';
                    elseif (count($groups) === 1 || !array_filter($hits, fn($h) => !in_array($h['kind'], ['crm_paid', 'crm_rec'], true))) $conf = 'medium';
                    else $conf = 'low';
                    if (self::gap($best, $d) > 21 && in_array($best['kind'], ['jb_pay', 'crm_rec', 'crm_paid'], true)) $conf = 'low';
                }
                $note = $others ? count($others) . ' other candidate' . (count($others) === 1 ? '' : 's') . ' of the same amount — picked ' .
                                  ($best['_why'] ? 'the one the memo names' : 'the closest date') . '.' : null;
                self::assign($lines[$id], [[$best, $d['amount']]], $conf, $best['_why'], $note, $left);
                $lines[$id]['alternatives'] = array_map(fn($h) => self::brief($h, $left[$h['key']]), array_slice($others, 0, 4));
            }
        }

        // 2. Several of one payer's candidates adding up to the cent.
        foreach ($deposits as $d) {
            $id = (int)$d['id'];
            if (!$open($id)) continue;
            $target = (int)round($d['amount'] * 100);
            $pool = [];
            foreach ($cands as $k => $c) {
                if (!$avail($c, $d) || $left[$k] >= $d['amount'] - self::CENT) continue;
                if (empty($c['partial_ok']) && $left[$k] < $c['amount'] - self::CENT) continue;
                $pool[] = $c + ['_why' => self::named($ident[$id], $c)];
            }
            if (!$pool) continue;
            $named = array_values(array_filter($pool, fn($c) => $c['_why'] !== null));
            $tries = [];
            if ($named) {
                // Everything the memo names is one payer (a property manager pays for several strata plans), per booking path.
                foreach (['crm_rec', 'jobber', 'crm_paid', 'crm_open'] as $fam) {
                    $set = array_values(array_filter($named, fn($c) => $c['family'] === $fam));
                    if (count($set) >= 2) $tries[] = ['named', $set];
                }
            } else {
                $by = [];
                foreach ($pool as $c) {
                    if ($c['group'] !== '') $by[$c['family'] . '|' . $c['group']][] = $c;
                    if ($c['kind'] === 'jb_pay' && in_array($c['method'], ['cheque', 'other'], true)) $by['jobber|day:' . $c['date']][] = $c;
                }
                foreach ($by as $set) if (count($set) >= 2) $tries[] = ['group', $set];
            }
            $hits = [];
            foreach ($tries as [$how, $set]) {
                usort($set, fn($a, $b) => [abs(self::gap($a, $d)) > 45 ? 1 : 0, $a['date'], $a['key']] <=> [abs(self::gap($b, $d)) > 45 ? 1 : 0, $b['date'], $b['key']]);
                $set = array_slice($set, 0, self::POOL);
                $cents = array_map(fn($c) => (int)round($left[$c['key']] * 100), $set);
                $ans = self::subsets($cents, $target);
                if (!$ans || count($ans[0]) < 2) continue;
                $hits[] = ['how' => $how, 'set' => array_map(fn($i) => $set[$i], $ans[0]), 'unique' => self::interchangeable($ans, $cents)];
                if ($how === 'named') break;
            }
            if (!$hits) continue;
            if (count($hits) > 1) {
                $lines[$id]['why'] = 'Adds up exactly to the invoices of ' . count($hits) . ' different payers — which one?';
                $lines[$id]['alternatives'] = array_map(fn($h) => ['number' => implode(' + ', array_column($h['set'], 'number')), 'payer' => $h['set'][0]['payer'],
                                                                  'amount' => $d['amount'], 'kind' => $h['set'][0]['kind']], array_slice($hits, 0, 4));
                continue;
            }
            $h = $hits[0];
            $conf = $h['how'] === 'named' ? ($h['unique'] ? 'high' : 'medium') : ($h['unique'] ? 'medium' : 'low');
            $why = $h['how'] === 'named' ? $h['set'][0]['_why'] : null;
            $note = $h['unique'] ? null : 'Another combination of the same payer\'s items also adds up — picked the oldest.';
            self::assign($lines[$id], array_map(fn($c) => [$c, $left[$c['key']]], $h['set']), $conf, $why, $note, $left);
        }

        // 3. Partials: a named payer's open invoice receiving less than it owes (or the rest of it).
        foreach ($deposits as $d) {
            $id = (int)$d['id'];
            if (!$open($id)) continue;
            $fit = [];
            foreach ($cands as $k => $c) {
                if (empty($c['partial_ok']) || !$avail($c, $d)) continue;
                $why = self::named($ident[$id], $c);
                if ($why === null || $left[$k] < $d['amount'] - self::CENT) continue;
                $fit[] = $c + ['_why' => $why];
            }
            if (!$fit) continue;
            usort($fit, fn($a, $b) => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
            $c = $fit[0];
            $rest = abs($left[$c['key']] - $d['amount']) < self::CENT;
            $conf = count($fit) === 1 ? 'medium' : 'low';
            $note = $rest ? 'Pays the rest of ' . $c['number'] . ' after an earlier part-payment.'
                          : 'Part-payment: ' . $c['number'] . ' owes $' . number_format($left[$c['key']], 2) . ' — $' . number_format($left[$c['key']] - $d['amount'], 2) . ' would still be owing.';
            if (count($fit) > 1) $note .= ' ' . (count($fit) - 1) . ' other open invoice(s) of this payer could take it — picked the oldest.';
            self::assign($lines[$id], [[$c, $d['amount']]], $conf, $c['_why'], $note, $left);
            $lines[$id]['partial'] = !$rest;
        }

        // 4. What's left: closest candidates; flags; signatures.
        $out = [];
        foreach ($deposits as $d) {
            $id = (int)$d['id'];
            $l = $lines[$id];
            if ($l['outcome'] === 'needs_you') {
                if ($l['why'] === '') {
                    $l['why'] = 'Nothing adds up to $' . number_format($d['amount'], 2) . ' — a partial with no payer named, a deposit that isn\'t a client payment, or an invoice not in the CRM or the Jobber import.';
                }
                $l['flag'] = IncomeCleanupService::nonIncomeReason((string)$d['description']);
                $l['candidates'] = self::closest($d, $cands, $left, $ident[$id]);
            }
            if (in_array($l['outcome'], self::PROPOSED, true)) {
                if ($l['source'] === 'crm' && isset(($opts['bbc'] ?? [])[$id])) {
                    $l['blocked'] = 'The bank balance check booked this deposit as income (journal entry #' . $opts['bbc'][$id] . '); tying it to a CRM invoice would count the revenue twice in the journal. Reverse that entry first.';
                }
                if ($l['source'] === 'jobber' && isset(($opts['bank_sync'] ?? [])[$id])) {
                    $l['blocked'] = 'The nightly bank sync already posted this deposit to the journal — the Jobber booking would post it twice.';
                }
                if ($l['source'] === 'jobber' && isset(($opts['bbc'] ?? [])[$id])) {
                    $l['flags'][] = 'Already booked as income by the bank balance check (entry #' . $opts['bbc'][$id] . ') — that entry is reversed first.';
                }
            }
            $out[] = self::sign($l);
        }
        return $out;
    }

    private static function line(array $d, array $locked, array $skipped): array
    {
        $id = (int)$d['id'];
        $month = substr($d['date'], 0, 7);
        return ['id' => $id, 'date' => $d['date'], 'month' => $month, 'amount' => round((float)$d['amount'], 2), 'description' => (string)$d['description'],
                'outcome' => isset($skipped[$id]) ? 'skipped' : 'needs_you', 'confidence' => null, 'source' => null, 'action' => 'none',
                'targets' => [], 'why' => isset($skipped[$id]) ? 'You skipped this deposit' . ($skipped[$id] !== '' ? ': ' . $skipped[$id] : '') . '.' : '',
                'named' => null, 'note' => null, 'partial' => false, 'fy2025_part' => 0.0, 'income_delta' => 0.0, 'flag' => null, 'flags' => [],
                'blocked' => null, 'locked' => isset($locked[$month]), 'candidates' => [], 'alternatives' => [], 'skip_note' => $skipped[$id] ?? null];
    }

    /** Put candidates on a line and take what they used off what's available. */
    private static function assign(array &$line, array $picks, string $conf, ?string $why, ?string $note, array &$left): void
    {
        $first = $picks[0][0];
        $line['outcome'] = $conf;
        $line['confidence'] = $conf;
        $line['source'] = $first['family'] === 'jobber' ? 'jobber' : 'crm';
        $line['action'] = ['crm_rec' => 'link_payments', 'crm_paid' => 'already_recorded', 'crm_open' => 'record_payment', 'jobber' => 'jobber'][$first['family']];
        $line['named'] = $why;
        $line['note'] = $note;
        $pre = 0.0;
        foreach ($picks as [$c, $amt]) {
            $amt = round((float)$amt, 2);
            if (!empty($c['deposit_kind'])) {
                $line['flags'][] = 'Includes a prepayment on a Jobber quote — held in 2160 Customer Deposits until the job is invoiced.';
                if ($line['confidence'] === 'high') $line['outcome'] = $line['confidence'] = 'medium';
            }
            $line['targets'][] = self::brief($c, $amt);
            $left[$c['key']] = round($left[$c['key']] - $amt, 2);
            if ($c['kind'] === 'jb_pay') $pre += (float)$c['pre2026'];
            if ($c['kind'] === 'jb_inv' && (string)$c['issued'] < self::FY_START) $pre += $amt;
        }
        $line['fy2025_part'] = round(min($pre, $line['amount']), 2);
        $line['income_delta'] = in_array($line['action'], ['link_payments', 'already_recorded'], true) ? -$line['amount']
                              : ($line['action'] === 'jobber' ? -$line['fy2025_part'] : 0.0);
        $n = count($picks);
        $m = '$' . number_format($line['amount'], 2);
        $list = implode(', ', array_column($line['targets'], 'number'));
        $payer = $first['payer'] !== '' ? ' (' . $first['payer'] . ')' : '';
        $line['why'] = [
            'link_payments'    => "{$m} is the payment already recorded on {$list}{$payer} — link it so it counts once.",
            'already_recorded' => "{$m} = {$list}{$payer}, already marked paid — link it so it counts once.",
            'record_payment'   => "{$m} pays {$list}{$payer} — record it on the invoice" . ($n > 1 ? 's' : '') . ' (income moves from the bank line to the invoice).',
            'jobber'           => "{$m} paid {$list}{$payer}" . ($line['fy2025_part'] > self::CENT ? ' — $' . number_format($line['fy2025_part'], 2) . ' of it settles 2025 receivable, not 2026 income.' : ' — 2026 income stays on the deposit.'),
        ][$line['action']];
    }

    /** The bits of a candidate a page needs. Pure. */
    public static function brief(array $c, float $amount): array
    {
        return ['key' => $c['key'], 'kind' => $c['kind'], 'number' => $c['number'], 'payer' => $c['payer'], 'amount' => round($amount, 2),
                'owing' => round($c['amount'], 2), 'date' => $c['date'], 'issued' => $c['issued'] ?? null,
                'year' => !empty($c['issued']) ? substr((string)$c['issued'], 0, 4) : null];
    }

    public static function publicCandidate(array $c): array
    {
        return ['key' => $c['key'], 'kind' => $c['kind'], 'number' => $c['number'], 'payer' => $c['payer'], 'names' => $c['names'],
                'amount' => $c['amount'], 'date' => $c['date'], 'issued' => $c['issued'] ?? null, 'partial_ok' => !empty($c['partial_ok']),
                'method' => $c['method'] ?? null, 'pre2026' => $c['pre2026'] ?? 0.0];
    }

    private static function inWindow(array $c, string $date): bool
    {
        [$lo, $hi] = self::WINDOWS[$c['kind']];
        $on = (string)$c['date'];
        if ($on === '') return in_array($c['kind'], ['crm_open', 'jb_inv'], true);
        if ($c['kind'] === 'jb_pay' && ($c['method'] ?? '') === 'card') { $lo = -10; $hi = 0; }   // a payout lands after its charges
        $gap = self::days($on, $date);
        return $gap >= $lo && $gap <= $hi;
    }

    /** Candidate date minus deposit date, in days. */
    private static function gap(array $c, array $d): int
    {
        return $c['date'] === '' ? 0 : self::days((string)$c['date'], $d['date']);
    }

    private static function rankKey(array $c, array $d): array
    {
        $date = in_array($c['kind'], ['crm_open', 'jb_inv'], true) ? 0 : abs(self::gap($c, $d));
        return [$c['_why'] === null ? 1 : 0, self::RANK[$c['kind']], $date, $c['date'], $c['key']];
    }

    private static function days(string $a, string $b): int
    {
        return (int)round((strtotime(substr($a, 0, 10) . ' 12:00:00') - strtotime(substr($b, 0, 10) . ' 12:00:00')) / 86400);
    }

    /** Up to 5 nearest amounts in the window (named payers first). */
    private static function closest(array $d, array $cands, array $left, array $ident): array
    {
        $c = [];
        foreach ($cands as $k => $x) {
            if ($left[$k] <= self::CENT || !self::inWindow($x, $d['date'])) continue;
            $why = self::named($ident, $x);
            $c[] = [$why === null ? 1 : 0, abs($left[$k] - $d['amount']), self::brief($x, $left[$k]) + ['named' => $why, 'diff' => round($left[$k] - $d['amount'], 2)]];
        }
        usort($c, fn($a, $b) => [$a[0], $a[1], $a[2]['key']] <=> [$b[0], $b[1], $b[2]['key']]);
        return array_map(fn($x) => $x[2], array_slice($c, 0, 5));
    }

    /** Stable fingerprint of what approving a line would do. Pure. */
    public static function sign(array $l): array
    {
        $t = array_map(fn($x) => $x['key'] . '=' . number_format($x['amount'], 2, '.', ''), $l['targets']);
        sort($t);
        $l['signature'] = substr(sha1(implode('|', [$l['id'], $l['outcome'], $l['action'], number_format($l['amount'], 2, '.', ''), implode(',', $t), (string)$l['blocked']])), 0, 16);
        return $l;
    }

    public static function bookable(array $l): bool
    {
        return in_array($l['outcome'], self::PROPOSED, true) && $l['action'] !== 'none' && !$l['locked'] && $l['blocked'] === null;
    }

    /** Every open invoice (CRM + Jobber) and whether money for it is sitting in the bank. Pure. */
    public static function openCoverage(array $cands, array $lines): array
    {
        $paid = [];
        foreach ($lines as $l) {
            if (!in_array($l['outcome'], self::PROPOSED, true)) continue;
            foreach ($l['targets'] as $t) {
                $paid[$t['key']][] = ['transaction_id' => $l['id'], 'date' => $l['date'], 'amount' => $t['amount'], 'confidence' => $l['confidence']];
            }
        }
        $out = [];
        foreach ($cands as $c) {
            if (!in_array($c['kind'], ['crm_open', 'jb_inv'], true)) continue;
            $deps = $paid[$c['key']] ?? [];
            $in = round(array_sum(array_column($deps, 'amount')), 2);
            $out[] = ['key' => $c['key'], 'kind' => $c['kind'], 'number' => $c['number'], 'payer' => $c['payer'], 'issued' => $c['issued'] ?? null,
                      'owing' => $c['amount'], 'in_bank' => $in, 'deposits' => $deps,
                      'status' => $in <= self::CENT ? 'none' : ($in >= $c['amount'] - self::CENT ? 'covered' : 'partly'),
                      'year' => !empty($c['issued']) ? substr((string)$c['issued'], 0, 4) : null];
        }
        usort($out, fn($a, $b) => [$a['status'] === 'none' ? 1 : 0, (string)$a['issued'], $a['key']] <=> [$b['status'] === 'none' ? 1 : 0, (string)$b['issued'], $b['key']]);
        return $out;
    }

    /** Counts and $ by outcome / source / action; what booking everything would change. Pure. */
    public static function summarise(array $lines, array $open, array $cands = []): array
    {
        $z = fn() => ['count' => 0, 'total' => 0.0];
        $by = []; foreach (self::OUTCOMES as $o) $by[$o] = $z();
        $src = ['crm' => $z(), 'jobber' => $z()];
        $act = ['link_payments' => $z(), 'already_recorded' => $z(), 'record_payment' => $z(), 'jobber' => $z()];
        $prop = $z(); $book = $z(); $blocked = $z(); $locked = $z(); $partials = $z();
        $fy25 = 0.0; $delta = 0.0; $dep = $z(); $flagged = $z();
        $add = function (array &$b, float $v) { $b['count']++; $b['total'] += $v; };
        foreach ($lines as $l) {
            $add($dep, $l['amount']);
            $add($by[$l['outcome']], $l['amount']);
            if ($l['flag']) $add($flagged, $l['amount']);
            if (!in_array($l['outcome'], self::PROPOSED, true)) continue;
            $add($prop, $l['amount']);
            $add($src[$l['source']], $l['amount']);
            $add($act[$l['action']], $l['amount']);
            if ($l['partial']) $add($partials, $l['amount']);
            if ($l['blocked']) $add($blocked, $l['amount']);
            if ($l['locked']) $add($locked, $l['amount']);
            if (self::bookable($l)) {
                $add($book, $l['amount']);
                $fy25 += $l['fy2025_part'];
                $delta += $l['income_delta'];
            }
        }
        $cov = ['crm' => [], 'jobber' => []];
        foreach (['crm', 'jobber'] as $s) foreach (['open', 'covered', 'partly', 'none'] as $k) $cov[$s][$k] = $z();
        foreach ($open as $o) {
            $s = $o['kind'] === 'crm_open' ? 'crm' : 'jobber';
            $add($cov[$s]['open'], $o['owing']);
            $add($cov[$s][$o['status']], $o['status'] === 'none' ? $o['owing'] : $o['in_bank']);
        }
        $jp = $z();
        foreach ($cands as $c) if ($c['kind'] === 'jb_pay') $add($jp, $c['amount']);
        $jpLeft = $jp;
        foreach ($lines as $l) {
            if (!in_array($l['outcome'], self::PROPOSED, true)) continue;
            foreach ($l['targets'] as $t) if ($t['kind'] === 'jb_pay') { $jpLeft['count']--; $jpLeft['total'] -= $t['amount']; }
        }
        $round = function ($x) use (&$round) {
            if (is_array($x)) return array_map($round, $x);
            return is_float($x) ? round($x, 2) : $x;
        };
        return $round([
            'deposits' => $dep, 'by_outcome' => $by, 'proposed' => $prop, 'bookable' => $book, 'by_source' => $src, 'by_action' => $act,
            'partials' => $partials, 'blocked' => $blocked, 'locked' => $locked, 'flagged_not_income' => $flagged,
            'fy2025_settled' => $fy25, 'income_2026_delta' => $delta,
            'open_invoices' => $cov,
            'jobber_payments_unbooked' => $jp, 'jobber_payments_no_deposit' => $jpLeft,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // BOOK / MANUAL / SKIP / UNDO
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Book approved lines. $approved: txId => signature the page showed. The scan is rebuilt here;
     * a line is booked only if it is still proposed, bookable and has the same signature.
     */
    public function book(array $approved, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'booked' => [], 'failed' => [['id' => 0, 'message' => 'Run migration 1260 first — every booking is logged so it can be undone.']]];
        $lines = [];
        foreach ($this->scan()['lines'] as $l) $lines[$l['id']] = $l;
        $booked = []; $failed = [];
        foreach ($approved as $tx => $sig) {
            $tx = (int)$tx;
            $l = $lines[$tx] ?? null;
            $why = null;
            if (!$l) $why = 'That deposit is no longer waiting (booked or changed elsewhere) — reload.';
            elseif (!in_array($l['outcome'], self::PROPOSED, true)) $why = 'Nothing proposed for this deposit any more — reload.';
            elseif ($l['locked']) $why = $l['month'] . ' is locked — nothing in a closed month changes.';
            elseif ($l['blocked']) $why = $l['blocked'];
            elseif (!hash_equals($l['signature'], (string)$sig)) $why = 'This line changed since you looked — reload and check it again.';
            if ($why !== null) { $failed[] = ['id' => $tx, 'message' => $why]; continue; }
            try {
                $booked[] = ['id' => $tx] + $this->bookLine($l, $userId, (string)$l['confidence']);
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                $failed[] = ['id' => $tx, 'message' => $e->getMessage()];
            }
        }
        if ($booked) $this->refreshSnapshot();
        return ['ok' => (bool)$booked && !$failed, 'booked' => $booked, 'failed' => $failed,
                'message' => count($booked) . ' deposit(s) booked' . ($failed ? ', ' . count($failed) . ' not' : '') . '.'];
    }

    /**
     * Tim ticked a deposit and the items it paid. $targets: list of ['key' => 'crm_open:12', 'amount' => 100.00?].
     * One booking path per deposit (all CRM recorded / all CRM paid / all CRM open / all Jobber); must add up to the cent.
     */
    public function manual(int $txId, array $targets, int $userId): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1260 first.'];
        $scan = $this->scanRaw();
        $dep = null;
        foreach ($scan['deposits'] as $d) if ($d['id'] === $txId) $dep = $d;
        if (!$dep) return ['ok' => false, 'message' => 'That deposit is not waiting any more — reload.'];
        if (in_array(substr($dep['date'], 0, 7), $scan['locked'], true)) return ['ok' => false, 'message' => substr($dep['date'], 0, 7) . ' is locked.'];
        $picks = [];
        foreach ($targets as $t) {
            $c = $scan['candidates'][(string)($t['key'] ?? '')] ?? null;
            if (!$c) return ['ok' => false, 'message' => 'One of the items you ticked is no longer open — reload.'];
            $amt = isset($t['amount']) && $t['amount'] !== '' && $t['amount'] !== null ? round((float)$t['amount'], 2) : $c['amount'];
            if (empty($c['partial_ok']) && abs($amt - $c['amount']) >= self::CENT) return ['ok' => false, 'message' => $c['number'] . ' can only be taken whole ($' . number_format($c['amount'], 2) . ').'];
            if ($amt <= self::CENT || $amt > $c['amount'] + self::CENT) return ['ok' => false, 'message' => $c['number'] . ' owes $' . number_format($c['amount'], 2) . ' — that amount doesn\'t fit.'];
            $picks[] = [$c, $amt];
        }
        if (!$picks) return ['ok' => false, 'message' => 'Tick what this deposit paid.'];
        $fams = array_unique(array_map(fn($p) => $p[0]['family'], $picks));
        if (count($fams) > 1) return ['ok' => false, 'message' => 'One deposit, one kind: recorded payments, paid invoices, open invoices, or Jobber — not a mix. (Book a mixed deposit in the income clean-up / Jobber import.)'];
        $sum = round(array_sum(array_map(fn($p) => $p[1], $picks)), 2);
        if (abs($sum - $dep['amount']) >= self::CENT) {
            return ['ok' => false, 'message' => sprintf('Those come to $%s; the deposit is $%s (difference $%s).', number_format($sum, 2), number_format($dep['amount'], 2), number_format($dep['amount'] - $sum, 2))];
        }
        $line = self::line($dep, [], []);
        $left = [];
        foreach ($picks as [$c]) $left[$c['key']] = $c['amount'];
        self::assign($line, $picks, 'manual', 'picked by hand', null, $left);
        if ($line['source'] === 'crm' && isset($scan['bbc'][$txId])) return ['ok' => false, 'message' => 'The bank balance check booked this deposit as income (journal entry #' . $scan['bbc'][$txId] . ') — reverse that entry first.'];
        try {
            $r = $this->bookLine(self::sign($line), $userId, 'manual');
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $this->refreshSnapshot();
        return ['ok' => true, 'message' => 'Booked: ' . $line['why']] + $r;
    }

    /** One line through the existing path. Logged in payment_match_log. */
    protected function bookLine(array $l, int $userId, string $confidence): array
    {
        $tx = (int)$l['id'];
        $targets = $l['targets'];
        if ($l['source'] === 'jobber') {
            $payIds = []; $inv = [];
            $cands = $this->scanRaw()['candidates'];
            foreach ($targets as $t) {
                $c = $cands[$t['key']] ?? null;
                if (!$c) throw new RuntimeException($t['number'] . ' is no longer open — reload.');
                if ($c['kind'] === 'jb_pay') $payIds = array_merge($payIds, $c['payment_ids']);
                else $inv[] = ['jobber_invoice_id' => (int)$c['id'], 'amount' => $t['amount']];
            }
            $r = $this->jobber()->bookMatched($tx, $payIds, $inv, 'Payment match: ' . ($l['named'] ?: 'amount') . ' (' . $confidence . ')', $userId);
            if (empty($r['ok'])) throw new RuntimeException($r['message'] ?? 'Jobber booking refused.');
            $logId = $this->log($l, $confidence, 'jobber', (string)$r['batch_id'], $userId, (float)($r['fy2025_part'] ?? $l['fy2025_part']));
            return ['log_id' => $logId, 'path' => 'jobber', 'batch_id' => $r['batch_id'], 'message' => $r['message']];
        }
        $ic = [
            'id' => $tx, 'date' => $l['date'], 'month' => $l['month'], 'amount' => $l['amount'], 'gst' => 0.0, 'description' => $l['description'],
            'bucket' => count($targets) > 1 ? 'exact_sum' : 'exact', 'action' => $l['action'],
            'invoice_ids' => [], 'invoice_numbers' => array_column($targets, 'number'), 'allocation_ids' => [], 'allocations' => [],
            'payer' => $targets[0]['payer'] ?? '', 'say' => 'Payment match (' . $confidence . '): ' . $l['why'], 'note' => $l['note'],
            'income_delta' => $l['income_delta'], 'locked' => false, 'skipped' => false, 'flag' => null, 'candidates' => [],
        ];
        $cands = $this->scanRaw()['candidates'];
        foreach ($targets as $t) {
            $c = $cands[$t['key']] ?? null;
            if (!$c) throw new RuntimeException($t['number'] . ' is no longer open — reload.');
            $ic['invoice_ids'][] = (int)$c['invoice_id'];
            if ($c['kind'] === 'crm_rec') $ic['allocation_ids'][] = (int)$c['id'];
            if ($c['kind'] === 'crm_open') $ic['allocations'][] = ['invoice_id' => (int)$c['invoice_id'], 'amount' => round((float)$t['amount'], 2)];
        }
        $ic['invoice_ids'] = array_values(array_unique($ic['invoice_ids']));
        $r = $this->cleanup()->bookFor($ic, $userId);
        $logId = $this->log($l, $confidence, 'income_cleanup', (string)$r['log_id'], $userId, 0.0);
        return ['log_id' => $logId, 'path' => 'income_cleanup', 'cleanup_log_id' => $r['log_id']];
    }

    public function skip(int $txId, int $userId, string $note = ''): array
    {
        if (!$this->ready()) return ['ok' => false, 'message' => 'Run migration 1260 first.'];
        $line = null;
        foreach ($this->scan()['lines'] as $l) if ($l['id'] === $txId) $line = $l;
        if (!$line) return ['ok' => false, 'message' => 'That deposit is no longer waiting.'];
        if ($line['outcome'] === 'skipped') return ['ok' => true, 'message' => 'Already skipped.'];
        $line['note'] = trim($note);
        $this->db->prepare("INSERT INTO payment_match_log (transaction_id, confidence, source, action, amount, targets, signature, status, note, created_by, created_at)
                            VALUES (?, ?, ?, 'skip', ?, ?, ?, 'skipped', ?, ?, ?)")
           ->execute([$txId, $line['confidence'], $line['source'], $line['amount'], json_encode($line['targets']), $line['signature'], mb_substr(trim($note), 0, 500) ?: null, $userId, date('Y-m-d H:i:s')]);
        $this->refreshSnapshot();
        return ['ok' => true, 'message' => 'Skipped — left exactly as it is.'];
    }

    public function unskip(int $txId, int $userId): array
    {
        $this->db->prepare("UPDATE payment_match_log SET status = 'unskipped', undone_by = ?, undone_at = ? WHERE transaction_id = ? AND status = 'skipped'")
           ->execute([$userId, date('Y-m-d H:i:s'), $txId]);
        $this->refreshSnapshot();
        return ['ok' => true, 'message' => 'Back on the list.'];
    }

    /** Undo one approval through the path that booked it. */
    public function undo(int $logId, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM payment_match_log WHERE id = ?");
        $s->execute([$logId]);
        $log = $s->fetch(PDO::FETCH_ASSOC);
        if (!$log || $log['status'] !== 'booked') return ['ok' => false, 'message' => 'Nothing to undo there.'];
        $r = $log['path'] === 'jobber'
            ? $this->jobber()->undo((string)$log['path_ref'], $userId)
            : $this->cleanup()->reverse((int)$log['path_ref'], $userId);
        if (empty($r['ok'])) return ['ok' => false, 'message' => 'Not undone: ' . ($r['message'] ?? 'unknown')];
        $this->db->prepare("UPDATE payment_match_log SET status = 'undone', undone_by = ?, undone_at = ? WHERE id = ?")
           ->execute([$userId, date('Y-m-d H:i:s'), $logId]);
        $this->refreshSnapshot();
        return ['ok' => true, 'message' => 'Undone — the deposit is back as it was.'];
    }

    public function recentLog(int $limit = 40): array
    {
        try {
            $s = $this->db->prepare("SELECT id, transaction_id, confidence, source, action, amount, fy2025_part, targets, status, note, created_at, undone_at
                                     FROM payment_match_log ORDER BY id DESC LIMIT " . max(1, min(500, $limit)));
            $s->execute();
            return array_map(function ($r) {
                $t = json_decode((string)$r['targets'], true) ?: [];
                $r['numbers'] = array_column($t, 'number');
                unset($r['targets']);
                return $r;
            }, $s->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** load() once per request for booking (candidates by key). */
    private ?array $raw = null;
    private function scanRaw(): array
    {
        return $this->raw ??= $this->load();
    }

    private function refreshSnapshot(): void
    {
        $this->raw = null;
        try { $this->snapshot($this->scan()['summary']); } catch (Throwable $e) { /* cache only */ }
    }

    private function log(array $l, string $confidence, string $path, string $ref, int $userId, float $fy25): int
    {
        $this->db->prepare("INSERT INTO payment_match_log (transaction_id, confidence, source, action, amount, fy2025_part, targets, signature, path, path_ref, status, note, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', ?, ?, ?)")
           ->execute([(int)$l['id'], $confidence, $l['source'], $l['action'], $l['amount'], round($fy25, 2), json_encode($l['targets']), $l['signature'] ?? null,
                      $path, $ref, $l['note'] ? mb_substr((string)$l['note'], 0, 500) : null, $userId, date('Y-m-d H:i:s')]);
        $this->raw = null;
        return (int)$this->db->lastInsertId();
    }

    protected function cleanup(): PaymentMatchCleanupBridge
    {
        return new PaymentMatchCleanupBridge($this->db, $this->ledger);
    }

    protected function jobber(): JobberLedgerService
    {
        return new JobberLedgerService($this->db, $this->ledger ??= new LedgerService($this->db));
    }

    protected function lockedMonths(): array
    {
        $out = [];
        try {
            foreach ($this->db->query("SELECT year, month FROM accounting_periods WHERE status = 'locked'")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $out[] = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
            }
        } catch (Throwable $e) { /* nothing locked */ }
        return $out;
    }

    private function idSet(string $sql): array
    {
        try {
            return array_fill_keys(array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN)), true);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function has(string $sql): bool
    {
        try {
            $this->db->query($sql);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * The income clean-up's own booking path, for a line the payment matcher built (same actions,
 * same checks — assertOwes / assertStillPaidUnlinked — same income_cleanup_log, same reverse()).
 */
class PaymentMatchCleanupBridge extends IncomeCleanupService
{
    public function bookFor(array $line, int $userId): array
    {
        return $this->bookLine(self::sign($line), $userId);
    }
}
