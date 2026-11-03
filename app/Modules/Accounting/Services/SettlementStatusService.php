<?php
declare(strict_types=1);

/**
 * SettlementStatusService — where every Stripe payout of a month stands against the bank, in five words.
 *
 * Harvested from Anthropic's small-business plugin (month-end-prep, settlement match, 2026-11-03). Penny already
 * BOOKS a payout only when it adds up to the cent (StripePayoutService). What was missing is the month-end view:
 * which payouts are booked, which are sitting in the bank unbooked, which have not reached the bank, and which
 * Stripe-looking deposits have no payout behind them. States (plugin names, our meanings):
 *   reconciled               the bank line is booked: to this payout (payment_reference = payout id) or, for a single-charge
 *                            payout, to its invoice at import time (type transfer, matched_invoice_id set)
 *   unreconciled_in_ledger   a STRIPE bank line of the same amount arrived within DATE_DAYS of the payout, not booked yet
 *   date_mismatch            same amount, but the bank line is DATE_DAYS+1 … LATE_DAYS off the arrival date (timing; low priority)
 *   missing_in_ledger        the payout is paid and older than DATE_DAYS, and no bank line carries it (statement not
 *                            imported yet, or it went to another account)
 *   in_transit               paid in the last DATE_DAYS or not yet paid — nothing to check
 *   unmatched_deposit        a STRIPE bank line with no payout of that amount near it (another processor's? a refund reversal?)
 * Amounts match to the cent (Stripe pays the exact net; the plugin's $0.50 is for processors that round). READ ONLY:
 * the only Stripe call is payouts->all (GET); nothing here writes to the books. The report is cached per month in
 * ops_settings for CACHE_HOURS so the close page does not call Stripe on every load.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
final class SettlementStatusService
{
    public const DATE_DAYS = 2;      // within this many days of arrival = the same settlement
    public const LATE_DAYS = 5;      // StripePayoutService's own booking window; beyond it we stop looking
    public const CACHE_HOURS = 6;
    public const STATES = ['reconciled', 'unreconciled_in_ledger', 'date_mismatch', 'missing_in_ledger', 'in_transit', 'unmatched_deposit'];

    private PDO $db;
    /** @var callable|null returns payouts for [from, to] as {id, amount (cents), arrival 'Y-m-d', status}; null = Stripe client */
    private $payoutSource;

    public function __construct(PDO $db, ?callable $payoutSource = null)
    {
        $this->db = $db;
        $this->payoutSource = $payoutSource;
    }

    /**
     * Pure: classify a month's payouts against the Stripe-looking bank lines around it.
     * @param array $payouts   rows {id, amount (int cents), arrival 'Y-m-d', status ('paid' | other)}
     * @param array $bankLines rows {id, date 'Y-m-d', cents (int), payout_ref (?string: payment_reference when booked), booked (bool)}
     *                         — may include lines from LATE_DAYS either side of the month; only lines dated inside
     *                         [$from, $to] can be orphans (a margin line belongs to the next month's payouts)
     * @return array{rows: array, counts: array<string,int>}
     */
    public static function classify(array $payouts, array $bankLines, string $today, ?string $from = null, ?string $to = null): array
    {
        $rows = [];
        $usedLines = [];
        $byRef = [];
        foreach ($bankLines as $i => $l) if (!empty($l['payout_ref'])) $byRef[(string)$l['payout_ref']] = $i;

        foreach ($payouts as $p) {
            $row = ['payout' => (string)$p['id'], 'amount' => round(((int)$p['amount']) / 100, 2), 'arrival' => (string)$p['arrival'],
                    'line_id' => null, 'line_date' => null, 'days_off' => null, 'status' => null];
            if (isset($byRef[(string)$p['id']])) {
                $l = $bankLines[$byRef[(string)$p['id']]];
                $usedLines[$byRef[(string)$p['id']]] = true;
                $row['status'] = 'reconciled';
                $row['line_id'] = (int)$l['id']; $row['line_date'] = (string)$l['date']; $row['days_off'] = self::days((string)$p['arrival'], (string)$l['date']);
                $rows[] = $row; continue;
            }
            if ((string)($p['status'] ?? '') !== 'paid' || self::days((string)$p['arrival'], $today) < self::DATE_DAYS) {
                $row['status'] = 'in_transit'; $rows[] = $row; continue;
            }
            // nearest line of the same cents, by date distance (booked-at-import lines count: that IS the settlement)
            $best = null;
            foreach ($bankLines as $i => $l) {
                if (isset($usedLines[$i]) || !empty($l['payout_ref']) || (int)$l['cents'] !== (int)$p['amount']) continue;
                $d = abs(self::days((string)$p['arrival'], (string)$l['date']));
                if ($d > self::LATE_DAYS) continue;
                if ($best === null || $d < $best[1]) $best = [$i, $d];
            }
            if ($best !== null) {
                $l = $bankLines[$best[0]]; $usedLines[$best[0]] = true;
                $row['line_id'] = (int)$l['id']; $row['line_date'] = (string)$l['date']; $row['days_off'] = $best[1];
                $row['status'] = !empty($l['booked']) ? 'reconciled' : ($best[1] <= self::DATE_DAYS ? 'unreconciled_in_ledger' : 'date_mismatch');
            } else {
                $row['status'] = 'missing_in_ledger';
            }
            $rows[] = $row;
        }
        foreach ($bankLines as $i => $l) {
            if (isset($usedLines[$i]) || !empty($l['booked'])) continue;
            if (($from !== null && (string)$l['date'] < $from) || ($to !== null && (string)$l['date'] > $to)) continue;   // margin line: another month's
            $rows[] = ['payout' => null, 'amount' => round(((int)$l['cents']) / 100, 2), 'arrival' => null,
                       'line_id' => (int)$l['id'], 'line_date' => (string)$l['date'], 'days_off' => null, 'status' => 'unmatched_deposit'];
        }
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $r) $counts[$r['status']]++;
        return ['rows' => $rows, 'counts' => $counts];
    }

    /** Close-page wording for the counts; empty when nothing needs a person. */
    public static function blockers(array $counts): array
    {
        $b = [];
        $n = (int)($counts['unreconciled_in_ledger'] ?? 0) + (int)($counts['date_mismatch'] ?? 0);
        if ($n > 0) $b[] = $n . ' Stripe payout' . ($n === 1 ? '' : 's') . ' in the bank but not booked yet (Penny\'s bank card books them)';
        $n = (int)($counts['missing_in_ledger'] ?? 0);
        if ($n > 0) $b[] = $n . ' Stripe payout' . ($n === 1 ? '' : 's') . ' not on any bank line — a statement may be missing';
        $n = (int)($counts['unmatched_deposit'] ?? 0);
        if ($n > 0) $b[] = $n . ' Stripe-looking deposit' . ($n === 1 ? '' : 's') . ' with no payout behind ' . ($n === 1 ? 'it' : 'them');
        return $b;
    }

    /**
     * The month's report, cached. Returns null (and names why in 'error') when Stripe cannot be read — absent is not zero.
     * @return array{month:string, as_of:string, counts:array, rows:array, error:?string}|null
     */
    public function report(string $ym, bool $fresh = false, ?string $today = null): ?array
    {
        $today = $today ?: date('Y-m-d');
        $key = 'settlements_' . $ym;
        if (!$fresh) {
            try {
                $s = $this->db->prepare("SELECT setting_value FROM ops_settings WHERE setting_key = ?");
                $s->execute([$key]);
                $cached = json_decode((string)$s->fetchColumn(), true);
                if (is_array($cached) && !empty($cached['as_of']) && (time() - strtotime($cached['as_of'])) < self::CACHE_HOURS * 3600) return $cached;
            } catch (Throwable $e) { /* no ops_settings: compute */ }
        }
        $from = $ym . '-01';
        $to = date('Y-m-t', strtotime($from));
        try {
            $payouts = $this->payouts($from, $to);
        } catch (Throwable $e) {
            error_log('[SettlementStatus] Stripe read failed: ' . $e->getMessage());
            return ['month' => $ym, 'as_of' => date('Y-m-d H:i:s'), 'counts' => [], 'rows' => [], 'error' => 'Stripe could not be read: ' . $e->getMessage()];
        }
        $lines = $this->stripeBankLines(date('Y-m-d', strtotime($from . ' -' . self::LATE_DAYS . ' days')), date('Y-m-d', strtotime($to . ' +' . self::LATE_DAYS . ' days')));
        $r = self::classify($payouts, $lines, $today, $from, $to);
        $out = ['month' => $ym, 'as_of' => date('Y-m-d H:i:s'), 'counts' => $r['counts'], 'rows' => $r['rows'], 'error' => null];
        try {
            $this->db->prepare("INSERT INTO ops_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
               ->execute([$key, json_encode($out), 'Stripe settlement status for ' . $ym . ' (SettlementStatusService cache)']);
        } catch (Throwable $e) { /* cache is a convenience */ }
        return $out;
    }

    /** Stripe payouts arriving in [from, to], read-only. */
    private function payouts(string $from, string $to): array
    {
        if ($this->payoutSource) return (array)call_user_func($this->payoutSource, $from, $to);
        if (!defined('STRIPE_SECRET_KEY') && defined('PUBLIC_ROOT') && is_file(PUBLIC_ROOT . '/app_config/secrets.php')) {
            require_once PUBLIC_ROOT . '/app_config/secrets.php';
        }
        if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) throw new RuntimeException('Stripe key not configured');
        if (!class_exists('\Stripe\StripeClient')) {   // the SDK is Composer-loaded; production has no global autoloader (same as StripePayoutService)
            $a = (defined('PUBLIC_ROOT') ? PUBLIC_ROOT : '') . '/vendor/autoload.php';
            if (!is_file($a) && defined('PUBLIC_ROOT')) $a = dirname(PUBLIC_ROOT) . '/vendor/autoload.php';
            if (is_file($a)) require_once $a;
        }
        if (!class_exists('\Stripe\StripeClient')) throw new RuntimeException('Stripe SDK not loaded');
        $stripe = new \Stripe\StripeClient(STRIPE_SECRET_KEY);
        $out = [];
        $list = $stripe->payouts->all(['arrival_date' => ['gte' => strtotime($from . ' 00:00:00'), 'lte' => strtotime($to . ' 23:59:59')], 'limit' => 100]);
        foreach ($list->autoPagingIterator() as $p) {
            $out[] = ['id' => (string)$p->id, 'amount' => (int)$p->amount, 'arrival' => date('Y-m-d', (int)$p->arrival_date), 'status' => (string)$p->status];
        }
        return $out;
    }

    /** Stripe-looking bank lines in the window, with whether they are booked to a payout already. */
    private function stripeBankLines(string $from, string $to): array
    {
        $s = $this->db->prepare("SELECT t.id, t.transaction_date, t.amount, t.type, t.payment_reference, t.matched_invoice_id
                                 FROM accounting_transactions t
                                 WHERE t.reference_type = 'bank_import' AND t.description LIKE '%STRIPE%' AND t.amount > 0
                                   AND t.type IN ('income', 'transfer')   -- not the fee rows (type expense, same description)
                                   AND COALESCE(t.status, '') NOT IN ('void', 'deleted')
                                   AND t.transaction_date BETWEEN ? AND ?");
        $s->execute([$from, $to]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $ref = (string)($r['payment_reference'] ?? '');
            $isPayoutRef = strpos($ref, 'po_') === 0;
            $out[] = ['id' => (int)$r['id'], 'date' => substr((string)$r['transaction_date'], 0, 10), 'cents' => (int)round((float)$r['amount'] * 100),
                      'payout_ref' => $isPayoutRef ? $ref : null,
                      // booked = a transfer tied to a payout, or an import-time single-invoice match (matched_invoice_id set)
                      'booked' => $isPayoutRef || !empty($r['matched_invoice_id'])];
        }
        return $out;
    }

    private static function days(string $from, string $to): int
    {
        return (int)round((strtotime($to) - strtotime($from)) / 86400);
    }
}
