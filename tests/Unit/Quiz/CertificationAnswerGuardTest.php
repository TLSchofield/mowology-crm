<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Certification answers: only the exam's owner, only the exam's questions, one answer each
 * (Fable review 2026-10-12).
 */
class CertificationAnswerGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE cert_exam_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, course_id INTEGER,
                   question_ids TEXT, questions_count INTEGER, passed INTEGER NULL)");
        $db->exec("CREATE TABLE cert_exam_answers (id INTEGER PRIMARY KEY, exam_session_id INTEGER, question_id INTEGER,
                   selected_option_id INTEGER, is_correct INTEGER, time_taken_seconds INTEGER)");
        $db->exec("CREATE TABLE quiz_options (id INTEGER PRIMARY KEY, question_id INTEGER, option_text TEXT, is_correct INTEGER)");
        $db->exec("INSERT INTO cert_exam_sessions VALUES (1, 7, 3, '10,11', 2, NULL)");
        $db->exec("INSERT INTO quiz_options VALUES (100, 10, 'Right', 1), (101, 10, 'Wrong', 0), (110, 11, 'Right', 1), (200, 20, 'Other', 1)");
        $this->db = $db;
    }

    private function svc(): CertificationService
    {
        return new CertificationService($this->db);
    }

    public function test_owner_answers_own_question_once(): void
    {
        $r = $this->svc()->submitAnswer(1, 10, 100, 5, 7);
        $this->assertTrue($r['is_correct']);
        $this->assertSame(1, $r['answered_count']);

        $this->expectExceptionMessage('already been answered');
        $this->svc()->submitAnswer(1, 10, 100, 5, 7);
    }

    public function test_someone_else_cannot_answer_the_session(): void
    {
        $this->expectExceptionMessage('Exam session not found.');
        $this->svc()->submitAnswer(1, 10, 100, 5, 8);
    }

    public function test_question_outside_the_session_is_refused(): void
    {
        $this->expectExceptionMessage('not part of this exam');
        $this->svc()->submitAnswer(1, 20, 200, 5, 7);
    }

    public function test_option_from_another_question_is_refused(): void
    {
        $this->expectExceptionMessage('does not belong');
        $this->svc()->submitAnswer(1, 11, 100, 5, 7);
    }

    public function test_session_question_ids_parse(): void
    {
        $this->assertSame([10, 11], CertificationService::sessionQuestionIds(['question_ids' => ' 10, 11,,x']));
    }
}
