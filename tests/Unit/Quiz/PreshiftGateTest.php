<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PreshiftGate: the quiz is on only when switched on AND at least one usable active question exists.
 */
class PreshiftGateTest extends TestCase
{
    private function stmt(mixed $fetchColumn = false): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($fetchColumn);
        return $stmt;
    }

    /** Builds a PDO whose ops_settings lookup returns $setting and whose question count returns $count. */
    private function db(string|false $setting, int $count, bool $skip = false): PDO
    {
        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnCallback(function (string $sql) use ($setting, $skip) {
            if (str_contains($sql, 'ops_settings')) return $this->stmt($setting);
            if (str_contains($sql, 'quiz_preshift_skip')) return $this->stmt($skip ? 1 : 0);
            return $this->stmt(false);
        });
        $db->method('query')->willReturn($this->stmt($count));
        return $db;
    }

    public function test_off_when_switched_off_even_with_questions(): void
    {
        $this->assertFalse(PreshiftGate::isActive($this->db('0', 12)));
    }

    public function test_off_when_switched_on_but_no_questions(): void
    {
        $this->assertFalse(PreshiftGate::isActive($this->db('1', 0)));
    }

    public function test_on_when_switched_on_with_one_question(): void
    {
        $this->assertTrue(PreshiftGate::isActive($this->db('1', 1)));
    }

    public function test_off_when_setting_missing(): void
    {
        $this->assertFalse(PreshiftGate::isActive($this->db(false, 5)));
    }

    public function test_user_skip_turns_it_off_for_that_user_only(): void
    {
        $this->assertFalse(PreshiftGate::isRequiredFor($this->db('1', 3, true), 7));
        $this->assertTrue(PreshiftGate::isRequiredFor($this->db('1', 3, false), 7));
    }
}
