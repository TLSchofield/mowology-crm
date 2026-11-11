<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Otto's crew-training rules: certification gaps (a lapsed cert is missing), repeat
 * problems, quiz meeting topics, and safety refreshers.
 */
class TrainingRulesTest extends TestCase
{
    private const NOW = '2026-10-06 12:00:00';

    public function test_service_keys_match_slug_label_and_course_spellings(): void
    {
        $this->assertSame('hedge_trimming', TrainingRules::serviceKey('Hedge Trimming'));
        $this->assertSame('hedge_trimming', TrainingRules::serviceKey('hedge-trimming'));
        $this->assertSame('lawn_care', TrainingRules::serviceKey(' lawn_care '));
    }

    public function test_a_lapsed_or_revoked_cert_does_not_count(): void
    {
        $this->assertTrue(TrainingRules::certValid(['status' => 'active', 'expires_at' => '2027-01-01 00:00:00'], self::NOW));
        $this->assertTrue(TrainingRules::certValid(['status' => 'active', 'expires_at' => null], self::NOW));
        $this->assertFalse(TrainingRules::certValid(['status' => 'active', 'expires_at' => '2026-10-01 00:00:00'], self::NOW), 'lapsed but still marked active');
        $this->assertFalse(TrainingRules::certValid(['status' => 'expired', 'expires_at' => '2027-01-01 00:00:00'], self::NOW));
        $this->assertFalse(TrainingRules::certValid(['status' => 'revoked', 'expires_at' => null], self::NOW));
        $this->assertFalse(TrainingRules::certValid(null, self::NOW));
    }

    public function test_missing_reports_the_tier_first_then_the_course(): void
    {
        $reqs = [['min_tier_level' => 2, 'course_id' => 7, 'course_name' => 'Hedge Trimming']];
        $this->assertSame(2, TrainingRules::missing($reqs, 1, [], self::NOW)['tier']);
        $m = TrainingRules::missing($reqs, 2, [], self::NOW);
        $this->assertNull($m['tier']);
        $this->assertSame('Hedge Trimming', $m['course']);
        $this->assertNull(TrainingRules::missing($reqs, 3, [7 => ['status' => 'active', 'expires_at' => '2027-03-01 00:00:00']], self::NOW));
        $this->assertNotNull(TrainingRules::missing($reqs, 3, [7 => ['status' => 'active', 'expires_at' => '2026-09-30 00:00:00']], self::NOW));
        $this->assertNull(TrainingRules::missing([], 0, [], self::NOW), 'no requirement, nothing missing');
    }

    public function test_visit_problems(): void
    {
        $this->assertSame(['no after photo'], TrainingRules::visitProblems(['status' => 'completed', 'photo_types_required' => '["before","after"]', 'photo_categories' => ['before']]));
        $this->assertSame([], TrainingRules::visitProblems(['status' => 'completed', 'photo_types_required' => '["before","after"]', 'photo_categories' => ['after', 'before']]));
        $this->assertSame(['skipped'], TrainingRules::visitProblems(['status' => 'skipped', 'photo_types_required' => '["after"]']));
        $this->assertSame(['issue noted'], TrainingRules::visitProblems(['status' => 'completed', 'issue_notes' => 1]));
        $this->assertSame(['issue noted'], TrainingRules::visitProblems(['status' => 'completed', 'photo_categories' => ['issue']]));
    }

    private static function ans(int $user, int $q, int $ok, string $at = '2026-10-01 08:00:00', int $cat = 1): array
    {
        return ['user_id' => $user, 'question_id' => $q, 'is_correct' => $ok, 'answered_at' => $at,
                'category_id' => $cat, 'category' => $cat === 1 ? 'Mowing' : 'Pests', 'question_text' => "Question {$q}"];
    }

    public function test_a_topic_needs_three_people_and_half_wrong(): void
    {
        $two = [self::ans(1, 10, 0), self::ans(2, 10, 0)];
        $this->assertSame([], TrainingRules::topics($two));
        $three = [self::ans(1, 10, 0), self::ans(2, 10, 0), self::ans(3, 10, 1)];
        $t = TrainingRules::topics($three);
        $this->assertSame('Mowing', $t[0]['category']);
        $this->assertSame(2, $t[0]['questions'][0]['wrong']);
        $mostlyRight = [self::ans(1, 10, 0), self::ans(2, 10, 1), self::ans(3, 10, 1), self::ans(4, 10, 1)];
        $this->assertSame([], TrainingRules::topics($mostlyRight));
    }

    public function test_only_each_persons_latest_answer_counts(): void
    {
        $learned = [self::ans(1, 10, 0, '2026-09-20'), self::ans(1, 10, 1, '2026-10-02'), self::ans(2, 10, 0), self::ans(3, 10, 1)];
        $this->assertSame([], TrainingRules::topics($learned), 'person 1 got it right the second time');
    }

    public function test_refresher_states(): void
    {
        $this->assertSame('missing', TrainingRules::refresher(null, self::NOW)['state']);
        $this->assertSame('expired', TrainingRules::refresher(['status' => 'active', 'expires_at' => '2026-10-01 00:00:00'], self::NOW)['state']);
        $this->assertSame(['state' => 'due', 'expires' => '2026-10-30'], TrainingRules::refresher(['status' => 'active', 'expires_at' => '2026-10-30 00:00:00'], self::NOW));
        $this->assertNull(TrainingRules::refresher(['status' => 'active', 'expires_at' => '2026-12-30 00:00:00'], self::NOW));
        $this->assertNull(TrainingRules::refresher(['status' => 'active', 'expires_at' => null], self::NOW), 'never expires');
        $this->assertSame('missing', TrainingRules::refresher(['status' => 'revoked', 'expires_at' => null], self::NOW)['state']);
    }

    public function test_pair_ids_are_stable(): void
    {
        $this->assertSame(4007, TrainingRules::pairId(4, 7));
        $this->assertNotSame(TrainingRules::pairId(4, 7), TrainingRules::pairId(4, 8));
    }

    public function test_training_badges(): void
    {
        $d = fn(string $kind, array $o = []) => ['kind' => $kind, 'status' => 'accepted', 'outcome' => $o];
        $ids = fn(array $b) => array_column($b['earned'], 'key');
        $this->assertContains('coach', $ids(OttoBadgeService::compute([], 5)));
        $this->assertNotContains('coach', $ids(OttoBadgeService::compute([], 4)));
        $ahead = array_fill(0, 10, $d('safety_refresher', ['before_expiry' => true]));
        $this->assertContains('safety', $ids(OttoBadgeService::compute($ahead)));
        $late = array_fill(0, 10, $d('safety_refresher', ['before_expiry' => false]));
        $this->assertNotContains('safety', $ids(OttoBadgeService::compute($late)));
    }

    public function test_headline_mentions_training(): void
    {
        $this->assertSame('Hey Tim — 1 crew, 4 stops today. 3 training notes for the crew.',
            OttoRules::headline(['stops' => 4, 'crews' => 1, 'training' => 3], 'Tim'));
    }

    public function test_training_kinds_are_part_of_otto_and_silent_before_tables_exist(): void
    {
        foreach (OpsDeskService::TRAINING_KINDS as $k) $this->assertContains($k, OpsDeskService::KINDS);
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame([], (new TrainingService($pdo))->items());
    }
}
