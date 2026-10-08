<?php
/**
 * InvoicePaymentPlanner — which journal entries an invoice's payments need (migration 1236).
 *
 * Until 2026-10-07 LedgerSyncService posted ONE payment entry per invoice ('payment',
 * source_id = invoice id) for amount_paid at the first sync, and never again: every part
 * payment recorded after that never reached the journal (cash and AR both wrong).
 *
 * Now each payment is its own entry (DR Bank / CR AR):
 *   'payment_allocation'  invoice_payment_allocations.id  (deposit attach, Record Payment,
 *                                                          e-Transfer / Yardi inbox)
 *   'stripe_payment'      stripe_payments.id (succeeded)
 *   'payment'             invoices.id — only what has no record of its own (the old direct
 *                         amount_paid path), less account credit applied (not cash).
 *
 * The old one-per-invoice entry ("legacy", amount L) covers the payments recorded BEFORE it
 * was posted (record created_at <= entry created_at) plus the direct remainder. When
 * L = covered + direct, it stays and only the uncovered payments are posted. When it doesn't
 * add up (an allocation detached later, a direct payment added later), the redo is: reverse
 * it, post every payment by itself — append-only, never a delete.
 *
 * The nightly sync applies only 'auto' actions: posts (never reversals) for payments recorded
 * on/after ops_settings 'ledger_payments_each_from', or for invoices with no payment entry at
 * all (the old code would have posted those too). Everything older is a proposal on the
 * bank balance check page (BankBalanceCheckService), booked only when Tim approves.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';

class InvoicePaymentPlanner
{
    public const SETTING = 'ledger_payments_each_from';
    public const CENT = 0.01;
    public const SOURCES = ['payment', 'payment_allocation', 'stripe_payment'];

    private PDO $db;
    private ?string $from = null;
    private bool $fromRead = false;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** When per-payment posting started (migration 1236), or null = not yet (old behaviour). */
    public function goForwardFrom(): ?string
    {
        if ($this->fromRead) return $this->from;
        $this->fromRead = true;
        try {
            $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ? LIMIT 1");
            $s->execute([self::SETTING]);
            $v = $s->fetchColumn();
            $this->from = ($v !== false && trim((string)$v) !== '') ? trim((string)$v) : null;
        } catch (Throwable $e) {
            $this->from = null;
        }
        return $this->from;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // PURE
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Plan one invoice. Pure.
     * @param array $inv      id, amount_paid, paid_at?, created_at?, contact_id?, invoice_number?, credit_applied? (non-cash, >= 0)
     * @param array $payments list of {kind: 'alloc'|'stripe', id, date, amount, created_at}
     * @param array $posted   live per-payment entries: "payment_allocation:12" => {entry_id, amount}
     * @param array|null $legacy live 'payment' entry for this invoice: {entry_id, amount, entry_date, created_at}
     * @param string|null $from  go-forward moment ('Y-m-d H:i:s'); null = nothing is auto
     * @return array{invoice_id:int, state:string, reason:string, actions:array, effect:float}
     *   state: ok | missing (posts only) | redo (reverse legacy + posts) | review (doesn't add up — nothing booked)
     *   actions: [{op:'post', source_type, source_id, date, amount, auto, label} | {op:'reverse', entry_id, date, amount, auto:false}]
     */
    public static function plan(array $inv, array $payments, array $posted, ?array $legacy, ?string $from = null): array
    {
        $id = (int)$inv['id'];
        $paid = round((float)($inv['amount_paid'] ?? 0), 2);
        $credit = round(max(0.0, (float)($inv['credit_applied'] ?? 0)), 2);
        $paidDate = self::day($inv['paid_at'] ?? null) ?: self::day($inv['created_at'] ?? null) ?: date('Y-m-d');
        usort($payments, fn($a, $b) => [(string)$a['date'], (string)$a['kind'], (int)$a['id']] <=> [(string)$b['date'], (string)$b['kind'], (int)$b['id']]);

        $recorded = round(array_sum(array_map(fn($p) => (float)$p['amount'], $payments)), 2);
        $direct = round($paid - $recorded, 2);
        $out = ['invoice_id' => $id, 'state' => 'ok', 'reason' => '', 'actions' => [], 'effect' => 0.0];
        if ($direct < -self::CENT) {
            $out['state'] = 'review';
            $out['reason'] = sprintf('Payments on record ($%s) are more than the invoice says was paid ($%s) — check it before anything is posted.',
                                     number_format($recorded, 2), number_format($paid, 2));
            return $out;
        }
        $direct = max(0.0, $direct);
        $directCash = round(max(0.0, $direct - $credit), 2);

        $own = [];        // payments that already have their own live entry with the right amount
        $wrong = [];      // own entry with a different amount → reverse + post
        foreach ($payments as $p) {
            $k = self::key($p);
            if (!isset($posted[$k])) continue;
            if (abs((float)$posted[$k]['amount'] - (float)$p['amount']) < self::CENT) $own[$k] = true;
            else $wrong[$k] = $posted[$k];
        }

        $post = function (array $p, bool $auto) use (&$out, $id) {
            $out['actions'][] = ['op' => 'post', 'source_type' => self::sourceType($p['kind']), 'source_id' => (int)$p['id'],
                                 'date' => (string)$p['date'], 'amount' => round((float)$p['amount'], 2), 'auto' => $auto,
                                 'label' => ($p['kind'] === 'stripe' ? 'Stripe payment' : 'Payment') . ' ' . $p['date']];
        };
        $isNew = function (array $p) use ($from): bool {
            return $from !== null && (string)($p['created_at'] ?? '') !== '' && (string)$p['created_at'] >= $from;
        };

        if ($legacy === null) {
            // No payment entry at all: every payment by itself (the old code would have posted amount_paid).
            foreach ($payments as $p) {
                $k = self::key($p);
                if (isset($own[$k])) continue;
                if (isset($wrong[$k])) {
                    $out['actions'][] = ['op' => 'reverse', 'entry_id' => (int)$wrong[$k]['entry_id'], 'date' => (string)$p['date'],
                                         'amount' => -round((float)$wrong[$k]['amount'], 2), 'auto' => false];
                }
                $post($p, $from !== null && !isset($wrong[$k]));
            }
            if ($directCash > self::CENT) {
                $out['actions'][] = ['op' => 'post', 'source_type' => 'payment', 'source_id' => $id, 'date' => $paidDate,
                                     'amount' => $directCash, 'auto' => $from !== null, 'label' => 'Payment (no separate record) ' . $paidDate];
            }
        } else {
            $L = round((float)$legacy['amount'], 2);
            $legacyAt = (string)($legacy['created_at'] ?? '');
            $covered = 0.0;
            $uncovered = [];
            foreach ($payments as $p) {
                $k = self::key($p);
                if (isset($own[$k]) || isset($wrong[$k])) continue;
                if ($legacyAt !== '' && (string)($p['created_at'] ?? '') !== '' && (string)$p['created_at'] <= $legacyAt) {
                    $covered += (float)$p['amount'];
                } else {
                    $uncovered[] = $p;
                }
            }
            $covered = round($covered, 2);
            $consistent = !$wrong && (abs($L - $covered - $direct) < self::CENT || abs($L - $covered - $directCash) < self::CENT);
            if ($consistent) {
                foreach ($uncovered as $p) $post($p, $isNew($p));
            } else {
                $out['actions'][] = ['op' => 'reverse', 'entry_id' => (int)$legacy['entry_id'], 'date' => self::day($legacy['entry_date'] ?? null) ?: $paidDate,
                                     'amount' => -$L, 'auto' => false];
                foreach ($payments as $p) {
                    $k = self::key($p);
                    if (isset($own[$k])) continue;
                    if (isset($wrong[$k])) {
                        $out['actions'][] = ['op' => 'reverse', 'entry_id' => (int)$wrong[$k]['entry_id'], 'date' => (string)$p['date'],
                                             'amount' => -round((float)$wrong[$k]['amount'], 2), 'auto' => false];
                    }
                    $post($p, false);
                }
                if ($directCash > self::CENT) {
                    $out['actions'][] = ['op' => 'post', 'source_type' => 'payment', 'source_id' => $id, 'date' => $paidDate,
                                         'amount' => $directCash, 'auto' => false, 'label' => 'Payment (no separate record) ' . $paidDate];
                }
                $out['reason'] = sprintf('The old payment entry ($%s) doesn\'t match the payments on record ($%s) — reverse it, post each payment by itself.',
                                         number_format($L, 2), number_format(round($covered + $direct, 2), 2));
            }
        }

        $hasReverse = false;
        foreach ($out['actions'] as $a) {
            $out['effect'] += (float)$a['amount'];
            if ($a['op'] === 'reverse') $hasReverse = true;
        }
        $out['effect'] = round($out['effect'], 2);
        if ($out['actions']) $out['state'] = $hasReverse ? 'redo' : 'missing';
        return $out;
    }

    public static function sourceType(string $kind): string
    {
        return $kind === 'stripe' ? 'stripe_payment' : ($kind === 'direct' ? 'payment' : 'payment_allocation');
    }

    public static function key(array $p): string
    {
        return self::sourceType((string)$p['kind']) . ':' . (int)$p['id'];
    }

    private static function day($v): string
    {
        $s = substr((string)($v ?? ''), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && $s !== '0000-00-00' ? $s : '';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // DB
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Every invoice with something paid, planned. Bulk reads (one query per table).
     * @param int|null $invoiceId one invoice, or null for all
     * @return array<int, array> invoice id => plan + invoice fields (number, contact_id)
     */
    public function planAll(?int $invoiceId = null): array
    {
        $from = $this->goForwardFrom();
        $one = $invoiceId !== null ? ' AND id = ' . (int)$invoiceId : '';
        $invoices = $this->db->query("SELECT id, invoice_number, amount_paid, paid_at, created_at, contact_id
                                      FROM invoices WHERE COALESCE(total, 0) > 0 AND COALESCE(amount_paid, 0) > 0{$one}")
                             ->fetchAll(PDO::FETCH_ASSOC);
        if (!$invoices) return [];

        $payments = [];
        $oneA = $invoiceId !== null ? ' AND invoice_id = ' . (int)$invoiceId : '';
        try {
            foreach ($this->db->query("SELECT id, invoice_id, amount, payment_date, created_at FROM invoice_payment_allocations WHERE amount > 0{$oneA}")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $payments[(int)$r['invoice_id']][] = ['kind' => 'alloc', 'id' => (int)$r['id'], 'amount' => round((float)$r['amount'], 2),
                    'date' => self::day($r['payment_date']) ?: self::day($r['created_at']), 'created_at' => (string)($r['created_at'] ?? '')];
            }
        } catch (Throwable $e) { /* before migration 1062 */ }
        try {
            foreach ($this->db->query("SELECT id, invoice_id, amount_cents, webhook_received_at, created_at FROM stripe_payments WHERE status = 'succeeded'{$oneA}")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $at = (string)(($r['webhook_received_at'] ?? null) ?: ($r['created_at'] ?? ''));
                $payments[(int)$r['invoice_id']][] = ['kind' => 'stripe', 'id' => (int)$r['id'], 'amount' => round((int)$r['amount_cents'] / 100, 2),
                    'date' => self::day($at), 'created_at' => $at];
            }
        } catch (Throwable $e) { /* no Stripe table */ }
        $credits = [];
        try {
            foreach ($this->db->query("SELECT invoice_id, SUM(-amount) AS applied FROM client_credits WHERE type = 'applied' AND invoice_id IS NOT NULL{$oneA} GROUP BY invoice_id")
                         ->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $credits[(int)$r['invoice_id']] = round(max(0.0, (float)$r['applied']), 2);
            }
        } catch (Throwable $e) { /* before migration 1102 */ }

        // Live payment entries: amount = the entry's debits.
        $legacy = [];
        $posted = [];
        $rows = $this->db->query("
            SELECT je.id, je.source_type, je.source_id, je.entry_date, je.created_at, SUM(jl.debit) AS amount
            FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id
            WHERE je.source_type IN ('payment', 'payment_allocation', 'stripe_payment')
              AND je.reversed_by_entry_id IS NULL AND je.source_id IS NOT NULL
            GROUP BY je.id, je.source_type, je.source_id, je.entry_date, je.created_at
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $e = ['entry_id' => (int)$r['id'], 'amount' => round((float)$r['amount'], 2), 'entry_date' => (string)$r['entry_date'],
                  'created_at' => (string)($r['created_at'] ?? '')];
            if ($r['source_type'] === 'payment') $legacy[(int)$r['source_id']] = $e;
            else $posted[$r['source_type'] . ':' . (int)$r['source_id']] = $e;
        }

        $out = [];
        foreach ($invoices as $inv) {
            $iid = (int)$inv['id'];
            $inv['credit_applied'] = $credits[$iid] ?? 0.0;
            $plan = self::plan($inv, $payments[$iid] ?? [], $posted, $legacy[$iid] ?? null, $from);
            $plan['invoice_number'] = (string)($inv['invoice_number'] ?? '');
            $plan['contact_id'] = !empty($inv['contact_id']) ? (int)$inv['contact_id'] : null;
            $out[$iid] = $plan;
        }
        return $out;
    }

    /**
     * Live per-payment entries whose payment record is gone (an allocation detached, a Stripe
     * payment no longer 'succeeded'): reverse them.
     * @return array<int, array{entry_id:int, source_type:string, source_id:int, date:string, amount:float}>
     */
    public function orphanEntries(): array
    {
        $out = [];
        $checks = [
            'payment_allocation' => "SELECT id FROM invoice_payment_allocations WHERE amount > 0",
            'stripe_payment'     => "SELECT id FROM stripe_payments WHERE status = 'succeeded'",
        ];
        foreach ($checks as $type => $sql) {
            try {
                $alive = array_flip(array_map('intval', $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN)));
            } catch (Throwable $e) {
                $alive = [];
            }
            $s = $this->db->prepare("
                SELECT je.id, je.source_id, je.entry_date, SUM(jl.debit) AS amount
                FROM journal_entries je JOIN journal_lines jl ON jl.entry_id = je.id
                WHERE je.source_type = ? AND je.reversed_by_entry_id IS NULL
                GROUP BY je.id, je.source_id, je.entry_date
            ");
            $s->execute([$type]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!isset($alive[(int)$r['source_id']])) {
                    $out[] = ['entry_id' => (int)$r['id'], 'source_type' => $type, 'source_id' => (int)$r['source_id'],
                              'date' => substr((string)$r['entry_date'], 0, 10), 'amount' => round((float)$r['amount'], 2)];
                }
            }
        }
        return $out;
    }

    /** The journal entry for one planned post action (DR Bank / CR AR), with account codes. */
    public static function entryFor(array $action, array $plan, LedgerService $ledger): array
    {
        $e = $ledger->buildPaymentEntry([
            'id' => $action['source_id'], 'invoice_id' => $plan['invoice_id'], 'date' => $action['date'],
            'amount' => $action['amount'], 'contact_id' => $plan['contact_id'] ?? null,
            'memo' => ($action['label'] ?? 'Payment') . ' — invoice ' . (($plan['invoice_number'] ?? '') !== '' ? $plan['invoice_number'] : '#' . $plan['invoice_id']),
        ]);
        $e['source_type'] = $action['source_type'];
        $e['source_id'] = (int)$action['source_id'];
        return $e;
    }

    /**
     * The nightly sync's part: post the 'auto' actions of one invoice's plan (never reverses).
     * @return int entries posted
     */
    public function applyAuto(array $plan, LedgerService $ledger): int
    {
        $n = 0;
        foreach ($plan['actions'] as $a) {
            if ($a['op'] !== 'post' || empty($a['auto'])) continue;
            $ledger->postManual(self::entryFor($a, $plan, $ledger));
            $n++;
        }
        return $n;
    }
}
