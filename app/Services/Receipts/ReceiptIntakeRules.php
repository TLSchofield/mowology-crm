<?php
declare(strict_types=1);

/**
 * Pure rules used by ReceiptIntakeService after OCR and matching are complete.
 */
class ReceiptIntakeRules
{
    // Scorer scale: own crew (20) + GPS within 200m (50) + same day (10)
    // + purchase inside the visit window (30) = 110. Nearby without crew is at most 70.
    public const DECISIVE_JOB_MIN_SCORE = 100.0;
    public const DECISIVE_JOB_MIN_MARGIN = 20.0;

    /**
     * Use a device total only to repair a missing or arithmetically impossible server total.
     * Tax values always remain the server's values.
     */
    public static function deviceTotalFill(array $server, array $device, string $serverText, string $deviceText): ?array
    {
        $serverTotal = self::positiveAmount($server['total'] ?? null);
        if ($serverTotal !== null && !self::serverTotalImpossible($server, $serverTotal)) {
            return null;
        }

        $deviceTotal = self::positiveAmount($device['total'] ?? null);
        if ($deviceTotal === null) {
            return null;
        }

        $serverPayment = self::paymentAmount($serverText);
        $devicePayment = self::paymentAmount($deviceText);
        if (($serverPayment !== null && abs($serverPayment - $deviceTotal) < 0.005)
            || ($devicePayment !== null && abs($devicePayment - $deviceTotal) < 0.005)) {
            return ['total' => number_format($deviceTotal, 2, '.', ''), 'reason' => 'confirmed by payment line'];
        }

        if (self::partsEqualTotal($device, $deviceTotal, 0.02)) {
            return ['total' => number_format($deviceTotal, 2, '.', ''), 'reason' => 'confirmed by device arithmetic'];
        }
        if (self::partsEqualTotal($server, $deviceTotal, 0.02)) {
            return ['total' => number_format($deviceTotal, 2, '.', ''), 'reason' => 'confirmed by server arithmetic'];
        }

        return null;
    }

    public static function decisiveJob(array $suggestions, float $minScore, float $minMargin): ?int
    {
        if (empty($suggestions)) return null;

        $ranked = [];
        foreach ($suggestions as $index => $suggestion) {
            $ranked[] = ['index' => (int)$index, 'score' => (float)($suggestion['score'] ?? 0)];
        }
        usort($ranked, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) return 0;
            return $a['score'] < $b['score'] ? 1 : -1;
        });

        if ($ranked[0]['score'] < $minScore) return null;
        if (isset($ranked[1]) && $ranked[0]['score'] - $ranked[1]['score'] < $minMargin) return null;
        return $ranked[0]['index'];
    }

    public static function formatCard(?string $last4, ?array $row, ?string $paymentMethod): ?array
    {
        if ($last4 === null || $last4 === '') return null;
        return [
            'last4' => $last4,
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'holder' => isset($row['full_name']) && $row['full_name'] !== '' ? (string)$row['full_name'] : null,
            'label' => isset($row['label']) && $row['label'] !== '' ? (string)$row['label'] : null,
            'payment_method' => $paymentMethod ?: 'credit_card',
        ];
    }

    private static function serverTotalImpossible(array $server, float $total): bool
    {
        $gst = self::amount($server['gst'] ?? null);
        if ($gst !== null && $gst >= $total) return true;

        $subtotal = self::amount($server['subtotal'] ?? null);
        $pst = self::amount($server['pst'] ?? null);
        if ($subtotal === null || $gst === null || $pst === null) return false;
        return abs(($subtotal + $gst + $pst) - $total) > max(0.05, $total * 0.01);
    }

    private static function partsEqualTotal(array $parsed, float $total, float $tolerance): bool
    {
        $subtotal = self::amount($parsed['subtotal'] ?? null);
        $gst = self::amount($parsed['gst'] ?? null);
        $pst = self::amount($parsed['pst'] ?? null);
        return $subtotal !== null && $gst !== null && $pst !== null
            && abs(($subtotal + $gst + $pst) - $total) <= $tolerance;
    }

    private static function paymentAmount(string $text): ?float
    {
        if (!function_exists('extractPaymentLineAmount')) return null;
        $amount = extractPaymentLineAmount((array)preg_split('/\r?\n/', $text));
        return self::amount($amount);
    }

    private static function positiveAmount($value): ?float
    {
        $amount = self::amount($value);
        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private static function amount($value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
