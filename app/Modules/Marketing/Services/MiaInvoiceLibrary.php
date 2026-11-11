<?php
declare(strict_types=1);

/**
 * MiaInvoiceLibrary — the asks Mia may suggest for the customer-facing invoice note (2026-11-01).
 *
 * Starter set, reviewed by the owner: an entry he strikes (ops_settings mia_invoice_library_struck, CSV
 * of keys) is never used. Wording follows app/Services/Copy/mowology-copy-rules.md: Tim's voice, short
 * sentences, one ask, no exclamation marks, no prices or dates that are not real, no incentive for a review,
 * no watering advice in summer. Facts come from MiaSeasonThemes (nesting season ends the hedge window on
 * March 15; leaf collection runs October–December; aeration wants mid-Sep to mid-Oct).
 *
 * Fields: key, method (review|seasonal|referral|next_year), priority (lower first), months (list of 1-12),
 * needs (service keywords of which the invoice must carry one; [] = any), avoid (keywords that, when already on
 * the invoice, rule the ask out), version (bump when the wording changes), text with {first_name}
 * {review_url} {phone}.
 *
 * Pure. No namespace / no autoloader in production: require_once and call.
 */
class MiaInvoiceLibrary
{
    /** The house phone line (copy rules). Own constant: production's MiaWording is older than the repo's. */
    public const PHONE = '(778) 846-9273';

    private const ALL = [
        ['review_after_work', 'review', 10, [1,2,3,4,5,6,7,8,9,10,11,12], [], [], 1,
            "Thanks, {first_name}. If you have a minute, a Google review helps your neighbours find a crew they can count on: {review_url}\n\nSomething not right? Call or text me on {phone} and I'll sort it out first.\n\nThanks, Tim"],
        ['leaf_cleanup', 'seasonal', 20, [10,11,12], [], ['leaf', 'fall-clean', 'cleanup', 'clean-up'], 1,
            "Thanks, {first_name}. Leaves are coming down now, and the city collects them through December. If you'd like a leaf clean-up before the winter rain, tell me and I'll fit it in on a visit. {phone}.\n\nThanks, Tim"],
        ['next_year_mowing', 'next_year', 25, [10,11,12], ['lawn-cut', 'mow'], [], 1,
            "Thanks, {first_name}. Your mowing carries on until the grass stops growing. If you'd like the same schedule again next spring, tell me and I'll book you in early. {phone}.\n\nThanks, Tim"],
        ['spring_hedges', 'seasonal', 20, [1,2], [], ['hedge'], 1,
            "Thanks, {first_name}. Heavy hedge work goes best before March 15, when the birds start nesting. If your hedges need it, tell me now and I'll book a date. {phone}.\n\nThanks, Tim"],
        ['fall_aeration', 'seasonal', 20, [9,10], ['lawn', 'mow'], ['aerat', 'overseed'], 1,
            "Thanks, {first_name}. Mid-September to mid-October is a good window to aerate and overseed a lawn. If you'd like it done, tell me and I'll fit it in. {phone}.\n\nThanks, Tim"],
        ['referral_neighbour', 'referral', 40, [1,2,3,4,5,6,7,8,9,10,11,12], [], [], 1,
            "Thanks, {first_name}. If a neighbour needs a hand with their lawn or hedges, I'd be glad if you passed my number on: {phone}.\n\nThanks, Tim"],
    ];

    /** @return list<array{key:string, method:string, priority:int, months:int[], needs:string[], avoid:string[], version:int, text:string}> */
    public static function all(): array
    {
        return array_map(fn($r) => ['key' => $r[0], 'method' => $r[1], 'priority' => $r[2], 'months' => $r[3],
            'needs' => $r[4], 'avoid' => $r[5], 'version' => $r[6], 'text' => $r[7]], self::ALL);
    }

    public static function find(string $key): ?array
    {
        foreach (self::all() as $e) if ($e['key'] === $key) return $e;
        return null;
    }

    /** True when any keyword is a substring of any service name (lowercased, spaces and underscores as hyphens). */
    public static function anyMatch(array $keywords, array $serviceNames): bool
    {
        foreach ($serviceNames as $n) {
            $s = strtolower(str_replace(['_', ' '], '-', (string)$n));
            foreach ($keywords as $k) if ($s !== '' && strpos($s, $k) !== false) return true;
        }
        return false;
    }

    public static function render(string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) $map['{' . $k . '}'] = (string)$v;
        return strtr($text, $map);
    }
}
