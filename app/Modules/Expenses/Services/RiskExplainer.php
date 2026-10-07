<?php
/**
 * RiskExplainer — Penny says, in plain English, why a receipt has the risk score it has.
 *
 * Why (2026-10-07): the iOS receipt screen showed "25 · Medium risk · Review before
 * approving" with no reason, even on an already-approved Esso diesel fill-up for $200.00.
 * The score comes from AnomalyDetector.php; the rule codes it fired are stored on the
 * expense (expenses.anomaly_flags, anomaly_score). The rules' own detail text is written
 * for developers, so this turns each code into one line in Penny's voice, with the
 * receipt's context (a round $200 at a gas station is a fill-up asked for by amount).
 *
 * Which codes: the stored ones (what set the score the app shows). Details are re-run
 * from AnomalyDetector for numbers; a rule that depends on "now" (rapid submission,
 * split within the hour) may not fire again later, so every line can be said from the
 * expense alone. Used by bookkeeper-mobile.php ?mode=risk.
 *
 * No namespace / no autoloader in production: require_once and `new`/static.
 */
class RiskExplainer
{
    public const CODES = ['ROUND_NUMBER', 'HIGH_AMOUNT', 'DUPLICATE_DAY', 'NO_RECEIPT', 'CATEGORY_MISMATCH',
                          'RAPID_SUBMISSION', 'SPLIT_SUSPICION', 'GST_MISMATCH', 'WEEKEND_EXPENSE', 'LOCATION_MISMATCH'];

    private const FUEL_VENDORS = '/\b(esso|shell|chevron|petro[- ]?can(ada)?|husky|mobil|co-?op gas|ultramar|pioneer|fas gas|7-eleven|race trac|domo|super save gas|canadian tire gas|costco gas)\b/i';

    /** Tier names, the same bands as the app's ring and the web icon: >30 high, 16–30 medium, 1–15 low. */
    public static function tier(int $score): string
    {
        if ($score > 30) return 'high';
        if ($score > 15) return 'medium';
        if ($score > 0) return 'low';
        return 'none';
    }

