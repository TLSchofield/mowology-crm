<?php
/**
 * OttoQuestionService — questions Otto asks the owner when he is unsure.
 *
 *   weather_tolerance  A visit is flagged for rain and Otto has no lean for that service
 *                      type yet. "Rain 55% tomorrow on 3 mowing visits. Do you usually
 *                      keep mowing going in rain like that?"
 *                      keep / move → counted as a decision in the weather lesson
 *                      depends     → nothing learned; Otto keeps asking per visit
 *   silent_pattern     The owner said "it's fine" the last 3 times one person's phone went
 *                      quiet. "Wait an hour before I flag them?"
 *                      yes → silent lesson {wait_minutes: 60}; no → keep flagging at 15
 * One question per subject, ever (otto_questions unique kind + scope_key).
 *
 * No namespace / no autoloader in production: require_once and `new`.
 */
require_once __DIR__ . '/OttoRules.php';
require_once __DIR__ . '/OpsDeskService.php';

class OttoQuestionService
{
    public const ANSWERS = [
        'weather_tolerance' => ['keep', 'move', 'depends'],
        'silent_pattern'    => ['yes', 'no'],
    ];
    public const SILENT_WAIT = 60;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Ask about service types that have flagged visits and no lean. Cheap; safe on card load. */
    public function scanWeather(array $items): int
    {
        $byService = [];
        foreach ($items as $it) {
            if ($it['kind'] !== 'weather' || ($it['propose']['lean'] ?? null) !== null || $it['rain'] === null) continue;
            $k = strtolower((string)$it['service_type']);
            $byService[$k][] = $it;
        }
        $made = 0;
        foreach ($byService as $service => $list) {
            $rain = max(array_map(fn($i) => (int)$i['rain'], $list));
            $q = self::weatherQuestion($service, $rain, count($list));
            $made += $this->ask('weather_tolerance', $service, $q, ['rain' => $rain]);
        }
        return $made;
    }

    /** After a "fine" on someone's quiet phone: ask once they've been fine three times running. */
    public function afterSilentFine(int $userId): void
    {
        try {
            $s = $this->db->prepare("
                SELECT outcome_json FROM otto_suggestions
                WHERE kind = 'silent' AND subject_id = ? AND status IN ('accepted', 'dismissed')
                ORDER BY decided_at DESC, id DESC LIMIT 3
            ");
            $s->execute([$userId]);
            $choices = array_map(fn($j) => (json_decode((string)$j, true) ?: [])['choice'] ?? '', $s->fetchAll(PDO::FETCH_COLUMN));
            if (!OttoRules::shouldAskSilentPattern($choices)) return;
            $n = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
            $n->execute([$userId]);
            $who = OpsDeskService::firstName((string)$n->fetchColumn());
            $this->ask('silent_pattern', 'user:' . $userId,
                "{$who}'s phone has gone quiet three times and each time you said it was fine. Should I wait an hour before flagging {$who}?",
                ['user_id' => $userId]);
        } catch (Throwable $e) {
            error_log('Otto silent question: ' . $e->getMessage());
        }
    }

    /** @return array<int, array{id: int, kind: string, question: string, answers: string[]}> */
    public function open(): array
    {
        try {
            $rows = $this->db->query("SELECT id, kind, question FROM otto_questions WHERE answer IS NULL ORDER BY created_at, id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return array_map(fn($r) => ['id' => (int)$r['id'], 'kind' => $r['kind'], 'question' => $r['question'],
            'answers' => self::ANSWERS[$r['kind']] ?? []], $rows);
    }

    public function answer(int $id, string $answer, int $actorId): array
    {
        $s = $this->db->prepare("SELECT * FROM otto_questions WHERE id = ?");
        $s->execute([$id]);
        $q = $s->fetch(PDO::FETCH_ASSOC);
        if (!$q) return ['ok' => false, 'message' => 'That question is gone.'];
        if ($q['answer'] !== null) return ['ok' => false, 'message' => 'Already answered.'];
        if (!in_array($answer, self::ANSWERS[$q['kind']] ?? [], true)) return ['ok' => false, 'message' => 'Pick one of the answers.'];
        $this->db->prepare("UPDATE otto_questions SET answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ? AND answer IS NULL")
            ->execute([$answer, $actorId ?: null, $id]);
        $ctx = json_decode((string)$q['context_json'], true) ?: [];
        require_once __DIR__ . '/OttoActionService.php';
        $act = new OttoActionService($this->db);
        if ($q['kind'] === 'weather_tolerance' && in_array($answer, ['keep', 'move'], true)) {
            $act->learnWeather((string)$q['scope_key'], $answer, isset($ctx['rain']) ? (int)$ctx['rain'] : null);
            return ['ok' => true, 'message' => 'Got it. I\'ll suggest that for ' . $q['scope_key'] . ' from now on, once I\'ve seen it a couple more times.'];
        }
        if ($q['kind'] === 'silent_pattern' && $answer === 'yes') {
            $act->updateLesson('silent', (string)$q['scope_key'], fn(array $v) => ['wait_minutes' => self::SILENT_WAIT] + $v);
            return ['ok' => true, 'message' => 'Done. I\'ll wait an hour before flagging that phone.'];
        }
        return ['ok' => true, 'message' => 'Thanks. Noted.'];
    }

    public function answeredCount(): int
    {
        try {
            return (int)$this->db->query("SELECT COUNT(*) FROM otto_questions WHERE answer IS NOT NULL")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function ask(string $kind, string $key, string $question, array $context): int
    {
        try {
            $s = $this->db->prepare("INSERT IGNORE INTO otto_questions (kind, scope_key, question, context_json) VALUES (?, ?, ?, ?)");
            $s->execute([$kind, mb_substr($key, 0, 120), mb_substr($question, 0, 500), json_encode($context)]);
            return $s->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ── Pure ────────────────────────────────────────────────────────────────

    public static function weatherQuestion(string $service, int $rain, int $visits): string
    {
        return "Rain {$rain}% is in the forecast for " . OttoRules::plural($visits, $service . ' visit')
            . ". Do you usually keep {$service} going in rain like that, or move it?";
    }
}

