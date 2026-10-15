<?php
declare(strict_types=1);

/**
 * MiaThemeOutcomes — which season theme brings in work (2026-10-15, heads plan D4).
 *
 * A theme is scored by the work it brought, not by likes: accepted quotes whose service matches the
 * theme, around this time of year (±SEASON_DAYS by day of year), the last 12 months at full weight and
 * the 12 before at half. A win a campaign led to (campaign_attribution) counts double. A theme needs
 * MIN_WINS (weighted) before it scores at all; engagement stays only as an early nudge, capped at
 * ENGAGEMENT_CAP, because reach has been zero since the Meta tokens broke.
 * The score feeds MiaSeasonThemes::pick() (0..1.5: it nudges a theme ahead, the season order stands).
 */
class MiaThemeOutcomes
{
    public const SEASON_DAYS = 45;
    public const MIN_WINS = 3.0;
    public const ENGAGEMENT_CAP = 0.5;
    public const MAX_SCORE = 1.5;

    /**
     * @param list<array{service_type: ?string, at: string, amount?: float|string, attributed?: bool|int}> $wins
     * @param list<array> $themes MiaSeasonThemes::all()
     * @return array<string, float> theme key => score (only themes above the average and with enough wins)
     */
    public static function scores(array $wins, array $themes, DateTimeImmutable $today): array
    {
        // One win is shared between the themes it matches ("maintenance" fits four): it counts once in all.
        $byTheme = [];
        foreach ($wins as $x) {
            $w = self::weight((string)$x['at'], $today) * (!empty($x['attributed']) ? 2.0 : 1.0);
            if ($w <= 0) continue;
            $keys = [];
            foreach ($themes as $t) if (MiaSeasonThemes::matchesService($t, $x['service_type'] ?? null)) $keys[] = $t['key'];
            foreach ($keys as $k) $byTheme[$k] = ($byTheme[$k] ?? 0.0) + $w / count($keys);
        }
        $counted = array_filter($byTheme, fn($w) => $w >= self::MIN_WINS);
        if (!$counted) return [];
        $avg = array_sum($counted) / count($counted);
        $out = [];
        foreach ($counted as $k => $w) {
            // One theme alone with enough wins: it is the one that sells now.
            $s = count($counted) === 1 ? 1.0 : min(self::MAX_SCORE, max(0.0, $w / $avg - 1.0) * 2);
            if ($s > 0) $out[$k] = round($s, 2);
        }
        return $out;
    }

    /** 1 within the last year, 0.5 the year before, 0 older — and only around the same time of year. */
    public static function weight(string $at, DateTimeImmutable $today): float
    {
        $ts = strtotime($at);
        if (!$ts) return 0.0;
        $d = new DateTimeImmutable(date('Y-m-d', $ts));
        $age = (int)$d->diff($today)->days * ($d > $today ? -1 : 1);
        if ($age < 0 || $age > 730) return 0.0;
        // Days from the same date in last / this / next year (real calendar days, leap years included).
        $near = PHP_INT_MAX;
        foreach ([-1, 0, 1] as $dy) {
            $y = (int)$today->format('Y') + $dy;
            $md = $d->format('m-d') === '02-29' && !checkdate(2, 29, $y) ? '02-28' : $d->format('m-d');
            $near = min($near, (int)(new DateTimeImmutable($y . '-' . $md))->diff($today)->days);
        }
        if ($near > self::SEASON_DAYS) return 0.0;
        return $age <= 365 ? 1.0 : 0.5;
    }

    /** Outcome scores first; engagement only lifts a theme that has no outcome score, and only a little. */
    public static function blend(array $outcome, array $engagement): array
    {
        $out = $outcome;
        foreach ($engagement as $k => $v) {
            if (!isset($out[$k])) $out[$k] = round(min(self::ENGAGEMENT_CAP, (float)$v), 2);
        }
        return $out;
    }

    /** Accepted quotes of the last two years, with whether a campaign led to them. */
    public static function wins(PDO $db, DateTimeImmutable $today): array
    {
        try {
            $s = $db->prepare("
                SELECT COALESCE(NULLIF((SELECT jp.service_type FROM job_plans jp WHERE jp.quote_id = q.id ORDER BY jp.id LIMIT 1), ''), q.service_type) AS service_type,
                       COALESCE(q.accepted_at, q.accepted_date, q.updated_at) AS at, q.total_amount AS amount,
                       EXISTS (SELECT 1 FROM campaign_attribution ca WHERE ca.contact_id = q.contact_id AND ca.contact_id IS NOT NULL
                               AND ca.attributed_at BETWEEN DATE_SUB(COALESCE(q.accepted_at, q.accepted_date, q.updated_at), INTERVAL 30 DAY)
                                                    AND COALESCE(q.accepted_at, q.accepted_date, q.updated_at)) AS attributed
                FROM quotes q
                WHERE q.status = 'accepted' AND COALESCE(q.accepted_at, q.accepted_date, q.updated_at) >= ?
            ");
            $s->execute([$today->modify('-730 days')->format('Y-m-d')]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('Mia theme outcomes: ' . $e->getMessage());
            return [];
        }
    }
}
