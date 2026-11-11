<?php
/**
 * OttoBrainService — what Otto has learned, counted for his brain on the dashboard
 * (shared HeadBrain, start line in ops_settings "otto_brain_baseline").
 *
 * Every unit is something real, learned from the owner:
 *   weather   one triangle per service type where Otto now leans one way (3+ of the owner's
 *             rain calls), coloured by strength = the calls on the side he leans to
 *             (HeadBrain::TIERS). Replaces the old "weather" count.
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
require_once dirname(__DIR__, 3) . '/Services/HeadBrain.php';

class OttoBrainService
{
    public const LABELS = [
        'crew'      => ['crew phone pattern learned', 'crew phone patterns learned'],
        'durations' => ['visit length learned', 'visit lengths learned'],
        'answers'   => ['question answered', 'questions answered'],
        'badges'    => ['badge', 'badges'],
        'rules'     => ['bylaw rule confirmed', 'bylaw rules confirmed'],
        'road'      => ['truck road factor learned', 'truck road factors learned'],
        'packs'     => ['battery run logged', 'battery runs logged'],
        'intervals' => ['service interval set', 'service intervals set'],
        'mapped'    => ['service mapped to its training', 'services mapped to their training'],
        'shadow'    => ['shadowing pair learned', 'shadowing pairs learned'],
        'topics'    => ['crew-meeting topic taken up', 'crew-meeting topics taken up'],
        'coached'   => ['crew member certified after a flag', 'crew members certified after a flag'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array{units: int, parts: array, since: ?string, bright: float} */
    public function learned(?int $rightFirstTime): array
    {
        $weather = $this->lessons('weather', true);
        $raw = self::rawCounts(
            [],
            count($this->lessons('silent')),
            count($this->lessons('duration')),
            (new OttoQuestionService($this->db))->answeredCount(),
            count((new OttoBadgeService($this->db))->badges()['earned']),
            $this->dispatchCounts()
        );
        require_once APP_ROOT . '/Services/HeadBrain.php';
        unset($raw['weather']);
        $b = HeadBrain::withItems((new HeadBrain($this->db, 'otto'))->learned($raw, self::LABELS), self::weatherItems($weather));
        $b['bright'] = $rightFirstTime === null ? 0.5 : max(0.0, min(1.0, $rightFirstTime / 100));
        return $b;
    }

    private function lessons(string $scope, bool $keyed = false): array
    {
        try {
            $s = $this->db->prepare("SELECT scope_key, value_json FROM otto_lessons WHERE scope = ?");
            $s->execute([$scope]);
            $rows = array_map(fn($j) => json_decode((string)$j, true) ?: [], $s->fetchAll(PDO::FETCH_KEY_PAIR));
            return $keyed ? $rows : array_values($rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Dispatcher learning: rules Tim confirmed, the truck's road factor, pack runs, intervals. */
    private function dispatchCounts(): array
    {
        $out = ['rules' => 0, 'road' => 0, 'packs' => 0, 'intervals' => 0];
        try {
            require_once __DIR__ . '/MunicipalRuleService.php';
            $out['rules'] = (new MunicipalRuleService($this->db))->confirmedCount();
            require_once __DIR__ . '/EquipmentService.php';
            $eq = new EquipmentService($this->db);
            if ($eq->ready()) $out = $eq->learnedCounts() + $out;
        } catch (Throwable $e) { /* not migrated yet */ }
        try {
            require_once __DIR__ . '/TrainingService.php';
            $tr = new TrainingService($this->db);
            if ($tr->ready()) $out = $tr->learnedCounts() + $out;
        } catch (Throwable $e) { /* no certification tables */ }
        return $out;
    }

    /**
     * Pure: [service type => lesson{keep: int[], move: int[]}] → one item per service type
     * with LEAN_MIN+ of the owner's calls (as the old count); strength = calls on the bigger side.
     */
    public static function weatherItems(array $lessons): array
    {
        $out = [];
        foreach ($lessons as $type => $l) {
            $keep = count((array)($l['keep'] ?? []));
            $move = count((array)($l['move'] ?? []));
            $n = $keep + $move;
            if ($n < OttoRules::LEAN_MIN) continue;
            $agree = max($keep, $move);
            $lean = $agree / $n < OttoRules::LEAN_SHARE ? 'no clear lean yet' : ($keep >= $move ? 'keep it' : 'move it');
            $out[] = HeadBrain::item('weather:' . $type, ucfirst((string)$type) . ' in the rain: ' . $lean, $agree,
                ['group' => 'Rain calls', 'note' => $agree . ' of ' . $n . ' calls agree']);
        }
        return $out;
    }

    /** Pure: a weather lesson counts once it holds enough decisions to lean on. */
    public static function rawCounts(array $weatherLessons, int $crew, int $durations, int $answers, int $badges, array $dispatch = []): array
    {
        $weather = count(array_filter($weatherLessons,
            fn($l) => count((array)($l['keep'] ?? [])) + count((array)($l['move'] ?? [])) >= OttoRules::LEAN_MIN));
        $out = ['weather' => $weather, 'crew' => $crew, 'durations' => $durations, 'answers' => $answers, 'badges' => $badges];
        foreach (['rules', 'road', 'packs', 'intervals', 'mapped', 'shadow', 'topics', 'coached'] as $k) {
            if (array_key_exists($k, $dispatch)) $out[$k] = max(0, (int)$dispatch[$k]);
        }
        return $out;
    }
}
