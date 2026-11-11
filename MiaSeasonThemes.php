<?php
/**
 * MiaSeasonThemes — what Mia's weekly Google post and social drafts are about, by date.
 *
 * Simple month-based themes for Metro Vancouver lawns and gardens, behind MiaThemeSource so
 * the marketing calendar (built separately) can feed themes later without touching the
 * drafting code: implement MiaThemeSource and pass it to MiaChannelsService.
 *
 * Every theme's words follow app/Services/Copy/mowology-copy-rules.md: short sentences, no
 * exclamation marks, no invented deadlines or prices. Facts that are load-bearing:
 *   - BC Wildlife Act s.34: active nests are protected; nesting season is roughly
 *     March 15 – August 15, so heavy hedge work goes before March 15 or after mid-August.
 *   - Aeration / overseeding wants soil at 10–15 °C (April–May; again mid-Sep to mid-Oct).
 *   - Summer: Metro Vancouver watering restrictions — Stage 2 and 3 ban lawn watering. Mia
 *     NEVER tells anyone to water a lawn in summer; a golden lawn is dormant, not dead.
 *   - Leaves: city leaf collection runs October–December; blowing leaves into the street
 *     can be fined. Irrigation blow-outs before the first hard frost.
 *   - Property managers: snow and salt contracts are signed before October 1 (Jun–Sep).
 *
 * Pure (no DB). No namespace / no autoloader in production: require_once and call.
 */

interface MiaThemeSource
{
    /**
     * Themes that apply on $day, most time-sensitive first.
     * @return array<int, array{key: string, title: string, services: string[], audience: string,
     *   google_post: string, social_line: string, cta: string, page: string}>
     */
    public function themesFor(DateTimeImmutable $day): array;
}

class MiaSeasonThemes implements MiaThemeSource
{
    /** How many weeks before the same theme is drafted again (unless nothing else applies). */
    public const REPEAT_WEEKS = 3;

    /**
     * [key, from (m-d), to (m-d), title, services, audience, google_post, social_line, cta, page]
     * Windows are inclusive; a window whose from > to wraps the new year.
     */
    private const THEMES = [
        ['spring_early_bird', '01-01', '01-31', 'Spring early-bird bookings',
            ['spring-cleanup', 'cleanup', 'maintenance'], 'all',
            "We're booking spring cleanups now. Booking early means you pick the week, and your beds and lawn are ready before the growth starts. Every visit ends with a photo report, so you can see the work without going outside.",
            'Spring is closer than it looks. We are booking cleanups now.', 'BOOK', '/services/spring-cleanup'],
        ['hedges_before_nesting', '02-01', '03-14', 'Hedges before March 15',
            ['hedge'], 'all',
            "Heavy hedge cutting is best done before March 15. From mid-March to mid-August birds are nesting, and BC's Wildlife Act protects active nests. If your hedges need a hard cut this year, now is the window.",
            'Hedges cut back before the birds move in. After March 15 we wait for nesting season to end.', 'BOOK', '/services/hedge-trimming'],
        ['spring_cleanup', '02-01', '03-31', 'Spring cleanup, lime and aeration',
            ['spring-cleanup', 'cleanup', 'lime', 'aeration', 'moss'], 'all',
            "Spring cleanup clears the winter debris, cuts back the beds and gets the lawn ready to grow. Our coast's rain leaves most lawns acidic, so we check whether lime will help. We send you photos after every visit.",
            'Winter cleared out, beds edged, lawn ready to grow.', 'BOOK', '/services/spring-cleanup'],
        ['aeration_overseed', '04-01', '05-31', 'Aeration and overseeding',
            ['aeration', 'overseed', 'lawn'], 'all',
            "Aeration and overseeding work best once the soil is 10 to 15 degrees, which is usually April and May here. The cores let air and water reach the roots, and the new seed fills the thin patches before summer.",
            'Aerated and overseeded while the soil is warm enough for the seed to take.', 'BOOK', '/services/lawn-care-programs'],
        ['moss_and_beds', '04-01', '05-31', 'Moss, beds and mulch',
            ['moss', 'mulch', 'bed', 'garden', 'maintenance'], 'all',
            "Moss moves in where a lawn is thin, shaded or compacted. We rake it out, fix the cause where we can, and finish the beds with fresh mulch so they hold moisture through the summer.",
            'Moss out, beds edged, fresh mulch down.', 'BOOK', '/services/landscape-maintenance-vancouver'],
        ['hedges_after_nesting', '08-15', '09-15', 'Hedges after nesting season',
            ['hedge'], 'all',
            "Nesting season ends in mid-August, so this is when overgrown hedges get their proper cut. One good trim now keeps them tidy through the winter.",
            'Nesting season is over. Hedges back in shape.', 'BOOK', '/services/hedge-trimming'],
        ['summer_lawn', '06-01', '08-31', 'Summer lawn care',
            ['mow', 'lawn', 'maintenance', 'weekly'], 'all',
            "A lawn that turns gold in a dry summer is dormant, not dead. It greens up again when the rain comes back. We raise the mowing height in the heat so the grass shades its own roots, and we follow the city's watering stages.",
            'Mowed high for the heat. A golden lawn in August is resting, not dying.', 'BOOK', '/services/professional-lawn-mowing-care'],
        ['snow_salt_contracts', '06-01', '09-30', 'Snow and salt contracts (property managers)',
            ['snow', 'salt', 'strata', 'commercial'], 'pm',
            "Property managers: snow and salt contracts for this winter are signed before October 1. We plan the winter routes in September, so sites signed by then are on the route before the first frost. Ask us for a one-page summary you can take to your council.",
            'Winter plans are made in September. Snow and salt contracts are signed before October 1.', 'CALL', '/services/snow-removal'],
        ['fall_aeration', '09-15', '10-15', 'Fall aeration and top-dressing',
            ['aeration', 'top-dress', 'overseed', 'lawn'], 'all',
            "Mid-September to mid-October is the second good window for aeration. Top-dressing and seed now give the lawn a head start in spring, while the soil is still warm.",
            'Aerated and top-dressed while the soil is still warm.', 'BOOK', '/services/lawn-care-programs'],
        ['bulbs', '10-01', '11-30', 'Bulb planting',
            ['bulb', 'planting', 'garden', 'bed'], 'home',
            "Spring bulbs go in during October and November. Plant them now and the beds come up in colour in March and April.",
            'Bulbs in the ground now, colour in March.', 'BOOK', '/services/landscape-maintenance-vancouver'],
        ['leaf_cleanup', '10-01', '12-31', 'Leaf cleanup',
            ['leaf', 'leaves', 'fall-cleanup', 'cleanup'], 'all',
            "Leaf season. The city collects yard waste from October to December, and blowing leaves into the street can be fined. We clear lawns, beds and gutters and take the leaves with us.",
            'Leaves cleared and taken away. Nothing blown into the street.', 'BOOK', '/services/fall-cleanup'],
        ['irrigation_blowout', '10-01', '11-30', 'Irrigation blow-outs',
            ['irrigation', 'sprinkler', 'winterization'], 'all',
            "Irrigation lines need blowing out before the first hard frost. Water left in the pipes freezes and cracks them, and the repair waits until spring.",
            'Irrigation lines blown out before the frost gets to them.', 'CALL', '/services/fall-cleanup'],
        ['winter_quiet', '12-01', '12-31', 'Winter property checks',
            ['snow', 'salt', 'storm', 'maintenance'], 'all',
            "Winter work is quieter but it still matters: storm cleanup, salt on walkways, and a check that nothing is draining where it shouldn't. Property-management clients get storm call-outs within 24 hours.",
            'Walkways salted, storm debris cleared.', 'CALL', '/services/snow-removal'],
    ];

