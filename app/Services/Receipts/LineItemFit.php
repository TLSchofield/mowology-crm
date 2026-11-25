<?php
declare(strict_types=1);

/** Pure checks shared by receipt line-item recovery paths. */
final class LineItemFit
{
    public static function sum(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (!empty($item['removed'])) continue;
            $value = $item['line_total'] ?? ($item['amount'] ?? 0);
            if (is_numeric($value)) $sum += (float)$value;
        }
        return $sum;
    }

    public static function addsUp(array $items, ?float $amount, ?float $total, float $tol = 0.05, ?float $gst = null, ?float $pst = null): bool
    {
        $kept = array_filter($items, static function ($item): bool {
            return is_array($item) && empty($item['removed']);
        });
        if (!$kept) return false;
        // A line whose amount is not a number ("$5.00", "") would count as 0 and let an incomplete set pass (Codex review 2026-11-05).
        foreach ($kept as $item) {
            if (!is_numeric($item['line_total'] ?? ($item['amount'] ?? null))) return false;
        }
        // Either figure counts: most receipts print pre-tax item prices (they sum to the subtotal), but fuel
        // and some stores print tax-in prices (they sum to the total). The replay measured both (2026-11-05).
        $sum = self::sum($items);
        foreach ([$amount, $total] as $target) {
            if ($target !== null && $target > 0 && abs($sum - $target) <= $tol + 0.000000001) return true;
        }
        if (($amount === null || $amount <= 0) && $total !== null && $total > 0
            && (($gst !== null && $gst > 0) || ($pst !== null && $pst > 0))) {
            $preTax = $total - ($gst ?? 0) - ($pst ?? 0);
            if ($preTax > 0 && abs($sum - $preTax) <= $tol + 0.000000001) return true;
        }
        return false;
    }

    /** @return array{text: string, raw_response: ?array, format: string} */
    public static function readStored(?string $rawOcrJson): array
    {
        if ($rawOcrJson === null || trim($rawOcrJson) === '') {
            return ['text' => '', 'raw_response' => null, 'format' => 'none'];
        }
        $decoded = json_decode($rawOcrJson, true);
        if (is_array($decoded)) {
            $visionText = $decoded['responses'][0]['fullTextAnnotation']['text'] ?? null;
            if (is_string($visionText)) {
                return ['text' => $visionText, 'raw_response' => $decoded, 'format' => 'vision_raw'];
            }
            if (isset($decoded['text']) && is_string($decoded['text'])) {
                return ['text' => $decoded['text'], 'raw_response' => null, 'format' => 'inbox'];
            }
        }
        if (is_string($decoded)) {
            return ['text' => $decoded, 'raw_response' => null, 'format' => 'text'];
        }
        return ['text' => $rawOcrJson, 'raw_response' => null, 'format' => 'text'];
    }
}
