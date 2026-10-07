<?php
/**
 * StripePayoutService — split a Stripe payout bank line into the invoices it paid and
 * Stripe's fee, so it stops counting as extra revenue.
 *
 * Found 2026-10-05: a payout that carries ONE card payment is matched at import (the
 * deposit becomes a transfer and the fee is booked). A payout that carries SEVERAL was
 * left as income on 4900 — while every one of its invoices already has its own income
 * row — so the Accounting page's profit counted that money twice (about $83K in 2025,
 * $32K in 2026), and the fees inside it were never booked. The journal itself skips
 * revenue deposits, so it was not double counted there; the fees were missing in both.
 *
 * STRIPE IS READ ONLY HERE. The only Stripe calls are payouts->all and
 * balanceTransactions->all (HTTP GET). Nothing is created, changed, refunded or
 * cancelled at Stripe — StripePayoutServiceTest fails if a write call appears.
 *
 * A payout is booked only when it adds up EXACTLY: the charges' net equals the bank
 * line to the cent, every charge belongs to one of our invoices, and there are no
 * refunds, disputes or adjustments in it. Anything else is left for the owner, with
 * the reason. Booking a payout:
 *   - the bank line becomes a reconciled transfer (out of revenue), linked to the payout;
 *   - each invoice's own income row is marked reconciled;
 *   - one fee line is added on 6850 Payment Processing Fees and posted to the journal;
 *   - the review is recorded so it leaves Penny's list. Append-only: nothing is deleted.
 * Locked months are refused.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/LedgerService.php';
require_once __DIR__ . '/LedgerSyncService.php';

class StripePayoutService
{
    private PDO $db;
    /** @var object|null \Stripe\StripeClient */
    private $stripe;
    /** payouts by amount-in-cents, filled once per request */
    private ?array $payouts = null;

    public function __construct(PDO $db, $stripeClient = null)
    {
        $this->db = $db;
        $this->stripe = $stripeClient;
    }

    /** The Stripe client, created lazily from STRIPE_SECRET_KEY (read-only use). */
    private function stripe()
    {
        if ($this->stripe) return $this->stripe;
        if (!class_exists('\Stripe\StripeClient')) {
            $a = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '') . '/vendor/autoload.php';
            if (!is_file($a) && defined('PUBLIC_ROOT')) $a = dirname(PUBLIC_ROOT) . '/vendor/autoload.php';
            if (is_file($a)) require_once $a;
        }
        if (!defined('STRIPE_SECRET_KEY') && defined('PUBLIC_ROOT') && is_file(PUBLIC_ROOT . '/app_config/secrets.php')) {
            require_once PUBLIC_ROOT . '/app_config/secrets.php';
        }
        if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) throw new RuntimeException('Stripe key not configured');
        return $this->stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
    }

    /** Stripe payout bank lines still booked as income. */
    public function waiting(int $limit = 500): array
    {
        $s = $this->db->query("
            SELECT t.id, t.transaction_date, t.amount, t.description
            FROM accounting_transactions t
            WHERE t.reference_type = 'bank_import' AND t.type = 'income'
              AND t.description LIKE '%STRIPE%' AND t.matched_invoice_id IS NULL
              AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
            ORDER BY t.transaction_date DESC
            LIMIT " . max(1, min(1000, $limit)));
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * What a bank line's payout contains, checked. Never writes.
     * @return array{ok: bool, reason: ?string, payout: ?string, invoices: array, fees: float, gross: float}
     */
    public function read(array $line): array
    {
        $cents = (int)round((float)$line['amount'] * 100);
        $payout = $this->findPayout($cents, (string)$line['transaction_date']);
        if (!$payout) return self::result(false, 'No Stripe payout of ' . self::money($cents / 100) . ' arrived within 5 days of this line.');
        // The same payout imported twice (overlapping statements) must not be booked twice.
        $used = $this->db->prepare("SELECT id, transaction_date FROM accounting_transactions
                                    WHERE reference_type = 'bank_import' AND type = 'transfer' AND payment_reference = ? AND id <> ? LIMIT 1");
        $used->execute([$payout, (int)($line['id'] ?? 0)]);
        if ($u = $used->fetch(PDO::FETCH_ASSOC)) {
            return self::result(false, 'That payout is already booked on bank line #' . $u['id'] . ' — this line is probably a duplicate import (run duplicate bank lines first).');
        }
        $tx = [];
        foreach ($this->stripe()->balanceTransactions->all(['payout' => $payout, 'limit' => 100])->autoPagingIterator() as $bt) {
            $src = is_string($bt->source) ? $bt->source : ($bt->source->id ?? null);
            $tx[] = ['type' => (string)$bt->type, 'amount' => (int)$bt->amount, 'fee' => (int)$bt->fee, 'net' => (int)$bt->net, 'source' => $src];
        }
        $invoices = $this->invoicesForCharges(array_values(array_filter(array_column($tx, 'source'))));
        $r = self::check($cents, $tx, $invoices);
        $r['payout'] = $payout;
        return $r;
    }

    /** Book one payout line. Re-reads Stripe and refuses unless it adds up exactly. */
    public function apply(int $transactionId, int $userId): array
    {
        $s = $this->db->prepare("SELECT * FROM accounting_transactions WHERE id = ? AND reference_type = 'bank_import'");
        $s->execute([$transactionId]);
        $line = $s->fetch(PDO::FETCH_ASSOC);
        if (!$line) return ['ok' => false, 'message' => 'Bank line not found'];
        if ($line['type'] !== 'income' || $line['matched_invoice_id'] || stripos((string)$line['description'], 'STRIPE') === false) {
            return ['ok' => false, 'message' => 'Already booked, or not a Stripe payout'];
        }
        $ledger = new LedgerService($this->db);
        if ($ledger->isLocked((string)$line['transaction_date'])) return ['ok' => false, 'message' => 'That month is locked'];

        $r = $this->read($line);
        if (!$r['ok']) return ['ok' => false, 'message' => $r['reason']];

        $ids = array_map(fn($i) => (int)$i['invoice_id'], $r['invoices']);
        $feeId = null;
        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE accounting_transactions SET type = 'transfer', status = 'reconciled', matched_invoice_id = ?,
                       match_confidence = 100, matched_at = NOW(), matched_by = 'penny', payment_reference = ?
                WHERE id = ? AND type = 'income'
            ")->execute([$ids[0], $r['payout'], $transactionId]);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("
                UPDATE accounting_transactions SET status = 'reconciled', matched_at = COALESCE(matched_at, NOW()), matched_by = COALESCE(matched_by, 'penny')
                WHERE reference_type = 'invoice' AND type = 'income' AND reference_id IN ({$in})
            ")->execute($ids);
            if ($r['fees'] > 0.004) {
                $this->db->prepare("
                    INSERT INTO accounting_transactions
                        (transaction_date, type, account_id, amount, description, reference_type, status, is_auto_categorized,
                         bank_account, bank_account_id, import_session_id, created_by, payment_reference)
                    VALUES (?, 'expense', ?, ?, ?, 'bank_import', 'cleared', 1, ?, ?, ?, ?, ?)
                ")->execute([
                    $line['transaction_date'], $this->feeAccountId(), $r['fees'],
                    'Stripe fees — payout ' . $r['payout'] . ' (' . count($ids) . ' invoices: ' . implode(', ', array_column($r['invoices'], 'number')) . ')',
                    $line['bank_account'], $line['bank_account_id'], $line['import_session_id'], $userId, $r['payout'],
                ]);
                $feeId = (int)$this->db->lastInsertId();
            }
            try {
                $this->db->prepare("
                    INSERT INTO bank_line_reviews (transaction_id, suggested_account_id, final_account_id, outcome, decided_by)
                    VALUES (?, ?, ?, 'accepted', ?)
                    ON DUPLICATE KEY UPDATE outcome = 'accepted', decided_by = VALUES(decided_by), decided_at = NOW()
                ")->execute([$transactionId, $line['account_id'], $line['account_id'], $userId]);
            } catch (Throwable $e) { /* before migration 1129 */ }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['ok' => false, 'message' => 'Not booked: ' . $e->getMessage()];
        }

        // Journal: the deposit had no entry (revenue deposits are skipped); reverse one if it somehow has.
        $old = $ledger->findEntryIdBySource('bank_import', $transactionId);
        if ($old) $ledger->reverseEntry($old, $userId, 'Stripe payout ' . $r['payout'] . ' — its invoices already carry this revenue', 'penny');
        if ($feeId) {
            try {
                $args = (new LedgerSyncService($this->db))->bankEntryArgsFor($feeId);
                if ($args) { $args['proposed_by'] = 'penny'; $ledger->postManual($args); }
            } catch (Throwable $e) {
                error_log('Stripe fee journal post failed (tx ' . $feeId . '): ' . $e->getMessage());   // nightly sync posts it
            }
        }
        return ['ok' => true, 'message' => 'Booked: ' . count($ids) . ' invoices (' . implode(', ', array_column($r['invoices'], 'number')) . ') and ' . self::money($r['fees']) . ' Stripe fees.',
                'invoices' => $r['invoices'], 'fees' => $r['fees']];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function findPayout(int $cents, string $date): ?string
    {
        if ($this->payouts === null) $this->payouts = [];
        $t = strtotime(substr($date, 0, 10));
        foreach ($this->payouts as $p) {
            if ($p['amount'] === $cents && abs($p['arrival'] - $t) <= 5 * 86400) return $p['id'];
        }
        $list = $this->stripe()->payouts->all(['arrival_date' => ['gte' => $t - 6 * 86400, 'lte' => $t + 6 * 86400], 'limit' => 100]);
        $hit = null;
        foreach ($list->data as $p) {
            $row = ['id' => $p->id, 'amount' => (int)$p->amount, 'arrival' => (int)$p->arrival_date];
            $this->payouts[$p->id] = $row;
            if ($p->status === 'paid' && $row['amount'] === $cents && abs($row['arrival'] - $t) <= 5 * 86400) $hit = $hit ?? $p->id;
        }
        return $hit;
    }

    /** charge id => invoice (id, number), from stripe_payments. */
    private function invoicesForCharges(array $chargeIds): array
    {
        if (!$chargeIds) return [];
        $in = implode(',', array_fill(0, count($chargeIds), '?'));
        $s = $this->db->prepare("
            SELECT sp.stripe_charge_id, i.id, i.invoice_number,
                   EXISTS(SELECT 1 FROM accounting_transactions t WHERE t.reference_type = 'invoice' AND t.reference_id = i.id AND t.type = 'income') AS counted
            FROM stripe_payments sp JOIN invoices i ON i.id = sp.invoice_id
            WHERE sp.stripe_charge_id IN ({$in})
        ");
        $s->execute($chargeIds);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['stripe_charge_id']] = ['invoice_id' => (int)$r['id'], 'number' => $r['invoice_number'], 'counted' => (bool)$r['counted']];
        }
        return $out;
    }

    private function feeAccountId(): int
    {
        $id = $this->db->query("SELECT id FROM chart_of_accounts WHERE code = '6850' AND is_active = 1 LIMIT 1")->fetchColumn()
           ?: $this->db->query("SELECT id FROM chart_of_accounts WHERE type = 'expense' AND is_active = 1 AND (name LIKE '%processing fee%' OR name LIKE '%bank charge%') LIMIT 1")->fetchColumn();
        if (!$id) throw new RuntimeException('No 6850 Payment Processing Fees account');
        return (int)$id;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Does this payout add up, to the cent, to known invoices only?
     * @param int   $bankCents the bank line in cents
     * @param array $tx        balance transactions: type, amount, fee, net (cents), source
     * @param array $invoices  charge id => {invoice_id, number, counted}
     */
    public static function check(int $bankCents, array $tx, array $invoices): array
    {
        $lines = array_values(array_filter($tx, fn($t) => $t['type'] !== 'payout'));
        if (!$lines) return self::result(false, 'Stripe shows nothing inside this payout.');
        $odd = array_values(array_unique(array_map(fn($t) => $t['type'], array_filter($lines, fn($t) => !in_array($t['type'], ['charge', 'payment'], true)))));
        if ($odd) return self::result(false, 'This payout includes a ' . implode(' / ', $odd) . ' — that needs you, not me.');
        $net = array_sum(array_column($lines, 'net'));
        if ($net !== $bankCents) return self::result(false, 'Stripe says ' . self::money($net / 100) . ' but the bank shows ' . self::money($bankCents / 100) . '.');
        $out = []; $fees = 0; $gross = 0;
        foreach ($lines as $t) {
            $inv = $invoices[$t['source'] ?? ''] ?? null;
            if (!$inv) return self::result(false, 'A ' . self::money($t['amount'] / 100) . ' card payment in it isn\'t on any invoice here.');
            if (!$inv['counted']) return self::result(false, $inv['number'] . ' isn\'t counted as income yet — record its payment first.');
            $out[] = ['invoice_id' => $inv['invoice_id'], 'number' => $inv['number'], 'amount' => $t['amount'] / 100, 'fee' => $t['fee'] / 100];
            $fees += $t['fee']; $gross += $t['amount'];
        }
        $r = self::result(true, null);
        $r['invoices'] = $out; $r['fees'] = round($fees / 100, 2); $r['gross'] = round($gross / 100, 2);
        return $r;
    }

    private static function result(bool $ok, ?string $reason): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'payout' => null, 'invoices' => [], 'fees' => 0.0, 'gross' => 0.0];
    }

    private static function money(float $v): string
    {
        return '$' . number_format($v, 2);
    }
}
