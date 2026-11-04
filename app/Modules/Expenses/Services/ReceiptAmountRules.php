<?php
declare(strict_types=1);

final class ReceiptAmountRules
{
    public static function amountFromTotal(float $total, float $gst, float $pst, float $recyclingTax = 0.0): float
    {
        return max(0.0, $total - $gst - $pst - $recyclingTax);
    }

    public static function footingError(float $amount, float $gst, float $pst, float $total): float
    {
        return round(abs(($amount + $gst + $pst) - $total), 2);
    }

    public static function approvalError(float $amount, float $gst, float $pst, float $total): ?string
    {
        if ($amount >= 0 && $gst >= 0 && $pst >= 0 && self::footingError($amount, $gst, $pst, $total) <= 0.02) {
            return null;
        }

        return sprintf(
            'Amounts must be non-negative and foot to the total: amount %.2f + GST %.2f + PST %.2f must equal total %.2f (within $0.02)',
            $amount,
            $gst,
            $pst,
            $total
        );
    }

    public static function footingErrorMessage(float $amount, float $gst, float $pst, float $total): ?string
    {
        if (self::footingError($amount, $gst, $pst, $total) <= 0.02) return null;
        return sprintf(
            'amount %.2f + GST %.2f + PST %.2f must equal total %.2f (within $0.02)',
            $amount,
            $gst,
            $pst,
            $total
        );
    }

    public static function inputValue(array $input, array $stored, string $key)
    {
        return array_key_exists($key, $input) ? $input[$key] : ($stored[$key] ?? null);
    }

    public static function budgetCrossingLevel(string $currentStatus, float $newPct, float $warningThreshold, float $criticalThreshold): ?string
    {
        if ($currentStatus !== 'critical' && $newPct >= $criticalThreshold) return 'critical';
        if ($currentStatus === 'ok' && $newPct >= $warningThreshold) return 'warning';
        return null;
    }

    public static function monthNumber(string $expenseDate): int
    {
        $timestamp = strtotime($expenseDate);
        return $timestamp === false ? (int) date('n') : (int) date('n', $timestamp);
    }

    public static function calculatedGst(float $subtotal, float $gstRate, bool $vendorGstExempt): float
    {
        return $vendorGstExempt ? 0.0 : round($subtotal * $gstRate, 2);
    }

    public static function updateWonArchiveClaim(int $rowCount): bool
    {
        return $rowCount === 1;
    }
}
