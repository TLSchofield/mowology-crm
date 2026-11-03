<?php
declare(strict_types=1);

/**
 * ContentMix — the calendar's balance and cadence, measured (small-business plugin, social-content-engine, 2026-11-03).
 *   cadence   posts per week by who does the marketing: solo owner 2 (+1 email a month); owner with office help 3–4 (+1 email
 *             per two weeks); someone on marketing 5 (+weekly email). Horizon 30 days unless there is a dated reason.
 *   mix       40% useful / 30% proof / 20% offer / 10% people — proof is the one that is always short ("ask for job photos")
 *   opener    every run says: published since last run / still scheduled / the first gap
 *   conflicts two posts on one day for the same service; a scheduled time in the past; an offer post after its offer ends
 * House rules kept over the plugin's: a caption is one honest sentence with at most 3 hashtags; no emoji; no exclamation marks.
 * Pure and static. Posts: {date 'Y-m-d' or datetime, kind (useful|proof|offer|people), status (published|scheduled|draft), service?, offer_ends?}.
 */
final class ContentMix
{
    public const TARGET = ['useful' => 0.40, 'proof' => 0.30, 'offer' => 0.20, 'people' => 0.10];
    public const CADENCE = ['solo' => [2, 'one email a month'], 'office_help' => [4, 'one email every two weeks'], 'marketing_help' => [5, 'a weekly email']];
    public const HORIZON_DAYS = 30;
    public const MAX_HASHTAGS = 3;

    /** @return array{published_since:int, scheduled:int, first_gap:?string, weeks_covered:float, per_week:int, email:string, mix:array, short:array, conflicts:string[], say:string[]} */
    public static function report(array $posts, string $today, string $team = 'solo', ?string $lastRun = null): array
    {
        [$perWeek, $email] = self::CADENCE[$team] ?? self::CADENCE['solo'];
        $t = strtotime($today);
        $horizonEnd = date('Y-m-d', strtotime($today . ' +' . self::HORIZON_DAYS . ' days'));
        $published = 0; $scheduled = 0; $byDay = []; $mixCount = array_fill_keys(array_keys(self::TARGET), 0); $conf = []; $total = 0;
        foreach ($posts as $p) {
            $d = substr((string)($p['date'] ?? ''), 0, 10); if ($d === '') continue;
            $st = (string)($p['status'] ?? 'draft');
            if ($st === 'published' && ($lastRun === null || $d >= substr($lastRun, 0, 10))) $published++;
            if ($st === 'scheduled' && $d >= $today) { $scheduled++; $byDay[$d][] = $p; }
            if ($st === 'scheduled' && strtotime((string)$p['date']) < $t) $conf[] = 'A scheduled post is dated in the past (' . $p['date'] . ') — it will never go';
            if (!empty($p['offer_ends']) && (string)$p['offer_ends'] < $d) $conf[] = 'An offer post on ' . $d . ' lands after its offer ends (' . $p['offer_ends'] . ')';
            if ($d >= $today && $d <= $horizonEnd && $st !== 'published') { $k = (string)($p['kind'] ?? 'useful'); if (isset($mixCount[$k])) { $mixCount[$k]++; $total++; } }
        }
        foreach ($byDay as $d => $list) {
            $svc = array_count_values(array_map(fn($p) => strtolower((string)($p['service'] ?? '')), $list));
            foreach ($svc as $s => $n) if ($s !== '' && $n > 1) $conf[] = "$n posts on $d about $s — one of them should move";
        }
        // first gap: the first week in the horizon with fewer than perWeek scheduled
        $firstGap = null;
        for ($w = 0; $w < 4; $w++) {
            $ws = date('Y-m-d', strtotime($today . " +" . ($w * 7) . " days")); $we = date('Y-m-d', strtotime($ws . ' +6 days'));
            $n = 0; foreach ($byDay as $d => $list) if ($d >= $ws && $d <= $we) $n += count($list);
            if ($n < $perWeek) { $firstGap = $ws; break; }
        }
        $mix = []; $short = [];
        foreach (self::TARGET as $k => $share) {
            $have = $total ? $mixCount[$k] / $total : 0.0;
            $mix[$k] = ['have' => round($have, 2), 'target' => $share, 'count' => $mixCount[$k]];
            if ($total >= 4 && $have < $share * 0.5) $short[] = $k;   // under half its target share is "short"
        }
        $say = ['Published since last run: ' . $published . '. Still scheduled: ' . $scheduled . '. ' . ($firstGap ? 'Gap starting the week of ' . date('M j', strtotime($firstGap)) . ' (fewer than ' . $perWeek . ' a week).' : 'No gap in the next 4 weeks.')];
        if ($short) $say[] = 'The mix is short on ' . implode(' and ', array_map(fn($k) => $k . ' (' . round($mix[$k]['have'] * 100) . '% of ' . round(self::TARGET[$k] * 100) . '%)', $short)) . (in_array('proof', $short, true) ? ' — proof is the usual gap: ask the crew for finished-job photos.' : '.');
        foreach ($conf as $c) $say[] = $c;
        return ['published_since' => $published, 'scheduled' => $scheduled, 'first_gap' => $firstGap, 'per_week' => $perWeek, 'email' => $email, 'mix' => $mix, 'short' => $short, 'conflicts' => $conf, 'say' => $say];
    }

    /** House caption rules (stricter than the plugin's hook/benefit/CTA/3–5 hashtags). @return string[] problems */
    public static function captionProblems(string $caption): array
    {
        $w = [];
        $tags = preg_match_all('/(^|\s)#\w+/u', $caption);
        if ($tags > self::MAX_HASHTAGS) $w[] = $tags . ' hashtags — at most ' . self::MAX_HASHTAGS;
        $body = trim(preg_replace('/(^|\s)#\w+/u', '', $caption));
        $sentences = count(array_filter(preg_split('/(?<=[.?!])\s+/', $body), fn($s) => str_word_count($s) > 0));
        if ($sentences > 2) $w[] = $sentences . ' sentences — one honest sentence (two at most)';
        if ($body === '') $w[] = 'No caption, only hashtags';
        foreach (VoiceProfile::check($caption) as $v) if (strpos($v, 'price or percentage') === false) $w[] = $v;
        return $w;
    }

    /** Email shape (plugin): subject under 50 characters, preheader 40–90, 100–250 words, one call to action. @return string[] */
    public static function emailProblems(string $subject, string $body, ?string $preheader = null): array
    {
        $w = [];
        if (mb_strlen($subject) >= 50) $w[] = 'Subject is ' . mb_strlen($subject) . ' characters — keep it under 50';
        if ($preheader !== null && $preheader !== '' && (mb_strlen($preheader) < 40 || mb_strlen($preheader) > 90)) $w[] = 'Preheader should be 40–90 characters and add something the subject does not';
        $words = str_word_count(strip_tags($body));
        if ($words < 100) $w[] = "Body is $words words — 100–250 reads as a real note";
        if ($words > 250) $w[] = "Body is $words words — 100–250; cut to one point";
        $ctas = preg_match_all('/\b(reply|call|book|click|tap|let me know|get in touch|see it here|read more)\b/i', strip_tags($body));
        if ($ctas > 2) $w[] = 'More than one ask — one call to action per email';
        return $w;
    }
}
