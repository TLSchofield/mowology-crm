<?php
/**
 * DepositBackfillService — link orphan bank deposits to what they paid (owner, 2026-10-11).
 * Plan: docs/plans/2026-10-11-penny-payment-certainty.md, step 2.
 *
 * Orphans = income bank deposits since the Jobber cutover with no invoice linked. Candidates:
 *   - CRM invoices already marked PAID with no deposit behind them (the "hand-recorded payment"
 *     gap): the invoice's ledger row already recognises the income, so the deposit is the SAME
 *     money counted again → link it (allocation + deposit becomes a cash-clearing transfer, via
 *     InvoiceReconciliationService::linkAllocationsToDeposit — the same path as "Already recorded").
 *   - Jobber payments (jobber_payments): Jobber invoices are not in the books, the deposit is the
 *     only income record for that era → the deposit STAYS income; bank_deposit_sources records
 *     which payment it was (payer, method, Jobber invoice numbers) for Penny's payment habits.
 *
 * Matching (pure, unit-tested): method from the bank memo must fit the payment's method; payment
 * dated 30 days before to 5 days after the deposit; exact amount (one candidate, or several where
 * the memo names one payer); otherwise an exact group of up to 5 (cheques banked together).
 * Deposits that mix CRM and Jobber payments, ambiguous ones and unmatched ones are only listed.
 * Nothing dated before 2026-02-25 (FY2025 is filed and locked) is touched.
 *
 * Global-namespace service: require_once the file.
 */
declare(strict_types=1);

require_once __DIR__ . '/InvoiceReconciliationService.php';

class DepositBackfillService
{
    public const SINCE = '2026-02-25';
    public const DAYS_BEFORE = 30;
    public const DAYS_AFTER = 5;
    /** Certain = the payment was recorded within this many days of the deposit … */
    public const CERTAIN_DAYS = 3;
    /** … and no other payment of the same amount lies within this many days of it. */
    public const UNIQUE_DAYS = 60;   // (kept for reference; pairing now uses CERTAIN_DAYS — see doubt())
    /** A bank wording is taught to Penny only when at least this many certain links agree. */
    public const TEACH_MIN = 2;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════════════════
    // PURE (unit-tested)
    // ══════════════════════════════════════════════════════════════════════

    /** What the bank memo says this deposit is: cheque | e_transfer | stripe | eft | other. */
    public static function kindOf(string $memo): string
    {
        $u = strtoupper((string)preg_replace('/[^A-Za-z]/', '', $memo));
        if (str_contains($u, 'CHEQUE') || str_contains($u, 'MOBILEDEPOSIT') || str_contains($u, 'ATM')) return 'cheque';
        if (str_contains($u, 'ETRANSFER')) return 'e_transfer';
        if (str_contains($u, 'STRIPE')) return 'stripe';
        if (str_contains($u, 'PREAUTHORIZED')) return 'eft';
        return 'other';
    }

    /** The allocation method for a deposit of this kind (when the invoice recorded none). */
    public static function methodForKind(string $kind): string
    {
        return ['cheque' => 'cheque', 'e_transfer' => 'e_transfer', 'stripe' => 'credit_card'][$kind] ?? 'other';
    }

    /** The payer's name as the bank prints it (shared with Penny's bank card). */
    public static function memoPayer(string $memo): ?string
    {
        return InvoiceReconciliationService::memoPayer($memo);
    }

    /** Can a payment recorded with $method arrive as a deposit of this kind? */
    public static function methodFits(string $kind, string $method): bool
    {
        $m = str_replace([' ', '-'], '_', strtolower($method));
        return match ($kind) {
            'cheque'     => str_contains($m, 'cheque') || str_contains($m, 'check'),
            'e_transfer' => str_contains($m, 'transfer') || str_contains($m, 'interac'),
            'stripe'     => str_contains($m, 'stripe') || str_contains($m, 'card') || str_contains($m, 'jobber'),
            default      => true,
        };
    }