    /**
     * The codes to explain: the stored ones (they set the stored score), else the fresh run's.
     * @param string $stored  expenses.anomaly_flags ("ROUND_NUMBER,GST_MISMATCH")
     * @param array  $fresh   AnomalyDetector details [{code, score, detail}]
     */
    public static function codes(string $stored, array $fresh): array
    {
        $codes = array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper($stored))))));
        if (!$codes) $codes = array_values(array_unique(array_map(fn($d) => (string)($d['code'] ?? ''), $fresh)));
        return array_values(array_filter($codes, fn($c) => $c !== ''));
    }

    public static function isFuel(array $e): bool
    {
        if (stripos((string)($e['accounting_category'] ?? ''), 'fuel') !== false) return true;
        return (bool)preg_match(self::FUEL_VENDORS, (string)($e['vendor_name'] ?? $e['vendor_name_raw'] ?? ''));
    }

    private static function money(float $v): string
    {
        return '$' . number_format($v, 2);
    }

    private static function vendor(array $e): string
    {
        $v = trim((string)($e['vendor_name'] ?? '')) ?: trim((string)($e['vendor_name_raw'] ?? ''));
        return $v !== '' ? $v : 'this vendor';
    }

    /**
     * Pure: one line in Penny's voice for one flag.
     * @param array       $e      the expense (total, amount = subtotal, gst_amount, expense_date,
     *                            accounting_category, vendor_name / vendor_name_raw)
     * @param string|null $detail AnomalyDetector's detail for this code, when it fired again
     * @param array       $ctx    extra facts: same_amount (DUPLICATE_DAY: the other one matches)
     */
    public static function say(string $code, array $e, ?string $detail = null, array $ctx = []): string
    {
        $total = (float)($e['total'] ?? 0);
        $sub = (float)($e['amount'] ?? 0);
        $gst = (float)($e['gst_amount'] ?? 0);
        $vendor = self::vendor($e);
        $cat = trim((string)($e['accounting_category'] ?? ''));
        switch ($code) {
            case 'ROUND_NUMBER':
                $amt = '$' . number_format($total, $total == floor($total) ? 0 : 2);
                return self::isFuel($e)
                    ? "A round {$amt} is normal for a fill-up you asked for by amount; nothing to worry about."
                    : "A round {$amt} — fine for a set price or a deposit, but check it isn't an estimate.";
            case 'HIGH_AMOUNT':
                if ($detail !== null && preg_match('/average of \$([\d,]+\.?\d*)/', $detail, $m)) {
                    return 'That\'s more than double the usual ' . ($cat !== '' ? $cat : 'spend') . ' for this month (about $' . $m[1] . ').';
                }
                return 'Bigger than usual for ' . ($cat !== '' ? $cat : 'this kind of expense') . ' — make sure the amount is right.';
            case 'DUPLICATE_DAY':
                return !empty($ctx['same_amount'])
                    ? "There's another {$vendor} receipt that day for the same amount."
                    : "There's another {$vendor} receipt that day. Make sure it isn't the same one twice.";
            case 'NO_RECEIPT':
                return "There's no photo of the receipt, so I couldn't check it against the paper.";
            case 'CATEGORY_MISMATCH':
                if ($detail !== null && preg_match("/default is '([^']+)'/", $detail, $m)) {
                    return "It's filed under {$cat}, but {$vendor} is usually {$m[1]}.";
                }
                return "It's filed under " . ($cat !== '' ? $cat : 'a category') . " that {$vendor} isn't usually filed under.";
            case 'RAPID_SUBMISSION':
                return 'It came in with several others within a few minutes — usually just a batch from someone\'s wallet.';
            case 'SPLIT_SUSPICION':
                return "Two {$vendor} receipts within an hour that add up to over \$200 — check it wasn't one purchase split in two.";
            case 'GST_MISMATCH':
                if ($detail !== null && stripos($detail, 'exempt') !== false) {
                    return 'GST was charged, but ' . $vendor . ' is set up as GST-exempt. Worth a look.';
                }
                if ($sub > 0 && $gst > 0) {
                    return 'GST is ' . self::money($gst) . ', but 5% of the subtotal would be ' . self::money(round($sub * 0.05, 2)) . '. Worth a look.';
                }
                return "The GST doesn't look like 5% of the subtotal. Worth a look.";
            case 'WEEKEND_EXPENSE':
                $ts = strtotime((string)($e['expense_date'] ?? ''));
                return 'Bought on a ' . ($ts ? date('l', $ts) : 'weekend') . '.';
            case 'LOCATION_MISMATCH':
                return "The photo was taken more than 20 km from {$vendor} and from the job.";
        }
        return $detail !== null && $detail !== '' ? $detail : 'Flagged: ' . strtolower(str_replace('_', ' ', $code)) . '.';
    }

    /** Pure: Penny's one-line summary above the flags. */
    public static function summary(int $score, string $status, int $flagCount): string
    {
        if (in_array($status, ['approved', 'forwarded', 'sent'], true)) {
            return $flagCount ? "You approved this; here's what I'd flagged." : 'You approved this; I had nothing to flag.';
        }
        if ($status === 'rejected') {
            return $flagCount ? "You rejected this; here's what I'd flagged." : 'You rejected this; I had nothing to flag.';
        }
        if ($flagCount === 0) return 'Nothing to flag — this one looks routine.';
        switch (self::tier($score)) {
            case 'high':   return "I'd check this one before approving.";
            case 'medium': return 'Worth a quick look before you approve.';
            default:       return 'Looks routine — just a small note.';
        }
    }

    /**
     * Pure: the whole answer for the app.
     * @param array $e     the expense row (+ vendor_name)
     * @param array $fresh AnomalyDetector details for it now
     * @param array $ctx   per-code extra facts, e.g. ['DUPLICATE_DAY' => ['same_amount' => true]]
     * @return array{score:int, tier:string, summary:string, flags:array}
     */
    public static function explain(array $e, array $fresh, array $ctx = []): array
    {
        $score = (int)($e['anomaly_score'] ?? 0);
        $byCode = [];
        foreach ($fresh as $d) $byCode[(string)($d['code'] ?? '')] = $d;
        $flags = [];
        foreach (self::codes((string)($e['anomaly_flags'] ?? ''), $fresh) as $code) {
            $detail = isset($byCode[$code]) ? (string)$byCode[$code]['detail'] : null;
            $flags[] = ['code' => $code, 'detail' => $detail, 'penny' => self::say($code, $e, $detail, $ctx[$code] ?? [])];
        }
        if ($score === 0 && $fresh) $score = (int)max(array_map(fn($d) => (int)($d['score'] ?? 0), $fresh));
        return [
            'score'   => $score,
            'tier'    => self::tier($score),
            'summary' => self::summary($score, (string)($e['status'] ?? ''), count($flags)),
            'flags'   => $flags,
        ];
    }

    /** DB facts the lines need: is the same-day receipt at that vendor for the same total? */
    public static function context(PDO $db, array $e): array
    {
        $ctx = [];
        try {
            $vid = (int)($e['vendor_id'] ?? 0);
            $sql = "SELECT COUNT(*) FROM expenses WHERE expense_date = ? AND id <> ? AND ABS(total - ?) < 0.005 AND "
                 . ($vid > 0 ? 'vendor_id = ?' : 'vendor_name_raw = ?');
            $s = $db->prepare($sql);
            $s->execute([(string)($e['expense_date'] ?? ''), (int)($e['id'] ?? 0), (float)($e['total'] ?? 0),
                         $vid > 0 ? $vid : (string)($e['vendor_name_raw'] ?? '')]);
            $ctx['DUPLICATE_DAY'] = ['same_amount' => (int)$s->fetchColumn() > 0];
        } catch (Throwable $ex) {
            // context is a bonus — the line still reads without it
        }
        return $ctx;
    }
}
