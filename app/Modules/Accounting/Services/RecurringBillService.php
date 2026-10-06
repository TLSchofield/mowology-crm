<?php
/**
 * RecurringBillService — Penny spots the bills that come out every month and watches them.
 *
 * Code only, from the imported bank lines: a payee whose spending lines land in 3+
 * different months, about a month apart, for a steady amount, is a recurring bill
 * (Telus, insurance, insurance financing, the bank's own charges…). For each she knows
 * the usual amount and when the next one is due, and flags:
 *   due    — expected within the next 7 days
 *   late   — more than 7 days past when it was expected and no line yet (or not imported)
 *   changed — the latest one differs from the usual amount by more than 15%
 * Statements spell the same payee two ways ("PAYMENT TELUS MOBILITY" vs
 * "PREAUTHORIZEDPAYMENT TELUSMOBILITY"), so payees are keyed on their letters alone.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
class RecurringBillService
{
    /** Statement words that aren't the payee. */
    private const NOISE = ['PREAUTHORIZEDPAYMENT', 'PREAUTHORIZED', 'PREAUTHORISED', 'POINTOFSALE', 'POINTSALE', 'PAYMENT', 'PURCHASE', 'DEBIT', 'POS', 'BILLPAYMENT', 'BILL'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function bills(?string $today = null): array
    {
        $rows = $this->db->query("
            SELECT transaction_date, amount, description FROM accounting_transactions
            WHERE reference_type = 'bank_import' AND type = 'expense'
              AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 15 MONTH)
            ORDER BY transaction_date
        ")->fetchAll(PDO::FETCH_ASSOC);
        $last = $this->db->query("SELECT MAX(transaction_date) FROM accounting_transactions WHERE reference_type = 'bank_import'")->fetchColumn();
        return self::detect($rows, $today ?? date('Y-m-d'), $last ?: null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure (unit tested)
    // ─────────────────────────────────────────────────────────────────────────

    /** "PREAUTHORIZEDPAYMENT TELUSMOBILITY 123" and "PAYMENT TELUS MOBILITY" → "TELUSMOBILITY". */
    public static function payeeKey(string $description): string
    {
        $s = strtoupper((string)preg_replace('/[^A-Za-z]+/', '', $description));
        do {
            $before = $s;
            foreach (self::NOISE as $n) {
                if (str_starts_with($s, $n) && strlen($s) > strlen($n) + 3) $s = substr($s, strlen($n));
            }
        } while ($s !== $before);
        return substr($s, 0, 16);
    }

    /**
     * @param array  $rows       transaction_date, amount, description — oldest first
     * @param string $today      Y-m-d
     * @param ?string $lastImport the latest bank line date (a bill "late" after this may just not be imported yet)
     */
    public static function detect(array $rows, string $today, ?string $lastImport = null): array
    {
        $by = [];
        foreach ($rows as $r) {
            $k = self::payeeKey((string)$r['description']);
            if (strlen($k) < 4 || self::isTransfer((string)$r['description'])) continue;
            $by[$k][] = $r;
        }
        $out = [];
        foreach ($by as $key => $lines) {
            // One line per month (the biggest) — a second purchase in a month isn't the bill.
            $months = [];
            foreach ($lines as $l) {
                $m = substr((string)$l['transaction_date'], 0, 7);
                if (!isset($months[$m]) || (float)$l['amount'] > (float)$months[$m]['amount']) $months[$m] = $l;
            }
            if (count($months) < 3) continue;
            ksort($months);
            $series = array_values($months);
            $gaps = [];
            for ($i = 1; $i < count($series); $i++) {
                $gaps[] = (strtotime($series[$i]['transaction_date']) - strtotime($series[$i - 1]['transaction_date'])) / 86400;
            }
            $gap = self::median($gaps);
            if ($gap < 25 || $gap > 35) continue;                         // not monthly
            $amounts = array_map(fn($l) => (float)$l['amount'], $series);
            $usual = self::median(array_slice($amounts, 0, -1) ?: $amounts);
            $steady = count(array_filter($amounts, fn($a) => abs($a - $usual) <= max(5, $usual * 0.25))) >= count($amounts) * 0.75;
            if (!$steady) continue;                                      // a shop you visit monthly, not a bill
            $latest = end($series);
            $next = date('Y-m-d', strtotime($latest['transaction_date'] . ' +1 month'));   // monthly bills keep their day
            $daysToNext = (int)round((strtotime($next) - strtotime($today)) / 86400);
            $status = [];
            if ($daysToNext >= 0 && $daysToNext <= 7) $status[] = 'due';
            if ($daysToNext < -7) $status[] = ($lastImport && $lastImport < $next) ? 'not_imported' : 'late';
            if (abs((float)$latest['amount'] - $usual) > max(1, $usual * 0.15)) $status[] = 'changed';
            $out[] = [
                'payee' => self::label((string)$latest['description']), 'key' => $key,
                'usual' => round($usual, 2), 'latest' => round((float)$latest['amount'], 2), 'latest_date' => $latest['transaction_date'],
                'next' => $next, 'months' => count($series), 'status' => $status,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['next'], $b['next']));
        return $out;
    }

    /** Money moved between accounts (card payments, funds transfers) isn't a bill. */
    public static function isTransfer(string $description): bool
    {
        return (bool)preg_match('/\b(VISA|MASTERCARD|AMEX)\b|FUNDS ?TRANSFER|TRANSFER (TO|FROM)|\bTFR\b/i', $description)
            && !preg_match('/E-?TRANSFER|INTERAC/i', $description);
    }

    /** A readable payee name from a statement line. */
    public static function label(string $description): string
    {
        $s = preg_replace('/\b(PREAUTHORI[ZS]ED ?PAYMENT|PREAUTHORI[ZS]ED|POINT OF SALE|PAYMENT|PURCHASE)\b/i', '', $description);
        $s = preg_replace('/[\d#*]+/', ' ', $s);
        return ucwords(strtolower(trim(preg_replace('/\s+/', ' ', $s), " -–—()")));
    }

    private static function median(array $v): float
    {
        if (!$v) return 0.0;
        sort($v);
        $n = count($v);
        return $n % 2 ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2;
    }
}
