<?php
/**
 * Job Plan / Visit / Calendar Stop Functions — AGGREGATOR
 * /crm/includes/plan-functions.php
 *
 * Core functions for the job model:
 *   job_plans       → the service agreement / contract
 *   plan_line_items → what services are included in each visit
 *   calendar_stops  → one card per property per day per crew
 *   job_visits      → one occurrence of work
 *
 * This file was a ~3,200-line monolith. It was decomposed (2026-06-18) into
 * focused domain files under Plan/. To keep all ~28 callers and the
 * /crm/includes/plan-functions.php shim working unchanged, every function keeps
 * its original global name and signature; this file simply loads each part in
 * order. Add new Plan logic to the relevant Plan/*.php file (or a new one
 * required below), not here.
 *
 * Migrated from: public/crm/includes/plan-functions.php
 */

$__planDir = __DIR__ . '/Plan';

require_once $__planDir . '/PlanHelpers.php';        // time helpers, horizon check, number generators
require_once $__planDir . '/PlanCrud.php';           // createJobPlan / createPlanFromQuote
require_once $__planDir . '/PlanLineItems.php';      // line items + quote linkage
require_once $__planDir . '/VisitGeneration.php';    // generateVisits + recurrence/holiday math
require_once $__planDir . '/CalendarStops.php';      // ensureCalendarStop / getCalendarStops
require_once $__planDir . '/VisitLifecycle.php';     // visit status/lifecycle, pause/resume, exceptions, invoicing
require_once $__planDir . '/PlanDashboard.php';      // dashboard/list helpers + tracking requirements
require_once $__planDir . '/PlanProfitability.php';  // overhead + plan/stop profitability
require_once $__planDir . '/PlanEditing.php';        // cleanup, updateJobPlan, replacePlanLineItems
require_once $__planDir . '/PlanCrew.php';           // crew assignments + unscheduled visits
require_once $__planDir . '/PlanMaterials.php';      // fertilizer bundles, materials, purchase-task schedule

unset($__planDir);
