<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * Visit status & lifecycle, plan pause/resume, exceptions, invoicing
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 *
 * Phase 2 (2026-06-18): the logic now lives in VisitLifecycleService. These global
 * functions are a backward-compatible facade — names and signatures are unchanged
 * from the original monolith. updateVisitStatus() is the revenue-critical
 * completion path; its behaviour is preserved exactly. Add new logic to the
 * service, not here.
 */

require_once __DIR__ . '/../VisitLifecycleService.php';

// ============================================================================
// VISIT STATUS / LIFECYCLE / PLAN MGMT / EXCEPTIONS / INVOICING
// global facade over VisitLifecycleService
// ============================================================================

/** @see VisitLifecycleService::updateVisitStatus() */
function updateVisitStatus(int $visitId, string $newStatus, int $userId, ?string $notes = null): bool {
    return VisitLifecycleService::updateVisitStatus($visitId, $newStatus, $userId, $notes);
}

/** @see VisitLifecycleService::getVisitWithPlan() */
function getVisitWithPlan(int $visitId): ?array {
    return VisitLifecycleService::getVisitWithPlan($visitId);
}

/** @see VisitLifecycleService::getPlanDetails() */
function getPlanDetails(int $planId): ?array {
    return VisitLifecycleService::getPlanDetails($planId);
}

/** @see VisitLifecycleService::getPlanVisits() */
function getPlanVisits(int $planId, ?string $status = null, int $limit = 50, int $offset = 0): array {
    return VisitLifecycleService::getPlanVisits($planId, $status, $limit, $offset);
}

/** @see VisitLifecycleService::pausePlan() */
function pausePlan(int $planId, int $userId, string $reason = ''): bool {
    return VisitLifecycleService::pausePlan($planId, $userId, $reason);
}

/** @see VisitLifecycleService::resumePlan() */
function resumePlan(int $planId, int $userId): bool {
    return VisitLifecycleService::resumePlan($planId, $userId);
}

/** @see VisitLifecycleService::propagatePlanChanges() */
function propagatePlanChanges(int $planId, array $changes, int $userId): void {
    VisitLifecycleService::propagatePlanChanges($planId, $changes, $userId);
}

/** @see VisitLifecycleService::skipVisitDate() */
function skipVisitDate(int $planId, string $date, int $userId, string $reason = ''): bool {
    return VisitLifecycleService::skipVisitDate($planId, $date, $userId, $reason);
}

/** @see VisitLifecycleService::moveVisit() */
function moveVisit(int $visitId, string $newDate, ?string $newTimeStart, int $userId): bool {
    return VisitLifecycleService::moveVisit($visitId, $newDate, $newTimeStart, $userId);
}

/** @see VisitLifecycleService::canInvoiceVisit() */
function canInvoiceVisit(int $visitId): array {
    return VisitLifecycleService::canInvoiceVisit($visitId);
}
