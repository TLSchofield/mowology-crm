<?php
declare(strict_types=1);

/**
 * One neutral key per kind of work, so Penny's margins, Sam's funnel and Otto's jobs group the same way.
 * Pure: no database. Production holds 15+ spellings of the same few services (2026-10-26: `lawn_care`,
 * `7 Day Lawn Cut Service`, `Lawn Cut`, `GARDENING`, `Garden Care`, ...). The keys name the kind of work, not a trade,
 * so another trade adds its own spellings to the table without changing the keys that already exist.
 * An unrecognised spelling is `other`, never a guess; callers show it as "Other" and it appears in unmapped().
 */
final class MiaServiceKey
{
    public const OTHER = 'other';

    /** Normalised spelling => key. Add a spelling here when unmapped() reports it. */
    private const MAP = [
        'lawn care' => 'recurring_upkeep', 'lawn cut' => 'recurring_upkeep', 'lawn maintenance' => 'recurring_upkeep',
        '7 day lawn cut service' => 'recurring_upkeep', '14 day lawn cut service' => 'recurring_upkeep',
        'maintenance' => 'recurring_upkeep',
        'gardening' => 'garden_care', 'garden care' => 'garden_care', 'garden maintenance' => 'garden_care',
        'hedge trimming' => 'garden_care',
        'aeration' => 'seasonal_treatment', 'summer fertilizer' => 'seasonal_treatment',
        'landscaping' => 'install_project', 'black composted bark mulch' => 'install_project', 'premium topsoil' => 'install_project',
        'snow removal' => 'winter_service', 'salt application' => 'winter_service',
        'cleanup' => 'cleanup',
        'skilled labor' => 'labor', 'basic labor' => 'labor',
    ];

    /** @return string[] every key a spelling can map to, plus OTHER */
    public static function keys(): array
    {
        $k = array_values(array_unique(array_values(self::MAP)));
        $k[] = self::OTHER;
        return $k;
    }

    public static function normalise(?string $raw): string
    {
        $s = strtolower(trim((string)$raw));
        return trim((string)preg_replace('/[\s_\-]+/', ' ', $s));
    }

    public static function keyFor(?string $raw): string
    {
        return self::MAP[self::normalise($raw)] ?? self::OTHER;
    }

    /**
     * Spellings that map to OTHER, so a new one is noticed and added instead of silently grouped away.
     * @param string[] $spellings
     * @return string[]
     */
    public static function unmapped(array $spellings): array
    {
        $out = [];
        foreach ($spellings as $s) {
            if (self::normalise((string)$s) !== '' && self::keyFor((string)$s) === self::OTHER) { $out[$s] = $s; }
        }
        return array_values($out);
    }
}