    /**
     * Match one deposit against candidate payments (not yet used).
     * $dep: transaction_date, amount, description. $cands: [key, amount, method, date, name, src].
     * @return array{class: string, pick: array}  class: single | subset | ambiguous | none
     */
    public static function match(array $dep, array $cands): array
    {
        $kind = self::kindOf((string)$dep['description']);
        $dt = strtotime((string)$dep['transaction_date']);
        $amt = round((float)$dep['amount'], 2);
        $pool = array_values(array_filter($cands, function ($c) use ($kind, $dt) {
            if (empty($c['date']) || !self::methodFits($kind, (string)$c['method'])) return false;
            $p = strtotime((string)$c['date']);
            return $p >= $dt - self::DAYS_BEFORE * 86400 && $p <= $dt + self::DAYS_AFTER * 86400;
        }));
        usort($pool, fn($a, $b) => [abs(strtotime($a['date']) - $dt), $a['key']] <=> [abs(strtotime($b['date']) - $dt), $b['key']]);
        $exact = array_values(array_filter($pool, fn($c) => abs(round((float)$c['amount'], 2) - $amt) <= 0.01));
        if (count($exact) === 1) return ['class' => 'single', 'pick' => $exact];
        if (count($exact) > 1) {
            // Monthly contracts repeat the amount: the one recorded within CERTAIN_DAYS is the pair
            // (doubt() then checks nothing else competes inside that window).
            $near = array_values(array_filter($exact, fn($c) => abs(strtotime((string)$c['date']) - $dt) <= self::CERTAIN_DAYS * 86400));
            if (count($near) === 1) return ['class' => 'single', 'pick' => $near];
            $words = InvoiceReconciliationService::bankMemoWords((string)$dep['description']);
            $named = $words ? array_values(array_filter($exact, fn($c) => !empty($c['name']) && InvoiceReconciliationService::payerMatchesWords((string)$c['name'], $words))) : [];
            return count($named) === 1 ? ['class' => 'single', 'pick' => $named] : ['class' => 'ambiguous', 'pick' => $exact];
        }
        if ($kind !== 'e_transfer' && $pool) {   // e-Transfers arrive one by one
            $sub = InvoiceReconciliationService::exactSubsetBounded(array_map(fn($c) => round((float)$c['amount'], 2), $pool), $amt, 5, 14);
            if ($sub) return ['class' => 'subset', 'pick' => array_map(fn($i) => $pool[$i], $sub)];
        }
        return ['class' => 'none', 'pick' => []];
    }

    /**
     * Is this match beyond doubt? One-to-one pairing within CERTAIN_DAYS: every payment recorded
     * within CERTAIN_DAYS of the deposit; for a single payment, no OTHER payment of that amount
     * within CERTAIN_DAYS of the deposit, and no OTHER orphan deposit of that amount within
     * CERTAIN_DAYS of the payment. (Monthly contracts — Macdonald $1,194.39, VML $9,590.41 — repeat
     * the amount every month, so "unique in 60 days" rejected same-day pairs; competition inside
     * the window is what makes a pairing uncertain.) Null = certain, else the reason.
     */
    public static function doubt(array $dep, array $pick, array $allCands, array $allDeps = []): ?string
    {
        $dt = strtotime((string)$dep['transaction_date']);
        $win = self::CERTAIN_DAYS * 86400;
        foreach ($pick as $p) {
            $gap = abs(strtotime((string)$p['date']) - $dt) / 86400;
            if ($gap > self::CERTAIN_DAYS) return sprintf('%s was recorded %d days from the deposit', $p['ref'], (int)round($gap));
        }
        if (count($pick) === 1) {
            $amt = round((float)$pick[0]['amount'], 2);
            $pt = strtotime((string)$pick[0]['date']);
            foreach ($allCands as $c) {
                if ($c['key'] === $pick[0]['key'] || abs(round((float)$c['amount'], 2) - $amt) > 0.01 || empty($c['date'])) continue;
                if (abs(strtotime((string)$c['date']) - $dt) <= $win) return sprintf('%s is the same amount, %s', $c['ref'], $c['date']);
            }
            foreach ($allDeps as $o) {
                if ((int)($o['id'] ?? 0) === (int)($dep['id'] ?? -1) || abs(round((float)$o['amount'], 2) - $amt) > 0.01) continue;
                if (abs(strtotime((string)$o['transaction_date']) - $pt) <= $win) return sprintf('another deposit of the same amount on %s', $o['transaction_date']);
            }
        }
        return null;
    }

