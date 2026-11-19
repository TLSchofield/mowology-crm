<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Time helpers, visit-horizon check, plan/visit number generators
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 *
 * Phase 2 (2026-06-18): the logic now lives in PlanHelpersService. These global
 * functions are a backward-compatible facade — names and signatures are unchanged
 * from the original monolith. Add new logic to the service, not here.
 */

require_once __DIR__ . '/../PlanHelpersService.php';

// ============================================================================
// TIME / HORIZON / NUMBERING — global facade over PlanHelpersService
// ============================================================================

/** @see PlanHelpersService::planTimeStringToMinutes() */
function planTimeStringToMinutes(string $t): int {
    return PlanHelpersService::planTimeStringToMinutes($t);
}

/** @see PlanHelpersService::planMinutesToTimeString() */
function planMinutesToTimeString(int $minutes): string {
    return PlanHelpersService::planMinutesToTimeString($minutes);
}

/** @see PlanHelpersService::isVisitHorizonCurrent() */
function isVisitHorizonCurrent(): bool {
    return PlanHelpersService::isVisitHorizonCurrent();
}

/** @see PlanHelpersService::generatePlanNumber() */
function generatePlanNumber(): string {
    return PlanHelpersService::generatePlanNumber();
}

/** @see PlanHelpersService::generateVisitNumber() */
function generateVisitNumber(string $planNumber, int $sequenceIndex): string {
    return PlanHelpersService::generateVisitNumber($planNumber, $sequenceIndex);
}
