<?php
/**
 * TrainingRules — the pure rules behind Otto's crew-training suggestions (unit tested).
 *
 *   gap       a person scheduled on a service whose certification they don't hold
 *             (tier from cert_tier_achievements, course from cert_records — a lapsed
 *             cert counts as missing; Tim, 2026-10-06)
 *   quality   ≥3 problem visits on one service in 60 days (missing required photos,
 *             issue notes/photos, skips)
 *   topics    quiz questions most of the crew gets wrong: ≥3 people, ≥50% wrong, 30 days
 *   refresher a safety cert missing, lapsed, or lapsing within 30 days
 *
 * No namespace / no autoloader in production: require_once, then call statically.
 */
class TrainingRules
{
    public const LOOKAHEAD_DAYS = 7;
    public const PROBLEM_MIN = 3;
    public const PROBLEM_DAYS = 60;
    public const TOPIC_PEOPLE = 3;
    public const TOPIC_WRONG_SHARE = 0.5;
    public const TOPIC_DAYS = 30;
    public const TOPICS_SHOWN = 3;
    public const REFRESHER_WARN_DAYS = 30;
    /** Required for anyone who runs mowers, trimmers or blowers. */
    public const EQUIPMENT_SAFETY = 'equipment-safety';
    /** Watched for lapsing whenever someone already holds them. */
    public const SAFETY_COURSES = ['equipment-safety', 'whmis-ppe'];

    /** "Hedge Trimming" / "hedge-trimming" / "hedge_trimming" → "hedge_trimming" */
    public static function serviceKey(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$s))), '_');
    }

    /** A cert counts only while active and not past its expiry (a lapsed cert is missing). */
    public static function certValid(?array $rec, string $now): bool
    {
        if (!$rec || ($rec['status'] ?? '') !== 'active') return false;
        return empty($rec['expires_at']) || (string)$rec['expires_at'] > $now;
    }

    /**
     * What a person is missing for a service, or null when they meet it.
     * @param array $reqs [[min_tier_level, course_id, course_name], ...]
     * @param array $certs course_id => cert record (status, expires_at)
     * @return array{tier: ?int, course_id: ?int, course: ?string}|null
     */
    public static function missing(array $reqs, int $userTier, array $certs, string $now): ?array
    {
        foreach ($reqs as $r) {
            if ($userTier < (int)$r['min_tier_level']) {
                return ['tier' => (int)$r['min_tier_level'], 'course_id' => $r['course_id'] ? (int)$r['course_id'] : null, 'course' => $r['course_name'] ?? null];
            }
            if (!empty($r['course_id']) && !self::certValid($certs[(int)$r['course_id']] ?? null, $now)) {
                return ['tier' => null, 'course_id' => (int)$r['course_id'], 'course' => $r['course_name'] ?? null];
            }
        }
        return null;
    }

    /** Required photo types the visit doesn't have. @param ?string $requiredJson '["before","after"]' */
    public static function missingPhotos(?string $requiredJson, array $have): array
    {
        $req = $requiredJson ? json_decode($requiredJson, true) : null;
        if (!is_array($req)) return [];
        $have = array_map('strtolower', $have);
        return array_values(array_filter(array_map('strtolower', $req), fn($t) => $t !== '' && !in_array($t, $have, true)));
    }

    /** The problems on one visit, in words: "no after photo", "issue noted", "skipped". */
    public static function visitProblems(array $v): array
    {
        $p = [];
        if (($v['status'] ?? '') === 'skipped') {
            $p[] = 'skipped';
        } else {
            foreach (self::missingPhotos($v['photo_types_required'] ?? null, (array)($v['photo_categories'] ?? [])) as $t) $p[] = "no {$t} photo";
        }
        if ((int)($v['issue_notes'] ?? 0) > 0 || in_array('issue', (array)($v['photo_categories'] ?? []), true)) $p[] = 'issue noted';
        return $p;
    }

    /**
     * Quiz questions most of the crew gets wrong, grouped into meeting topics.
     * A person counts as wrong on a question when their latest answer to it was wrong.
     * @param array $answers rows: user_id, question_id, is_correct, answered_at, category_id, category, question_text
     * @return array<int, array{category_id: int, category: string, questions: array}>
     */
    public static function topics(array $answers): array
    {
        $latest = [];
        foreach ($answers as $a) {
            $k = (int)$a['question_id'] . ':' . (int)$a['user_id'];
            if (!isset($latest[$k]) || (string)$a['answered_at'] >= (string)$latest[$k]['answered_at']) $latest[$k] = $a;
        }
        $byQ = [];
        foreach ($latest as $a) {
            $q = (int)$a['question_id'];
            $byQ[$q] ??= ['people' => 0, 'wrong' => 0, 'row' => $a];
            $byQ[$q]['people']++;
            if (!(int)$a['is_correct']) $byQ[$q]['wrong']++;
        }
        $cats = [];
        foreach ($byQ as $q => $s) {
            if ($s['people'] < self::TOPIC_PEOPLE || $s['wrong'] / $s['people'] < self::TOPIC_WRONG_SHARE) continue;
            $c = (int)($s['row']['category_id'] ?? 0);
            $cats[$c] ??= ['category_id' => $c, 'category' => (string)($s['row']['category'] ?? 'General'), 'questions' => []];
            $cats[$c]['questions'][] = ['question_id' => $q, 'text' => (string)$s['row']['question_text'], 'wrong' => $s['wrong'], 'people' => $s['people']];
        }
        foreach ($cats as &$c) usort($c['questions'], fn($a, $b) => [$b['wrong'] / $b['people'], $b['people']] <=> [$a['wrong'] / $a['people'], $a['people']]);
        unset($c);
        $cats = array_values($cats);
        usort($cats, fn($a, $b) => count($b['questions']) <=> count($a['questions']));
        return array_slice($cats, 0, self::TOPICS_SHOWN);
    }

    /**
     * Where a safety cert stands: missing | expired | due (within 30 days) | null when fine.
     * @return array{state: string, expires: ?string}|null
     */
    public static function refresher(?array $rec, string $now, int $warnDays = self::REFRESHER_WARN_DAYS): ?array
    {
        if (!$rec || in_array($rec['status'] ?? '', ['revoked', 'pending'], true)) return ['state' => 'missing', 'expires' => null];
        $exp = $rec['expires_at'] ?? null;
        if (!$exp) return null;
        $expDay = substr((string)$exp, 0, 10);
        if ((string)$exp <= $now || ($rec['status'] ?? '') === 'expired') return ['state' => 'expired', 'expires' => $expDay];
        $warn = date('Y-m-d H:i:s', strtotime($now . " +{$warnDays} days"));
        return (string)$exp <= $warn ? ['state' => 'due', 'expires' => $expDay] : null;
    }

    /** A stable id for a person × thing pair inside otto_suggestions.subject_id. */
    public static function pairId(int $userId, int $otherId): int
    {
        return $userId * 1000 + ($otherId % 1000);
    }
}