    /**
     * Run the deposits in date order; a candidate is used once. Each row gets an action:
     * link_crm (all picks CRM, certain), source_jobber (all picks Jobber, certain),
     * review (mixed, ambiguous or not certain — with the reason), none.
     */
    public static function plan(array $deposits, array $cands): array
    {
        $used = [];
        $out = [];
        foreach ($deposits as $d) {
            $free = array_values(array_filter($cands, fn($c) => !isset($used[$c['key']])));
            $m = self::match($d, $free);
            $srcs = array_values(array_unique(array_column($m['pick'], 'src')));
            $action = 'none';
            $why = null;
            if (in_array($m['class'], ['single', 'subset'], true)) {
                $why = self::doubt($d, $m['pick'], $cands, $deposits);
                if ($why === null && count($srcs) > 1) $why = 'mixes CRM and Jobber payments';
                $action = $why !== null ? 'review' : ($srcs === ['crm'] ? 'link_crm' : 'source_jobber');
                if ($action !== 'review') foreach ($m['pick'] as $p) $used[$p['key']] = true;
            } elseif ($m['class'] === 'ambiguous') {
                $action = 'review';
                $why = count($m['pick']) . ' payments of this amount';
            }
            $out[] = ['deposit' => $d, 'class' => $m['class'], 'action' => $action, 'why' => $why, 'pick' => $m['pick'],
                      'kind' => self::kindOf((string)$d['description']), 'memo_payer' => self::memoPayer((string)$d['description'])];
        }
        return $out;
    }

    /** Totals per action, and how much 2026 income drops (CRM links remove a double count). */
    public static function summary(array $plan): array
    {
        $s = [];
        foreach ($plan as $r) {
            $a = $r['action'];
            $s[$a]['n'] = ($s[$a]['n'] ?? 0) + 1;
            $s[$a]['amount'] = round(($s[$a]['amount'] ?? 0) + (float)$r['deposit']['amount'], 2);
        }
        return ['by_action' => $s, 'income_drop' => round($s['link_crm']['amount'] ?? 0, 2)];
    }

    // ══════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════

