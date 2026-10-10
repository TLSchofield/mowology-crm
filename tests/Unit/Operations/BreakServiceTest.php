<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Breaks from the crew phone's GPS; the BC 5-hour rule (owner, 2026-10-10). */
class BreakServiceTest extends TestCase
{
    private int $in;
    private int $out;

    protected function setUp(): void
    {
        $this->in = strtotime('2026-10-09 07:00');
        $this->out = strtotime('2026-10-09 15:30');
    }

    private function stop(string $from, string $to, string $type, ?string $kind = null, string $name = 'X'): array
    {
        return ['start' => strtotime("2026-10-09 $from"), 'end' => strtotime("2026-10-09 $to"), 'label' => ['type' => $type, 'kind' => $kind, 'name' => $name]];
    }

    public function test_a_coffee_stop_is_a_break_but_a_job_is_not(): void
    {
        $b = BreakService::candidates([
            $this->stop('09:00', '11:30', 'property'),                       // working on a job
            $this->stop('12:05', '12:37', 'unnamed'),                        // lunch pull-in
            $this->stop('13:00', '13:40', 'place', 'dump', 'Vancouver Landfill'),
            $this->stop('14:00', '14:10', 'unnamed'),                        // too short
            $this->stop('07:02', '07:40', 'unnamed'),                        // loading up at the start
        ], $this->in, $this->out);
        $this->assertCount(1, $b);
        $this->assertSame(32, $b[0]['minutes']);
        $this->assertSame('unnamed stop', $b[0]['where']);
    }

    public function test_bc_meal_break_rule(): void
    {
        $this->assertNull(BreakService::complianceGap(290, []));            // under 5 h
        $this->assertNull(BreakService::complianceGap(720, []));            // 12 h = a forgotten clock-out, not a long day
        $this->assertNull(BreakService::complianceGap(510, [32]));
        $this->assertStringContainsString('no break found', BreakService::complianceGap(510, []));
        $this->assertStringContainsString('longest break found was 22 min', BreakService::complianceGap(510, [22]));
    }

    public function test_every_onboarding_question_has_a_default(): void
    {
        foreach (OnboardingQuestions::QUESTIONS as $k => $q) {
            $this->assertCount(4, $q, $k);
            $this->assertNotSame('', $q[0]);
        }
        $this->assertSame('1', OnboardingQuestions::QUESTIONS['breaks_paid'][1]);
    }
}
