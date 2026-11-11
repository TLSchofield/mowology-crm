<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * generateVisits + recurrence/holiday math
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 *
 * Phase 2 (2026-06-18): the logic now lives in VisitGenerationService. These
 * global functions are a backward-compatible facade — names and signatures are
 * unchanged from the original monolith, so every caller and the legacy shim keep
 * working. Add new logic to VisitGenerationService, not here.
 */

require_once __DIR__ . '/../VisitGenerationService.php';

// ============================================================================
// VISIT GENERATOR — global facade over VisitGenerationService
// ============================================================================

/** @see VisitGenerationService::generateVisits() */
function generateVisits(?int $planId = null, ?int $horizonDays = null): array {
    return VisitGenerationService::generateVisits($planId, $horizonDays);
}

/** @see VisitGenerationService::getActiveHolidays() */
function getActiveHolidays(string $fromDate, string $toDate): array {
    return VisitGenerationService::getActiveHolidays($fromDate, $toDate);
}

/** @see VisitGenerationService::findBumpDate() */
function findBumpDate(string $holidayDate, array $holidays, array $blackouts): ?string {
    return VisitGenerationService::findBumpDate($holidayDate, $holidays, $blackouts);
}

/** @see VisitGenerationService::parseDowList() */
function parseDowList($rawDow, DateTime $fallback): array {
    return VisitGenerationService::parseDowList($rawDow, $fallback);
}

/** @see VisitGenerationService::calculateRecurrenceDates() */
function calculateRecurrenceDates(array $plan, string $fromDate, string $toDate, array $holidays = []): array {
    return VisitGenerationService::calculateRecurrenceDates($plan, $fromDate, $toDate, $holidays);
}
