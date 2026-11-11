<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Fertilizer bundle visits, materials calc, purchase-task schedule
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 *
 * Phase 2 (2026-06-18): the logic now lives in PlanMaterialsService. These global
 * functions are a backward-compatible facade — names and signatures are unchanged
 * from the original monolith. Add new logic to the service, not here.
 */

require_once __DIR__ . '/../PlanMaterialsService.php';

// ============================================================================
// FERTILIZER BUNDLE + PURCHASE TASK SCHEDULE — global facade over PlanMaterialsService
// ============================================================================

/** @see PlanMaterialsService::generateFertilizerVisits() */
function generateFertilizerVisits(int $planId, array $dates): void {
    PlanMaterialsService::generateFertilizerVisits($planId, $dates);
}

/** @see PlanMaterialsService::calculateMaterialsForVisit() */
function calculateMaterialsForVisit(int $planId): ?string {
    return PlanMaterialsService::calculateMaterialsForVisit($planId);
}

/** @see PlanMaterialsService::getPurchaseTasksForSchedule() */
function getPurchaseTasksForSchedule(PDO $db, string $startDate, string $endDate, ?int $crewId = null): array {
    return PlanMaterialsService::getPurchaseTasksForSchedule($db, $startDate, $endDate, $crewId);
}
