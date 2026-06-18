<?php
/**
 * Part of the Job Plan / Visit / Calendar Stop function library.
 * generateVisits + recurrence/holiday math
 *
 * Loaded via app/Modules/Jobs/Services/PlanFunctions.php (aggregator).
 * Global functions — names/signatures unchanged from the original monolith.
 */

// ============================================================================
// VISIT GENERATOR
// ============================================================================

/**
 * Generate visits for active plans within their rolling horizon.
 *
 * For each active recurring plan: ensures visits exist from today through
 * today + horizon_days. For one-time plans: creates a single visit if none exists.
 *
 * Dedup via UNIQUE(plan_id, scheduled_date, sequence_index).
 *
 * @param int|null $planId Specific plan, or NULL for all active plans
 * @param int|null $horizonDays Override the plan's horizon_days
 * @return array ['plans_processed' => int, 'visits_created' => int, 'errors' => []]
 */
function generateVisits(?int $planId = null, ?int $horizonDays = null): array {
    $db = getDB();
    $plansProcessed = 0;
    $visitsCreated = 0;
    $errors = [];

    try {
        // Get plans to process
        $sql = "SELECT * FROM job_plans WHERE status = 'active'";
        $params = [];
        if ($planId !== null) {
            $sql .= " AND id = ?";
            $params[] = $planId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Batch-fetch crew assignments for all plans so we can sync calendar_stop_crew
        $planCrewMap = []; // plan_id => [user_id, ...]
        if (!empty($plans)) {
            $planIds = array_column($plans, 'id');
            $ph = implode(',', array_fill(0, count($planIds), '?'));
            $pcStmt = $db->prepare("
                SELECT plan_id, user_id FROM plan_crew_assignments
                WHERE plan_id IN ({$ph})
                ORDER BY FIELD(role, 'lead', 'crew'), user_id
            ");
            $pcStmt->execute($planIds);
            foreach ($pcStmt->fetchAll(PDO::FETCH_ASSOC) as $pcr) {
                $planCrewMap[(int)$pcr['plan_id']][] = (int)$pcr['user_id'];
            }
        }

        // Fetch global holidays once for the full horizon window
        $maxHorizon = $horizonDays ?? 42;
        $holidayFrom = date('Y-m-d');
        $holidayTo = date('Y-m-d', strtotime("+{$maxHorizon} days"));
        // Extend range 7 days back to support bump lookups near window start
        $holidayFromExtended = date('Y-m-d', strtotime("-7 days"));
        $holidays = [];
        try {
            $holidays = getActiveHolidays($holidayFromExtended, $holidayTo);
        } catch (Exception $e) {
            // Table may not exist yet — continue without holidays
            error_log("getActiveHolidays: " . $e->getMessage());
        }

        foreach ($plans as $plan) {
            try {
                // Remove visits that predate the plan's start date — orphaned when
                // a plan is edited to start later or change its recurrence day.
                cleanupOrphanedVisits((int)$plan['id']);

                $horizon = $horizonDays ?? (int)$plan['horizon_days'];
                $today = new DateTime('today');
                $toDate = (clone $today)->modify("+{$horizon} days");

                // Don't generate past the plan end date
                if ($plan['plan_end_date']) {
                    $endDate = new DateTime($plan['plan_end_date']);
                    if ($toDate > $endDate) {
                        $toDate = $endDate;
                    }
                }

                if ($plan['is_recurring']) {
                    // Determine the from-date for this generation pass.
                    //
                    // Incremental mode (visits_generated_through is set): start from
                    // where we last left off, never before today.
                    //
                    // Fresh/reset mode (visits_generated_through is NULL, e.g. after a
                    // recurrence edit): start from plan_start_date so that visits between
                    // the new start date and today are not silently skipped. Cap the
                    // lookback at 90 days to avoid rebuilding years of history.
                    if ($plan['visits_generated_through']) {
                        $fromDate = clone $today;
                        $genThrough = new DateTime($plan['visits_generated_through']);
                        $genThrough->modify('+1 day');
                        if ($genThrough > $fromDate) $fromDate = $genThrough;
                    } else {
                        $ninetyDaysAgo = (clone $today)->modify('-90 days');
                        if ($plan['plan_start_date']) {
                            $planStart = new DateTime($plan['plan_start_date']);
                            $fromDate  = $planStart < $ninetyDaysAgo ? $ninetyDaysAgo : $planStart;
                        } else {
                            $fromDate = clone $today;
                        }
                    }

                    if ($fromDate > $toDate) {
                        $plansProcessed++;
                        continue; // Already generated up to horizon
                    }

                    $dates = calculateRecurrenceDates($plan, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'), $holidays);
                } else {
                    // One-time plan: single visit on plan_start_date
                    $visitDate = $plan['plan_start_date'] ?: date('Y-m-d');
                    // Check if a non-cancelled visit already exists (cancelled visits
                    // don't count — the plan still needs its active visit)
                    $checkStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status != 'cancelled'");
                    $checkStmt->execute([$plan['id']]);
                    if ((int)$checkStmt->fetchColumn() > 0) {
                        $plansProcessed++;
                        continue; // One-time already has its active visit
                    }
                    $dates = [$visitDate];
                }

                // Get next sequence index
                $seqStmt = $db->prepare("SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?");
                $seqStmt->execute([$plan['id']]);
                $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;

                foreach ($dates as $date) {
                    // Derive estimated arrival and departure from plan defaults
                    $estArrival   = $plan['default_time_start'] ?: null;
                    $estDeparture = null;
                    if ($estArrival && !empty($plan['estimated_duration_minutes'])) {
                        $arrivalMins   = planTimeStringToMinutes($estArrival);
                        $departureMins = $arrivalMins + (int)$plan['estimated_duration_minutes'];
                        $estDeparture  = planMinutesToTimeString($departureMins);
                    }

                    // Ensure calendar stop exists (populates times on insert, preserves manual edits on dup)
                    $stopId = ensureCalendarStop(
                        (int)$plan['property_id'],
                        $date,
                        $plan['default_crew_id'] ? (int)$plan['default_crew_id'] : null,
                        $estArrival,
                        $estDeparture
                    );

                    // Sync junction table: insert all plan crew into calendar_stop_crew (INSERT IGNORE
                    // so we don't overwrite crew manually set via the assign-crew modal)
                    if ($stopId > 0) {
                        $planCrew = $planCrewMap[(int)$plan['id']] ?? ($plan['default_crew_id'] ? [(int)$plan['default_crew_id']] : []);
                        if (!empty($planCrew)) {
                            $jInsStmt = $db->prepare("INSERT IGNORE INTO calendar_stop_crew (stop_id, user_id) VALUES (?, ?)");
                            foreach ($planCrew as $crewUserId) {
                                $jInsStmt->execute([$stopId, $crewUserId]);
                            }
                        }
                    }

                    $visitNumber = generateVisitNumber($plan['plan_number'], $nextSeq);

                    // INSERT IGNORE for dedup (UNIQUE constraint on plan_id + date + seq)
                    $insStmt = $db->prepare("
                        INSERT IGNORE INTO job_visits (
                            visit_number, plan_id, stop_id,
                            scheduled_date, scheduled_time_start, scheduled_time_end,
                            sequence_index, assigned_crew_id, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                    ");
                    $insStmt->execute([
                        $visitNumber,
                        $plan['id'],
                        $stopId,
                        $date,
                        $plan['default_time_start'],
                        $plan['default_time_end'],
                        $nextSeq,
                        $plan['default_crew_id']
                    ]);

                    if ($insStmt->rowCount() > 0) {
                        $visitsCreated++;
                    }
                    $nextSeq++;
                }

                // Update generation watermark
                $upStmt = $db->prepare("
                    UPDATE job_plans SET visits_generated_through = ? WHERE id = ?
                ");
                $upStmt->execute([$toDate->format('Y-m-d'), $plan['id']]);

                $plansProcessed++;

            } catch (Exception $e) {
                $errors[] = "Plan {$plan['plan_number']}: " . $e->getMessage();
                error_log("generateVisits error for plan {$plan['id']}: " . $e->getMessage());
            }
        }

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        error_log("generateVisits error: " . $e->getMessage());
    }

    return ['plans_processed' => $plansProcessed, 'visits_created' => $visitsCreated, 'errors' => $errors];
}

/**
 * Fetch active company holidays for a date range.
 * Returns lookup array: ['2026-07-01' => 'Canada Day', ...]
 * Handles is_annual holidays by matching month+day across years.
 */
function getActiveHolidays(string $fromDate, string $toDate): array {
    $db = getDB();
    $holidays = [];

    // Exact date matches (non-annual or annual with matching year)
    $stmt = $db->prepare("
        SELECT holiday_date, name
        FROM company_holidays
        WHERE is_active = 1
          AND holiday_date BETWEEN ? AND ?
    ");
    $stmt->execute([$fromDate, $toDate]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $holidays[$row['holiday_date']] = $row['name'];
    }

    // Annual holidays: generate dates for each year in range
    $annualStmt = $db->prepare("
        SELECT holiday_date, name
        FROM company_holidays
        WHERE is_active = 1 AND is_annual = 1
    ");
    $annualStmt->execute();
    $annuals = $annualStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($annuals) {
        $startYear = (int)substr($fromDate, 0, 4);
        $endYear = (int)substr($toDate, 0, 4);
        foreach ($annuals as $row) {
            $md = substr($row['holiday_date'], 5); // MM-DD
            for ($y = $startYear; $y <= $endYear; $y++) {
                $genDate = $y . '-' . $md;
                if ($genDate >= $fromDate && $genDate <= $toDate) {
                    $holidays[$genDate] = $row['name'];
                }
            }
        }
    }

    return $holidays;
}

/**
 * Find the nearest working day before a holiday for visit bumping.
 * Searches up to 7 days back, skipping weekends, holidays, and blackouts.
 * Returns YYYY-MM-DD string or null if no valid day found.
 */
function findBumpDate(string $holidayDate, array $holidays, array $blackouts): ?string {
    $dt = new DateTime($holidayDate);
    for ($i = 0; $i < 7; $i++) {
        $dt->modify('-1 day');
        $candidate = $dt->format('Y-m-d');
        $dow = (int)$dt->format('w'); // 0=Sun, 6=Sat

        // Skip weekends
        if ($dow === 0 || $dow === 6) continue;
        // Skip other holidays
        if (isset($holidays[$candidate])) continue;
        // Skip per-plan blackouts
        if (isset($blackouts[$candidate])) continue;

        return $candidate;
    }
    return null; // No valid day found (extremely unlikely)
}

/**
 * Parse a recurrence_day_of_week value into an array of day integers (0=Sun..6=Sat).
 * Handles both the old single-integer format ("3") and the new multi-day format ("1,3,5").
 * Falls back to the plan's start-date day-of-week when the value is null or empty.
 */
function parseDowList($rawDow, DateTime $fallback): array {
    if ($rawDow === null || $rawDow === '') {
        return [(int)$fallback->format('w')];
    }
    $days = array_values(array_filter(
        array_unique(array_map('intval', explode(',', (string)$rawDow))),
        function (int $d): bool { return $d >= 0 && $d <= 6; }
    ));
    return !empty($days) ? $days : [(int)$fallback->format('w')];
}

/**
 * Calculate occurrence dates for a recurring plan.
 * Returns array of YYYY-MM-DD strings. Respects blackout_dates and holidays.
 * Holiday visits are bumped to the last available working day before the holiday.
 */
function calculateRecurrenceDates(array $plan, string $fromDate, string $toDate, array $holidays = []): array {
    $dates = [];
    $current = new DateTime($fromDate);
    $end = new DateTime($toDate);
    $maxDates = 200; // safety limit

    $pattern = $plan['recurrence_pattern'] ?? 'weekly';
    $interval = max(1, (int)($plan['recurrence_interval'] ?? 1));
    $intervalUnit = $plan['recurrence_interval_unit'] ?? 'weeks';
    $targetDow = $plan['recurrence_day_of_week']; // 0=Sun..6=Sat, or null

    // Parse blackout dates
    $blackouts = [];
    if (!empty($plan['blackout_dates'])) {
        $decoded = json_decode($plan['blackout_dates'], true);
        if (is_array($decoded)) {
            $blackouts = array_flip($decoded);
        }
    }

    // For weekly/biweekly, we need a reference point for interval counting
    $planStart = new DateTime($plan['plan_start_date'] ?: $fromDate);

    while ($current <= $end && count($dates) < $maxDates) {
        $shouldInclude = false;
        $currentDow = (int)$current->format('w'); // 0=Sun..6=Sat
        $dateStr = $current->format('Y-m-d');

        switch ($pattern) {
            case 'weekly':
            case 'biweekly':
                // Parse comma-separated days (supports "3" legacy or "1,3,5" multi-day)
                $targetDays = parseDowList($targetDow, $planStart);
                // Use interval from the plan (biweekly forces 2, weekly defaults to 1)
                $weekInterval = ($pattern === 'biweekly') ? max(2, $interval) : $interval;
                if (in_array($currentDow, $targetDays, true)) {
                    if ($weekInterval <= 1) {
                        $shouldInclude = true;
                    } else {
                        $diffDays = (int)$current->diff($planStart)->days;
                        $diffWeeks = (int)floor($diffDays / 7);
                        $shouldInclude = ($diffWeeks % $weekInterval === 0);
                    }
                }
                break;

            case 'monthly':
                $targetDay = (int)$planStart->format('j'); // day of month
                $currentDay = (int)$current->format('j');
                if ($currentDay === $targetDay) {
                    if ($interval <= 1) {
                        $shouldInclude = true;
                    } else {
                        $diffMonths = ((int)$current->format('Y') - (int)$planStart->format('Y')) * 12
                                    + ((int)$current->format('n') - (int)$planStart->format('n'));
                        $shouldInclude = ($diffMonths >= 0 && $diffMonths % $interval === 0);
                    }
                }
                break;

            case 'yearly':
                $targetMonth = (int)$planStart->format('n');
                $targetDayOfMonth = (int)$planStart->format('j');
                $currentMonth = (int)$current->format('n');
                $currentDayOfMonth = (int)$current->format('j');
                if ($currentMonth === $targetMonth && $currentDayOfMonth === $targetDayOfMonth) {
                    $yearDiff = (int)$current->format('Y') - (int)$planStart->format('Y');
                    $shouldInclude = ($yearDiff >= 0 && $yearDiff % $interval === 0);
                }
                break;

            case 'custom':
                // Custom interval using interval + intervalUnit
                $diff = $planStart->diff($current);
                $unitValue = 0;
                if ($intervalUnit === 'days') {
                    $unitValue = $diff->days;
                } elseif ($intervalUnit === 'weeks') {
                    $unitValue = (int)floor($diff->days / 7);
                } elseif ($intervalUnit === 'months') {
                    $unitValue = $diff->m + ($diff->y * 12);
                } elseif ($intervalUnit === 'years') {
                    $unitValue = $diff->y;
                }
                $shouldInclude = ($unitValue >= 0 && $unitValue % $interval === 0);
                // For weeks/months custom, also check day-of-week match
                if ($shouldInclude && $intervalUnit === 'weeks') {
                    $targetDays = parseDowList($targetDow, $planStart);
                    $shouldInclude = in_array($currentDow, $targetDays, true);
                }
                // For months/years custom, check day-of-month match
                if ($shouldInclude && in_array($intervalUnit, ['months', 'years'], true)) {
                    $shouldInclude = ((int)$current->format('j') === (int)$planStart->format('j'));
                }
                break;
        }

        // Check per-plan blackout (drops visit entirely — client vacation, etc.)
        if ($shouldInclude && isset($blackouts[$dateStr])) {
            $shouldInclude = false;
        }

        // Check global holidays (bump visit to last working day before)
        if ($shouldInclude && isset($holidays[$dateStr])) {
            $bumped = findBumpDate($dateStr, $holidays, $blackouts);
            if ($bumped && !in_array($bumped, $dates, true)) {
                $dates[] = $bumped;
            }
            $shouldInclude = false; // Don't add the holiday date itself
        }

        if ($shouldInclude) {
            $dates[] = $dateStr;
        }

        $current->modify('+1 day');
    }

    return $dates;
}
