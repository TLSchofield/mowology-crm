<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Overhead settings + plan/stop profitability
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 *
 * Phase 2 (2026-06-18): the logic now lives in PlanProfitabilityService. These
 * global functions are a backward-compatible facade — names and signatures are
 * unchanged from the original monolith. Add new logic to the service, not here.
 */

require_once __DIR__ . '/../PlanProfitabilityService.php';

// ============================================================================
// PROFITABILITY CALCULATIONS — global facade over PlanProfitabilityService
// ============================================================================

/** @see PlanProfitabilityService::getOverheadPercentage() */
function getOverheadPercentage(): float {
    return PlanProfitabilityService::getOverheadPercentage();
}

/** @see PlanProfitabilityService::getOverheadSettings() */
function getOverheadSettings(): array {
    return PlanProfitabilityService::getOverheadSettings();
}

/** @see PlanProfitabilityService::getMonthlyOverheadTotal() */
function getMonthlyOverheadTotal(): float {
    return PlanProfitabilityService::getMonthlyOverheadTotal();
}

/** @see PlanProfitabilityService::getPlanProfitability() */
function getPlanProfitability(int $planId): array {
    return PlanProfitabilityService::getPlanProfitability($planId);
}

/** @see PlanProfitabilityService::getStopProfitabilityBatch() */
function getStopProfitabilityBatch(array $planIds): array {
    return PlanProfitabilityService::getStopProfitabilityBatch($planIds);
}
