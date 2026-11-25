<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The daily quiz is the required Hydrangea Masterclass until a person has mastered it.
 * Every path must fail open: a problem means "nothing pending", never a blocked crew member.
 */
class MandatoryCourseQuizServiceTest extends TestCase
{
    private function stmt(array $r): PDOStatement
    {
        $s = $this->createMock(PDOStatement::class);
        $s->method('execute')->willReturn(true);
        $s->method('fetchColumn')->willReturn($r['col'] ?? false);
        $s->method('fetch')->willReturn($r['row'] ?? false);
        $s->method('fetchAll')->willReturn($r['all'] ?? []);
        return $s;
    }

    /**
     * @param array $o gate (bool, daily quiz on), skip (bool), courseId (int), total, mastered, ids (int[])
     */
    private function db(array $o): PDO
    {
        $o += ['gate' => true, 'skip' => false, 'courseId' => 12, 'total' => 48, 'mastered' => 10, 'ids' => [1, 2, 3]];
        $db = $this->createMock(PDO::class);
        $db->method('query')->willReturn($this->stmt(['col' => 5])); // usable question count
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($o) {
            if (str_contains($sql, 'ops_settings')) {
                return $this->stmt(['col' => $o['gate'] ? '1' : '0']);
            }
            if (str_contains($sql, 'quiz_preshift_skip')) {
                return $this->stmt(['col' => $o['skip'] ? 1 : 0]);
            }
            if (str_contains($sql, 'FROM cert_courses')) {
                return $this->stmt(['col' => $o['courseId']]);
            }
            if (str_contains($sql, 'AS total')) {
                return $this->stmt(['row' => ['total' => $o['total'], 'mastered' => $o['mastered']]]);
            }
            return $this->stmt(['all' => $o['ids']]);
        });
        return $db;
    }

    public function test_pending_when_some_questions_not_mastered(): void
    {
        $this->assertSame(12, MandatoryCourseQuizService::pendingCourseId($this->db([]), 7));
    }

    public function test_not_pending_when_every_question_mastered(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['mastered' => 48]), 7));
    }

    public function test_not_pending_when_daily_quiz_is_off_for_the_business(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['gate' => false]), 7));
    }

    public function test_not_pending_for_a_person_set_to_skip_the_quiz(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['skip' => true]), 7));
    }

    public function test_not_pending_when_course_missing_inactive_or_not_required(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['courseId' => 0]), 7));
    }

    public function test_not_pending_when_course_has_no_usable_questions(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['total' => 0, 'mastered' => 0]), 7));
    }

    public function test_not_pending_when_fewer_usable_questions_than_a_full_round(): void
    {
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($this->db(['total' => 9, 'mastered' => 0]), 7));
        $this->assertSame(12, MandatoryCourseQuizService::pendingCourseId($this->db(['total' => 10, 'mastered' => 0]), 7));
    }

    public function test_pending_fails_open_when_the_database_errors(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('query')->willThrowException(new PDOException('boom'));
        $db->method('prepare')->willThrowException(new PDOException('boom'));
        $this->assertNull(MandatoryCourseQuizService::pendingCourseId($db, 7));
    }

    public function test_question_ids_are_ints_from_the_course(): void
    {
        $ids = MandatoryCourseQuizService::questionIds($this->db(['ids' => ['4', '9', '2']]), 7, 12, 10);
        $this->assertSame([4, 9, 2], $ids);
    }

    public function test_question_ids_fail_open_to_empty_so_the_normal_picker_runs(): void
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willThrowException(new PDOException('boom'));
        $this->assertSame([], MandatoryCourseQuizService::questionIds($db, 7, 12, 10));
    }

    public function test_round_is_course_length_only_while_pending(): void
    {
        $this->assertSame(10, MandatoryCourseQuizService::lengthFor(12, 5));
        $this->assertSame(5, MandatoryCourseQuizService::lengthFor(null, 5));
        $this->assertContains(MandatoryCourseQuizService::ROUND_LENGTH, [3, 5, 10]); // allowed by every start API
    }

    public function test_label_has_the_three_fields_the_apps_read(): void
    {
        $l = MandatoryCourseQuizService::label();
        foreach (['season_label', 'season_icon', 'season_tagline'] as $k) {
            $this->assertNotSame('', trim((string)($l[$k] ?? '')), $k);
        }
    }
}