    public function themesFor(DateTimeImmutable $day): array
    {
        $md = $day->format('m-d');
        $out = [];
        foreach (self::THEMES as $t) {
            if (!self::inWindow($md, $t[1], $t[2])) continue;
            $out[] = self::row($t) + ['span' => self::spanDays($t[1], $t[2])];
        }
        // Most time-sensitive (shortest window) first, then catalogue order.
        usort($out, fn($a, $b) => $a['span'] <=> $b['span']);
        return array_map(function ($t) { unset($t['span']); return $t; }, $out);
    }

    /** Every theme, for tests and the calendar to read. */
    public static function all(): array
    {
        return array_map([self::class, 'row'], self::THEMES);
    }

    /**
     * The one theme to draft this week.
     * @param string[] $recentKeys theme keys drafted in the last REPEAT_WEEKS weeks (newest first)
     * @param array<string, float> $performance theme key => score from what did well (MiaSocialService::rankThemes)
     * @param string $audience 'home' | 'pm' | 'all' — 'all' allows every theme
     */
    public static function pick(MiaThemeSource $source, DateTimeImmutable $day, array $recentKeys = [], array $performance = [], string $audience = 'all'): ?array
    {
        $themes = array_values(array_filter($source->themesFor($day), function ($t) use ($audience) {
            return $audience === 'all' || $t['audience'] === 'all' || $t['audience'] === $audience;
        }));
        if (!$themes) return null;
        $fresh = array_values(array_filter($themes, fn($t) => !in_array($t['key'], $recentKeys, true)));
        $pool = $fresh ?: $themes;
        if (!$fresh) {
            // Everything was used recently: take the one used longest ago.
            usort($pool, function ($a, $b) use ($recentKeys) {
                return (array_search($b['key'], $recentKeys, true) <=> array_search($a['key'], $recentKeys, true));
            });
            return $pool[0];
        }
        // Time-sensitive order stands; a theme that did well before nudges ahead by one place.
        $ranked = [];
        foreach ($pool as $i => $t) {
            $ranked[] = ['t' => $t, 'score' => -$i + min(1.5, (float)($performance[$t['key']] ?? 0))];
        }
        usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']);
        return $ranked[0]['t'];
    }

    /** Does a free-text service type ("Hedge Trimming", "spring_cleanup") belong to a theme? */
    public static function matchesService(array $theme, ?string $serviceType): bool
    {
        $s = strtolower(str_replace(['_', ' '], '-', (string)$serviceType));
        if ($s === '') return false;
        foreach ($theme['services'] as $kw) {
            if (strpos($s, $kw) !== false) return true;
        }
        return false;
    }

    /** Posts per month for a product's social_cadence ("0,0,1,…" Jan–Dec), 0 when unset. */
    public static function cadenceFor(?string $csv, int $month): int
    {
        $parts = array_map('trim', explode(',', (string)$csv));
        return count($parts) === 12 ? max(0, (int)$parts[$month - 1]) : 0;
    }

    private static function row(array $t): array
    {
        return [
            'key' => $t[0], 'title' => $t[3], 'services' => $t[4], 'audience' => $t[5],
            'google_post' => $t[6], 'social_line' => $t[7], 'cta' => $t[8], 'page' => $t[9],
        ];
    }

    private static function inWindow(string $md, string $from, string $to): bool
    {
        return $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);
    }

    private static function spanDays(string $from, string $to): int
    {
        $a = new DateTimeImmutable('2001-' . $from);
        $b = new DateTimeImmutable(($from <= $to ? '2001-' : '2002-') . $to);
        return (int)$a->diff($b)->days;
    }
}
