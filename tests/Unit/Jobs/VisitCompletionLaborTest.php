<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Modules/Jobs/Services/VisitCompletionService.php';

/**
 * Which minutes a visit's labor cost may be charged for (2026-10-26: eight plans held 100+ hour estimates and
 * a $48 mowing visit was charged $9,859.88 of labor, which made every margin on production wrong).
 */
final class VisitCompletionLaborTest extends TestCase
{
    public function testMeasuredTimeBeatsTheTimerAndTheTimerBeatsTheEstimate(): void
    {
        $this->assertSame(['minutes' => 45, 'source' => 'actual'], VisitCompletionService::resolveLaborMinutes(45, 50, 60));
        $this->assertSame(['minutes' => 50, 'source' => 'timer'], VisitCompletionService::resolveLaborMinutes(0, 50, 60));
        $this->assertSame(['minutes' => 60, 'source' => 'estimate'], VisitCompletionService::resolveLaborMinutes(0, 0, 60));
    }

    public function testAnAbsurdEstimateIsNeverCharged(): void
    {
        // plan 99 on production: 8,009 minutes
        $this->assertSame(['minutes' => null, 'source' => 'none'], VisitCompletionService::resolveLaborMinutes(0, 0, 8009));
        $this->assertSame(['minutes' => null, 'source' => 'none'], VisitCompletionService::resolveLaborMinutes(0, 0, VisitCompletionService::MAX_ESTIMATE_MINUTES + 1));
        $this->assertSame(480, VisitCompletionService::resolveLaborMinutes(0, 0, 480)['minutes'], 'eight hours exactly is allowed');
    }

    public function testAForgottenTimerIsNotChargedAndTheNextSourceIsUsed(): void
    {
        $this->assertSame(['minutes' => 60, 'source' => 'estimate'], VisitCompletionService::resolveLaborMinutes(0, 5000, 60));
        $this->assertSame(['minutes' => null, 'source' => 'none'], VisitCompletionService::resolveLaborMinutes(5000, 5000, 0));
    }

    public function testACrewsCombinedTimerMinutesAreMeasuredTimeNotAnError(): void
    {
        // maintenance visits on production carry 250 to 1,322 minutes: several people's timers added together
        $this->assertSame(['minutes' => 1322, 'source' => 'timer'], VisitCompletionService::resolveLaborMinutes(0, 1322, 1712));
        $this->assertSame(['minutes' => 2400, 'source' => 'actual'], VisitCompletionService::resolveLaborMinutes(2400, 0, 0));
        $this->assertSame('none', VisitCompletionService::resolveLaborMinutes(2401, 2401, 0)['source']);
    }

    public function testNothingKnownMeansUnknownNotZero(): void
    {
        $this->assertSame(['minutes' => null, 'source' => 'none'], VisitCompletionService::resolveLaborMinutes(0, 0, 0));
        $this->assertSame(['minutes' => null, 'source' => 'none'], VisitCompletionService::resolveLaborMinutes(-5, -5, -5));
    }

    public function testTheCaptureReadsTimerEntriesBeforeItChargesLaborAndNeverGuessesAMargin(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../app/Modules/Jobs/Services/VisitCompletionService.php');
        $labor = strpos($src, 'resolveLaborMinutes((int)');
        $timer = strpos($src, 'FROM job_time_entries WHERE visit_id');
        $this->assertNotFalse($labor);
        $this->assertNotFalse($timer);
        $this->assertLessThan($labor, $timer, 'timer minutes are read before labor is computed');
        $this->assertStringContainsString('$quotedAmount !== null && $laborCost !== null', $src, 'no margin without a labor figure');
        $this->assertStringNotContainsString("(int)(\$visit['estimated_duration_minutes'] ?? 0);\n            }", $src, 'the old unguarded fallback is gone');
    }


    public function testTheRepairComparesAgainstTheOldMarginLaborNotItself(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../scripts/snippets/repair-visit-margins.php');
        $this->assertStringContainsString("(float)\$c['r']['labor_cost']]);", $src);
        $this->assertStringNotContainsString("jv_labor']);", $src);
    }

    public function testAForgottenTwelveHourTimerOnASmallJobIsSuspectButARealLongJobIsNot(): void
    {
        $this->assertTrue(VisitCompletionService::isSuspectTimer(720, 58.0));
        $this->assertTrue(VisitCompletionService::isSuspectTimer(600, 199.99));
        $this->assertFalse(VisitCompletionService::isSuspectTimer(599, 58.0));
        $this->assertFalse(VisitCompletionService::isSuspectTimer(720, 200.0), 'a $200 job can take a long day');
        $this->assertFalse(VisitCompletionService::isSuspectTimer(720, 4500.0));
        $this->assertFalse(VisitCompletionService::isSuspectTimer(720, null), 'no quote means no judgement');
    }

    public function testTheRepairBlanksSuspectTimersAsUnknown(): void
    {
        $src = (string)file_get_contents(__DIR__ . '/../../../scripts/snippets/repair-visit-margins.php');
        $this->assertStringContainsString("'source' => 'suspect'", $src);
        $this->assertStringContainsString('>= 600', $src);
        $this->assertStringContainsString('< 200.0', $src);
    }
}
