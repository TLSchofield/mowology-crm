<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization safety-net for the PlanFunctions decomposition.
 *
 * PlanFunctions.php is a procedural library (~50 global functions) required by
 * ~28 production call sites, including the revenue-critical timer/clock-out path.
 * It has no other test coverage. This test is the tripwire for the Phase 1
 * file-split: it asserts every public function still loads with its original
 * arity after the monolith was cut into Plan/*.php domain files.
 *
 * If a function goes missing or its signature changes, this fails — long before
 * production does.
 */
class PlanFunctionsLoadTest extends TestCase
{
    /** name => number of declared parameters (baseline captured 2026-06-18, pre-split) */
    private const EXPECTED = [
        'planTimeStringToMinutes' => 1,
        'planMinutesToTimeString' => 1,
        'isVisitHorizonCurrent' => 0,
        'generatePlanNumber' => 0,
        'generateVisitNumber' => 2,
        'createJobPlan' => 2,
        'createPlanFromQuote' => 2,
        'addPlanLineItems' => 2,
        'getPlanLineItems' => 1,
        'updatePlanTotalFromItems' => 1,
        'getNextScheduledVisitDate' => 1,
        'getQuoteLineItemsWithStatus' => 1,
        'getPlansForQuote' => 1,
        'generateVisits' => 2,
        'getActiveHolidays' => 2,
        'findBumpDate' => 3,
        'parseDowList' => 2,
        'calculateRecurrenceDates' => 4,
        'ensureCalendarStop' => 5,
        'getCalendarStops' => 4,
        'updateVisitStatus' => 4,
        'getVisitWithPlan' => 1,
        'getPlanDetails' => 1,
        'getPlanVisits' => 4,
        'pausePlan' => 3,
        'resumePlan' => 2,
        'propagatePlanChanges' => 3,
        'skipVisitDate' => 4,
        'moveVisit' => 4,
        'canInvoiceVisit' => 1,
        'getPlanDashboardStats' => 0,
        'getRecentPlansOnProperty' => 2,
        'resolveTrackingRequirementsForPlan' => 1,
        'resolveTrackingRequirements' => 1,
        'getOverheadPercentage' => 0,
        'getOverheadSettings' => 0,
        'getMonthlyOverheadTotal' => 0,
        'getPlanProfitability' => 1,
        'getStopProfitabilityBatch' => 1,
        'cleanupOrphanedVisits' => 1,
        'updateJobPlan' => 3,
        'replacePlanLineItems' => 2,
        'getPlanCrewAssignments' => 1,
        'setPlanCrewAssignments' => 3,
        'getVisitCrewAssignments' => 1,
        'setVisitCrewAssignments' => 3,
        'getUnscheduledVisits' => 0,
        'generateFertilizerVisits' => 2,
        'calculateMaterialsForVisit' => 1,
        'getPurchaseTasksForSchedule' => 4,
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../app/Modules/Jobs/Services/PlanFunctions.php';
    }

    public function test_all_plan_functions_are_defined(): void
    {
        $missing = array_filter(
            array_keys(self::EXPECTED),
            static fn(string $fn): bool => !function_exists($fn)
        );
        $this->assertSame([], array_values($missing), 'Plan functions missing after split: ' . implode(', ', $missing));
        $this->assertCount(50, self::EXPECTED, 'Expected baseline of 50 public Plan functions');
    }

    public function test_function_signatures_are_unchanged(): void
    {
        foreach (self::EXPECTED as $fn => $arity) {
            if (!function_exists($fn)) {
                continue; // reported by the other test
            }
            $ref = new ReflectionFunction($fn);
            $this->assertSame(
                $arity,
                $ref->getNumberOfParameters(),
                "Arity of {$fn}() changed during the split"
            );
        }
    }
}