    public function ready(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM bank_deposit_sources LIMIT 0');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function deposits(): array
    {
        $s = $this->db->prepare("
            SELECT id, transaction_date, amount, description FROM accounting_transactions
            WHERE type = 'income' AND reference_type = 'bank_import' AND matched_invoice_id IS NULL
              AND transaction_date >= ?
              AND NOT EXISTS (SELECT 1 FROM bank_deposit_sources b WHERE b.transaction_id = accounting_transactions.id)
            ORDER BY transaction_date, id
        ");
        $s->execute([self::SINCE]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public function candidates(): array
    {
        $out = [];
        $s = $this->db->prepare("
            SELECT i.id, i.invoice_number, COALESCE(NULLIF(i.amount_paid, 0), i.total) amt, COALESCE(i.payment_method, '') m,
                   COALESCE(i.payment_date, DATE(i.paid_at)) d, i.bill_to_name name
            FROM invoices i
            WHERE i.status = 'paid' AND i.invoice_date >= ?
              AND NOT EXISTS (SELECT 1 FROM invoice_payment_allocations a WHERE a.invoice_id = i.id AND a.transaction_id IS NOT NULL)
        ");
        $s->execute([self::SINCE]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['key' => 'crm' . $r['id'], 'src' => 'crm', 'id' => (int)$r['id'], 'ref' => $r['invoice_number'],
                      'amount' => (float)$r['amt'], 'method' => $r['m'], 'date' => $r['d'], 'name' => $r['name']];
        }
        try {
            $rows = $this->db->query("
                SELECT j.id, j.invoice_numbers, j.amount, j.method, j.payment_date, j.client_name, j.contact_id
                FROM jobber_payments j
                WHERE j.kind = 'payment'
                  AND NOT EXISTS (SELECT 1 FROM bank_deposit_sources b WHERE b.source = 'jobber_payment' AND b.source_id = j.id)
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out[] = ['key' => 'job' . $r['id'], 'src' => 'jobber', 'id' => (int)$r['id'], 'ref' => 'Jobber #' . $r['invoice_numbers'],
                          'amount' => (float)$r['amount'], 'method' => (string)$r['method'], 'date' => $r['payment_date'],
                          'name' => $r['client_name'], 'contact_id' => $r['contact_id'] !== null ? (int)$r['contact_id'] : null];
            }
        } catch (Throwable $e) { /* jobber_payments not imported here */ }
        return $out;
    }

    public function buildPlan(): array
    {
        return self::plan($this->deposits(), $this->candidates());
    }

    /**
     * Write the plan's link_crm and source_jobber rows. Each deposit is its own transaction.
     * @return array{linked: int, sourced: int, skipped: array}
     */
    public function apply(array $plan, int $userId): array
    {
        $rec = new InvoiceReconciliationService($this->db);
        $out = ['linked' => 0, 'sourced' => 0, 'income_removed' => 0.0, 'skipped' => []];
        foreach ($plan as $r) {
            $tx = (int)$r['deposit']['id'];
            if ($r['action'] === 'link_crm') {
                try {
                    $this->db->beginTransaction();
                    $ids = [];
                    foreach ($r['pick'] as $p) $ids = array_merge($ids, $this->allocationsFor($p, $r['deposit'], $userId));
                    $rec->linkAllocationsToDeposit($tx, $ids, $userId);
                    $this->db->prepare("UPDATE accounting_transactions SET notes = CONCAT_WS('\n', NULLIF(TRIM(notes), ''), ?) WHERE id = ?")
                        ->execute(['Linked by the deposit backfill ' . date('Y-m-d') . ' — paid ' . implode(', ', array_column($r['pick'], 'ref')), $tx]);
                    $this->db->commit();
                    $out['linked']++;
                    $out['income_removed'] = round($out['income_removed'] + (float)$r['deposit']['amount'], 2);
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                    $out['skipped'][] = ['deposit' => $tx, 'why' => $e->getMessage()];
                }
            } elseif ($r['action'] === 'source_jobber') {
                try {
                    $ins = $this->db->prepare("
                        INSERT IGNORE INTO bank_deposit_sources (transaction_id, source, source_id, contact_id, payer_name, method, amount, created_by)
                        VALUES (?, 'jobber_payment', ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($r['pick'] as $p) {
                        $ins->execute([$tx, $p['id'], $p['contact_id'] ?? null, mb_substr((string)$p['name'], 0, 255), mb_substr((string)$p['method'], 0, 30), round((float)$p['amount'], 2), $userId]);
                    }
                    // The bank line says what it paid (owner, 2026-10-11): Jobber invoice number(s) and payer.
                    // It stays income — only the reference, the note and (when empty) the contact change.
                    $refs = implode(', ', array_column($r['pick'], 'ref'));
                    $payer = implode(' / ', array_unique(array_filter(array_map(fn($p) => trim((string)$p['name']), $r['pick']))));
                    $contacts = array_values(array_unique(array_filter(array_map(fn($p) => $p['contact_id'] ?? null, $r['pick']))));
                    $this->db->prepare("
                        UPDATE accounting_transactions SET
                            payment_reference = COALESCE(NULLIF(payment_reference, ''), ?),
                            contact_id = COALESCE(contact_id, ?),
                            notes = CONCAT_WS('\n', NULLIF(TRIM(notes), ''), ?)
                        WHERE id = ?
                    ")->execute([mb_substr($refs, 0, 100), count($contacts) === 1 ? $contacts[0] : null,
                                 'Jobber-era payment: ' . $refs . ($payer !== '' ? ' — ' . $payer : '') . ' (deposit backfill ' . date('Y-m-d') . ')', $tx]);
                    $out['sourced']++;
                } catch (Throwable $e) {
                    $out['skipped'][] = ['deposit' => $tx, 'why' => $e->getMessage()];
                }
            }
        }
        return $out;
    }

    /**
     * Teach Penny the bank wordings these certain links prove (bank_memo_payers, migration 1284 —
     * the table her bank card already reads: "VML" → Vancouver Management Ltd). A wording is taught
     * only when EVERY certain link carrying it points to ONE payer: a property manager's wording
     * ("DORSET REALTY GROUP CANADA LTD") pays for many stratas and must not be pinned to one.
     * Existing patterns are never overwritten. Returns [taught, several].
     */
    public function teachMemoPayers(array $plan, int $userId): array
    {
        $by = [];
        foreach ($plan as $r) {
            if (!in_array($r['action'], ['link_crm', 'source_jobber'], true) || empty($r['memo_payer'])) continue;
            $pattern = self::teachPattern((string)$r['memo_payer'], (string)$r['deposit']['description']);
            if ($pattern === null) continue;
            $seenHere = [];   // a deposit with several payments is ONE confirmation per payer
            foreach ($r['pick'] as $p) {
                [$name, $companyId] = $p['src'] === 'crm' ? $this->invoicePayer((int)$p['id']) : [trim((string)$p['name']), null];
                if ($name === '') continue;
                $k = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $name));
                if (isset($seenHere[$k])) continue;
                $seenHere[$k] = true;
                $by[$pattern][$k] = [$name, $companyId, ($by[$pattern][$k][2] ?? 0) + 1];
            }
        }
        $taught = [];
        $several = [];
        require_once __DIR__ . '/PayerIdentityService.php';
        $ids = new PayerIdentityService($this->db);   // payer_aliases (1362) + bank_memo_payers while it lives
        foreach ($by as $pattern => $payers) {
            if (count($payers) !== 1) { $several[$pattern] = array_values(array_map(fn($x) => $x[0], $payers)); continue; }
            [$name, $companyId, $times] = array_values($payers)[0];
            if ($times < self::TEACH_MIN) { $several[$pattern] = ['only ' . $times . ' confirmed link — not taught yet']; continue; }
            try {
                if ($ids->timesFor('bank_memo', $pattern) > 0) continue;   // an existing wording is never overwritten
                $ok = $ids->learn('bank_memo', $pattern, $name, 'learned', ['company_id' => $companyId],
                                  'Learned from confirmed deposits (backfill ' . date('Y-m-d') . ', user #' . $userId . ')', $userId);
                if ($ok !== null || !$ids->ready()) $taught[$pattern] = $name;
            } catch (Throwable $e) {
                error_log('[deposit backfill] teach ' . $pattern . ': ' . $e->getMessage());
            }
        }
        return ['taught' => $taught, 'several' => $several];
    }

    /**
     * The wording worth teaching, or null: no ATM/ABM lines (they name the machine's location, not
     * the payer), trailing cheque numbers dropped ("OBSIDIAN - 71" → "OBSIDIAN"), at least 4 letters.
     */
    public static function teachPattern(string $memoPayer, string $memo): ?string
    {
        if (preg_match('/\b(ATM|ABM)\b/i', $memo)) return null;
        $p = trim(strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', ' ', $memoPayer)));
        $p = trim((string)preg_replace('/(\s+\d+)+$/', '', $p));
        if (strlen((string)preg_replace('/[^A-Z]/', '', $p)) < 4 || strlen($p) > 80) return null;
        return $p;
    }

    /** The payer as invoices name it (the matcher compares against these). */
    private function invoicePayer(int $invoiceId): array
    {
        $s = $this->db->prepare("
            SELECT COALESCE(NULLIF(i.bill_to_name, ''), co.company_name, NULLIF(TRIM(CONCAT(COALESCE(ct.first_name,''), ' ', COALESCE(ct.last_name,''))), '')) AS n,
                   i.company_id
            FROM invoices i LEFT JOIN companies co ON co.id = i.company_id LEFT JOIN contacts ct ON ct.id = i.contact_id
            WHERE i.id = ?
        ");
        $s->execute([$invoiceId]);
        $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        return [trim((string)($r['n'] ?? '')), isset($r['company_id']) ? (int)$r['company_id'] : null];
    }

    /**
     * Undo this backfill for one deposit. NOT InvoiceReconciliationService::detach() — that is
     * for deposits attached to unpaid invoices and takes the payment off the invoice (it turned
     * INV-2026-0010 back to "overdue" in the 2026-10-11 pilot). Here the invoice was paid before
     * and stays paid: only the backfill's own allocation rows are removed and the deposit is
     * restored as income; Jobber sources and the note are removed.
     */
    public function undo(int $transactionId): array
    {
        $this->db->beginTransaction();
        try {
            $del = $this->db->prepare("DELETE FROM invoice_payment_allocations WHERE transaction_id = ? AND notes LIKE 'Deposit backfill %'");
            $del->execute([$transactionId]);
            $allocs = $del->rowCount();
            $left = $this->db->prepare("SELECT COUNT(*) FROM invoice_payment_allocations WHERE transaction_id = ?");
            $left->execute([$transactionId]);
            if ($allocs > 0 && (int)$left->fetchColumn() === 0) {
                // Linked through linkAllocationsToDeposit → the row is a transfer; put it back as income.
                $this->db->prepare("
                    UPDATE accounting_transactions SET type = 'income', status = 'cleared',
                           matched_invoice_id = NULL, match_confidence = NULL, matched_at = NULL, matched_by = NULL
                    WHERE id = ? AND type = 'transfer'
                ")->execute([$transactionId]);
            }
            // The contact the backfill set from the Jobber payment comes off with it.
            $cs = $this->db->prepare("SELECT DISTINCT contact_id FROM bank_deposit_sources WHERE transaction_id = ? AND contact_id IS NOT NULL");
            $cs->execute([$transactionId]);
            $srcContacts = array_map('intval', $cs->fetchAll(PDO::FETCH_COLUMN));
            if (count($srcContacts) === 1) {
                $this->db->prepare("UPDATE accounting_transactions SET contact_id = NULL WHERE id = ? AND contact_id = ?")
                    ->execute([$transactionId, $srcContacts[0]]);
            }
            $src = $this->db->prepare("DELETE FROM bank_deposit_sources WHERE transaction_id = ?");
            $src->execute([$transactionId]);
            // Take the backfill's own note lines and Jobber reference off the bank line (PHP, not
            // REGEXP_REPLACE — MySQL 5.7-safe).
            $row = $this->db->prepare("SELECT notes, payment_reference FROM accounting_transactions WHERE id = ?");
            $row->execute([$transactionId]);
            if ($t = $row->fetch(PDO::FETCH_ASSOC)) {
                $lines = array_filter(preg_split('/\R/', (string)$t['notes']), fn($l) => trim($l) !== ''
                    && strpos($l, 'Linked by the deposit backfill') !== 0 && strpos($l, 'Jobber-era payment:') !== 0);
                $ref = str_starts_with((string)$t['payment_reference'], 'Jobber #') ? null : $t['payment_reference'];
                $this->db->prepare("UPDATE accounting_transactions SET notes = ?, payment_reference = ? WHERE id = ?")
                    ->execute([$lines ? implode("\n", $lines) : null, $ref, $transactionId]);
            }
            $this->db->prepare("UPDATE bank_import_rows SET match_status = 'unmatched' WHERE transaction_id = ? AND match_status <> 'unmatched'")
                ->execute([$transactionId]);
            $this->db->commit();
            return ['allocations_removed' => $allocs, 'sources_removed' => $src->rowCount()];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * The paid invoice's payment as allocation rows not yet tied to a deposit: its existing
     * unlinked ones when they cover it, else one new row (the invoice itself is not changed —
     * it is already paid). Caller holds the transaction.
     * @return int[]
     */
    private function allocationsFor(array $cand, array $dep, int $userId): array
    {
        $s = $this->db->prepare("SELECT id, amount FROM invoice_payment_allocations WHERE invoice_id = ? AND transaction_id IS NULL");
        $s->execute([$cand['id']]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if ($rows && abs(round(array_sum(array_column($rows, 'amount')), 2) - round((float)$cand['amount'], 2)) <= 0.01) {
            return array_map(fn($r) => (int)$r['id'], $rows);
        }
        if ($rows) throw new RuntimeException('Invoice ' . $cand['ref'] . ' has part-recorded payments — review by hand.');
        $method = strtolower((string)$cand['method']) ?: self::methodForKind(self::kindOf((string)$dep['description']));
        $this->db->prepare("
            INSERT INTO invoice_payment_allocations (invoice_id, transaction_id, amount, payment_date, method, reference, notes, created_by)
            VALUES (?, NULL, ?, ?, ?, NULL, ?, ?)
        ")->execute([$cand['id'], round((float)$cand['amount'], 2), substr((string)($cand['date'] ?: $dep['transaction_date']), 0, 10),
                     mb_substr($method, 0, 20), 'Deposit backfill ' . date('Y-m-d'), $userId]);
        return [(int)$this->db->lastInsertId()];
    }
}
