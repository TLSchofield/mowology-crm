<?php
/** Pure parser for Jobber's comma-separated invoice line-item text. */
require_once __DIR__ . '/CloserPricing.php';

final class JobberLineParser
{
    public static function parse(?string $text): array
    {
        if ($text === null || trim($text) === '') return [];
        $pattern = '/\((-?[\d.]+),\s*(-?)\$(-?[\d,]+(?:\.\d+)?)\)/';
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) return [];

        $lines = [];
        $previousEnd = 0;
        foreach ($matches[0] as $i => $group) {
            $start = $group[1];
            $name = substr($text, $previousEnd, $start - $previousEnd);
            $name = preg_replace('/^\s*,\s*/', '', $name);
            $amountText = str_replace(',', '', $matches[3][$i][0]);
            $amount = (float)$amountText;
            if ($matches[2][$i][0] === '-' && $amount > 0) $amount *= -1;
            $lines[] = [
                'name' => trim((string)$name),
                'qty' => (float)$matches[1][$i][0],
                'amount' => $amount,
            ];
            $previousEnd = $start + strlen($group[0]);
        }
        return $lines;
    }

    public static function forService(array $lines, string $serviceKey): array
    {
        return array_values(array_filter($lines, static function (array $line) use ($serviceKey): bool {
            return in_array($serviceKey, CloserPricing::serviceKeys((string)($line['name'] ?? '')), true);
        }));
    }
}
