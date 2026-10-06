<?php
/**
 * OttoBrainService — what Otto has learned, counted for his brain on the dashboard
 * (shared HeadBrain, start line in ops_settings "otto_brain_baseline").
 *
 * Every unit is something real, learned from the owner:
 *   weather   service types where Otto now leans one way (3+ of the owner's rain calls)
 *   crew      people whose quiet phone Otto now waits longer for (owner's answer)
 *   durations plans whose visit length the owner has set
 *   answers   questions the owner answered
 *   badges    earned, never claimed (OttoBadgeService)
 * Brightness is "right first time": the share of his calls the owner kept.
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OttoBadgeService.php';
require_once __DIR__ . '/OttoQuestionService.php';
require_once __DIR__ . '/OttoRules.php';

class OttoBrainService
{
    public const LABELS = [
        'weather'   => ['service type with a rain call learned', 'service types with a rain call learned'],
        'crew'      => ['crew phone pattern learned', 'crew phone patterns learned'],
        'durations' => ['visit length learned', 'visit lengths learned'],
        'answers'   => ['question answered', 'questions answered'],
        'badges'    => ['badge', 'badges'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{units: int, parts: array, since: ?string, bright: float} */
    public function learned(?int $rightFirstTime): array
    {
        $raw = self::rawCounts(
            $this->lessons('weather'),
            count($this->lessons('silent')),
            count($this->lessons('duration')),
            (new OttoQuestionService($this->db))->answeredCount(),
            count((new OttoBadgeService($this->db))->badges()['earned'])
        );
        require_once APP_ROOT . '/Services/HeadBrain.php';
        $b = (new HeadBrain($this->db, 'otto'))->learned($raw, self::LABELS);
        $b['bright'] = $rightFirstTime === null ? 0.5 : max(0.0, min(1.0, $rightFirstTime / 100));
        return $b;
    }

    private function lessons(string $scope): array
    {
        try {
            $s = $this->db->prepare("SELECT value_json FROM otto_lessons WHERE scope = ?");
            $s->execute([$scope]);
            return array_map(fn($j) => json_decode((string)$j, true) ?: [], $s->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Pure: a weather lesson counts once it holds enough decisions to lean on. */
    public static function rawCounts(array $weatherLessons, int $crew, int $durations, int $answers, int $badges): array
    {
        $weather = count(array_filter($weatherLessons,
            fn($l) => count((array)($l['keep'] ?? [])) + count((array)($l['move'] ?? [])) >= OttoRules::LEAN_MIN));
        return ['weather' => $weather, 'crew' => $crew, 'durations' => $durations, 'answers' => $answers, 'badges' => $badges];
    }
}
