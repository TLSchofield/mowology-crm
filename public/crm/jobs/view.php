<?php
/**
 * Job Plan View & Management
 * Shows plan details, stats, visits list, and notes.
 */
require_once dirname(__DIR__) . '/../loginAuth/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/plan-functions.php';

requireLogin();
$user = getCurrentUser();
requirePermission('jobs.view');

$planId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$planId) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// ── POST Handlers ────────────────────────────────────────────────────

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Pause plan
    if ($action === 'pause_plan') {
        $reason = trim($_POST['pause_reason'] ?? '');
        if (pausePlan($planId, $user['id'], $reason)) {
            $message = 'Plan paused. Future scheduled visits have been cancelled.';
            $messageType = 'success';
        } else {
            $message = 'Could not pause this plan. It may already be paused.';
            $messageType = 'error';
        }
    }

    // Resume plan
    if ($action === 'resume_plan') {
        if (resumePlan($planId, $user['id'])) {
            header("Location: view.php?id={$planId}&resumed=1");
            exit;
        } else {
            $message = 'Could not resume this plan. It may already be active.';
            $messageType = 'error';
        }
    }

    // Delete plan — admin / jobs.edit only; no completed visits; no invoices
    if ($action === 'delete_plan') {
        requirePermission('jobs.edit');

        // Guard: no completed visits
        $cvCount = (int)$db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status = 'completed'")
                            ->execute([$planId]) ? $db->query("SELECT COUNT(*) FROM job_visits WHERE plan_id = " . (int)$planId . " AND status = 'completed'")->fetchColumn() : 0;
        // Use prepared statement properly
        $cvStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status = 'completed'");
        $cvStmt->execute([$planId]);
        $cvCount = (int)$cvStmt->fetchColumn();

        if ($cvCount > 0) {
            $message = 'Cannot delete a plan that has completed visits.';
            $messageType = 'error';
        } else {
            // Guard: no invoices
            $invStmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE plan_id = ?");
            $invStmt->execute([$planId]);
            if ((int)$invStmt->fetchColumn() > 0) {
                $message = 'Cannot delete a plan that has been invoiced.';
                $messageType = 'error';
            } else {
                try {
                    // Find stops that belong ONLY to this plan (safe to delete)
                    $orphanStmt = $db->prepare("
                        SELECT DISTINCT jv.stop_id
                        FROM job_visits jv
                        WHERE jv.plan_id = ?
                          AND jv.stop_id IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM job_visits jv2
                              WHERE jv2.stop_id = jv.stop_id
                                AND jv2.plan_id != ?
                          )
                    ");
                    $orphanStmt->execute([$planId, $planId]);
                    $orphanStopIds = array_column($orphanStmt->fetchAll(PDO::FETCH_ASSOC), 'stop_id');

                    if (!empty($orphanStopIds)) {
                        $ph = implode(',', array_fill(0, count($orphanStopIds), '?'));
                        // Delete crew assignments first (FK)
                        $db->prepare("DELETE FROM calendar_stop_crew WHERE stop_id IN ({$ph})")
                           ->execute($orphanStopIds);
                        // Delete the stops themselves
                        $db->prepare("DELETE FROM calendar_stops WHERE id IN ({$ph})")
                           ->execute($orphanStopIds);
                    }

                    // Delete the plan — CASCADE handles job_visits + plan_line_items
                    $db->prepare("DELETE FROM job_plans WHERE id = ?")->execute([$planId]);

                    header('Location: index.php?plan_deleted=1');
                    exit;
                } catch (PDOException $e) {
                    $message = 'Could not delete plan: ' . htmlspecialchars($e->getMessage());
                    $messageType = 'error';
                }
            }
        }
    }

    // Start visit
    if ($action === 'start_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        if ($visitId && updateVisitStatus($visitId, 'in_progress', $user['id'])) {
            header("Location: view.php?id={$planId}&visit_started=1");
            exit;
        }
        $message = 'Could not start visit.';
        $messageType = 'error';
    }

    // Complete visit
    if ($action === 'complete_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $notes = trim($_POST['completion_notes'] ?? '');
        $actualAmount = isset($_POST['actual_amount']) && $_POST['actual_amount'] !== ''
            ? floatval($_POST['actual_amount'])
            : null;

        if ($visitId && updateVisitStatus($visitId, 'completed', $user['id'], $notes ?: null)) {
            // Update actual_amount if provided
            if ($actualAmount !== null) {
                $stmt = $db->prepare("UPDATE job_visits SET actual_amount = ? WHERE id = ?");
                $stmt->execute([$actualAmount, $visitId]);
            }

            // Stop any active timers for all crew on this visit
            require_once dirname(__DIR__) . '/includes/timeclock-functions.php';
            $activeTimers = $db->prepare("
                SELECT id, user_id FROM job_time_entries
                WHERE visit_id = ? AND status = 'active' AND end_time IS NULL
            ");
            $activeTimers->execute([$visitId]);
            foreach ($activeTimers->fetchAll(PDO::FETCH_ASSOC) as $timer) {
                try {
                    stopVisitTimer($visitId, $timer['user_id'], null, null, $notes ?: 'Visit completed');
                } catch (Exception $e) {
                    // Log but don't block the completion
                    error_log("Timer stop failed for visit $visitId user {$timer['user_id']}: " . $e->getMessage());
                }
            }

            header("Location: view.php?id={$planId}&visit_completed=1");
            exit;
        }
        $message = 'Could not complete visit.';
        $messageType = 'error';
    }

    // Skip visit
    if ($action === 'skip_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $reason = trim($_POST['skip_reason'] ?? '');
        if ($visitId && updateVisitStatus($visitId, 'skipped', $user['id'], $reason ?: null)) {
            header("Location: view.php?id={$planId}&visit_skipped=1");
            exit;
        }
        $message = 'Could not skip visit.';
        $messageType = 'error';
    }

    // Restore a skipped/cancelled visit and mark it completed (so it can be invoiced).
    // For visits the system auto-skipped (rollover) or auto-cancelled but were actually serviced.
    if ($action === 'complete_skipped_visit') {
        requirePermission('jobs.edit');
        $visitId = intval($_POST['visit_id'] ?? 0);
        $vChk = $db->prepare("SELECT id, status FROM job_visits WHERE id = ? AND plan_id = ? AND status IN ('skipped','cancelled')");
        $vChk->execute([$visitId, $planId]);
        if ($vChk->fetch() && updateVisitStatus($visitId, 'completed', $user['id'], 'Restored from skipped/cancelled → completed')) {
            header("Location: view.php?id={$planId}&visit_restored=1");
            exit;
        }
        $message = 'Could not restore visit. Check that it has not already been completed or invoiced.';
        $messageType = 'error';
    }

    // Weather visit
    if ($action === 'weather_visit') {
        $visitId = intval($_POST['visit_id'] ?? 0);
        $reason = trim($_POST['weather_reason'] ?? '');
        if ($visitId && updateVisitStatus($visitId, 'weather', $user['id'], $reason ?: null)) {
            header("Location: view.php?id={$planId}&visit_weather=1");
            exit;
        }
        $message = 'Could not mark visit as weather delay.';
        $messageType = 'error';
    }

    // Update tracking overrides
    if ($action === 'update_tracking') {
        $trackingLevel = $_POST['tracking_level_override'] ?? 'inherit';
        $autoClockIn = $_POST['auto_clock_in_override'] ?? 'inherit';
        $clockIn = $_POST['require_clock_in_override'] ?? 'inherit';
        $gps = $_POST['require_gps_override'] ?? 'inherit';
        $photos = $_POST['require_photos_override'] ?? 'inherit';

        $validLevels = ['standard', 'heightened', 'custom'];

        $stmt = $db->prepare("
            UPDATE job_plans SET
                tracking_level_override = ?,
                auto_clock_in_override = ?,
                require_clock_in_override = ?,
                require_gps_override = ?,
                require_photos_override = ?
            WHERE id = ?
        ");
        $stmt->execute([
            ($trackingLevel !== 'inherit' && in_array($trackingLevel, $validLevels)) ? $trackingLevel : null,
            $autoClockIn !== 'inherit' ? ($autoClockIn === '1' ? 1 : 0) : null,
            $clockIn !== 'inherit' ? ($clockIn === '1' ? 1 : 0) : null,
            $gps !== 'inherit' ? ($gps === '1' ? 1 : 0) : null,
            $photos !== 'inherit' ? ($photos === '1' ? 1 : 0) : null,
            $planId
        ]);
        header("Location: view.php?id={$planId}&tracking_updated=1");
        exit;
    }

    // Add plan note
    if ($action === 'add_note') {
        $noteContent = trim($_POST['note_content'] ?? '');
        $noteType = $_POST['note_type'] ?? 'general';

        if ($noteContent) {
            $stmt = $db->prepare("
                INSERT INTO plan_notes (plan_id, note_type, content, created_by)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$planId, $noteType, $noteContent, $user['id']]);
            header("Location: view.php?id={$planId}&note_added=1");
            exit;
        }
        $message = 'Note content is required.';
        $messageType = 'error';
    }

    // Edit plan details
    if ($action === 'edit_plan') {
        $isRecurring = ($_POST['plan_type'] ?? 'one_time') === 'recurring' ? 1 : 0;
        $recurrencePattern = $isRecurring ? ($_POST['recurrence_pattern'] ?? 'weekly') : null;
        $recurrenceInterval = $isRecurring ? max(1, intval($_POST['recurrence_interval'] ?? 1)) : 1;
        $recurrenceIntervalUnit = $isRecurring ? ($_POST['recurrence_interval_unit'] ?? 'weeks') : 'weeks';
        $recurrenceDow = null;
        if ($isRecurring && isset($_POST['recurrence_day_of_week']) && $_POST['recurrence_day_of_week'] !== '') {
            $dowParts = array_values(array_filter(
                array_unique(array_map('intval', explode(',', $_POST['recurrence_day_of_week']))),
                function($d) { return $d >= 0 && $d <= 6; }
            ));
            sort($dowParts);
            if (!empty($dowParts)) $recurrenceDow = implode(',', $dowParts);
        }

        // Map presets
        if ($recurrencePattern === 'daily') {
            $recurrencePattern = 'custom';
            $recurrenceInterval = 1;
            $recurrenceIntervalUnit = 'days';
        } elseif ($recurrencePattern === 'biweekly') {
            $recurrenceInterval = 2;
            $recurrenceIntervalUnit = 'weeks';
        } elseif ($recurrencePattern === 'yearly') {
            $recurrenceInterval = max(1, $recurrenceInterval);
            $recurrenceIntervalUnit = 'years';
        }

        if (!in_array($recurrenceIntervalUnit, ['days', 'weeks', 'months', 'years'], true)) {
            $recurrenceIntervalUnit = 'weeks';
        }

        // Multi-crew assignment
        $crewIds = [];
        if (!empty($_POST['crew_ids']) && is_array($_POST['crew_ids'])) {
            $crewIds = array_map('intval', $_POST['crew_ids']);
            $crewIds = array_filter($crewIds, function($id) { return $id > 0; });
        }

        $planData = [
            'title'                    => trim($_POST['edit_title'] ?? ''),
            'description'              => trim($_POST['edit_description'] ?? ''),
            'service_type'             => $_POST['edit_service_type'] ?? 'landscaping',
            'pricing_model'            => $_POST['edit_pricing_model'] ?? 'per_visit',
            'price_per_visit'          => floatval($_POST['edit_price_per_visit'] ?? 0) ?: null,
            'invoice_timing'           => in_array($_POST['edit_invoice_timing'] ?? '', ['after_visit','end_of_month','upfront'], true) ? $_POST['edit_invoice_timing'] : 'after_visit',
            'estimated_duration_minutes' => intval($_POST['edit_duration'] ?? 60),
            'default_crew_id'          => !empty($crewIds) ? $crewIds[0] : (!empty($_POST['edit_crew_id']) ? intval($_POST['edit_crew_id']) : null),
            'default_time_start'       => !empty($_POST['edit_time_start']) ? $_POST['edit_time_start'] : null,
            'default_time_end'         => !empty($_POST['edit_time_end']) ? $_POST['edit_time_end'] : null,
            'plan_start_date'          => $_POST['edit_start_date'] ?? date('Y-m-d'),
            'plan_end_date'            => !empty($_POST['edit_end_date']) ? $_POST['edit_end_date'] : null,
            'is_recurring'             => $isRecurring,
            'recurrence_pattern'       => $recurrencePattern,
            'recurrence_interval'      => $recurrenceInterval,
            'recurrence_interval_unit' => $recurrenceIntervalUnit,
            'recurrence_day_of_week'   => $recurrenceDow,
            'horizon_days'             => intval($_POST['edit_horizon_days'] ?? 28),
            'crew_ids'                 => $crewIds,
        ];

        $result = updateJobPlan($planId, $planData, (int)$user['id']);

        if ($result['success']) {
            $suffix = $result['visits_regenerated'] ? '&visits_regenerated=1' : '';
            header("Location: view.php?id={$planId}&plan_updated=1{$suffix}");
            exit;
        }
        $message = implode(' ', $result['errors']);
        $messageType = 'error';
    }

    // Update line items
    if ($action === 'update_line_items') {
        $formItems = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['service_type'])) continue;
                $formItems[] = [
                    'service_type' => $item['service_type'],
                    'description'  => $item['description'] ?? '',
                    'quantity'     => floatval($item['quantity'] ?? 1),
                    'unit_type'    => $item['unit_type'] ?? 'visit',
                    'unit_price'   => floatval($item['unit_price'] ?? 0),
                    'line_total'   => floatval($item['line_total'] ?? 0),
                ];
            }
        }

        if (replacePlanLineItems($planId, $formItems)) {
            header("Location: view.php?id={$planId}&items_updated=1");
            exit;
        }
        $message = 'Could not update line items.';
        $messageType = 'error';
    }

    // Add manual time entry
    if ($action === 'add_time_entry') {
        requirePermission('jobs.edit');
        $visitId   = intval($_POST['te_visit_id'] ?? 0);
        $userId    = intval($_POST['te_user_id'] ?? 0);
        $startTime = trim($_POST['te_start_time'] ?? '');
        $endTime   = trim($_POST['te_end_time'] ?? '');
        $teNotes   = trim($_POST['te_notes'] ?? '');

        if ($visitId && $userId && $startTime && $endTime) {
            // Validate visit belongs to this plan
            $vChk = $db->prepare("SELECT id FROM job_visits WHERE id = ? AND plan_id = ?");
            $vChk->execute([$visitId, $planId]);
            if ($vChk->fetch()) {
                $dur = $db->prepare("
                    INSERT INTO job_time_entries
                        (visit_id, user_id, start_time, end_time, duration_minutes, status, notes, auto_started)
                    VALUES
                        (?, ?, ?, ?, TIMESTAMPDIFF(MINUTE, ?, ?), 'edited', ?, 0)
                ");
                $dur->execute([$visitId, $userId, $startTime, $endTime, $startTime, $endTime, $teNotes ?: null]);
                header("Location: view.php?id={$planId}&time_added=1#timeLogCard");
                exit;
            }
        }
        $message = 'Could not add time entry. Check all fields.';
        $messageType = 'error';
    }

    // Delete a time entry
    if ($action === 'delete_time_entry') {
        requirePermission('jobs.edit');
        $entryId = intval($_POST['del_entry_id'] ?? 0);
        if ($entryId) {
            $eChk = $db->prepare("
                SELECT jte.id FROM job_time_entries jte
                JOIN job_visits jv ON jte.visit_id = jv.id
                WHERE jte.id = ? AND jv.plan_id = ?
            ");
            $eChk->execute([$entryId, $planId]);
            if ($eChk->fetch()) {
                $db->prepare("DELETE FROM job_time_entries WHERE id = ?")->execute([$entryId]);
                header("Location: view.php?id={$planId}&time_deleted=1");
                exit;
            }
        }
        $message = 'Could not delete time entry.';
        $messageType = 'error';
    }

    // Delete a visit — scheduled/skipped/weather: no time entries allowed.
    // Completed (admin only): allowed if the linked invoice is not paid.
    if ($action === 'delete_visit') {
        requirePermission('jobs.edit');
        $delVisitId = intval($_POST['del_visit_id'] ?? 0);
        if ($delVisitId) {
            $vChk = $db->prepare("
                SELECT jv.id, jv.status, jv.invoice_id,
                       COALESCE(i.status, '') AS inv_status
                FROM job_visits jv
                LEFT JOIN invoices i ON i.id = jv.invoice_id
                WHERE jv.id = ? AND jv.plan_id = ?
                  AND jv.status IN ('scheduled','skipped','weather','completed')
            ");
            $vChk->execute([$delVisitId, $planId]);
            $vRow = $vChk->fetch(PDO::FETCH_ASSOC);

            if ($vRow) {
                $isCompleted = ($vRow['status'] === 'completed');
                $invPaid     = in_array($vRow['inv_status'], ['paid', 'partial']);

                if ($isCompleted && !userHasPermission('admin')) {
                    $message = 'Only admins can delete completed visits.';
                    $messageType = 'error';
                } elseif ($isCompleted && $invPaid) {
                    $message = 'Cannot delete a completed visit whose invoice has been paid. Void the invoice first.';
                    $messageType = 'error';
                } else {
                    // For non-completed visits: block if time entries exist
                    $teChk = $db->prepare("SELECT COUNT(*) FROM job_time_entries WHERE visit_id = ?");
                    $teChk->execute([$delVisitId]);
                    if (!$isCompleted && (int)$teChk->fetchColumn() > 0) {
                        $message = 'Cannot delete a visit that has time entries. Delete the time entries first.';
                        $messageType = 'error';
                    } else {
                        // Void the linked invoice if unpaid (set status to cancelled)
                        if (!empty($vRow['invoice_id']) && !$invPaid) {
                            $db->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")
                               ->execute([$vRow['invoice_id']]);
                        }
                        $db->prepare("DELETE FROM job_visits WHERE id = ?")->execute([$delVisitId]);
                        header("Location: view.php?id={$planId}&visit_deleted=1");
                        exit;
                    }
                }
            } else {
                $message = 'Visit cannot be deleted (wrong status or plan).';
                $messageType = 'error';
            }
        }
    }

    // Move time entry to a different visit (on any plan at the same property)
    if ($action === 'move_time_entry') {
        requirePermission('jobs.edit');
        $entryId    = intval($_POST['mv_entry_id'] ?? 0);
        $newVisitId = intval($_POST['mv_visit_id'] ?? 0);

        if ($entryId && $newVisitId) {
            // Verify the entry currently belongs to a visit on this plan
            $eChk = $db->prepare("
                SELECT jte.id FROM job_time_entries jte
                JOIN job_visits jv ON jte.visit_id = jv.id
                WHERE jte.id = ? AND jv.plan_id = ?
            ");
            $eChk->execute([$entryId, $planId]);
            if ($eChk->fetch()) {
                $db->prepare("UPDATE job_time_entries SET visit_id = ? WHERE id = ?")
                   ->execute([$newVisitId, $entryId]);
                header("Location: view.php?id={$planId}&time_moved=1");
                exit;
            }
        }
        $message = 'Could not move time entry.';
        $messageType = 'error';
    }

    // Edit an existing time entry (adjust start/end times)
    if ($action === 'edit_time_entry') {
        requirePermission('jobs.edit');
        $entryId   = intval($_POST['ed_entry_id'] ?? 0);
        $startTime = trim($_POST['ed_start_time'] ?? '');
        $endTime   = trim($_POST['ed_end_time'] ?? '');
        $edNotes   = trim($_POST['ed_notes'] ?? '');

        if ($entryId && $startTime && $endTime) {
            $eChk = $db->prepare("
                SELECT jte.id FROM job_time_entries jte
                JOIN job_visits jv ON jte.visit_id = jv.id
                WHERE jte.id = ? AND jv.plan_id = ?
            ");
            $eChk->execute([$entryId, $planId]);
            if ($eChk->fetch()) {
                $db->prepare("
                    UPDATE job_time_entries
                    SET start_time = ?, end_time = ?,
                        duration_minutes = TIMESTAMPDIFF(MINUTE, ?, ?),
                        status = 'edited',
                        notes = ?
                    WHERE id = ?
                ")->execute([$startTime, $endTime, $startTime, $endTime, $edNotes ?: null, $entryId]);
                header("Location: view.php?id={$planId}&time_edited=1");
                exit;
            }
        }
        $message = 'Could not edit time entry. Check all fields.';
        $messageType = 'error';
    }

    // Edit visit
    if ($action === 'edit_visit') {
        $visitId = intval($_POST['edit_visit_id'] ?? 0);
        $newDate = $_POST['visit_date'] ?? '';
        $newTimeStart = !empty($_POST['visit_time_start']) ? $_POST['visit_time_start'] : null;
        $newTimeEnd = !empty($_POST['visit_time_end']) ? $_POST['visit_time_end'] : null;
        $updateScope = $_POST['update_scope'] ?? 'this_only';
        $newCrewIds = !empty($_POST['visit_crew_ids']) && is_array($_POST['visit_crew_ids'])
            ? array_values(array_filter(array_map('intval', $_POST['visit_crew_ids']), function($id) { return $id > 0; }))
            : [];

        if ($visitId && $newDate) {
            // Fetch original date before moving (needed for future-visit offset)
            $origStmt = $db->prepare("SELECT scheduled_date FROM job_visits WHERE id = ?");
            $origStmt->execute([$visitId]);
            $origDate = $origStmt->fetchColumn();

            $moved = moveVisit($visitId, $newDate, $newTimeStart, $user['id']);

            if (!$moved) {
                $message = 'Could not update the visit date. The visit may be in a state that prevents editing.';
                $messageType = 'error';
            } else {
                if ($newTimeEnd !== null) {
                    $db->prepare("UPDATE job_visits SET scheduled_time_end = ? WHERE id = ?")->execute([$newTimeEnd, $visitId]);
                }

                setVisitCrewAssignments($visitId, $newCrewIds);

                // Propagate changes to all future scheduled visits on this plan
                if ($updateScope === 'this_and_future' && $origDate) {
                    $origDt   = new DateTime($origDate);
                    $targetDt = new DateTime($newDate);
                    $offsetDays = (int)$origDt->diff($targetDt)->format('%r%a');

                    $futureStmt = $db->prepare(
                        "SELECT id, scheduled_date FROM job_visits
                         WHERE plan_id = ? AND status = 'scheduled' AND scheduled_date > ? AND id != ?
                         ORDER BY scheduled_date ASC"
                    );
                    $futureStmt->execute([$planId, $origDate, $visitId]);

                    foreach ($futureStmt->fetchAll(PDO::FETCH_ASSOC) as $fv) {
                        if ($offsetDays !== 0) {
                            $fDt = new DateTime($fv['scheduled_date']);
                            $fDt->modify("{$offsetDays} days");
                            moveVisit($fv['id'], $fDt->format('Y-m-d'), $newTimeStart, $user['id']);
                        } elseif ($newTimeStart !== null) {
                            $db->prepare("UPDATE job_visits SET scheduled_time_start = ? WHERE id = ?")->execute([$newTimeStart, $fv['id']]);
                        }
                        if ($newTimeEnd !== null) {
                            $db->prepare("UPDATE job_visits SET scheduled_time_end = ? WHERE id = ?")->execute([$newTimeEnd, $fv['id']]);
                        }
                        setVisitCrewAssignments($fv['id'], $newCrewIds);
                    }
                }

                header("Location: view.php?id={$planId}&visit_updated=1");
                exit;
            }
        } else {
            $message = 'Could not update visit.';
            $messageType = 'error';
        }
    }

    // Regenerate visits — resets the watermark and reruns generateVisits(),
    // which now backfills from plan_start_date (up to 90 days back).
    if ($action === 'regenerate_visits') {
        requirePermission('jobs.edit');
        $db->prepare("UPDATE job_plans SET visits_generated_through = NULL WHERE id = ?")
           ->execute([$planId]);
        generateVisits($planId);
        header("Location: view.php?id={$planId}&visits_regenerated=1");
        exit;
    }

    // Log a past completed visit (e.g., a service that was done but not recorded)
    if ($action === 'log_past_visit') {
        requirePermission('jobs.edit');
        $visitDate  = trim($_POST['lv_date'] ?? '');
        $timeStart  = !empty($_POST['lv_time_start']) ? trim($_POST['lv_time_start']) : null;
        $timeEnd    = !empty($_POST['lv_time_end'])   ? trim($_POST['lv_time_end'])   : null;
        $amount     = !empty($_POST['lv_amount'])     ? floatval($_POST['lv_amount']) : null;
        $lvNotes    = trim($_POST['lv_notes'] ?? '');

        if ($visitDate) {
            // Get the plan's number and default crew for this insert
            $planStmt = $db->prepare("SELECT plan_number, default_crew_id FROM job_plans WHERE id = ?");
            $planStmt->execute([$planId]);
            $planMeta = $planStmt->fetch(PDO::FETCH_ASSOC);

            // Next sequence index
            $seqStmt = $db->prepare("SELECT MAX(sequence_index) FROM job_visits WHERE plan_id = ?");
            $seqStmt->execute([$planId]);
            $nextSeq = ((int)$seqStmt->fetchColumn()) + 1;

            $visitNumber = generateVisitNumber($planMeta['plan_number'], $nextSeq);
            $crewId      = $planMeta['default_crew_id'] ?: null;

            // Ensure a calendar stop exists for this date/property so the visit
            // appears on the schedule page correctly
            $propStmt = $db->prepare("SELECT property_id FROM job_plans WHERE id = ?");
            $propStmt->execute([$planId]);
            $propId = (int)$propStmt->fetchColumn();
            $stopId = ensureCalendarStop($propId, $visitDate, $crewId, $timeStart, $timeEnd);

            $db->prepare("
                INSERT INTO job_visits
                    (visit_number, plan_id, stop_id, scheduled_date,
                     scheduled_time_start, scheduled_time_end,
                     sequence_index, assigned_crew_id,
                     status, status_changed_at, actual_amount, completion_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), ?, ?)
            ")->execute([
                $visitNumber, $planId, $stopId > 0 ? $stopId : null, $visitDate,
                $timeStart, $timeEnd,
                $nextSeq, $crewId,
                $amount, $lvNotes ?: null,
            ]);

            header("Location: view.php?id={$planId}&visit_logged=1");
            exit;
        }
        $message = 'Could not log visit — date is required.';
        $messageType = 'error';
    }
}

// ── Load Plan Data ───────────────────────────────────────────────────

// Silently clean up any skipped visits that predate the plan's start date.
// These accumulate when a plan is edited to start later or change its recurrence day.
cleanupOrphanedVisits($planId);

// If the plan's visit generation was reset (visits_generated_through = NULL),
// regenerate now so past visits since plan_start_date are created immediately
// rather than waiting for the next cron run.
$genCheckStmt = $db->prepare("SELECT visits_generated_through FROM job_plans WHERE id = ?");
$genCheckStmt->execute([$planId]);
if ($genCheckStmt->fetchColumn() === null) {
    generateVisits($planId);
}

$plan = getPlanDetails($planId);
$TAX_RATE = 0.05;   // BC GST — price_per_visit is stored NET (excl. tax); GST is added on top at invoice time

if (!$plan) {
    header('Location: index.php');
    exit;
}

// ── Contract value for the rate calculator ───────────────────────────
// Priority: contract billing_amount > quote total_amount > plan estimated_amount
$contractTotal = null;   // raw billing amount (per-cycle for monthly, lump-sum for others)
$contractLabel = null;
$contractCycle = null;   // 'monthly','per_visit','seasonal','annual','custom' — null if quote/estimate
if (!empty($plan['contract_billing_amount'])) {
    $contractTotal = (float)$plan['contract_billing_amount'];
    $contractLabel = $plan['contract_number'] ?? null;
    $contractCycle = $plan['contract_billing_cycle'] ?? 'monthly';
} elseif (!empty($plan['quote_total_amount'])) {
    $contractTotal = (float)$plan['quote_total_amount'];
    $contractLabel = $plan['quote_number'] ?? null;
    $contractCycle = 'seasonal'; // quote totals are lump-sum
} elseif (!empty($plan['estimated_amount'])) {
    $contractTotal = (float)$plan['estimated_amount'];
    // Derive cycle from the plan's own pricing model (monthly_flat stores the monthly rate)
    $pmToCycle = ['monthly_flat' => 'monthly', 'per_visit' => 'per_visit', 'seasonal' => 'seasonal'];
    $contractCycle = $pmToCycle[$plan['pricing_model'] ?? ''] ?? 'seasonal';
}

// Profitability data
$profitability = getPlanProfitability($planId);

// Get visits
$visits = getPlanVisits($planId, null, 200, 0);

// Batch-load multi-crew assignments for all visits
$visitCrewMap = [];
if (!empty($visits)) {
    $visitIds = array_column($visits, 'id');
    $placeholders = implode(',', array_fill(0, count($visitIds), '?'));
    $vcStmt = $db->prepare("SELECT vca.visit_id, vca.user_id, vca.role, u.full_name FROM visit_crew_assignments vca JOIN users u ON vca.user_id = u.id WHERE vca.visit_id IN ($placeholders) ORDER BY FIELD(vca.role,'lead','crew')");
    $vcStmt->execute($visitIds);
    foreach ($vcStmt->fetchAll(PDO::FETCH_ASSOC) as $vcRow) {
        $visitCrewMap[$vcRow['visit_id']][] = $vcRow;
    }
}

// Get plan line items
$planLineItems = getPlanLineItems($planId);

// ── GPS Conflict Detection ───────────────────────────────────────────────────
// For each past-scheduled visit, check whether GPS pings exist near the
// property on that date. If so, the crew was likely there but didn't record
// the visit — flag it as a GPS conflict.
$gpsConflictDates = [];
$propLat = (float)($plan['latitude']  ?? 0);
$propLng = (float)($plan['longitude'] ?? 0);
if ($propLat && $propLng) {
    // Collect dates of past scheduled visits
    $today = date('Y-m-d');
    $pastScheduledDates = [];
    foreach ($visits as $v) {
        if ($v['status'] === 'scheduled' && !empty($v['scheduled_date']) && $v['scheduled_date'] < $today) {
            $pastScheduledDates[] = $v['scheduled_date'];
        }
    }

    if (!empty($pastScheduledDates)) {
        // ~200 m bounding box
        $latDelta = 0.0018;
        $lngDelta = $propLng != 0 ? (0.0018 / max(0.001, cos(deg2rad($propLat)))) : 0.0018;

        $ph = implode(',', array_fill(0, count($pastScheduledDates), '?'));
        $gpsStmt = $db->prepare("
            SELECT DISTINCT DATE(timestamp) AS ping_date
            FROM crew_location_history
            WHERE latitude  BETWEEN ? AND ?
              AND longitude BETWEEN ? AND ?
              AND DATE(timestamp) IN ($ph)
        ");
        $params = array_merge([
            $propLat - $latDelta, $propLat + $latDelta,
            $propLng - $lngDelta, $propLng + $lngDelta,
        ], $pastScheduledDates);
        $gpsStmt->execute($params);
        foreach ($gpsStmt->fetchAll(PDO::FETCH_COLUMN) as $pingDate) {
            $gpsConflictDates[$pingDate] = true;
        }
    }
}

// Count completed visits with actual timer data (used to label the averaged duration estimate)
$durAvgCountStmt = $db->prepare("
    SELECT COUNT(DISTINCT jv.id)
    FROM job_visits jv
    JOIN job_time_entries jte ON jte.visit_id = jv.id
    WHERE jv.plan_id = ?
      AND jv.status = 'completed'
      AND jte.status IN ('completed', 'edited')
      AND jte.duration_minutes > 0
");
$durAvgCountStmt->execute([$planId]);
$durAvgCount = (int)($durAvgCountStmt->fetchColumn() ?: 0);

// Get tracking requirements (resolved: plan overrides > product defaults)
$trackingReqs = resolveTrackingRequirementsForPlan($planId);

// Get plan notes
$stmt = $db->prepare("
    SELECT pn.*, u.full_name
    FROM plan_notes pn
    LEFT JOIN users u ON pn.created_by = u.id
    WHERE pn.plan_id = ?
    ORDER BY pn.created_at DESC
");
$stmt->execute([$planId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get staff for crew dropdown
$staff = getStaffMembers();

// Get service templates for the Edit Services combobox
$stmtSt = $db->query("SELECT name, service_type, description, default_price, unit_type FROM service_templates WHERE is_active = 1 ORDER BY sort_order, name");
$serviceTemplates = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

// ── Flash messages from redirects ────────────────────────────────────

$csrfToken = generateCSRFToken();

if (isset($_GET['created'])) { $message = 'Plan created successfully!'; $messageType = 'success'; }
if (isset($_GET['resumed'])) { $message = 'Plan resumed. Visits have been regenerated.'; $messageType = 'success'; }
if (isset($_GET['visit_started'])) { $message = 'Visit started!'; $messageType = 'success'; }
if (isset($_GET['visit_completed'])) { $message = 'Visit completed!'; $messageType = 'success'; }
if (isset($_GET['visit_skipped'])) { $message = 'Visit skipped.'; $messageType = 'success'; }
if (isset($_GET['visit_weather'])) { $message = 'Visit marked as weather delay.'; $messageType = 'success'; }
if (isset($_GET['note_added'])) { $message = 'Note added!'; $messageType = 'success'; }
if (isset($_GET['plan_updated'])) {
    $message = 'Plan updated successfully!';
    if (isset($_GET['visits_regenerated'])) $message .= ' Future visits have been regenerated.';
    $messageType = 'success';
}
if (isset($_GET['items_updated'])) { $message = 'Line items updated!'; $messageType = 'success'; }
if (isset($_GET['visit_updated'])) { $message = 'Visit updated!'; $messageType = 'success'; }
if (isset($_GET['time_added']))   { $message = 'Time entry added!'; $messageType = 'success'; }
if (isset($_GET['time_moved']))   { $message = 'Time entry moved!'; $messageType = 'success'; }
if (isset($_GET['time_deleted']))   { $message = 'Time entry deleted.'; $messageType = 'success'; }
if (isset($_GET['visit_deleted']))  { $message = 'Visit deleted.'; $messageType = 'success'; }
if (isset($_GET['time_edited']))  { $message = 'Time entry updated!'; $messageType = 'success'; }
if (isset($_GET['visit_logged']))  { $message = 'Past visit logged and ready to invoice.'; $messageType = 'success'; }

// ── Delete Plan eligibility ───────────────────────────────────────────
$canDeletePlan = false;
if (in_array($plan['status'], ['active', 'paused', 'completed', 'cancelled'])) {
    $cdpStmt = $db->prepare("SELECT COUNT(*) FROM job_visits WHERE plan_id = ? AND status = 'completed'");
    $cdpStmt->execute([$planId]);
    $cdpInvStmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE plan_id = ?");
    $cdpInvStmt->execute([$planId]);
    $canDeletePlan = ((int)$cdpStmt->fetchColumn() === 0) && ((int)$cdpInvStmt->fetchColumn() === 0);
}

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Build a human-readable recurrence description.
 */
function describeRecurrence(array $plan): string {
    if (!$plan['is_recurring']) return 'One-time';

    $pattern = $plan['recurrence_pattern'] ?? 'weekly';
    $interval = (int)($plan['recurrence_interval'] ?? 1);
    $unit = $plan['recurrence_interval_unit'] ?? 'weeks';

    switch ($pattern) {
        case 'weekly':  return $interval > 1 ? "Every {$interval} weeks" : 'Weekly';
        case 'biweekly': return 'Every 2 weeks';
        case 'monthly': return $interval > 1 ? "Every {$interval} months" : 'Monthly';
        case 'yearly':  return $interval > 1 ? "Every {$interval} years" : 'Yearly';
        case 'custom':
            $unitLabel = rtrim($unit, 's');
            if ($interval === 1) return 'Every ' . $unitLabel;
            return "Every {$interval} {$unit}";
        default:
            return ucfirst(str_replace('_', ' ', $pattern));
    }
}

/**
 * Separate visits into upcoming and past.
 */
function splitVisits(array $visits): array {
    $today = date('Y-m-d');
    $upcoming = [];
    $past = [];

    foreach ($visits as $v) {
        if ($v['scheduled_date'] >= $today && in_array($v['status'], ['scheduled', 'in_progress'])) {
            $upcoming[] = $v;
        } else {
            $past[] = $v;
        }
    }

    // Sort upcoming by date ASC
    usort($upcoming, function ($a, $b) {
        return strcmp($a['scheduled_date'], $b['scheduled_date']);
    });

    // Past stays DESC (already from query)
    return ['upcoming' => $upcoming, 'past' => $past];
}

$splitVisits = splitVisits($visits);

// ── Page Setup ───────────────────────────────────────────────────────

$pageTitle = 'Plan ' . htmlspecialchars($plan['plan_number']);
$activePage = 'jobs';

// Include Leaflet if this plan has a property with coordinates
$hasPropCoords = !empty($plan['latitude']) && !empty($plan['longitude']);
if ($hasPropCoords) {
    // Load Leaflet in <head> (same as clients_appstack.php) so it's ready before modal JS
    $extraHead  = '<link rel="stylesheet" href="/crm/js/leaflet/leaflet.min.css">';
    $extraHead .= '<script src="/crm/js/leaflet/leaflet.min.js"></script>';
}
?>
<?php include dirname(__DIR__) . '/includes/appstack_head.php'; ?>

            <a href="index.php" class="mw-back-link">&larr; Back to Plans</a>

            <?php if ($message): ?>
                <div class="mw-message <?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 1: Plan Header + Details
                 ══════════════════════════════════════════════════════ -->

            <?php if (!empty($plan['contract_id']) && !empty($plan['contract_number'])): ?>
                <a href="../contracts/view.php?id=<?php echo (int)$plan['contract_id']; ?>" class="mw-back-link mb-2">
                    &larr; Contract <?php echo htmlspecialchars($plan['contract_number']); ?>
                </a>
            <?php endif; ?>

            <div class="mw-page-header">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($plan['plan_number']); ?></h1>
                    <div class="mt-2">
                        <?php echo getStatusBadge($plan['status'], 'plan'); ?>
                        <span class="mw-badge-status" style="background: var(--mw-light); color: var(--mw-dark);">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                        </span>
                        <?php if ($plan['is_recurring']): ?>
                            <span class="mw-badge-status" style="background: var(--mw-lime); color: #000;">
                                Recurring
                            </span>
                        <?php else: ?>
                            <span class="mw-badge-status" style="background: #E5E7EB; color: #374151;">
                                One-time
                            </span>
                        <?php endif; ?>
                        <span class="ml-3 text-muted">
                            <?php echo htmlspecialchars($plan['title'] ?? ''); ?>
                        </span>
                    </div>
                </div>
                <div class="mw-header-actions">
                    <a href="/crm/invoices/create.php?plan_id=<?php echo (int)$planId; ?>" class="btn btn-success" title="Raise an invoice for this plan">
                        <i data-feather="file-plus" style="width:14px;height:14px;"></i> Create Invoice
                    </a>
                    <?php if ($hasPropCoords): ?>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="$('#planWorkZoneModal').modal('show')">
                            <i data-feather="map-pin" style="width:14px;height:14px;"></i> Work Zone
                        </button>
                    <?php endif; ?>
                    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
                        <button type="button" class="btn btn-outline-primary" onclick="showModal('editPlanModal')">
                            <i data-feather="edit-2" style="width:14px;height:14px;"></i> Edit Plan
                        </button>
                    <?php endif; ?>
                    <?php if ($plan['status'] === 'active' && $plan['is_recurring']): ?>
                        <form method="POST" style="display:inline;" title="Regenerate visits from the plan start date (backfills any missed past visits)">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" name="action" value="regenerate_visits" class="btn btn-outline-secondary">
                                <i data-feather="refresh-cw" style="width:14px;height:14px;"></i> Regenerate Visits
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($plan['status'] === 'active'): ?>
                        <button type="button" class="btn btn-warning" onclick="showModal('pauseModal')">
                            Pause Plan
                        </button>
                    <?php elseif ($plan['status'] === 'paused'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <button type="submit" name="action" value="resume_plan" class="btn btn-success">
                                Resume Plan
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($plan['quote_id']): ?>
                        <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>" class="btn btn-secondary">
                            View Quote <?php echo htmlspecialchars($plan['quote_number'] ?? ''); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($canDeletePlan): ?>
                        <button type="button" class="btn btn-outline-danger" onclick="showModal('deletePlanModal')">
                            <i data-feather="trash-2" style="width:14px;height:14px;"></i> Delete Plan
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 2: Plan Stats Row
                 ══════════════════════════════════════════════════════ -->

            <div class="row mb-4">
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Visits Completed</div>
                            <div class="mw-stat-value" style="color: var(--mw-green);">
                                <?php echo $plan['visits_completed']; ?>
                                <small class="text-muted">/ <?php echo $plan['total_visits']; ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Visits Scheduled</div>
                            <div class="mw-stat-value" style="color: #3B82F6;">
                                <?php echo $plan['visits_scheduled']; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Total Revenue</div>
                            <div class="mw-stat-value">
                                <?php echo formatCurrency($plan['total_revenue']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="mw-stat-label">Next Visit</div>
                            <div class="mw-stat-value" style="font-size: 1.1rem;">
                                <?php echo $plan['next_visit_date'] ? formatDate($plan['next_visit_date']) : 'None'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 2b: Profitability Dashboard
                 ══════════════════════════════════════════════════════ -->

            <?php if ($profitability['has_data']): ?>
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">Risk Analysis</h5>
                    <span class="text-muted" style="font-size:.75rem;">8-factor profitability risk score</span>
                </div>
                <div class="card-body p-0" id="mwInlineRiskBody">
                    <div class="d-flex align-items-center justify-content-center py-4">
                        <span class="text-muted small">Analysing&hellip;</span>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card mb-4">
                <div class="card-body text-center text-muted py-4">
                    <i data-feather="bar-chart-2" style="width:24px;height:24px;"></i>
                    <p class="mb-0 mt-2">Risk analysis will appear after visits are completed.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 Plan Details (Two-Column Grid)
                 ══════════════════════════════════════════════════════ -->

            <div class="row mb-4">
                <!-- Left Column: Property, Client, Service, Pricing -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Plan Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Property</span>
                                <span class="mw-detail-value">
                                    <?php
                                        $addr = trim(($plan['property_address'] ?? '') . ', ' . ($plan['property_city'] ?? ''), ', ');
                                        if ($addr && !empty($plan['property_id'])):
                                    ?>
                                        <a href="../properties/view.php?id=<?php echo (int)$plan['property_id']; ?>">
                                            <i data-feather="map-pin" style="width:13px;height:13px;vertical-align:-1px;margin-right:3px;"></i><?php echo htmlspecialchars($addr); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($addr ?: 'N/A'); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Client</span>
                                <span class="mw-detail-value">
                                    <?php if (!empty($plan['company_name']) && !empty($plan['company_id'])): ?>
                                        <a href="../companies/view.php?id=<?php echo (int)$plan['company_id']; ?>">
                                            <i data-feather="briefcase" style="width:13px;height:13px;vertical-align:-1px;margin-right:3px;"></i><?php echo htmlspecialchars($plan['company_name']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php
                                $contactName = trim(($plan['first_name'] ?? '') . ' ' . ($plan['last_name'] ?? ''));
                                if ($contactName):
                            ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Contact</span>
                                    <span class="mw-detail-value">
                                        <?php if (!empty($plan['contact_id'])): ?>
                                            <a href="../clients_appstack.php?action=view_contact&id=<?php echo (int)$plan['contact_id']; ?>">
                                                <i data-feather="user" style="width:13px;height:13px;vertical-align:-1px;margin-right:3px;"></i><?php echo htmlspecialchars($contactName); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($contactName); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($plan['contact_phone'])): ?>
                                            &mdash;
                                            <a href="tel:<?php echo htmlspecialchars($plan['contact_phone']); ?>">
                                                <?php echo formatPhone($plan['contact_phone']); ?>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Service Type</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['service_type']))); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Pricing Model</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $plan['pricing_model'] ?? 'per_visit'))); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Price / Visit <small class="text-muted">(excl. GST)</small></span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['price_per_visit'] ? formatCurrency($plan['price_per_visit']) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Duration</span>
                                <span class="mw-detail-value">
                                    <?php echo (int)$plan['estimated_duration_minutes']; ?> min
                                    <?php if ($durAvgCount > 0): ?>
                                        <small class="text-muted">· avg of <?php echo $durAvgCount; ?> visit<?php echo $durAvgCount !== 1 ? 's' : ''; ?></small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Crew</span>
                                <span class="mw-detail-value">
                                    <?php
                                    $crewAssignments = getPlanCrewAssignments($planId);
                                    if (!empty($crewAssignments)):
                                        $crewNames = [];
                                        foreach ($crewAssignments as $ca) {
                                            $name = htmlspecialchars($ca['full_name']);
                                            if ($ca['role'] === 'lead') $name .= ' <small class="text-muted">(Lead)</small>';
                                            $crewNames[] = $name;
                                        }
                                        echo implode(', ', $crewNames);
                                    else:
                                        echo htmlspecialchars($plan['default_crew_name'] ?? 'Unassigned');
                                    endif;
                                    ?>
                                </span>
                            </div>
                            <?php if ($plan['description']): ?>
                                <div class="mt-3">
                                    <span class="mw-detail-label">Description</span>
                                    <p class="mt-1" style="white-space: pre-line;"><?php echo htmlspecialchars($plan['description']); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Schedule, Recurrence, Dates, Audit -->
                <div class="col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Schedule & Recurrence</h5>
                        </div>
                        <div class="card-body">
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Plan Type</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['is_recurring'] ? 'Recurring' : 'One-time'; ?>
                                </span>
                            </div>
                            <?php if ($plan['is_recurring']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Recurrence</span>
                                    <span class="mw-detail-value">
                                        <?php echo htmlspecialchars(describeRecurrence($plan)); ?>
                                        <?php if ($plan['recurrence_day_of_week'] !== null && $plan['recurrence_day_of_week'] !== ''): ?>
                                            <?php
                                                $dowShort = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                                $dowParts = array_filter(array_map('intval', explode(',', (string)$plan['recurrence_day_of_week'])));
                                                $dowLabels = array_map(function($d) use ($dowShort) { return $dowShort[$d] ?? ''; }, $dowParts);
                                                $dowLabels = array_filter($dowLabels);
                                                if (!empty($dowLabels)) echo ' (' . implode(', ', $dowLabels) . ')';
                                            ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Start Date</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['plan_start_date'] ? formatDate($plan['plan_start_date']) : 'Not set'; ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">End Date</span>
                                <span class="mw-detail-value">
                                    <?php echo $plan['plan_end_date'] ? formatDate($plan['plan_end_date']) : 'Ongoing'; ?>
                                </span>
                            </div>
                            <?php if ($plan['default_time_start']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Default Time</span>
                                    <span class="mw-detail-value">
                                        <?php echo date('g:i A', strtotime($plan['default_time_start'])); ?>
                                        <?php if ($plan['default_time_end']): ?>
                                            - <?php echo date('g:i A', strtotime($plan['default_time_end'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Horizon</span>
                                <span class="mw-detail-value">
                                    <?php echo (int)$plan['horizon_days']; ?> days ahead
                                </span>
                            </div>
                            <?php if ($plan['status'] === 'paused' && $plan['paused_reason']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">Paused Reason</span>
                                    <span class="mw-detail-value text-warning">
                                        <?php echo htmlspecialchars($plan['paused_reason']); ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <hr class="my-3">

                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created By</span>
                                <span class="mw-detail-value">
                                    <?php echo htmlspecialchars($plan['created_by_name'] ?? 'Unknown'); ?>
                                </span>
                            </div>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">Created At</span>
                                <span class="mw-detail-value">
                                    <?php echo formatDateTime($plan['created_at']); ?>
                                </span>
                            </div>
                            <?php if ($plan['quote_number']): ?>
                                <div class="mw-detail-row">
                                    <span class="mw-detail-label">From Quote</span>
                                    <span class="mw-detail-value">
                                        <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>">
                                            <?php echo htmlspecialchars($plan['quote_number']); ?>
                                        </a>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Tracking & Compliance -->
                    <div class="card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Tracking &amp; Compliance</h5>
                            <span class="badge badge-<?php echo $trackingReqs['tracking_level'] === 'heightened' ? 'warning' : ($trackingReqs['tracking_level'] === 'custom' ? 'info' : 'secondary'); ?>">
                                <?php echo ucfirst(htmlspecialchars($trackingReqs['tracking_level'])); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <?php
                            $flags = [
                                ['key' => 'auto_clock_in', 'label' => 'Auto Clock-In', 'icon' => 'zap', 'override_key' => 'auto_clock_in'],
                                ['key' => 'require_clock_in', 'label' => 'Clock-In Required', 'icon' => 'clock', 'override_key' => 'clock_in'],
                                ['key' => 'require_gps', 'label' => 'GPS Required', 'icon' => 'map-pin', 'override_key' => 'gps'],
                                ['key' => 'require_photos', 'label' => 'Photos Required', 'icon' => 'camera', 'override_key' => 'photos'],
                            ];
                            foreach ($flags as $flag):
                                $active = $trackingReqs[$flag['key']];
                                $source = $trackingReqs['source'][$flag['override_key']];
                            ?>
                            <div class="mw-detail-row">
                                <span class="mw-detail-label">
                                    <i data-feather="<?php echo $flag['icon']; ?>" style="width:14px;height:14px;margin-right:4px;"></i>
                                    <?php echo $flag['label']; ?>
                                </span>
                                <span class="mw-detail-value">
                                    <?php if ($active): ?>
                                        <span class="badge badge-success">Yes</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">No</span>
                                    <?php endif; ?>
                                    <?php if ($source === 'plan'): ?>
                                        <span class="mw-tracking-override-badge">Override</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>

                            <hr class="my-3">

                            <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#trackingOverrideForm">
                                Edit Overrides
                            </button>

                            <div class="collapse mt-3" id="trackingOverrideForm">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                                    <input type="hidden" name="action" value="update_tracking">

                                    <?php
                                    // Current raw override values from the plan
                                    $rawOverrides = [
                                        'tracking_level_override' => $plan['tracking_level_override'] ?? null,
                                        'auto_clock_in_override' => $plan['auto_clock_in_override'] ?? null,
                                        'require_clock_in_override' => $plan['require_clock_in_override'] ?? null,
                                        'require_gps_override' => $plan['require_gps_override'] ?? null,
                                        'require_photos_override' => $plan['require_photos_override'] ?? null,
                                    ];
                                    ?>

                                    <div class="form-group">
                                        <label>Tracking Level</label>
                                        <select class="form-control form-control-sm" name="tracking_level_override">
                                            <option value="inherit" <?php echo $rawOverrides['tracking_level_override'] === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="standard" <?php echo $rawOverrides['tracking_level_override'] === 'standard' ? 'selected' : ''; ?>>Standard</option>
                                            <option value="heightened" <?php echo $rawOverrides['tracking_level_override'] === 'heightened' ? 'selected' : ''; ?>>Heightened</option>
                                            <option value="custom" <?php echo $rawOverrides['tracking_level_override'] === 'custom' ? 'selected' : ''; ?>>Custom</option>
                                        </select>
                                    </div>

                                    <?php $aciVal = $rawOverrides['auto_clock_in_override']; ?>
                                    <div class="form-group">
                                        <label>Auto Clock-In <small class="text-muted">(fixed-price / maintenance)</small></label>
                                        <select class="form-control form-control-sm" name="auto_clock_in_override">
                                            <option value="inherit" <?php echo $aciVal === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="1" <?php echo $aciVal !== null && (int)$aciVal === 1 ? 'selected' : ''; ?>>Enabled</option>
                                            <option value="0" <?php echo $aciVal !== null && (int)$aciVal === 0 ? 'selected' : ''; ?>>Disabled</option>
                                        </select>
                                    </div>

                                    <?php
                                    $overrideFlags = [
                                        ['name' => 'require_clock_in_override', 'label' => 'Clock-In'],
                                        ['name' => 'require_gps_override', 'label' => 'GPS'],
                                        ['name' => 'require_photos_override', 'label' => 'Photos'],
                                    ];
                                    foreach ($overrideFlags as $of):
                                        $val = $rawOverrides[$of['name']];
                                    ?>
                                    <div class="form-group">
                                        <label><?php echo $of['label']; ?></label>
                                        <select class="form-control form-control-sm" name="<?php echo $of['name']; ?>">
                                            <option value="inherit" <?php echo $val === null ? 'selected' : ''; ?>>Inherit from product</option>
                                            <option value="1" <?php echo $val !== null && (int)$val === 1 ? 'selected' : ''; ?>>Required</option>
                                            <option value="0" <?php echo $val !== null && (int)$val === 0 ? 'selected' : ''; ?>>Not required</option>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>

                                    <button type="submit" class="btn btn-sm btn-primary">Save Overrides</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Services Included (Plan Line Items)
                 ══════════════════════════════════════════════════════ -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Services Included</h5>
                    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="showModal('editItemsModal')">
                            <i data-feather="edit-2" style="width:12px;height:12px;"></i> Edit Items
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($planLineItems)): ?>
                <div class="card-body p-0">
                    <table class="mw-plan-items-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Description</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $itemsSubtotal = 0; ?>
                            <?php foreach ($planLineItems as $pli): ?>
                                <?php $itemsSubtotal += floatval($pli['line_total']); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pli['service_type']); ?></td>
                                    <td class="text-muted"><?php echo htmlspecialchars($pli['description'] ?: '-'); ?></td>
                                    <td class="text-right"><?php echo number_format(floatval($pli['quantity']), ($pli['quantity'] == intval($pli['quantity'])) ? 0 : 2); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($pli['unit_price']); ?></td>
                                    <td class="text-right"><?php echo formatCurrency($pli['line_total']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-right">Per Visit Total</td>
                                <td class="text-right"><?php echo formatCurrency($itemsSubtotal); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if ($plan['quote_id']): ?>
                    <div class="card-footer text-muted small">
                        From quote <a href="../quotes/view.php?id=<?php echo (int)$plan['quote_id']; ?>"><?php echo htmlspecialchars($plan['quote_number'] ?? ''); ?></a>
                    </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="card-body text-center text-muted py-3">
                    <p class="mb-0 small">No line items. Click "Edit Items" to add services.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 SECTION 3: Visits List
                 ══════════════════════════════════════════════════════ -->

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Visits
                        <small class="text-muted">(<?php echo count($visits); ?> total)</small>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary mw-visit-filter active" data-filter="all">All</button>
                        <button type="button" class="btn btn-sm btn-outline-primary mw-visit-filter" data-filter="upcoming">Upcoming</button>
                        <button type="button" class="btn btn-sm btn-outline-success mw-visit-filter" data-filter="completed">Completed</button>
                        <?php if ($plan['status'] === 'active'): ?>
                        <button type="button" class="btn btn-sm btn-warning ml-2" onclick="openLogPastVisitModal()"
                                title="Record a service that was done but not logged">
                            + Log Past Visit
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                $today = date('Y-m-d');
                $overdueCount = 0;
                foreach ($visits as $v) {
                    if ($v['status'] === 'scheduled' && ($v['scheduled_date'] ?? '') < $today) {
                        $overdueCount++;
                    }
                }
                ?>
                <?php
                $gpsConflictCount = 0;
                foreach ($visits as $v) {
                    if ($v['status'] === 'scheduled' && !empty($v['scheduled_date'])
                        && $v['scheduled_date'] < $today && !empty($gpsConflictDates[$v['scheduled_date']])) {
                        $gpsConflictCount++;
                    }
                }
                ?>
                <?php if ($gpsConflictCount > 0): ?>
                <div class="alert alert-danger mb-0 rounded-0 border-left-0 border-right-0 d-flex align-items-center" style="border-radius:0!important;">
                    <i data-feather="alert-octagon" style="width:16px;height:16px;margin-right:8px;flex-shrink:0;color:#dc3545;"></i>
                    <span>
                        <strong><?php echo $gpsConflictCount; ?> GPS conflict<?php echo $gpsConflictCount !== 1 ? 's' : ''; ?></strong>
                        — GPS shows the crew stopped at this property on <?php echo $gpsConflictCount !== 1 ? 'these dates' : 'this date'; ?> but the visit<?php echo $gpsConflictCount !== 1 ? 's were' : ' was'; ?> never recorded.
                        Use <strong>+ Log Past Visit</strong> to add the missing record.
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($overdueCount > $gpsConflictCount): ?>
                <div class="alert alert-warning mb-0 rounded-0 border-left-0 border-right-0 d-flex align-items-center" style="border-radius:0!important;">
                    <i data-feather="alert-triangle" style="width:16px;height:16px;margin-right:8px;flex-shrink:0;"></i>
                    <span>
                        <strong><?php echo ($overdueCount - $gpsConflictCount); ?> past-due visit<?php echo ($overdueCount - $gpsConflictCount) !== 1 ? 's' : ''; ?></strong>
                        — scheduled but not yet recorded.
                        Use <strong>Start</strong> to complete them or <strong>+ Log Past Visit</strong> if they were done but not tracked.
                    </span>
                </div>
                <?php endif; ?>
                <div class="card-body p-0">
                    <?php if (empty($visits)): ?>
                        <div class="p-4 text-center text-muted">
                            <p>No visits generated yet.</p>
                            <?php if ($plan['status'] === 'active'): ?>
                                <p class="small">Visits are generated automatically based on the recurrence pattern and horizon.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="mw-table" id="visitsTable">
                                <thead>
                                    <tr>
                                        <th>Visit #</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Crew</th>
                                        <th>Status</th>
                                        <th>Amount</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visits as $visit):
                                        $isUpcoming = ($visit['scheduled_date'] >= date('Y-m-d') && in_array($visit['status'], ['scheduled', 'in_progress']));
                                        $isCompleted = ($visit['status'] === 'completed');
                                        $rowClass = $isUpcoming ? 'mw-visit-upcoming' : ($isCompleted ? 'mw-visit-completed' : 'mw-visit-other');
                                    ?>
                                        <tr class="<?php echo $rowClass; ?>" data-visit-status="<?php echo htmlspecialchars($visit['status']); ?>">
                                            <td>
                                                <span class="font-weight-bold">
                                                    <?php echo htmlspecialchars($visit['visit_number']); ?>
                                                </span>
                                                <br>
                                                <small class="text-muted">#<?php echo (int)$visit['sequence_index']; ?></small>
                                            </td>
                                            <td>
                                                <?php echo formatDate($visit['scheduled_date']); ?>
                                                <?php if ($visit['scheduled_date'] === date('Y-m-d')): ?>
                                                    <span class="mw-badge-status" style="background: var(--mw-orange); color: #fff; font-size: 0.65rem; padding: 2px 6px;">TODAY</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['scheduled_time_start']): ?>
                                                    <?php echo date('g:i A', strtotime($visit['scheduled_time_start'])); ?>
                                                    <?php if ($visit['scheduled_time_end']): ?>
                                                        <br><small class="text-muted">to <?php echo date('g:i A', strtotime($visit['scheduled_time_end'])); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $vCrew = $visitCrewMap[$visit['id']] ?? [];
                                                if (!empty($vCrew)) {
                                                    $names = array_column($vCrew, 'full_name');
                                                    echo htmlspecialchars(count($names) > 2
                                                        ? $names[0] . ' +' . (count($names) - 1)
                                                        : implode(', ', $names));
                                                } else {
                                                    echo htmlspecialchars($visit['crew_name'] ?? 'Unassigned');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['status'] === 'completed' && empty($visit['invoice_id']) && empty($plan['contract_id'])): ?>
                                                    <a href="/crm/invoices/create.php?visit_id=<?php echo (int)$visit['id']; ?>"
                                                       class="mw-unbilled-pulse" title="Invoice not raised — click to fix">
                                                        <span class="mw-unbilled-dot"></span>
                                                        Invoice!
                                                    </a>
                                                <?php else: ?>
                                                    <?php echo getStatusBadge($visit['status'], 'visit'); ?>
                                                    <?php if ($visit['status'] === 'scheduled' && !empty($visit['scheduled_date']) && $visit['scheduled_date'] < $today): ?>
                                                        <?php if (!empty($gpsConflictDates[$visit['scheduled_date']])): ?>
                                                            <span class="badge badge-danger ml-1"
                                                                  title="GPS shows the crew stopped near this property on <?php echo htmlspecialchars($visit['scheduled_date']); ?> but the visit was never recorded">
                                                                <i data-feather="map-pin" style="width:10px;height:10px;vertical-align:middle;"></i>
                                                                GPS Conflict
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-warning ml-1" title="This visit is past its scheduled date and has not been recorded">Overdue</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <?php if ($visit['photo_count'] > 0): ?>
                                                    <small class="text-muted ml-1" title="<?php echo (int)$visit['photo_count']; ?> photos">
                                                        <i data-feather="camera" style="width: 12px; height: 12px;"></i>
                                                        <?php echo (int)$visit['photo_count']; ?>
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($visit['note_count'] > 0): ?>
                                                    <small class="text-muted ml-1" title="<?php echo (int)$visit['note_count']; ?> notes">
                                                        <i data-feather="message-square" style="width: 12px; height: 12px;"></i>
                                                        <?php echo (int)$visit['note_count']; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($visit['actual_amount']): ?>
                                                    <?php echo formatCurrency($visit['actual_amount']); ?>
                                                <?php elseif ($plan['price_per_visit']): ?>
                                                    <span class="text-muted"><?php echo formatCurrency($plan['price_per_visit']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right">
                                                <?php if ($visit['status'] === 'scheduled'): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                                            onclick="openEditVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', '<?php echo $visit['scheduled_date']; ?>', '<?php echo $visit['scheduled_time_start'] ?? ''; ?>', '<?php echo $visit['scheduled_time_end'] ?? ''; ?>')"
                                                            title="Edit visit">
                                                        <i data-feather="edit-2" style="width: 12px; height: 12px;"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="visit_id" value="<?php echo (int)$visit['id']; ?>">
                                                        <button type="submit" name="action" value="start_visit"
                                                                class="btn btn-sm btn-info" title="Start this visit">
                                                            Start
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            onclick="openSkipModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Skip this visit">
                                                        Skip
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-info"
                                                            onclick="openWeatherModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Weather delay">
                                                        <i data-feather="cloud-rain" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                    <?php if (userHasPermission('jobs.edit')): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                                            onclick="openDeleteVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Delete this visit">
                                                        <i data-feather="trash-2" style="width: 14px; height: 14px;"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php elseif ($visit['status'] === 'in_progress'): ?>
                                                    <button type="button" class="btn btn-sm btn-success"
                                                            onclick="openCompleteModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', <?php echo floatval($plan['price_per_visit'] ?? 0); ?>)"
                                                            title="Complete this visit">
                                                        Complete
                                                    </button>
                                                <?php elseif ($visit['status'] === 'completed'): ?>
                                                    <span class="text-muted small mr-2">
                                                        <?php echo $visit['completed_at'] ? formatDateTime($visit['completed_at']) : ''; ?>
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            onclick="openEditVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', '<?php echo $visit['scheduled_date']; ?>', '<?php echo $visit['scheduled_time_start'] ?? ''; ?>', '<?php echo $visit['scheduled_time_end'] ?? ''; ?>')"
                                                            title="Edit visit">
                                                        <i data-feather="edit-2" style="width: 12px; height: 12px;"></i>
                                                    </button>
                                                    <?php if (!empty($visit['invoice_id'])): ?>
                                                        <a href="/crm/invoices/view.php?id=<?php echo (int)$visit['invoice_id']; ?>"
                                                           class="btn btn-sm btn-outline-success" title="View invoice">
                                                            <i data-feather="file-text" style="width:12px;height:12px;"></i>
                                                            Invoiced
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (userHasPermission('admin')): ?>
                                                        <button type="button" class="btn btn-sm btn-outline-danger ml-1"
                                                                onclick="openDeleteVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                                title="Delete completed visit (admin)">
                                                            <i data-feather="trash-2" style="width: 12px; height: 12px;"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php elseif ($visit['status'] === 'skipped'): ?>
                                                    <span class="text-muted small mr-2">Skipped</span>
                                                    <?php if (userHasPermission('jobs.edit')): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary mr-1"
                                                            onclick="openEditVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>', '<?php echo $visit['scheduled_date']; ?>', '<?php echo $visit['scheduled_time_start'] ?? ''; ?>', '<?php echo $visit['scheduled_time_end'] ?? ''; ?>')"
                                                            title="Edit visit date/time">
                                                        <i data-feather="edit-2" style="width: 12px; height: 12px;"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-success"
                                                            onclick="openMarkCompletedModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Restore this visit and mark it completed">
                                                        Mark Completed
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger ml-1"
                                                            onclick="openDeleteVisitModal(<?php echo (int)$visit['id']; ?>, '<?php echo htmlspecialchars($visit['visit_number'], ENT_QUOTES); ?>')"
                                                            title="Delete this visit">
                                                        <i data-feather="trash-2" style="width: 12px; height: 12px;"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                <?php elseif ($visit['status'] === 'weather'): ?>
                                                    <span class="text-muted small">Weather</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php if ($visit['completion_notes']): ?>
                                            <tr class="<?php echo $rowClass; ?> mw-visit-note-row">
                                                <td></td>
                                                <td colspan="6">
                                                    <small class="text-muted">
                                                        <i data-feather="message-circle" style="width: 12px; height: 12px;"></i>
                                                        <?php echo htmlspecialchars($visit['completion_notes']); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Time Log + GPS Map Row
                 ══════════════════════════════════════════════════════ -->
            <div class="row mb-4">
                <!-- Time Log -->
                <div class="<?php echo $hasPropCoords ? 'col-lg-8' : 'col-12'; ?>">
                    <div class="card h-100" id="timeLogCard">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i data-feather="clock" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px;"></i>
                                Time Log
                                <span class="badge badge-secondary ml-2" id="timeLogTotal" style="display:none;"></span>
                            </h5>
                            <?php if (userHasPermission('jobs.edit')): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showModal('addTimeEntryModal')">
                                <i data-feather="plus" style="width:13px;height:13px;"></i> Add Entry
                            </button>
                            <?php endif; ?>
                        </div>
                        <div id="timeLogBody">
                            <div class="card-body text-center py-4 text-muted" id="timeLogLoading">
                                <div class="spinner-border spinner-border-sm text-primary mr-2" role="status"></div>
                                Loading time entries…
                            </div>
                        </div>
                    </div>
                </div>
                <?php if ($hasPropCoords): ?>
                <!-- GPS On-site Map -->
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i data-feather="map-pin" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px;"></i>
                                On-site GPS Tracks
                            </h5>
                        </div>
                        <div class="card-body p-0">
                            <div id="gpsTrackMap" style="height:380px;width:100%;border-radius:0 0 4px 4px;"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Photos Section — all photos from this plan's visits
                 ══════════════════════════════════════════════════════ -->
            <?php
            $planPhotos = [];
            try {
                $pStmt = $db->prepare("
                    SELECT vp.id, vp.visit_id, vp.photo_type, vp.filename, vp.caption,
                           vp.thumb_path, vp.grid_path, vp.view_path,
                           vp.uploaded_at, vp.uploaded_by_name,
                           jv.scheduled_date, jv.status AS visit_status
                    FROM visit_photos vp
                    JOIN job_visits jv ON jv.id = vp.visit_id
                    WHERE jv.plan_id = ?
                      AND vp.deleted_at IS NULL
                    ORDER BY jv.scheduled_date DESC, vp.uploaded_at ASC
                    LIMIT 60
                ");
                $pStmt->execute([$planId]);
                $planPhotos = $pStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $planPhotos = []; }

            // Group by visit date for visual grouping
            $photosByDate = [];
            foreach ($planPhotos as $ph) {
                $date = $ph['scheduled_date'] ?: 'undated';
                if (!isset($photosByDate[$date])) $photosByDate[$date] = [];
                $photosByDate[$date][] = $ph;
            }
            ?>
            <div class="card mb-4" id="planPhotosCard">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i data-feather="camera" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px;"></i>
                        Photos
                        <?php if (!empty($planPhotos)): ?>
                            <span class="badge badge-secondary ml-2"><?php echo count($planPhotos); ?></span>
                        <?php endif; ?>
                    </h5>
                    <?php if (!empty($planPhotos)): ?>
                    <a href="/crm/photos_appstack.php?plan_id=<?php echo (int)$planId; ?>" class="btn btn-sm btn-outline-secondary">
                        <i data-feather="external-link" style="width:13px;height:13px;"></i> View all
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($planPhotos)): ?>
                        <div class="text-center text-muted py-3">
                            <i data-feather="image" style="width:28px;height:28px;opacity:0.5;"></i>
                            <p class="mt-2 mb-0 small">No photos uploaded for this job yet.</p>
                            <p class="small text-muted">Photos taken by crew during visits will appear here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($photosByDate as $date => $dayPhotos): ?>
                            <div class="mw-plan-photo-day mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-muted small text-uppercase" style="letter-spacing:0.5px;">
                                        <?php echo $date === 'undated' ? 'No date' : date('D, M j, Y', strtotime($date)); ?>
                                    </strong>
                                    <span class="text-muted small"><?php echo count($dayPhotos); ?> photo<?php echo count($dayPhotos) === 1 ? '' : 's'; ?></span>
                                </div>
                                <div class="mw-plan-photo-grid">
                                    <?php foreach ($dayPhotos as $ph):
                                        $url = $ph['thumb_path'] ?: $ph['grid_path'] ?: ('/uploads/photos/' . $ph['filename']);
                                        $fullUrl = $ph['view_path'] ?: ('/uploads/photos/' . $ph['filename']);
                                        $typeLabel = ucfirst($ph['photo_type'] ?? 'other');
                                    ?>
                                    <a href="<?php echo h($fullUrl); ?>" target="_blank" class="mw-plan-photo-tile" title="<?php echo h($typeLabel); ?> &middot; <?php echo h($ph['uploaded_by_name'] ?? 'Crew'); ?>">
                                        <img src="<?php echo h($url); ?>" alt="" loading="lazy">
                                        <span class="mw-plan-photo-badge mw-pp-<?php echo h($ph['photo_type']); ?>"><?php echo h($typeLabel); ?></span>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Expenses Section
                 ══════════════════════════════════════════════════════ -->

            <div class="card mb-4" id="jobExpensesCard">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i data-feather="shopping-cart" style="width:15px;height:15px;vertical-align:-2px;margin-right:4px;"></i>
                        Expenses
                        <span class="badge badge-secondary ml-2" id="jobExpensesTotal" style="display:none;"></span>
                    </h5>
                    <a href="/crm/expenses_appstack.php" class="btn btn-sm btn-outline-secondary">
                        <i data-feather="external-link" style="width:13px;height:13px;"></i> Manage
                    </a>
                </div>
                <div id="jobExpensesBody">
                    <div class="card-body text-center py-4 text-muted" id="jobExpensesLoading">
                        <div class="spinner-border spinner-border-sm text-primary mr-2" role="status"></div>
                        Loading expenses…
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Reassign Expense Job Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="reassignExpenseModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">Move Expense to Another Job</h3>
                    <p class="text-muted small">Enter the Job Plan # to reassign this expense. Leave blank to unlink from any job.</p>
                    <input type="hidden" id="reassignExpenseId">
                    <div class="form-group">
                        <label class="form-label">Job Plan ID</label>
                        <input type="number" id="reassignJobId" class="form-control" placeholder="e.g. 42 (leave blank to unlink)">
                    </div>
                    <div class="mw-modal-actions">
                        <button type="button" class="btn btn-primary" onclick="doReassignExpense()">Move</button>
                        <button type="button" class="btn btn-secondary" onclick="hideModal('reassignExpenseModal')">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Delete Time Entry Confirm Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="deleteTimeEntryModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">Delete Time Entry?</h3>
                    <input type="hidden" id="delEntryId">
                    <p class="text-muted small mb-3">This will permanently remove the time entry for <strong id="delEntryDesc"></strong>. This cannot be undone.</p>
                    <div class="mw-modal-actions">
                        <button type="button" class="btn btn-danger" onclick="doDeleteTimeEntry()">Delete</button>
                        <button type="button" class="btn btn-secondary" onclick="hideModal('deleteTimeEntryModal')">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Delete Visit Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="deleteVisitModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">Delete Visit?</h3>
                    <p class="text-muted small mb-3">This will permanently remove <strong id="delVisitDesc"></strong> from this plan. This cannot be undone.</p>
                    <form method="POST" action="view.php?id=<?php echo (int)$planId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete_visit">
                        <input type="hidden" name="del_visit_id" id="delVisitId">
                        <div class="mw-modal-actions">
                            <button type="submit" class="btn btn-danger">Delete Visit</button>
                            <button type="button" class="btn btn-secondary" onclick="hideModal('deleteVisitModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Delete Plan Modal
                 ══════════════════════════════════════════════════════ -->
            <?php if ($canDeletePlan): ?>
            <div class="mw-modal-overlay" id="deletePlanModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title" style="color: var(--danger, #dc3545);">
                        <i data-feather="alert-triangle" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                        Delete Plan?
                    </h3>
                    <p class="text-muted small mb-1">You are about to permanently delete:</p>
                    <p class="mb-3"><strong><?php echo htmlspecialchars($plan['plan_number']); ?></strong>
                        &mdash; <?php echo htmlspecialchars($plan['title'] ?? $plan['service_type']); ?></p>
                    <p class="text-muted small mb-3">All scheduled visits and line items will be removed. This cannot be undone.</p>
                    <form method="POST" action="view.php?id=<?php echo (int)$planId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete_plan">
                        <div class="mw-modal-actions">
                            <button type="submit" class="btn btn-danger">Yes, Delete Plan</button>
                            <button type="button" class="btn btn-secondary" onclick="hideModal('deletePlanModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════════════════
                 Add Manual Time Entry Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="addTimeEntryModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">
                        <i data-feather="plus-circle" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                        Add Time Entry
                    </h3>
                    <p class="text-muted small mb-3">Manually log time for a crew member against a visit on this plan.</p>
                    <form method="POST" action="view.php?id=<?php echo (int)$planId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_time_entry">
                        <div class="form-group">
                            <label class="form-label font-weight-bold">Visit</label>
                            <select name="te_visit_id" class="form-control" required>
                                <option value="">— select visit —</option>
                                <?php foreach ($visits as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>">
                                    <?php echo htmlspecialchars($v['visit_number']); ?>
                                    &mdash; <?php echo htmlspecialchars(date('M j, Y', strtotime($v['scheduled_date']))); ?>
                                    (<?php echo htmlspecialchars($v['status']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-weight-bold">Crew Member</label>
                            <select name="te_user_id" class="form-control" required>
                                <option value="">— select crew —</option>
                                <?php foreach ($staff as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['full_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-6">
                                <label class="form-label font-weight-bold">Clock In</label>
                                <input type="datetime-local" name="te_start_time" class="form-control" required
                                       step="60">
                            </div>
                            <div class="form-group col-6">
                                <label class="form-label font-weight-bold">Clock Out</label>
                                <input type="datetime-local" name="te_end_time" class="form-control" required
                                       step="60">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Notes <span class="text-muted">(optional)</span></label>
                            <input type="text" name="te_notes" class="form-control" placeholder="e.g. Manual entry — GPS confirmed on-site">
                        </div>
                        <div class="mw-modal-actions">
                            <button type="submit" class="btn btn-primary">Add Entry</button>
                            <button type="button" class="btn btn-secondary" onclick="hideModal('addTimeEntryModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Move Time Entry Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="moveTimeEntryModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">
                        <i data-feather="move" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                        Move Time Entry
                    </h3>
                    <p class="text-muted small mb-3">Reassign this time entry to a different visit. Enter the visit ID from any plan at this property.</p>
                    <form method="POST" action="view.php?id=<?php echo (int)$planId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="move_time_entry">
                        <input type="hidden" name="mv_entry_id" id="mvEntryId">
                        <div class="form-group">
                            <label class="form-label font-weight-bold">Current Entry</label>
                            <div class="form-control-plaintext small text-muted" id="mvEntryDesc">—</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-weight-bold">Move to Visit</label>
                            <select name="mv_visit_id" class="form-control" required>
                                <option value="">— select visit —</option>
                                <?php foreach ($visits as $v): ?>
                                <option value="<?php echo (int)$v['id']; ?>">
                                    <?php echo htmlspecialchars($v['visit_number']); ?>
                                    &mdash; <?php echo htmlspecialchars(date('M j, Y', strtotime($v['scheduled_date']))); ?>
                                    (<?php echo htmlspecialchars($v['status']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Only visits on this plan are listed. For a different plan, first move the crew member there manually.</small>
                        </div>
                        <div class="mw-modal-actions">
                            <button type="submit" class="btn btn-primary">Move Entry</button>
                            <button type="button" class="btn btn-secondary" onclick="hideModal('moveTimeEntryModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Edit Time Entry Modal
                 ══════════════════════════════════════════════════════ -->
            <div class="mw-modal-overlay" id="editTimeEntryModal">
                <div class="mw-modal">
                    <h3 class="mw-modal-title">
                        <i data-feather="edit-2" style="width:16px;height:16px;vertical-align:-2px;margin-right:6px;"></i>
                        Edit Time Entry
                    </h3>
                    <p class="text-muted small mb-3" id="edEntryDesc">Adjust the clock-in and clock-out times for this entry.</p>
                    <form method="POST" action="view.php?id=<?php echo (int)$planId; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="edit_time_entry">
                        <input type="hidden" name="ed_entry_id" id="edEntryId">
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label class="form-label font-weight-bold">Clock In</label>
                                <input type="datetime-local" name="ed_start_time" id="edStartTime" class="form-control" required>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="form-label font-weight-bold">Clock Out</label>
                                <input type="datetime-local" name="ed_end_time" id="edEndTime" class="form-control" required>
                                <div id="edGpsHint" class="small text-muted mt-1" style="display:none;"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label font-weight-bold">Notes <small class="text-muted font-weight-normal">(optional)</small></label>
                            <input type="text" name="ed_notes" id="edNotes" class="form-control" placeholder="Reason for edit, e.g. timer failed to stop">
                        </div>
                        <div class="mw-modal-actions">
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <button type="button" class="btn btn-secondary" onclick="hideModal('editTimeEntryModal')">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ══════════════════════════════════════════════════════
                 Plan Notes Section
                 ══════════════════════════════════════════════════════ -->

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Plan Notes</h5>
                    <button type="button" class="btn btn-sm btn-secondary" onclick="showModal('noteModal')">
                        + Add Note
                    </button>
                </div>
                <div class="card-body">
                    <?php if (empty($notes)): ?>
                        <p class="text-muted small mb-0">No notes yet. Add instructions, customer requests, or internal notes.</p>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="mw-note-item">
                                <div class="mw-note-header">
                                    <span class="mw-note-type"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $note['note_type']))); ?></span>
                                    <span>
                                        <?php echo htmlspecialchars($note['full_name'] ?? 'System'); ?>
                                        &mdash;
                                        <?php echo formatDateTime($note['created_at']); ?>
                                    </span>
                                </div>
                                <div class="mw-note-content">
                                    <?php echo nl2br(htmlspecialchars($note['content'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>


    <!-- ══════════════════════════════════════════════════════
         MODALS
         ══════════════════════════════════════════════════════ -->

    <!-- Pause Plan Modal -->
    <div class="mw-modal-overlay" id="pauseModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Pause Plan</h3>
            <p class="text-muted small">Pausing will cancel all future scheduled visits. You can resume at any time.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="pause_plan">

                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <textarea name="pause_reason" class="form-control" rows="2"
                              placeholder="Why is this plan being paused?"></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-warning">Pause Plan</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('pauseModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Complete Visit Modal -->
    <div class="mw-modal-overlay" id="completeModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Complete Visit <span id="completeVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="complete_visit">
                <input type="hidden" name="visit_id" id="completeVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Actual Amount ($)</label>
                    <input type="number" name="actual_amount" id="completeActualAmount" class="form-control" step="0.01" min="0">
                    <small class="form-text text-muted">Leave blank to use the plan's default price per visit.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Completion Notes</label>
                    <textarea name="completion_notes" class="form-control" rows="3"
                              placeholder="Any notes about the completed work..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-success">Complete Visit</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('completeModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mark Completed Modal (for skipped/cancelled visits) -->
    <div class="mw-modal-overlay" id="markCompletedModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Mark Visit Completed</h3>
            <p>Mark <strong id="markCompletedVisitNumber"></strong> as completed? You can then invoice it.</p>
            <form method="POST" id="markCompletedForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="complete_skipped_visit">
                <input type="hidden" name="visit_id" id="markCompletedVisitId" value="">
                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-success">Mark Completed</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="hideModal('markCompletedModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Skip Visit Modal -->
    <div class="mw-modal-overlay" id="skipModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Skip Visit <span id="skipVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="skip_visit">
                <input type="hidden" name="visit_id" id="skipVisitId" value="">

                <div class="form-group mb-2">
                    <label class="form-label">Quick reason</label>
                    <div id="skipReasonChips" style="display:flex;flex-wrap:wrap;gap:6px;">
                        <?php foreach ([
                            'No growth',
                            'Street closed',
                            'Customer request',
                            'Weather',
                            'Gate locked',
                            'Dog in yard',
                            'Property inaccessible',
                            'Duplicate visit',
                        ] as $chip): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary mw-skip-chip"
                                data-reason="<?php echo htmlspecialchars($chip); ?>"
                                onclick="selectSkipChip(this)">
                            <?php echo htmlspecialchars($chip); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes <small class="text-muted">(optional — adds detail to the reason above)</small></label>
                    <textarea id="skipReasonText" name="skip_reason" class="form-control" rows="2"
                              placeholder="Any additional context…"></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-secondary">Skip Visit</button>
                    <button type="button" class="btn btn-outline-secondary" onclick="hideModal('skipModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Weather Visit Modal -->
    <div class="mw-modal-overlay" id="weatherModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Weather Delay <span id="weatherVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="weather_visit">
                <input type="hidden" name="visit_id" id="weatherVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Details (optional)</label>
                    <textarea name="weather_reason" class="form-control" rows="2"
                              placeholder="e.g., Heavy rain, snow storm..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-info">Mark Weather Delay</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('weatherModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Log Past Visit Modal -->
    <div class="mw-modal-overlay" id="logPastVisitModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Log Past Visit</h3>
            <p class="text-muted small mb-3">Record a service that was completed but not logged. The visit will be marked as <strong>completed</strong> and can be invoiced immediately.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="log_past_visit">

                <div class="form-group">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="lv_date" id="lvDate" class="form-control" required
                           max="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="lv_time_start" class="form-control"
                               value="<?php echo htmlspecialchars($plan['default_time_start'] ?? ''); ?>">
                    </div>
                    <div class="form-group col-6">
                        <label class="form-label">End Time</label>
                        <input type="time" name="lv_time_end" class="form-control"
                               value="<?php echo htmlspecialchars($plan['default_time_end'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Amount ($)</label>
                    <input type="number" name="lv_amount" class="form-control" step="0.01" min="0"
                           value="<?php echo htmlspecialchars($plan['price_per_visit'] ?? ''); ?>"
                           placeholder="Leave blank to use plan default">
                    <small class="form-text text-muted">Plan default: $<?php echo number_format(floatval($plan['price_per_visit'] ?? 0), 2); ?> excl. GST</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="lv_notes" class="form-control" rows="2"
                              placeholder="e.g., Service completed — not recorded at the time"></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-warning">Log Completed Visit</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('logPastVisitModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Note Modal -->
    <div class="mw-modal-overlay" id="noteModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Add Plan Note</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="add_note">

                <div class="form-group">
                    <label class="form-label">Note Type</label>
                    <select name="note_type" class="form-control">
                        <option value="general">General</option>
                        <option value="customer_request">Customer Request</option>
                        <option value="issue">Issue</option>
                        <option value="follow_up">Follow-up</option>
                        <option value="internal">Internal</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="note_content" class="form-control" required rows="4"
                              placeholder="Enter note..."></textarea>
                </div>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Add Note</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('noteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>


    <!-- Edit Plan Modal -->
    <?php if (in_array($plan['status'], ['active', 'paused'])): ?>
    <div class="mw-modal-overlay" id="editPlanModal">
        <div class="mw-modal mw-modal-wide">
            <h3 class="mw-modal-title">Edit Plan <?php echo htmlspecialchars($plan['plan_number']); ?></h3>
            <form method="POST" id="editPlanForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_plan">

                <div class="mw-form-row">
                    <div class="mw-form-group" style="flex:2;">
                        <label class="form-label">Plan Title *</label>
                        <input type="text" name="edit_title" class="form-control" required
                               value="<?php echo htmlspecialchars($plan['title'] ?? ''); ?>">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Service Type</label>
                        <select name="edit_service_type" class="form-control">
                            <?php
                            // Load from DB with fallback to hardcoded list
                            $serviceTypes = ['landscaping'=>'Landscaping','lawn_care'=>'Lawn Care','snow_removal'=>'Snow Removal','hedge_trimming'=>'Hedge Trimming','garden_maintenance'=>'Garden Maintenance','seasonal_cleanup'=>'Seasonal Cleanup'];
                            try {
                                $stRows = $db->query("SELECT slug, label FROM service_types WHERE is_active = 1 ORDER BY sort_order ASC, label ASC")->fetchAll(PDO::FETCH_ASSOC);
                                if ($stRows) {
                                    $serviceTypes = [];
                                    foreach ($stRows as $stRow) { $serviceTypes[$stRow['slug']] = $stRow['label']; }
                                }
                            } catch (Exception $e) { /* use fallback */ }
                            foreach ($serviceTypes as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo $plan['service_type'] === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Description</label>
                    <textarea name="edit_description" class="form-control" rows="3"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></textarea>
                </div>

                <hr class="my-3">
                <h6>Scheduling</h6>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Plan Type</label>
                        <select name="plan_type" id="editPlanType" class="form-control" onchange="toggleEditRecurring()">
                            <option value="one_time" <?php echo !$plan['is_recurring'] ? 'selected' : ''; ?>>One-Time</option>
                            <option value="recurring" <?php echo $plan['is_recurring'] ? 'selected' : ''; ?>>Recurring</option>
                        </select>
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="edit_start_date" id="editStartDateInput" class="form-control"
                               value="<?php echo $plan['plan_start_date'] ?? ''; ?>"
                               oninput="editUpdateRevenuePreview()">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="edit_end_date" id="editEndDateInput" class="form-control"
                               value="<?php echo $plan['plan_end_date'] ?? ''; ?>"
                               oninput="editUpdateRevenuePreview()">
                        <small class="text-muted">Blank = ongoing</small>
                    </div>
                </div>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Default Start Time</label>
                        <input type="time" name="edit_time_start" id="editTimeStartInput" class="form-control"
                               value="<?php echo $plan['default_time_start'] ?? ''; ?>"
                               oninput="editCalcDurationFromTime()">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Default End Time</label>
                        <input type="time" name="edit_time_end" id="editTimeEndInput" class="form-control"
                               value="<?php echo $plan['default_time_end'] ?? ''; ?>"
                               oninput="editCalcDurationFromTime()">
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Duration (min) <span id="editDurationHrsLabel" class="mw-duration-hrs-label"></span></label>
                        <input type="number" name="edit_duration" id="editDurationInput" class="form-control"
                               value="<?php echo (int)$plan['estimated_duration_minutes']; ?>" min="15" step="15"
                               oninput="editCalcContractHelper(); editUpdateRevenuePreview();">
                    </div>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Crew Assignment</label>
                    <div class="mw-crew-wrapper">
                        <div class="mw-crew-chips" id="editCrewChips">
                            <?php
                            $existingCrew = getPlanCrewAssignments($planId);
                            // Fallback: if no multi-crew rows, use legacy default_crew_id
                            if (empty($existingCrew) && !empty($plan['default_crew_id']) && !empty($plan['default_crew_name'])) {
                                $existingCrew = [['user_id' => (int)$plan['default_crew_id'], 'full_name' => $plan['default_crew_name'], 'role' => 'lead']];
                            }
                            foreach ($existingCrew as $ec):
                            ?>
                                <span class="mw-crew-chip <?php echo $ec['role'] === 'lead' ? 'mw-crew-lead' : ''; ?>">
                                    <?php echo htmlspecialchars($ec['full_name']); ?><?php echo $ec['role'] === 'lead' ? ' (Lead)' : ''; ?>
                                    <button type="button" class="mw-crew-chip-remove" onclick="editRemoveCrew(<?php echo (int)$ec['user_id']; ?>)">&times;</button>
                                    <input type="hidden" name="crew_ids[]" value="<?php echo (int)$ec['user_id']; ?>">
                                </span>
                            <?php endforeach; ?>
                            <button type="button" class="mw-crew-add-btn" onclick="editToggleCrewDropdown()">+ Assign</button>
                        </div>
                        <div class="mw-crew-dropdown" id="editCrewDropdown">
                            <?php foreach ($staff as $s): ?>
                                <div class="mw-crew-dropdown-item"
                                     data-id="<?php echo (int)$s['id']; ?>"
                                     data-name="<?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>"
                                     onclick="editAssignCrew(<?php echo (int)$s['id']; ?>, this.dataset.name)">
                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($s['role']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="hidden" name="default_crew_id" id="editDefaultCrewIdHidden" value="<?php echo (int)($plan['default_crew_id'] ?? 0); ?>">
                    <small class="text-muted">First person assigned is the crew lead.</small>
                </div>

                <div class="mw-form-group">
                    <label class="form-label">Horizon Days</label>
                    <input type="number" name="edit_horizon_days" class="form-control"
                           value="<?php echo (int)$plan['horizon_days']; ?>" min="7" max="90" style="width:120px;">
                </div>

                <?php
                // Determine the display recurrence pattern for the edit modal
                $editRecPattern = $plan['recurrence_pattern'] ?? 'weekly';
                $editIsCustom = false;
                if ($editRecPattern === 'custom' && (int)($plan['recurrence_interval'] ?? 1) === 1
                    && ($plan['recurrence_interval_unit'] ?? 'weeks') === 'days') {
                    $editRecPattern = 'daily';
                } elseif ($editRecPattern === 'weekly' && (int)($plan['recurrence_interval'] ?? 1) === 2) {
                    $editRecPattern = 'biweekly';
                } elseif ($editRecPattern === 'custom') {
                    $editIsCustom = true;
                }
                $editDow = $plan['recurrence_day_of_week'] ?? null;
                ?>

                <div id="editRecurringOptions" style="<?php echo $plan['is_recurring'] ? '' : 'display:none;'; ?>">
                    <h6 class="mt-3">Recurrence</h6>

                    <!-- Frequency picker -->
                    <div class="mw-freq-picker" id="editFreqPicker">
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'daily' ? 'active' : ''; ?>" data-freq="daily">Daily</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'weekly' ? 'active' : ''; ?>" data-freq="weekly">Weekly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'monthly' ? 'active' : ''; ?>" data-freq="monthly">Monthly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editRecPattern === 'yearly' ? 'active' : ''; ?>" data-freq="yearly">Yearly</button>
                        <button type="button" class="mw-freq-btn <?php echo $editIsCustom ? 'active' : ''; ?>" data-freq="custom">Custom</button>
                    </div>

                    <!-- Interval row -->
                    <div class="mw-interval-row" id="editIntervalRow">
                        <span class="mw-interval-label">Every</span>
                        <input type="number" name="recurrence_interval" id="editRecurrenceInterval"
                               class="form-control form-control-sm" value="<?php echo (int)($plan['recurrence_interval'] ?? 1); ?>" min="1" max="365">
                        <span class="mw-interval-label" id="editIntervalUnitLabel">
                            <?php
                            $unitLabels = ['daily'=>'day(s)','weekly'=>'week(s)','biweekly'=>'week(s)','monthly'=>'month(s)','yearly'=>'year(s)'];
                            echo $unitLabels[$editRecPattern] ?? 'week(s)';
                            ?>
                        </span>
                    </div>

                    <!-- Day-of-week picker -->
                    <div id="editDowPickerWrap" style="<?php echo in_array($editRecPattern, ['weekly','biweekly']) ? '' : 'display:none;'; ?>">
                        <label class="form-label mb-2">On</label>
                        <div class="mw-dow-picker" id="editDowPicker">
                            <?php
                                $dowLetters = ['S','M','T','W','T','F','S'];
                                $editDowArr = [];
                                if ($editDow !== null && $editDow !== '') {
                                    $editDowArr = array_filter(array_map('intval', explode(',', (string)$editDow)));
                                }
                            ?>
                            <?php for ($d = 0; $d <= 6; $d++): ?>
                                <button type="button" class="mw-dow-btn <?php echo in_array($d, $editDowArr) ? 'active' : ''; ?>" data-dow="<?php echo $d; ?>"><?php echo $dowLetters[$d]; ?></button>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Route Intelligence Hint — populated by JS on DOW/crew change -->
                    <div id="editRouteHint" style="display:none;"></div>

                    <!-- Custom unit picker -->
                    <div id="editCustomUnitWrap" style="<?php echo $editIsCustom ? '' : 'display:none;'; ?>" class="mb-2">
                        <select name="recurrence_interval_unit" id="editRecurrenceUnit" class="form-control form-control-sm" style="width:140px;">
                            <option value="days" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'days' ? 'selected' : ''; ?>>Days</option>
                            <option value="weeks" <?php echo ($plan['recurrence_interval_unit'] ?? 'weeks') === 'weeks' ? 'selected' : ''; ?>>Weeks</option>
                            <option value="months" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'months' ? 'selected' : ''; ?>>Months</option>
                            <option value="years" <?php echo ($plan['recurrence_interval_unit'] ?? '') === 'years' ? 'selected' : ''; ?>>Years</option>
                        </select>
                    </div>

                    <!-- Hidden fields synced by JS -->
                    <input type="hidden" name="recurrence_pattern" id="editRecurrencePatternHidden" value="<?php echo htmlspecialchars($editRecPattern); ?>">
                    <input type="hidden" name="recurrence_day_of_week" id="editRecurrenceDowHidden" value="<?php echo htmlspecialchars($editDow ?? ''); ?>">

                    <!-- Summary -->
                    <div class="mw-recurrence-summary" id="editRecurrenceSummary">
                        <i data-feather="repeat" style="width:14px;height:14px;"></i>
                        <span id="editRecurrenceSummaryText"><?php echo htmlspecialchars(describeRecurrence($plan)); ?></span>
                    </div>

                    <div class="mt-2">
                        <small class="text-muted"><i data-feather="alert-triangle" style="width:12px;height:12px;color:#F59E0B;"></i> Changing recurrence settings will cancel and regenerate future visits.</small>
                    </div>
                </div>

                <hr class="my-3">
                <h6>Pricing</h6>

                <div class="mw-form-row">
                    <div class="mw-form-group">
                        <label class="form-label">Pricing Model</label>
                        <select name="edit_pricing_model" class="form-control">
                            <?php
                            $pricingModels = ['per_visit'=>'Per Visit','monthly_flat'=>'Monthly Flat Rate','seasonal'=>'Seasonal','custom'=>'Custom'];
                            foreach ($pricingModels as $val => $label):
                            ?>
                                <option value="<?php echo $val; ?>" <?php echo ($plan['pricing_model'] ?? 'per_visit') === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mw-form-group">
                        <label class="form-label">Price Per Visit ($)</label>
                        <input type="number" name="edit_price_per_visit" id="editPricePerVisitInput" class="form-control" step="0.01" min="0"
                               value="<?php echo $plan['price_per_visit'] ?? ''; ?>"
                               oninput="editUpdateRevenuePreview()">
                    </div>
                </div>

                <!-- Contract Rate Calculator -->
                <div class="mw-contract-calc mw-contract-calc-open" id="editContractCalc">
                    <div class="mw-contract-calc-header" onclick="this.parentElement.classList.toggle('mw-contract-calc-open')">
                        <i data-feather="calculator" style="width:14px;height:14px;"></i>
                        <span>Rate Calculator</span>
                        <i data-feather="chevron-down" class="mw-calc-chevron" style="width:14px;height:14px;margin-left:auto;"></i>
                    </div>
                    <div class="mw-contract-calc-body">
                        <?php if ($contractTotal !== null): ?>
                        <!-- Auto-calculated from contract/quote value -->
                        <div class="mw-calc-auto-chain" id="editCalcAutoChain">
                            <!-- Populated by JS once days/dates are known -->
                        </div>
                        <div class="mw-calc-auto-source">
                            <i data-feather="link" style="width:11px;height:11px;"></i>
                            <?php echo $contractLabel ? htmlspecialchars($contractLabel) . ': ' : ''; ?>
                            <strong>$<?php echo number_format($contractTotal, 2); ?></strong><?php
                            $cycleDisplay = ['monthly'=>'/mo','per_visit'=>'/visit','annual'=>'/yr','seasonal'=>'','custom'=>''];
                            if ($contractCycle && isset($cycleDisplay[$contractCycle]) && $cycleDisplay[$contractCycle]) {
                                echo '<span class="mw-calc-cycle-label">' . $cycleDisplay[$contractCycle] . '</span>';
                            }
                            ?>
                        </div>
                        <details class="mw-calc-override-details mt-2">
                            <summary class="mw-calc-override-toggle">Override rate manually</summary>
                        <?php endif; ?>

                        <div class="mw-calc-toggle-row mt-2">
                            <button type="button" class="mw-calc-mode-btn active" onclick="editSetCalcMode('weekly')">Weekly Value</button>
                            <button type="button" class="mw-calc-mode-btn" onclick="editSetCalcMode('hourly')">Hourly Rate</button>
                        </div>
                        <div class="mw-form-row mt-2 mb-0">
                            <div class="mw-form-group mb-0">
                                <label class="form-label" id="editCalcInputLabel">Weekly Contract Value ($)</label>
                                <input type="number" id="editCalcInput" class="form-control" step="0.01" min="0"
                                       placeholder="e.g. 3840" oninput="editCalcContractHelper()">
                                <small class="text-muted" id="editCalcDivisorNote"></small>
                            </div>
                            <div class="mw-form-group mb-0 mw-calc-result-group">
                                <label class="form-label">→ Price Per Visit</label>
                                <div id="editCalcResult" class="mw-calc-result">—</div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-1" id="editCalcApplyBtn"
                                        style="display:none;" onclick="editApplyCalcResult()">Apply ↑</button>
                            </div>
                        </div>

                        <?php if ($contractTotal !== null): ?>
                        </details>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Revenue Preview -->
                <div class="mw-revenue-preview-panel" id="editRevenuePreview"></div>

                <hr class="my-3">

                <?php if (!empty($plan['contract_id'])): ?>
                    <?php
                    $contractTimingLabels = [
                        'after_visit'  => 'After Each Visit',
                        'end_of_month' => 'End of Month',
                        'upfront'      => 'Upfront / Prepay',
                    ];
                    $cInvoiceTiming = $plan['contract_invoice_timing'] ?? $plan['invoice_timing'] ?? 'after_visit';
                    $cTimingLabel   = $contractTimingLabels[$cInvoiceTiming] ?? 'After Each Visit';
                    ?>
                    <input type="hidden" name="edit_invoice_timing" value="<?php echo htmlspecialchars($cInvoiceTiming); ?>">
                    <div class="mw-contract-billing-note">
                        <i data-feather="file-text" style="width:14px;height:14px;flex-shrink:0;"></i>
                        <span>
                            <strong>Billing</strong> is managed by the contract —
                            <em><?php echo htmlspecialchars($cTimingLabel); ?></em>
                        </span>
                        <a href="/crm/contracts/view.php?id=<?php echo (int)$plan['contract_id']; ?>" class="mw-contract-billing-link">
                            <?php echo htmlspecialchars($plan['contract_number'] ?? 'View contract'); ?>
                            <i data-feather="external-link" style="width:11px;height:11px;margin-left:3px;"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <h6>Billing</h6>
                    <div class="mw-invoice-timing-selector">
                        <?php
                        $invoiceTiming = $plan['invoice_timing'] ?? 'after_visit';
                        $timingOptions = [
                            'after_visit' => [
                                'label' => 'After Each Visit',
                                'desc'  => 'Invoice sent when each visit is marked complete',
                                'icon'  => 'check-circle',
                            ],
                            'end_of_month' => [
                                'label' => 'End of Month',
                                'desc'  => 'All visits grouped into one invoice at month end',
                                'icon'  => 'calendar',
                            ],
                            'upfront' => [
                                'label' => 'Upfront / Prepay',
                                'desc'  => 'Invoice sent before service begins',
                                'icon'  => 'credit-card',
                            ],
                        ];
                        foreach ($timingOptions as $val => $opt): ?>
                        <label class="mw-timing-option <?php echo $invoiceTiming === $val ? 'mw-timing-active' : ''; ?>">
                            <input type="radio" name="edit_invoice_timing" value="<?php echo $val; ?>"
                                   <?php echo $invoiceTiming === $val ? 'checked' : ''; ?>
                                   onchange="document.querySelectorAll('.mw-timing-option').forEach(el=>el.classList.remove('mw-timing-active'));this.closest('.mw-timing-option').classList.add('mw-timing-active')">
                            <span class="mw-timing-icon"><i data-feather="<?php echo $opt['icon']; ?>"></i></span>
                            <span class="mw-timing-text">
                                <strong><?php echo $opt['label']; ?></strong>
                                <small><?php echo $opt['desc']; ?></small>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editPlanModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Line Items Modal -->
    <div class="mw-modal-overlay" id="editItemsModal">
        <div class="mw-modal mw-modal-wide">
            <h3 class="mw-modal-title">Edit Services — <?php echo htmlspecialchars($plan['plan_number']); ?></h3>
            <form method="POST" id="editItemsForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="update_line_items">

                <table class="mw-line-items-table" id="editItemsTable">
                    <thead>
                        <tr>
                            <th style="min-width:160px;">Service</th>
                            <th>Description</th>
                            <th style="width:70px;">Qty</th>
                            <th class="text-right" style="width:90px;">Price</th>
                            <th class="text-right" style="width:90px;">Total</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="editItemsBody">
                        <?php foreach ($planLineItems as $idx => $pli): ?>
                        <tr>
                            <td>
                                <div class="mw-service-combobox">
                                    <input type="text" name="items[<?php echo $idx; ?>][service_type]" class="form-control form-control-sm mw-service-input" value="<?php echo htmlspecialchars($pli['service_type']); ?>" placeholder="Search or type…" autocomplete="off" required>
                                    <div class="mw-service-dropdown"></div>
                                </div>
                            </td>
                            <td><input type="text" name="items[<?php echo $idx; ?>][description]" class="form-control form-control-sm mw-ei-desc" value="<?php echo htmlspecialchars($pli['description'] ?? ''); ?>"></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][quantity]" class="form-control form-control-sm mw-ei-qty" value="<?php echo floatval($pli['quantity']); ?>" min="0.01" step="0.01" onchange="recalcEditItemRow(this)"></td>
                            <td><input type="number" name="items[<?php echo $idx; ?>][unit_price]" class="form-control form-control-sm mw-ei-price text-right" value="<?php echo floatval($pli['unit_price']); ?>" min="0" step="0.01" onchange="recalcEditItemRow(this)">
                                <input type="hidden" name="items[<?php echo $idx; ?>][unit_type]" value="<?php echo htmlspecialchars($pli['unit_type'] ?? 'visit'); ?>"></td>
                            <td class="text-right"><span class="mw-ei-row-total"><?php echo formatCurrency($pli['line_total']); ?></span>
                                <input type="hidden" name="items[<?php echo $idx; ?>][line_total]" class="mw-ei-total-input" value="<?php echo floatval($pli['line_total']); ?>"></td>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditItemRow(this)" title="Remove">&times;</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right"><strong>Per Visit Total</strong></td>
                            <td class="text-right"><strong id="editItemsTotal"><?php echo formatCurrency(array_sum(array_column($planLineItems, 'line_total'))); ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addEditItem()">
                    <i data-feather="plus" style="width:14px;height:14px;"></i> Add Item
                </button>

                <div class="mw-modal-actions mt-3">
                    <button type="submit" class="btn btn-primary">Save Items</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editItemsModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Visit Modal -->
    <div class="mw-modal-overlay" id="editVisitModal">
        <div class="mw-modal">
            <h3 class="mw-modal-title">Edit Visit <span id="editVisitNumber"></span></h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="edit_visit">
                <input type="hidden" name="edit_visit_id" id="editVisitId" value="">

                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" name="visit_date" id="editVisitDate" class="form-control" required>
                </div>
                <div class="mw-form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="visit_time_start" id="editVisitTimeStart" class="form-control">
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <input type="time" name="visit_time_end" id="editVisitTimeEnd" class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Crew</label>
                    <div class="mw-crew-wrapper">
                        <div class="mw-crew-chips" id="visitEditCrewChips">
                            <button type="button" class="mw-crew-add-btn" onclick="visitEditToggleCrewDropdown()">+ Assign</button>
                        </div>
                        <div class="mw-crew-dropdown" id="visitEditCrewDropdown">
                            <?php foreach ($staff as $s): ?>
                                <div class="mw-crew-dropdown-item"
                                     data-id="<?php echo (int)$s['id']; ?>"
                                     data-name="<?php echo htmlspecialchars($s['full_name'], ENT_QUOTES); ?>"
                                     onclick="visitEditAssignCrew(<?php echo (int)$s['id']; ?>, this.dataset.name)">
                                    <?php echo htmlspecialchars($s['full_name']); ?>
                                    <small class="text-muted"><?php echo htmlspecialchars($s['role']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small class="text-muted">First person assigned is the crew lead.</small>
                </div>

                <?php if (!empty($plan['is_recurring'])): ?>
                <div class="form-group">
                    <label class="form-label">Apply to</label>
                    <div class="mw-radio-group">
                        <label class="mw-radio-option">
                            <input type="radio" name="update_scope" value="this_only" checked> This visit only
                        </label>
                        <label class="mw-radio-option">
                            <input type="radio" name="update_scope" value="this_and_future"> This &amp; all future visits
                        </label>
                    </div>
                    <small class="text-muted" id="editVisitScopeHint"></small>
                </div>
                <?php endif; ?>

                <div class="mw-modal-actions">
                    <button type="submit" class="btn btn-primary">Save Visit</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editVisitModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════
         JAVASCRIPT
         ══════════════════════════════════════════════════════ -->
    <script>
        // ── Radial chart sectors animate via CSS (see mw-radial-sector) ──

        // ── Modal helpers ─────────────────────────────────────
        function showModal(id) {
            document.getElementById(id).classList.add('show');
        }

        function hideModal(id) {
            document.getElementById(id).classList.remove('show');
        }

        // Close modal on overlay click
        document.querySelectorAll('.mw-modal-overlay').forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });

        // ── Visit action modals ───────────────────────────────
        function openLogPastVisitModal() {
            // Default to yesterday
            var yesterday = new Date();
            yesterday.setDate(yesterday.getDate() - 1);
            var y = yesterday.getFullYear();
            var m = String(yesterday.getMonth()+1).padStart(2,'0');
            var d = String(yesterday.getDate()).padStart(2,'0');
            document.getElementById('lvDate').value = y + '-' + m + '-' + d;
            showModal('logPastVisitModal');
        }

        function openCompleteModal(visitId, visitNumber, defaultAmount) {
            document.getElementById('completeVisitId').value = visitId;
            document.getElementById('completeVisitNumber').textContent = visitNumber;
            document.getElementById('completeActualAmount').value = defaultAmount > 0 ? defaultAmount.toFixed(2) : '';
            showModal('completeModal');
        }

        function openMarkCompletedModal(visitId, visitNumber) {
            document.getElementById('markCompletedVisitId').value = visitId;
            document.getElementById('markCompletedVisitNumber').textContent = visitNumber;
            showModal('markCompletedModal');
        }

        function selectSkipChip(btn) {
            // Toggle selection; prepend the chip reason to the textarea
            var chips = document.querySelectorAll('.mw-skip-chip');
            var textarea = document.getElementById('skipReasonText');
            var wasActive = btn.classList.contains('mw-skip-chip-active');

            // Deselect all chips
            chips.forEach(function(c) {
                c.classList.remove('mw-skip-chip-active', 'btn-secondary');
                c.classList.add('btn-outline-secondary');
            });

            // Remove any previously selected chip prefix from textarea
            var curVal = textarea.value;
            chips.forEach(function(c) {
                var prefix = c.dataset.reason + ': ';
                var prefixPlain = c.dataset.reason;
                if (curVal.startsWith(prefix)) curVal = curVal.slice(prefix.length).trim();
                else if (curVal === prefixPlain) curVal = '';
            });

            if (!wasActive) {
                btn.classList.add('mw-skip-chip-active', 'btn-secondary');
                btn.classList.remove('btn-outline-secondary');
                // Prepend chip label so the stored reason starts with it
                textarea.value = btn.dataset.reason + (curVal ? ': ' + curVal : '');
            } else {
                textarea.value = curVal;
            }
        }

        function openSkipModal(visitId, visitNumber) {
            document.getElementById('skipVisitId').value = visitId;
            document.getElementById('skipVisitNumber').textContent = visitNumber;
            // Reset chips and textarea each time the modal opens
            document.querySelectorAll('.mw-skip-chip').forEach(function(c) {
                c.classList.remove('mw-skip-chip-active', 'btn-secondary');
                c.classList.add('btn-outline-secondary');
            });
            document.getElementById('skipReasonText').value = '';
            showModal('skipModal');
        }

        function openWeatherModal(visitId, visitNumber) {
            document.getElementById('weatherVisitId').value = visitId;
            document.getElementById('weatherVisitNumber').textContent = visitNumber;
            showModal('weatherModal');
        }

        function openDeleteVisitModal(visitId, visitNumber) {
            document.getElementById('delVisitId').value = visitId;
            document.getElementById('delVisitDesc').textContent = visitNumber;
            showModal('deleteVisitModal');
        }

        // ── Visit filter buttons ──────────────────────────────
        document.querySelectorAll('.mw-visit-filter').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var filter = this.getAttribute('data-filter');

                // Toggle active class
                document.querySelectorAll('.mw-visit-filter').forEach(function(b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');

                // Show/hide rows
                var rows = document.querySelectorAll('#visitsTable tbody tr');
                rows.forEach(function(row) {
                    if (filter === 'all') {
                        row.style.display = '';
                    } else if (filter === 'upcoming') {
                        row.style.display = row.classList.contains('mw-visit-upcoming') ? '' : 'none';
                    } else if (filter === 'completed') {
                        row.style.display = row.classList.contains('mw-visit-completed') ? '' : 'none';
                    }
                });
            });
        });

        // Property coordinates (used by route hint)
        var PLAN_PROP_LAT = <?php echo (float)($plan['latitude']  ?? 0); ?>;
        var PLAN_PROP_LNG = <?php echo (float)($plan['longitude'] ?? 0); ?>;

        // ── Contract value from server ────────────────────────
        var editContractTotal = <?php echo json_encode($contractTotal); ?>; // null or float (raw per-cycle amount)
        var editContractLabel = <?php echo json_encode($contractLabel); ?>; // "CTR-2026-0001" etc.
        var editContractCycle = <?php echo json_encode($contractCycle); ?>; // 'monthly','per_visit','seasonal','annual','custom'

        // ── Edit Plan modal toggles ─────────────────────────
        function toggleEditRecurring() {
            var planType = document.getElementById('editPlanType');
            var opts = document.getElementById('editRecurringOptions');
            if (!planType || !opts) return;
            opts.style.display = (planType.value === 'recurring') ? '' : 'none';
        }

        // ── Edit Modal: Jobber-style recurrence controls ─────
        var editCurrentFreq = <?php echo json_encode($editRecPattern); ?>;
        // Multi-day: array of selected day ints (e.g. [1,3,5] = Mon/Wed/Fri)
        var editSelectedDows = <?php
            $eDowArr = [];
            if ($editDow !== null && $editDow !== '') {
                $eDowArr = array_values(array_filter(array_map('intval', explode(',', (string)$editDow)),
                    function($d) { return $d >= 0 && $d <= 6; }));
            }
            echo json_encode($eDowArr);
        ?>;
        var editDayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var editDayNamesShort = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

        // Frequency picker
        document.querySelectorAll('#editFreqPicker .mw-freq-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#editFreqPicker .mw-freq-btn').forEach(function(b) { b.classList.remove('active'); });
                this.classList.add('active');
                editCurrentFreq = this.dataset.freq;
                editUpdateRecurrenceUI();
            });
        });

        // DOW picker — multi-select: click toggles each day independently
        document.querySelectorAll('#editDowPicker .mw-dow-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var d = parseInt(this.dataset.dow);
                var idx = editSelectedDows.indexOf(d);
                if (idx === -1) {
                    editSelectedDows.push(d);
                    editSelectedDows.sort(function(a, b) { return a - b; });
                } else {
                    editSelectedDows.splice(idx, 1);
                }
                this.classList.toggle('active');
                editUpdateHiddenFields();
                editUpdateSummaryText();
                editCalcContractHelper();
                editUpdateRevenuePreview();
                editRouteHintUpdate(d);
            });
        });

        // Interval input
        var editIntervalInput = document.getElementById('editRecurrenceInterval');
        if (editIntervalInput) {
            editIntervalInput.addEventListener('input', function() {
                editUpdateHiddenFields();
                editUpdateSummaryText();
            });
        }

        // Custom unit select
        var editUnitSelect = document.getElementById('editRecurrenceUnit');
        if (editUnitSelect) {
            editUnitSelect.addEventListener('change', function() {
                editUpdateSummaryText();
            });
        }

        function editUpdateRecurrenceUI() {
            var dowWrap = document.getElementById('editDowPickerWrap');
            var customUnitWrap = document.getElementById('editCustomUnitWrap');
            var unitLabel = document.getElementById('editIntervalUnitLabel');

            dowWrap.style.display = 'none';
            customUnitWrap.style.display = 'none';
            unitLabel.style.display = '';

            switch (editCurrentFreq) {
                case 'daily': unitLabel.textContent = 'day(s)'; break;
                case 'weekly': unitLabel.textContent = 'week(s)'; dowWrap.style.display = ''; break;
                case 'monthly': unitLabel.textContent = 'month(s)'; break;
                case 'yearly': unitLabel.textContent = 'year(s)'; break;
                case 'custom': customUnitWrap.style.display = ''; unitLabel.style.display = 'none'; break;
            }

            editUpdateHiddenFields();
            editUpdateSummaryText();
            if (typeof feather !== 'undefined') feather.replace();
        }

        function editUpdateHiddenFields() {
            document.getElementById('editRecurrencePatternHidden').value = editCurrentFreq;
            document.getElementById('editRecurrenceDowHidden').value = editSelectedDows.length > 0 ? editSelectedDows.join(',') : '';
        }

        function editUpdateSummaryText() {
            var interval = parseInt(document.getElementById('editRecurrenceInterval').value) || 1;
            var text = 'Repeats ';
            switch (editCurrentFreq) {
                case 'daily': text += interval === 1 ? 'every day' : 'every ' + interval + ' days'; break;
                case 'weekly':
                    text += interval === 1 ? 'every week' : 'every ' + interval + ' weeks';
                    if (editSelectedDows.length > 0) {
                        text += ' on ' + editSelectedDows.map(function(d) { return editDayNamesShort[d]; }).join(', ');
                    }
                    break;
                case 'monthly': text += interval === 1 ? 'every month' : 'every ' + interval + ' months'; break;
                case 'yearly': text += interval === 1 ? 'every year' : 'every ' + interval + ' years'; break;
                case 'custom':
                    var unit = document.getElementById('editRecurrenceUnit').value;
                    text += 'every ' + interval + ' ' + unit;
                    break;
            }
            document.getElementById('editRecurrenceSummaryText').textContent = text;
        }

        // ── Edit Modal: Multi-crew assignment ─────────────────
        var editAssignedCrew = <?php
            $crewJson = [];
            if (!empty($existingCrew)) {
                // $existingCrew was fetched earlier for edit modal HTML
            } else {
                $existingCrew = getPlanCrewAssignments($planId);
                if (empty($existingCrew) && !empty($plan['default_crew_id']) && !empty($plan['default_crew_name'])) {
                    $existingCrew = [['user_id' => (int)$plan['default_crew_id'], 'full_name' => $plan['default_crew_name'], 'role' => 'lead']];
                }
            }
            foreach ($existingCrew as $ec) {
                $crewJson[] = ['id' => (int)$ec['user_id'], 'name' => $ec['full_name']];
            }
            echo json_encode($crewJson);
        ?>;

        function editToggleCrewDropdown() {
            var dd = document.getElementById('editCrewDropdown');
            dd.classList.toggle('show');
            dd.querySelectorAll('.mw-crew-dropdown-item').forEach(function(item) {
                var id = parseInt(item.dataset.id);
                item.classList.toggle('disabled', editAssignedCrew.some(function(c) { return c.id === id; }));
            });
        }

        function editAssignCrew(id, name) {
            if (editAssignedCrew.some(function(c) { return c.id === id; })) return;
            editAssignedCrew.push({ id: id, name: name });
            editRenderCrewChips();
            document.getElementById('editCrewDropdown').classList.remove('show');
        }

        function editRemoveCrew(id) {
            editAssignedCrew = editAssignedCrew.filter(function(c) { return c.id !== id; });
            editRenderCrewChips();
        }

        function editRenderCrewChips() {
            var container = document.getElementById('editCrewChips');
            var html = '';
            editAssignedCrew.forEach(function(c, idx) {
                var isLead = (idx === 0);
                html += '<span class="mw-crew-chip ' + (isLead ? 'mw-crew-lead' : '') + '">' +
                    escHtml(c.name) + (isLead ? ' (Lead)' : '') +
                    '<button type="button" class="mw-crew-chip-remove" onclick="editRemoveCrew(' + c.id + ')">&times;</button>' +
                    '<input type="hidden" name="crew_ids[]" value="' + c.id + '">' +
                    '</span>';
            });
            html += '<button type="button" class="mw-crew-add-btn" onclick="editToggleCrewDropdown()">+ Assign</button>';
            container.innerHTML = html;
            document.getElementById('editDefaultCrewIdHidden').value = editAssignedCrew.length > 0 ? editAssignedCrew[0].id : '';
            editUpdateRevenuePreview();
            editRouteHintUpdate();
        }

        // ── Route Intelligence Hint ───────────────────────────────
        var editHintTimer = null;

        function editRouteHintUpdate(clickedDow) {
            var dow = (clickedDow !== undefined && clickedDow !== null)
                ? clickedDow
                : (editSelectedDows.length > 0 ? editSelectedDows[0] : null);

            var el = document.getElementById('editRouteHint');
            if (dow === null || dow === undefined || !el) return;

            if (editHintTimer) clearTimeout(editHintTimer);
            editHintTimer = setTimeout(function() { editRouteHintFetch(dow); }, 250);
        }

        function editRouteHintFetch(dow) {
            var el = document.getElementById('editRouteHint');
            if (!el) return;
            el.innerHTML = '<span class="mw-route-hint-loading">Checking route…</span>';
            el.style.display = 'block';

            var url = '/crm/api/route-day-hint.php?dow=' + dow
                + '&prop_lat=' + PLAN_PROP_LAT
                + '&prop_lng=' + PLAN_PROP_LNG;
            var crewId = editAssignedCrew.length > 0 ? editAssignedCrew[0].id : null;
            if (crewId) url += '&crew_id=' + crewId;

            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success) { el.style.display = 'none'; return; }
                    editRouteHintRender(el, data);
                })
                .catch(function() { el.style.display = 'none'; });
        }

        function editRouteHintRender(el, data) {
            if (!data.stop_count || data.feasibility === 'empty') {
                el.innerHTML = '<div class="mw-route-hint mw-route-hint-empty">'
                    + '<span class="mw-route-hint-dot"></span>'
                    + 'No stops scheduled on ' + escHtml(data.date_label) + ' yet'
                    + '</div>';
                el.style.display = 'block';
                return;
            }
            var stops = data.stop_count === 1 ? '1 stop' : data.stop_count + ' stops';
            var load  = Math.round(data.day_load_pct) + '% day load';
            var near  = data.nearest_km !== null
                ? ' · ' + (data.nearest_km < 1 ? '< 1km' : data.nearest_km.toFixed(1) + 'km') + ' to nearest'
                : '';
            el.innerHTML = '<div class="mw-route-hint mw-route-hint-' + data.feasibility + '">'
                + '<span class="mw-route-hint-dot"></span>'
                + '<strong>' + escHtml(data.date_label) + '</strong>: '
                + stops + ' · ' + load + near
                + '</div>';
            el.style.display = 'block';
        }

        // ── Contract Rate Calculator ──────────────────────────────
        var editCalcMode = 'weekly';
        var editCalcResultValue = null;

        function editSetCalcMode(mode) {
            editCalcMode = mode;
            var label    = document.getElementById('editCalcInputLabel');
            var input    = document.getElementById('editCalcInput');
            var btns     = document.querySelectorAll('#editContractCalc .mw-calc-mode-btn');
            btns.forEach(function(b, i) {
                b.classList.toggle('active', (i === 0 && mode === 'weekly') || (i === 1 && mode === 'hourly'));
            });
            if (mode === 'weekly') {
                label.textContent = 'Weekly Contract Value ($)';
                input.placeholder = 'e.g. 3840';
            } else {
                label.textContent = 'Hourly Rate ($/hr)';
                input.placeholder = 'e.g. 80';
            }
            input.value = '';
            editCalcResultValue = null;
            document.getElementById('editCalcResult').textContent = '—';
            document.getElementById('editCalcApplyBtn').style.display = 'none';
            document.getElementById('editCalcDivisorNote').textContent = '';
        }

        function editCalcContractHelper() {
            var inputVal    = parseFloat(document.getElementById('editCalcInput').value) || 0;
            var durationMin = parseInt(document.getElementById('editDurationInput').value) || 0;
            var durationHrs = durationMin / 60;
            var resultEl    = document.getElementById('editCalcResult');
            var applyBtn    = document.getElementById('editCalcApplyBtn');
            var noteEl      = document.getElementById('editCalcDivisorNote');
            editCalcResultValue = null;

            if (editCalcMode === 'weekly') {
                var days = editSelectedDows.length;
                if (inputVal > 0 && days > 0) {
                    editCalcResultValue = inputVal / days;
                    resultEl.textContent = '$' + editCalcResultValue.toFixed(2);
                    noteEl.textContent   = '$' + inputVal.toLocaleString() + ' ÷ ' + days + ' day' + (days !== 1 ? 's' : '') + '/wk';
                    applyBtn.style.display = '';
                } else if (inputVal > 0 && days === 0) {
                    resultEl.textContent   = '—';
                    noteEl.textContent     = 'Select days above first';
                    applyBtn.style.display = 'none';
                } else {
                    resultEl.textContent   = '—';
                    noteEl.textContent     = '';
                    applyBtn.style.display = 'none';
                }
            } else {
                if (inputVal > 0 && durationHrs > 0) {
                    editCalcResultValue = inputVal * durationHrs;
                    resultEl.textContent = '$' + editCalcResultValue.toFixed(2);
                    noteEl.textContent   = '$' + inputVal + '/hr × ' + durationHrs.toFixed(1) + 'h';
                    applyBtn.style.display = '';
                } else if (inputVal > 0 && durationHrs === 0) {
                    resultEl.textContent   = '—';
                    noteEl.textContent     = 'Set duration above first';
                    applyBtn.style.display = 'none';
                } else {
                    resultEl.textContent   = '—';
                    noteEl.textContent     = '';
                    applyBtn.style.display = 'none';
                }
            }
            editUpdateRevenuePreview();
        }

        function editApplyCalcResult() {
            if (editCalcResultValue !== null) {
                document.getElementById('editPricePerVisitInput').value = editCalcResultValue.toFixed(2);
                editUpdateRevenuePreview();
            }
        }

        function editCalcDurationFromTime() {
            var startVal = document.getElementById('editTimeStartInput').value;
            var endVal   = document.getElementById('editTimeEndInput').value;
            if (!startVal || !endVal) return;
            var sp = startVal.split(':').map(Number);
            var ep = endVal.split(':').map(Number);
            var diff = (ep[0] * 60 + (ep[1] || 0)) - (sp[0] * 60 + (sp[1] || 0));
            if (diff > 0) {
                document.getElementById('editDurationInput').value = diff;
                var h = Math.floor(diff / 60), m = diff % 60;
                document.getElementById('editDurationHrsLabel').textContent =
                    h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
                editCalcContractHelper();
                editUpdateRevenuePreview();
            }
        }

        function editUpdateRevenuePreview() {
            var container = document.getElementById('editRevenuePreview');
            if (!container) return;

            var durationMin  = parseInt((document.getElementById('editDurationInput') || {}).value) || 0;
            var durationHrs  = durationMin / 60;
            var crewCount    = editAssignedCrew.length;
            var crewHours    = durationHrs * (crewCount || 1);
            var daysPerWeek  = editSelectedDows.length;

            // Season weeks / months from date fields
            // Fall back to 12-month rolling if no end date but contract total is known
            var startDateEl    = document.getElementById('editStartDateInput');
            var endDateEl      = document.getElementById('editEndDateInput');
            var startDateV     = startDateEl ? startDateEl.value : '';
            var endDateV       = endDateEl   ? endDateEl.value   : '';
            var seasonWeeks    = 0, seasonMonths = 0, seasonLabel = '', seasonIsEstimate = false;
            if (startDateV && endDateV) {
                var sd = new Date(startDateV), ed = new Date(endDateV);
                seasonWeeks  = Math.round((ed - sd) / (7 * 24 * 3600 * 1000));
                // Fractional months for monthly billing math
                seasonMonths = (ed.getFullYear() - sd.getFullYear()) * 12 +
                               (ed.getMonth()    - sd.getMonth())    +
                               (ed.getDate()     - sd.getDate()) / 30;
                var opts = { month: 'short', day: 'numeric' };
                seasonLabel  = sd.toLocaleDateString('en-CA', opts) + ' – ' + ed.toLocaleDateString('en-CA', opts);
            } else if (startDateV && editContractTotal) {
                // No end date but contract total known → assume 12-month rolling
                seasonWeeks      = 52;
                seasonMonths     = 12;
                seasonLabel      = '12-mo rolling';
                seasonIsEstimate = true;
            }

            // ── Auto-calculate price per visit from contract total ──────
            var ppvInput        = document.getElementById('editPricePerVisitInput');
            var pricePerVisit   = ppvInput ? (parseFloat(ppvInput.value) || 0) : 0;
            var autoCalcChain   = document.getElementById('editCalcAutoChain');
            var ppvFromContract = 0;
            var effectiveTotal  = 0;

            var canAutoCalc = editContractTotal && (
                editContractCycle === 'per_visit' ||
                (daysPerWeek > 0 && seasonWeeks > 0)
            );

            if (canAutoCalc) {
                function fmtC(n) { return '$' + Math.round(n).toLocaleString(); }
                var chainHtml = '<div class="mw-calc-chain-row">';

                if (editContractCycle === 'per_visit') {
                    // billing_amount IS the price per visit — no division needed
                    ppvFromContract = editContractTotal;
                    effectiveTotal  = ppvFromContract * daysPerWeek * seasonWeeks;
                    chainHtml += '<span class="mw-calc-chain-step mw-calc-chain-result">' + fmtC(ppvFromContract) + '/visit</span>' +
                                 '<span class="mw-calc-chain-op">from contract rate</span>';

                } else if (editContractCycle === 'monthly' && seasonMonths > 0) {
                    // billing_amount × months → total, then ÷ weeks ÷ days
                    effectiveTotal  = editContractTotal * seasonMonths;
                    var weeklyAmt   = effectiveTotal / seasonWeeks;
                    ppvFromContract = weeklyAmt / daysPerWeek;
                    var roundedMo   = Math.round(seasonMonths * 10) / 10;
                    chainHtml +=
                        '<span class="mw-calc-chain-step">' + fmtC(editContractTotal) + '/mo</span>' +
                        '<span class="mw-calc-chain-op">× ' + roundedMo + ' mo</span>' +
                        '<span class="mw-calc-chain-step">' + fmtC(effectiveTotal) + '</span>' +
                        '<span class="mw-calc-chain-op">÷ ' + seasonWeeks + 'w ÷ ' + daysPerWeek + 'd</span>' +
                        '<span class="mw-calc-chain-step mw-calc-chain-result">' + fmtC(ppvFromContract) + '/visit</span>';

                } else {
                    // seasonal, annual, custom (or monthly with no month data) → lump sum ÷ weeks ÷ days
                    effectiveTotal  = editContractTotal;
                    var weeklyAmt   = effectiveTotal / seasonWeeks;
                    ppvFromContract = weeklyAmt / daysPerWeek;
                    chainHtml +=
                        '<span class="mw-calc-chain-step">' + fmtC(editContractTotal) + '</span>' +
                        '<span class="mw-calc-chain-op">÷ ' + seasonWeeks + 'w ÷ ' + daysPerWeek + 'd</span>' +
                        '<span class="mw-calc-chain-step mw-calc-chain-result">' + fmtC(ppvFromContract) + '/visit</span>';
                }

                chainHtml += '</div>';
                if (seasonIsEstimate) {
                    chainHtml += '<p class="mw-calc-chain-hint">est. — no end date set</p>';
                }

                // Auto-fill the price per visit field if it's empty
                if (ppvInput && pricePerVisit === 0 && ppvFromContract > 0) {
                    ppvInput.value = ppvFromContract.toFixed(2);
                    pricePerVisit  = ppvFromContract;
                }

                if (autoCalcChain) {
                    autoCalcChain.innerHTML = chainHtml;
                    if (typeof feather !== 'undefined') feather.replace();
                }
            } else if (autoCalcChain) {
                var missing = [];
                if (!editContractTotal) missing.push('contract value');
                if (editContractCycle !== 'per_visit') {
                    if (daysPerWeek === 0) missing.push('days selected');
                    if (seasonWeeks === 0) missing.push(startDateV ? 'end date' : 'start & end dates');
                }
                autoCalcChain.innerHTML = missing.length
                    ? '<p class="mw-calc-chain-hint">Set ' + missing.join(', ') + ' to auto-calculate</p>'
                    : '';
            }

            var weeklyRev  = pricePerVisit * daysPerWeek;
            var totalValue = effectiveTotal || (weeklyRev * seasonWeeks);

            if (pricePerVisit <= 0 && durationMin <= 0) { container.innerHTML = ''; return; }

            function fmtMoney(n) {
                if (n >= 100000) return '$' + (n / 1000).toFixed(0) + 'k';
                if (n >= 10000)  return '$' + (n / 1000).toFixed(1) + 'k';
                return '$' + Math.round(n).toLocaleString();
            }
            function fmtDur(min) {
                if (min <= 0) return '—';
                var h = Math.floor(min / 60), m = min % 60;
                return h > 0 ? h + 'h' + (m > 0 ? ' ' + m + 'm' : '') : m + 'm';
            }

            var items = '';
            if (durationMin > 0) {
                items += '<div class="mw-rev-item"><span class="mw-rev-label">Duration</span><span class="mw-rev-value">' + fmtDur(durationMin) + '</span></div>';
            }
            if (pricePerVisit > 0) {
                items += '<div class="mw-rev-item"><span class="mw-rev-label">Per visit</span><span class="mw-rev-value">$' + Math.round(pricePerVisit).toLocaleString() + '</span></div>';
            }
            if (daysPerWeek > 0 && pricePerVisit > 0) {
                items += '<div class="mw-rev-item"><span class="mw-rev-label">Per week</span><span class="mw-rev-value">' +
                    fmtMoney(weeklyRev) + '<small class="mw-rev-sub">' + daysPerWeek + ' day' + (daysPerWeek !== 1 ? 's' : '') + '</small></span></div>';
            }
            if (seasonWeeks > 0) {
                items += '<div class="mw-rev-item"><span class="mw-rev-label">Season</span><span class="mw-rev-value">' +
                    seasonWeeks + ' wks<small class="mw-rev-sub">' + seasonLabel +
                    (seasonIsEstimate ? ' <em>(est.)</em>' : '') +
                    '</small></span></div>';
            }
            if (totalValue > 0) {
                var totalLabel = editContractTotal ? 'Contract Total' : 'Est. Total';
                items += '<div class="mw-rev-item mw-rev-item--total"><span class="mw-rev-label">' + totalLabel + '</span><span class="mw-rev-value">' + fmtMoney(totalValue) + '</span></div>';
            }

            var crewNote = (crewCount > 0 && durationHrs > 0)
                ? '<div class="mw-rev-crew-note"><i data-feather="users" style="width:12px;height:12px;vertical-align:-2px;"></i> ' +
                  crewCount + ' crew × ' + durationHrs.toFixed(1) + 'h = ' + crewHours.toFixed(1) + ' crew-hrs/visit</div>'
                : '';

            container.innerHTML =
                '<div class="mw-rev-preview-header">Revenue Breakdown</div>' +
                '<div class="mw-rev-preview-grid">' + items + '</div>' +
                crewNote;

            if (typeof feather !== 'undefined') feather.replace();
        }

        function escHtml(str) {
            if (!str) return '';
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        // ── Initialise revenue preview on page load ──────────────
        (function initEditModalPreview() {
            // Seed the duration hrs label from existing values
            var startEl = document.getElementById('editTimeStartInput');
            var endEl   = document.getElementById('editTimeEndInput');
            if (startEl && endEl && startEl.value && endEl.value) {
                var sp = startEl.value.split(':').map(Number);
                var ep = endEl.value.split(':').map(Number);
                var diff = (ep[0] * 60 + (ep[1] || 0)) - (sp[0] * 60 + (sp[1] || 0));
                if (diff > 0) {
                    var h = Math.floor(diff / 60), m = diff % 60;
                    var lbl = document.getElementById('editDurationHrsLabel');
                    if (lbl) lbl.textContent = h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
                }
            }
            editUpdateRevenuePreview();
        })();

        // Close edit crew dropdown on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#editCrewChips') && !e.target.closest('#editCrewDropdown')) {
                var dd = document.getElementById('editCrewDropdown');
                if (dd) dd.classList.remove('show');
            }
        });

        // ── Edit Visit modal ────────────────────────────────
        var visitCrewData = <?php
            $visitCrewJs = [];
            foreach ($visits as $v) {
                $vCrew = $visitCrewMap[$v['id']] ?? [];
                if (empty($vCrew) && !empty($v['assigned_crew_id'])) {
                    $vCrew = [['user_id' => (int)$v['assigned_crew_id'], 'full_name' => $v['crew_name'] ?? '']];
                }
                $visitCrewJs[(int)$v['id']] = array_map(function($c) {
                    return ['id' => (int)$c['user_id'], 'name' => $c['full_name']];
                }, $vCrew);
            }
            echo json_encode($visitCrewJs);
        ?>;

        var visitEditAssignedCrew = [];

        function openEditVisitModal(visitId, visitNumber, date, timeStart, timeEnd) {
            document.getElementById('editVisitId').value = visitId;
            document.getElementById('editVisitNumber').textContent = visitNumber;
            document.getElementById('editVisitDate').value = date;
            document.getElementById('editVisitTimeStart').value = timeStart || '';
            document.getElementById('editVisitTimeEnd').value = timeEnd || '';
            // Reset scope radio to "this only"
            var scopeThis = document.querySelector('input[name="update_scope"][value="this_only"]');
            if (scopeThis) { scopeThis.checked = true; }
            var hint = document.getElementById('editVisitScopeHint');
            if (hint) hint.textContent = '';
            visitEditAssignedCrew = visitCrewData[visitId] ? visitCrewData[visitId].slice() : [];
            visitEditRenderCrewChips();
            showModal('editVisitModal');
        }

        // Hint text when scope changes
        (function() {
            var radios = document.querySelectorAll('input[name="update_scope"]');
            var hint = document.getElementById('editVisitScopeHint');
            if (!radios.length || !hint) return;
            radios.forEach(function(r) {
                r.addEventListener('change', function() {
                    hint.textContent = this.value === 'this_and_future'
                        ? 'All future scheduled visits will shift by the same number of days and receive the same crew.'
                        : '';
                });
            });
        })();

        function visitEditToggleCrewDropdown() {
            var dd = document.getElementById('visitEditCrewDropdown');
            dd.classList.toggle('show');
            dd.querySelectorAll('.mw-crew-dropdown-item').forEach(function(item) {
                var id = parseInt(item.dataset.id);
                item.classList.toggle('disabled', visitEditAssignedCrew.some(function(c) { return c.id === id; }));
            });
        }

        function visitEditAssignCrew(id, name) {
            if (visitEditAssignedCrew.some(function(c) { return c.id === id; })) return;
            visitEditAssignedCrew.push({ id: id, name: name });
            visitEditRenderCrewChips();
            document.getElementById('visitEditCrewDropdown').classList.remove('show');
        }

        function visitEditRemoveCrew(id) {
            visitEditAssignedCrew = visitEditAssignedCrew.filter(function(c) { return c.id !== id; });
            visitEditRenderCrewChips();
        }

        function visitEditRenderCrewChips() {
            var container = document.getElementById('visitEditCrewChips');
            var html = '';
            visitEditAssignedCrew.forEach(function(c, idx) {
                var isLead = (idx === 0);
                html += '<span class="mw-crew-chip ' + (isLead ? 'mw-crew-lead' : '') + '">' +
                    escHtml(c.name) + (isLead ? ' <small>(Lead)</small>' : '') +
                    '<button type="button" class="mw-crew-chip-remove" data-uid="' + c.id + '">&times;</button>' +
                    '<input type="hidden" name="visit_crew_ids[]" value="' + c.id + '">' +
                    '</span>';
            });
            html += '<button type="button" class="mw-crew-add-btn js-visit-edit-assign">+ Assign</button>';
            container.innerHTML = html;
        }

        // Event delegation — avoids inline onclick inside innerHTML (CSP-safe)
        document.getElementById('visitEditCrewChips').addEventListener('click', function(e) {
            if (e.target.matches('.mw-crew-chip-remove')) {
                visitEditRemoveCrew(parseInt(e.target.getAttribute('data-uid')));
            } else if (e.target.matches('.js-visit-edit-assign')) {
                visitEditToggleCrewDropdown();
            }
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#visitEditCrewChips') && !e.target.closest('#visitEditCrewDropdown')) {
                var dd = document.getElementById('visitEditCrewDropdown');
                if (dd) dd.classList.remove('show');
            }
        });

        // ── Edit Line Items ─────────────────────────────────
        var editItemIndex = <?php echo count($planLineItems); ?>;

        // Service templates for the combobox (deduplicated by name)
        var mwServiceTemplates = (function() {
            var raw = <?php echo json_encode(array_values($serviceTemplates)); ?>;
            var seen = {}, out = [];
            raw.forEach(function(t) { if (!seen[t.name]) { seen[t.name] = true; out.push(t); } });
            return out;
        })();

        // ── Combobox — event delegation on #editItemsTable ──
        // All focus/input/keydown events bubble up; no per-input init needed.
        (function () {
            var table  = document.getElementById('editItemsTable');
            var activeInput = null; // the input currently showing a dropdown

            function escHtml(s) {
                return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            function getDD(input) {
                return input.closest('.mw-service-combobox').querySelector('.mw-service-dropdown');
            }

            function renderDD(input, q) {
                var dd = getDD(input);
                var matches = (q === '')
                    ? mwServiceTemplates
                    : mwServiceTemplates.filter(function(t) {
                        return (t.name||'').toLowerCase().indexOf(q) !== -1
                            || (t.service_type||'').toLowerCase().indexOf(q) !== -1;
                    });
                if (!matches.length) { closeDD(dd); return; }
                dd.innerHTML = '';
                matches.forEach(function(t) {
                    var item = document.createElement('div');
                    item.className = 'mw-sc-item';
                    item.dataset.name  = t.name;
                    item.dataset.desc  = t.description || '';
                    item.dataset.price = t.default_price || '0';
                    item.dataset.unit  = t.unit_type || 'visit';
                    item.innerHTML = '<span class="mw-sc-name">' + escHtml(t.name) + '</span>'
                        + (t.default_price ? '<span class="mw-sc-price">$' + parseFloat(t.default_price).toFixed(2) + '</span>' : '');
                    dd.appendChild(item);
                });
                dd.classList.add('is-open');
            }

            function closeDD(dd) { dd.classList.remove('is-open'); dd.innerHTML = ''; }

            function closeAllDD() {
                table.querySelectorAll('.mw-service-dropdown.is-open').forEach(closeDD);
            }

            function pickTemplate(item) {
                if (!activeInput) return;
                var dd = getDD(activeInput);
                activeInput.value = item.dataset.name;
                var tr = activeInput.closest('tr');
                var descInput  = tr.querySelector('.mw-ei-desc');
                var priceInput = tr.querySelector('.mw-ei-price');
                var unitInput  = tr.querySelector('input[name*="[unit_type]"]');
                if (descInput  && !descInput.value)                  descInput.value  = item.dataset.desc;
                if (priceInput && parseFloat(priceInput.value) === 0) priceInput.value = parseFloat(item.dataset.price).toFixed(2);
                if (unitInput) unitInput.value = item.dataset.unit || 'visit';
                recalcEditItemRow(priceInput || activeInput);
                closeDD(dd);
                activeInput = null;
            }

            // Delegated focus — always show full list on focus
            table.addEventListener('focusin', function(e) {
                if (!e.target.classList.contains('mw-service-input')) return;
                activeInput = e.target;
                renderDD(activeInput, '');
            });

            // Delegated input — filter as user types
            table.addEventListener('input', function(e) {
                if (!e.target.classList.contains('mw-service-input')) return;
                activeInput = e.target;
                renderDD(activeInput, e.target.value.trim().toLowerCase());
            });

            // Delegated keydown
            table.addEventListener('keydown', function(e) {
                if (!e.target.classList.contains('mw-service-input')) return;
                var input = e.target;
                var dd = getDD(input);
                var items = dd.querySelectorAll('.mw-sc-item');
                var active = dd.querySelector('.mw-sc-item.is-active');
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var next = active ? (active.nextElementSibling || items[0]) : items[0];
                    if (next) { if (active) active.classList.remove('is-active'); next.classList.add('is-active'); }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var prev = active ? (active.previousElementSibling || items[items.length-1]) : items[items.length-1];
                    if (prev) { if (active) active.classList.remove('is-active'); prev.classList.add('is-active'); }
                } else if (e.key === 'Enter' && active) {
                    e.preventDefault(); pickTemplate(active);
                } else if (e.key === 'Escape') {
                    closeDD(dd);
                }
            });

            // Delegated mousedown on dropdown items (bubbles through the table's container)
            document.addEventListener('mousedown', function(e) {
                var item = e.target.closest('.mw-sc-item');
                if (item) { e.preventDefault(); pickTemplate(item); return; }
                // Click outside — close all dropdowns
                if (!e.target.closest('.mw-service-combobox')) closeAllDD();
            });

        })();

        function addEditItem() {
            var body = document.getElementById('editItemsBody');
            var idx = editItemIndex++;
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><div class="mw-service-combobox">' +
                    '<input type="text" name="items[' + idx + '][service_type]" class="form-control form-control-sm mw-service-input" placeholder="Search or type…" autocomplete="off" required>' +
                    '<div class="mw-service-dropdown"></div>' +
                '</div></td>' +
                '<td><input type="text" name="items[' + idx + '][description]" class="form-control form-control-sm mw-ei-desc" placeholder="Description"></td>' +
                '<td><input type="number" name="items[' + idx + '][quantity]" class="form-control form-control-sm mw-ei-qty" value="1" min="0.01" step="0.01" onchange="recalcEditItemRow(this)"></td>' +
                '<td><input type="number" name="items[' + idx + '][unit_price]" class="form-control form-control-sm mw-ei-price text-right" value="0" min="0" step="0.01" onchange="recalcEditItemRow(this)">' +
                    '<input type="hidden" name="items[' + idx + '][unit_type]" value="visit"></td>' +
                '<td class="text-right"><span class="mw-ei-row-total">$0.00</span>' +
                    '<input type="hidden" name="items[' + idx + '][line_total]" class="mw-ei-total-input" value="0"></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditItemRow(this)" title="Remove">&times;</button></td>';
            body.appendChild(tr);
            tr.querySelector('.mw-service-input').focus();
        }

        function recalcEditItemRow(input) {
            var tr = input.closest('tr');
            var qty = parseFloat(tr.querySelector('.mw-ei-qty').value) || 0;
            var price = parseFloat(tr.querySelector('.mw-ei-price').value) || 0;
            var total = qty * price;
            tr.querySelector('.mw-ei-row-total').textContent = '$' + total.toFixed(2);
            tr.querySelector('.mw-ei-total-input').value = total.toFixed(2);
            updateEditItemTotals();
        }

        function removeEditItemRow(btn) {
            btn.closest('tr').remove();
            updateEditItemTotals();
        }

        function updateEditItemTotals() {
            var inputs = document.querySelectorAll('#editItemsBody .mw-ei-total-input');
            var sum = 0;
            inputs.forEach(function(inp) { sum += parseFloat(inp.value) || 0; });
            var totalEl = document.getElementById('editItemsTotal');
            if (totalEl) totalEl.textContent = '$' + sum.toFixed(2);
        }

        // ── Time Log ────────────────────────────────────────────
        (function () {
            var planId = <?php echo (int)$plan['id']; ?>;

            function fmtTime(s) {
                if (!s) return '—';
                var d = new Date(s.replace(' ', 'T'));
                return d.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit', hour12: true });
            }
            function fmtDate(s) {
                if (!s) return '';
                var d = new Date(s + 'T00:00:00');
                return d.toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            function statusBadge(status) {
                var map = {
                    active:    'badge-warning',
                    completed: 'badge-success',
                    edited:    'badge-info',
                    void:      'badge-secondary'
                };
                return '<span class="badge ' + (map[status] || 'badge-secondary') + '">' + status + '</span>';
            }

            function loadTimeLog() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '/crm/api/job-timer.php?action=plan_time_log&plan_id=' + planId, true);
                xhr.onload = function () {
                    var body = document.getElementById('timeLogBody');
                    var totalEl = document.getElementById('timeLogTotal');
                    if (xhr.status !== 200) {
                        body.innerHTML = '<div class="card-body text-danger small">Failed to load time entries.</div>';
                        return;
                    }
                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch(e) {
                        body.innerHTML = '<div class="card-body text-danger small">Invalid response.</div>';
                        return;
                    }
                    if (!data.success) {
                        body.innerHTML = '<div class="card-body text-danger small">' + (data.error || 'Error') + '</div>';
                        return;
                    }

                    // Update total badge
                    if (data.total_minutes > 0) {
                        totalEl.textContent = data.total_formatted;
                        totalEl.style.display = '';
                    }

                    if (!data.entries || data.entries.length === 0) {
                        body.innerHTML = '<div class="card-body text-center text-muted py-4">' +
                            '<i data-feather="clock" style="width:32px;height:32px;opacity:0.25;" class="mb-2"></i>' +
                            '<p class="mb-0 small">No time entries recorded for this plan yet.</p></div>';
                        if (window.feather) feather.replace();
                        return;
                    }

                    // Group by visit
                    var byVisit = {};
                    var visitOrder = [];
                    data.entries.forEach(function(e) {
                        if (!byVisit[e.visit_id]) {
                            byVisit[e.visit_id] = { visit_number: e.visit_number, date: e.scheduled_date, visit_status: e.visit_status, entries: [] };
                            visitOrder.push(e.visit_id);
                        }
                        byVisit[e.visit_id].entries.push(e);
                    });

                    var CAN_EDIT = <?php echo userHasPermission('jobs.edit') ? 'true' : 'false'; ?>;
                    // Encode a value as JSON safe for embedding in an HTML onclick="..." attribute.
                    // JSON.stringify uses raw " which breaks HTML attribute parsing — replace with &quot;
                    function qj(v) { return JSON.stringify(String(v == null ? '' : v)).replace(/"/g, '&quot;'); }

                    var html = '<div class="table-responsive"><table class="mw-table">' +
                        '<thead class="thead-light"><tr>' +
                        '<th>Visit</th><th>Crew Member</th><th>Clock In</th><th>Clock Out</th><th class="text-right">Duration</th><th>Status</th>' +
                        (CAN_EDIT ? '<th></th>' : '') +
                        '</tr></thead><tbody>';

                    visitOrder.forEach(function(vid) {
                        var v = byVisit[vid];
                        var visitMinutes = v.entries.reduce(function(s, e) { return s + e.duration_minutes; }, 0);
                        // Visit header row
                        html += '<tr class="table-light">' +
                            '<td colspan="6" class="small font-weight-bold py-1 px-3">' +
                            '<i data-feather="calendar" style="width:12px;height:12px;margin-right:4px;vertical-align:-1px;"></i>' +
                            v.visit_number + ' &mdash; ' + fmtDate(v.date) +
                            ' <span class="badge badge-light border ml-1">' + v.visit_status.replace('_', ' ') + '</span>' +
                            '<span class="text-muted ml-2 font-weight-normal">' + (visitMinutes > 0 ? formatMins(visitMinutes) + ' total' : '') + '</span>' +
                            '</td></tr>';
                        v.entries.forEach(function(e) {
                            var isGps = e.source === 'gps';
                            var sourceBadge = isGps
                                ? ' <span class="badge badge-light border text-muted" title="Estimated from GPS pings"><i data-feather="map-pin" style="width:9px;height:9px;"></i> GPS</span>'
                                : (e.auto_started ? ' <small class="text-muted">(auto)</small>' : '');
                            var moveBtn = (CAN_EDIT && !isGps && e.id)
                                ? '<button class="btn btn-sm btn-link p-0 text-muted mr-1" title="Edit times" onclick="openEditTimeEntry(' + e.id + ',' + qj(e.crew_name) + ',' + qj(e.start_time) + ',' + qj(e.end_time) + ',' + qj(e.notes) + ')">' +
                                  '<i data-feather="edit-2" style="width:13px;height:13px;"></i></button>' +
                                  '<button class="btn btn-sm btn-link p-0 text-muted mr-1" title="Move to different visit" onclick="openMoveTimeEntry(' + e.id + ',' + qj(e.crew_name) + ',' + qj(e.start_time) + ')">' +
                                  '<i data-feather="move" style="width:13px;height:13px;"></i></button>' +
                                  '<button class="btn btn-sm btn-link p-0 text-danger" title="Delete entry" onclick="deleteTimeEntry(' + e.id + ',' + qj(e.crew_name) + ')">' +
                                  '<i data-feather="trash-2" style="width:13px;height:13px;"></i></button>'
                                : '';
                            html += '<tr' + (isGps ? ' class="text-muted"' : '') + '>' +
                                '<td></td>' +
                                '<td>' + esc(e.crew_name) + sourceBadge + '</td>' +
                                '<td class="text-nowrap">' + fmtTime(e.start_time) + '</td>' +
                                '<td class="text-nowrap">' + (e.end_time ? fmtTime(e.end_time) : '<span class="text-warning">Active</span>') + '</td>' +
                                '<td class="text-right text-nowrap">' + (e.duration_minutes > 0 ? e.duration_formatted : '—') + '</td>' +
                                '<td>' + (isGps ? '<span class="badge badge-light border text-muted">gps est.</span>' : statusBadge(e.entry_status)) + '</td>' +
                                (CAN_EDIT ? '<td class="text-right text-nowrap">' + moveBtn + '</td>' : '') +
                                '</tr>';
                            if (e.notes && isGps) {
                                html += '<tr><td></td><td colspan="5" class="text-muted small py-1"><i data-feather="map-pin" style="width:11px;height:11px;"></i> ' + esc(e.notes) + ' near property</td></tr>';
                            } else if (e.notes) {
                                html += '<tr><td></td><td colspan="5" class="text-muted small py-1"><i data-feather="message-square" style="width:11px;height:11px;"></i> ' + esc(e.notes) + '</td></tr>';
                            }
                        });
                    });

                    // Total row
                    html += '<tr class="table-secondary font-weight-bold">' +
                        '<td colspan="4" class="text-right">Total Time</td>' +
                        '<td class="text-right">' + data.total_formatted + '</td>' +
                        '<td></td>' +
                        (CAN_EDIT ? '<td></td>' : '') +
                        '</tr>';

                    html += '</tbody></table></div>';
                    body.innerHTML = html;
                    if (window.feather) feather.replace();
                };
                xhr.onerror = function () {
                    document.getElementById('timeLogBody').innerHTML =
                        '<div class="card-body text-danger small">Network error loading time entries.</div>';
                };
                xhr.send();
            }

            function esc(s) {
                return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
            function formatMins(m) {
                var h = Math.floor(m / 60), min = m % 60;
                return (h > 0 ? h + 'h ' : '') + min + 'm';
            }

            loadTimeLog();

            window.deleteTimeEntry = function(entryId, crewName) {
                document.getElementById('delEntryId').value = entryId;
                document.getElementById('delEntryDesc').textContent = crewName;
                showModal('deleteTimeEntryModal');
            };
            window.doDeleteTimeEntry = function() {
                var entryId = document.getElementById('delEntryId').value;
                var fd = new FormData();
                fd.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
                fd.append('action', 'delete_time_entry');
                fd.append('del_entry_id', entryId);
                fetch('/crm/jobs/view.php?id=<?php echo (int)$planId; ?>', { method: 'POST', body: fd })
                    .then(function() {
                        hideModal('deleteTimeEntryModal');
                        loadTimeLog();
                    });
            };

            window.openMoveTimeEntry = function(entryId, crewName, startTime) {
                document.getElementById('mvEntryId').value = entryId;
                var desc = crewName;
                if (startTime) {
                    var d = new Date(startTime.replace(' ', 'T'));
                    desc += ' &mdash; ' + d.toLocaleDateString('en-CA', {month:'short', day:'numeric'}) +
                            ' ' + d.toLocaleTimeString('en-CA', {hour:'2-digit', minute:'2-digit', hour12:true});
                }
                document.getElementById('mvEntryDesc').innerHTML = desc;
                showModal('moveTimeEntryModal');
            };

            window.openEditTimeEntry = function(entryId, crewName, startTime, endTime, notes) {
                document.getElementById('edEntryId').value = entryId;
                document.getElementById('edEntryDesc').textContent = 'Editing entry for ' + crewName;
                // Convert "YYYY-MM-DD HH:MM:SS" → "YYYY-MM-DDTHH:MM" for datetime-local input
                function toLocal(ts) {
                    if (!ts) return '';
                    return ts.replace(' ', 'T').substring(0, 16);
                }
                document.getElementById('edStartTime').value = toLocal(startTime);
                document.getElementById('edEndTime').value = toLocal(endTime);
                document.getElementById('edNotes').value = notes || '';

                // Show GPS departure hints — one "Use" button per tracked crew/vehicle on-site
                var hintEl = document.getElementById('edGpsHint');
                if (hintEl) {
                    var deps = window._gpsDeparture || {};
                    var entries = Object.entries(deps);
                    if (entries.length) {
                        // Sort earliest to latest so truck departure (usually earliest) comes first
                        entries.sort(function(a,b) { return a[1] < b[1] ? -1 : 1; });
                        var rows = entries.map(function(kv) {
                            var name = kv[0], ts = kv[1];
                            var label = ts.substring(11, 16);
                            var dtVal = toLocal(ts);
                            return '<span class="mr-3 text-nowrap"><i data-feather="map-pin" style="width:10px;height:10px;vertical-align:-1px;"></i> ' +
                                name + ' left <strong>' + label + '</strong> ' +
                                '<button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size:11px;" ' +
                                'onclick="document.getElementById(\'edEndTime\').value=\'' + dtVal + '\'">Use</button></span>';
                        });
                        hintEl.style.display = '';
                        hintEl.innerHTML = rows.join('');
                        if (window.feather) feather.replace();
                    } else {
                        hintEl.style.display = 'none';
                    }
                }

                showModal('editTimeEntryModal');
            };
        })();

        <?php if ($hasPropCoords): ?>
        // ── GPS On-site Map ──────────────────────────────────────────
        (function () {
            var planId = <?php echo (int)$plan['id']; ?>;
            var mapEl = document.getElementById('gpsTrackMap');
            if (!mapEl || typeof L === 'undefined') return;

            var map = L.map('gpsTrackMap', { zoomControl: true, scrollWheelZoom: false });
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
                maxZoom: 19
            }).addTo(map);
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
                attribution: '', pane: 'overlayPane'
            }).addTo(map);

            // Crew colour palette (cycles if >6 crew)
            var palette = ['#2D8659','#e85d04','#3a86ff','#8338ec','#fb5607','#06d6a0'];
            var crewColors = {};
            var colorIdx = 0;

            // GPS departure index: keyed by crew_name → last ping timestamp near property
            // Exposed globally so openEditTimeEntry can show a "Use GPS departure" hint.
            window._gpsDeparture = {};

            fetch('/crm/api/job-timer.php?action=gps_pings&plan_id=' + planId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.prop_lat) return;

                    // Build departure index from pings (last ping per crew = departure)
                    if (data.pings && data.pings.length) {
                        data.pings.forEach(function(p) {
                            window._gpsDeparture[p.crew_name] = p.time; // overwrite → last wins
                        });
                    }

                    // Property marker
                    var propLatLng = [data.prop_lat, data.prop_lng];
                    L.circle(propLatLng, { radius: 200, color: '#2D8659', fillColor: '#2D8659', fillOpacity: 0.08, weight: 1, dashArray: '4' }).addTo(map);
                    L.marker(propLatLng, {
                        icon: L.divIcon({ className: '', html: '<div style="width:10px;height:10px;background:#2D8659;border:2px solid #fff;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.4);"></div>', iconSize:[10,10], iconAnchor:[5,5] })
                    }).bindTooltip('Property').addTo(map);

                    var bounds = [propLatLng];

                    if (data.pings && data.pings.length) {
                        data.pings.forEach(function(p) {
                            if (!crewColors[p.crew_id]) {
                                crewColors[p.crew_id] = palette[colorIdx % palette.length];
                                colorIdx++;
                            }
                            var color = crewColors[p.crew_id];
                            var ll = [p.lat, p.lng];
                            bounds.push(ll);
                            L.circleMarker(ll, { radius: 5, color: color, fillColor: color, fillOpacity: 0.8, weight: 1.5 })
                                .bindTooltip(p.crew_name + '<br><small>' + p.time + '</small>', { direction: 'top' })
                                .addTo(map);
                        });
                        map.fitBounds(bounds, { padding: [20, 20] });
                    } else {
                        map.setView(propLatLng, 17);
                    }
                })
                .catch(function() { mapEl.innerHTML = '<div class="p-3 text-muted small">Could not load GPS data.</div>'; });
        })();
        <?php endif; ?>

        // ── Job Expenses ─────────────────────────────────────────────
        (function() {
            var PLAN_ID = <?php echo (int)$planId; ?>;
            var CSRF    = <?php echo json_encode(generateCSRFToken()); ?>;
            var CAN_EDIT = <?php echo userHasPermission('expenses.edit') ? 'true' : 'false'; ?>;

            function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            function loadJobExpenses() {
                fetch('/crm/api/expenses.php?action=list&job_id=' + PLAN_ID + '&per_page=50')
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var body = document.getElementById('jobExpensesBody');
                        var totalEl = document.getElementById('jobExpensesTotal');
                        if (!body) return;

                        if (!data.success || !data.expenses || !data.expenses.length) {
                            body.innerHTML = '<div class="card-body text-center text-muted py-3 small">No expenses linked to this job yet.</div>';
                            if (totalEl) totalEl.style.display = 'none';
                            return;
                        }

                        var grandTotal = data.expenses.reduce(function(s, e) { return s + parseFloat(e.total || 0); }, 0);
                        if (totalEl) {
                            totalEl.textContent = '$' + grandTotal.toFixed(2);
                            totalEl.style.display = '';
                        }

                        var rows = data.expenses.map(function(e) {
                            var vendor = esc(e.vendor_name || e.vendor_name_raw || '—');
                            var statusCls = { draft:'badge-secondary', approved:'badge-primary', forwarded:'badge-success', rejected:'badge-danger' }[e.status] || 'badge-secondary';
                            var receiptHtml = e.receipt_path
                                ? '<a href="' + esc(e.receipt_path) + '" target="_blank" title="View receipt"><i data-feather="image" style="width:13px;height:13px;"></i></a>'
                                : '<span class="text-muted" title="No receipt"><i data-feather="image" style="width:13px;height:13px;opacity:0.25;"></i></span>';
                            var actions = CAN_EDIT
                                ? '<button class="btn btn-sm btn-link p-0 text-muted ml-2" onclick="openReassignModal(' + e.id + ')" title="Move to a different job"><i data-feather="move" style="width:13px;height:13px;"></i></button>'
                                + '<button class="btn btn-sm btn-link p-0 text-danger ml-1" onclick="deleteJobExpense(' + e.id + ')" title="Delete"><i data-feather="trash-2" style="width:13px;height:13px;"></i></button>'
                                : '';
                            return '<tr>' +
                                '<td>' + esc(e.expense_date) + '</td>' +
                                '<td>' + vendor + '</td>' +
                                '<td><small class="text-muted">' + esc(e.accounting_category || '—') + '</small></td>' +
                                '<td class="text-right font-weight-bold">$' + parseFloat(e.total).toFixed(2) + '</td>' +
                                '<td><span class="badge ' + statusCls + '">' + esc(e.status) + '</span></td>' +
                                '<td class="text-center">' + receiptHtml + '</td>' +
                                '<td class="text-right text-nowrap">' + actions + '</td>' +
                                '</tr>';
                        }).join('');

                        body.innerHTML = '<div class="table-responsive"><table class="mw-table">' +
                            '<thead class="thead-light"><tr>' +
                            '<th>Date</th><th>Vendor</th><th>Category</th>' +
                            '<th class="text-right">Total</th><th>Status</th>' +
                            '<th class="text-center">Receipt</th><th></th>' +
                            '</tr></thead><tbody>' + rows + '</tbody></table></div>';

                        if (window.feather) feather.replace();
                    })
                    .catch(function(err) {
                        var body = document.getElementById('jobExpensesBody');
                        if (body) body.innerHTML = '<div class="card-body text-muted small py-2">Could not load expenses.</div>';
                    });
            }

            window.openReassignModal = function(expenseId) {
                document.getElementById('reassignExpenseId').value = expenseId;
                document.getElementById('reassignJobId').value = '';
                showModal('reassignExpenseModal');
            };

            window.doReassignExpense = async function() {
                var expId = parseInt(document.getElementById('reassignExpenseId').value);
                var newJobId = document.getElementById('reassignJobId').value.trim();
                if (!expId) return;
                try {
                    var r = await fetch('/crm/api/expenses.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'reassign_job',
                            csrf_token: CSRF,
                            id: expId,
                            job_id: newJobId ? parseInt(newJobId) : null,
                        }),
                    });
                    var d = await r.json();
                    if (!d.success) throw new Error(d.error);
                    hideModal('reassignExpenseModal');
                    loadJobExpenses();
                } catch(e) { alert('Could not reassign: ' + e.message); }
            };

            window.deleteJobExpense = async function(id) {
                if (!confirm('Delete this expense? This cannot be undone.')) return;
                try {
                    var r = await fetch('/crm/api/expenses.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete', csrf_token: CSRF, id: id }),
                    });
                    var d = await r.json();
                    if (!d.success) throw new Error(d.error);
                    loadJobExpenses();
                } catch(e) { alert('Delete failed: ' + e.message); }
            };

            loadJobExpenses();
        })();

        // Pre-load route hint for the already-selected day when modal first opens
        (function() {
            var editBtn = document.querySelector('[onclick="showModal(\'editPlanModal\')"]');
            if (editBtn) {
                editBtn.addEventListener('click', function() {
                    if (editSelectedDows.length > 0) {
                        editRouteHintUpdate(editSelectedDows[0]);
                    }
                });
            }
        })();
    </script>

<?php if ($hasPropCoords): ?>
<!-- ══════════════════════════════════════════════════════
     WORK ZONE MODAL — Multi-zone geofence management
     ══════════════════════════════════════════════════════ -->
<div class="modal fade" id="planWorkZoneModal" tabindex="-1" role="dialog" aria-labelledby="planWorkZoneModalLabel" aria-hidden="true">
    <div class="modal-dialog mw-modal-fullscreen" role="document">
        <div class="modal-content">

            <div class="modal-header py-2 px-3">
                <h5 class="modal-title" id="planWorkZoneModalLabel">
                    <i data-feather="map-pin" style="width:16px;height:16px;vertical-align:-2px;color:var(--mw-green);"></i>
                    Work Zones &amp; Property Border
                    <span class="text-muted font-weight-normal small ml-2">
                        <?php echo htmlspecialchars($plan['property_address'] ?? ''); ?>
                    </span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <!-- Split body: left panel + map -->
            <div class="mw-wz-split-body">

                <!-- Left management panel -->
                <div class="mw-wz-panel" id="pwz-panel">

                    <!-- Hint bar -->
                    <div id="pwz-hint" class="mw-wz-hint mw-wz-hint--info">
                        <span id="pwz-hint-text">Loading zones…</span>
                    </div>

                    <!-- IDLE mode: zone list -->
                    <div id="pwz-idle-panel">

                        <!-- Property Border section -->
                        <div class="mw-wz-panel-section">
                            <div class="mw-wz-section-hdr">
                                <div>
                                    <div class="mw-wz-section-title">Property Border</div>
                                    <div class="mw-wz-section-sub">Auto clock-in boundary</div>
                                </div>
                                <span class="mw-wz-zone-dot" style="background:var(--mw-orange);border:2px dashed rgba(0,0,0,0.2);flex-shrink:0;"></span>
                            </div>
                            <div id="pwz-border-list" class="mw-wz-zone-list"></div>
                            <button class="mw-wz-add-btn" id="pwz-add-border-btn" onclick="pwzStartDraw('arrival_border')" disabled>
                                + Draw Property Border
                            </button>
                        </div>

                        <div class="mw-wz-panel-divider"></div>

                        <!-- Work Zones section -->
                        <div class="mw-wz-panel-section">
                            <div class="mw-wz-section-hdr">
                                <div>
                                    <div class="mw-wz-section-title">Work Zones</div>
                                    <div class="mw-wz-section-sub">Per-area time tracking</div>
                                </div>
                                <span class="mw-wz-zone-dot" style="background:var(--mw-green);flex-shrink:0;"></span>
                            </div>
                            <div id="pwz-zone-list" class="mw-wz-zone-list"></div>
                            <button class="mw-wz-add-btn mw-wz-add-btn--green" id="pwz-add-zone-btn" onclick="pwzStartDraw('work_zone')" disabled>
                                + Add Work Zone
                            </button>
                        </div>

                    </div><!-- /pwz-idle-panel -->

                    <!-- DRAW mode controls -->
                    <div id="pwz-draw-panel" style="display:none;">
                        <div class="mw-wz-draw-active-hdr">
                            <span id="pwz-drawing-label">Drawing…</span>
                        </div>
                        <div id="pwz-draw-label-row" class="mw-wz-form-row" style="display:none;">
                            <label class="mw-wz-label">Zone Label <span class="text-muted">(optional)</span></label>
                            <input type="text" id="pwz-draw-label-input" class="form-control form-control-sm"
                                   placeholder="e.g. Front lawn, Back beds…" maxlength="80">
                        </div>
                        <div class="mw-wz-draw-tips">
                            <div class="mw-wz-draw-tip"><strong>Click</strong> map to add corner points</div>
                            <div class="mw-wz-draw-tip"><strong>Double-click</strong> to close the shape</div>
                            <div class="mw-wz-draw-tip">Aim for 4–8 points for best accuracy</div>
                        </div>
                        <div class="mw-wz-vertex-count" id="pwz-vertex-count" style="display:none;">
                            <span id="pwz-vertex-num">0</span> vertices added
                        </div>
                        <div class="mw-wz-draw-btns">
                            <button class="btn btn-success btn-sm btn-block" id="pwz-finish-draw-btn"
                                    onclick="pwzFinishDraw()" disabled>
                                ✓ Finish Drawing
                            </button>
                            <button class="btn btn-outline-secondary btn-sm btn-block mt-1"
                                    onclick="pwzCancelDraw()">
                                Cancel
                            </button>
                        </div>
                    </div><!-- /pwz-draw-panel -->

                    <!-- SAVE PENDING mode controls -->
                    <div id="pwz-save-panel" style="display:none;">
                        <div class="mw-wz-draw-active-hdr mw-wz-draw-active-hdr--ready">
                            <span id="pwz-save-type-label">Zone drawn</span> — ready to save
                        </div>
                        <div id="pwz-save-label-row" class="mw-wz-form-row" style="display:none;">
                            <label class="mw-wz-label">Zone Label <span class="text-muted">(optional)</span></label>
                            <input type="text" id="pwz-save-label-input" class="form-control form-control-sm"
                                   placeholder="e.g. Front lawn, Back beds…" maxlength="80">
                        </div>
                        <div class="mw-wz-draw-btns">
                            <button class="btn btn-success btn-sm btn-block" id="pwz-confirm-save-btn"
                                    onclick="pwzSavePending()">
                                <span id="pwz-save-spinner" style="display:none;">⏳ </span>Save Zone
                            </button>
                            <button class="btn btn-outline-secondary btn-sm btn-block mt-1"
                                    onclick="pwzCancelPending()">
                                ↩ Redraw
                            </button>
                        </div>
                    </div><!-- /pwz-save-panel -->

                </div><!-- /mw-wz-panel -->

                <!-- Map area (right, fills remaining space) -->
                <div class="mw-wz-map-area">
                    <div id="pwz-map"></div>
                </div>

            </div><!-- /mw-wz-split-body -->

        </div>
    </div>
</div>

<script src="/crm/js/geofence/geofence-manager.js?v=3"></script>
<script>
(function() {
    var PWZ_PLAN_ID  = <?php echo (int)$plan['id']; ?>;
    var PWZ_PROP_ID  = <?php echo (int)($plan['property_id'] ?? 0); ?>;
    var PWZ_PROP_LAT = <?php echo (float)($plan['latitude']  ?? 49.2827); ?>;
    var PWZ_PROP_LNG = <?php echo (float)($plan['longitude'] ?? -123.1207); ?>;
    var PWZ_CSRF     = <?php echo json_encode(generateCSRFToken()); ?>;
    var PWZ_API      = '/crm/api/geofence.php';

    // Zone color palette
    var PWZ_BORDER_COLOR = '#e85d04';
    var PWZ_ZONE_COLORS  = ['#2D8659', '#1a73e8', '#9b59b6', '#16a085', '#e74c3c', '#f39c12', '#2ecc71', '#e91e63'];

    var pwzMgr          = null;
    var pwzZoneLayers   = []; // [{id, type, layer (L.polygon), color}]
    var pwzPendingRing  = null;
    var pwzPendingType  = null;
    var pwzDrawVertices = 0;
    var pwzIsSaving     = false;

    // ── Modal open/close ──────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function() {
        $('#planWorkZoneModal').on('shown.bs.modal', function() {
            pwzInitMap();
            pwzSafeFeather();
        });
        $('#planWorkZoneModal').on('hidden.bs.modal', function() {
            pwzDestroyMap();
            pwzShowPanel('idle');
        });
    });

    // ── Map init ──────────────────────────────────────────────────────
    function pwzInitMap() {
        pwzDestroyMap();
        // Use GeofenceManager for map + draw only (planId=null prevents auto-load)
        pwzMgr = new GeofenceManager({
            mapContainer: 'pwz-map',
            apiBase:      PWZ_API,
            csrfToken:    PWZ_CSRF,
            planId:       null,
            mode:         'edit',
            center:       [PWZ_PROP_LAT, PWZ_PROP_LNG],
            zoom:         18,
            onDraw: function(ring) {
                // Drawing complete — switch to save panel
                pwzPendingRing = ring;
                pwzShowPanel('save');
                var typeLabel = pwzPendingType === 'arrival_border' ? 'Property Border' : 'Work Zone';
                document.getElementById('pwz-save-type-label').textContent = typeLabel;
                document.getElementById('pwz-save-label-row').style.display =
                    pwzPendingType === 'work_zone' ? 'block' : 'none';
                // Copy any label the user typed during drawing rather than clearing it
                var drawnLabel = (document.getElementById('pwz-draw-label-input').value || '').trim();
                document.getElementById('pwz-save-label-input').value = drawnLabel;
                var hint = drawnLabel
                    ? 'Shape outlined. Label pre-filled — click Save Zone.'
                    : 'Shape outlined. Add a label and click Save Zone.';
                pwzSetHint(hint, 'success');
                pwzSafeFeather();
            },
        });
        pwzMgr.init();

        // Hook into map click to track vertex count for the Finish button
        if (pwzMgr._map) {
            pwzMgr._map.on('click', function() {
                if (pwzPendingType) {
                    pwzDrawVertices++;
                    var cEl  = document.getElementById('pwz-vertex-count');
                    var nEl  = document.getElementById('pwz-vertex-num');
                    var fBtn = document.getElementById('pwz-finish-draw-btn');
                    if (cEl)  cEl.style.display = 'block';
                    if (nEl)  nEl.textContent = pwzDrawVertices;
                    if (fBtn) fBtn.disabled = (pwzDrawVertices < 3);
                }
            });
        }

        pwzSetHint('Loading saved zones…', 'info');
        pwzLoadZones();
    }

    function pwzDestroyMap() {
        pwzZoneLayers.forEach(function(zl) {
            try { if (pwzMgr && pwzMgr._map) pwzMgr._map.removeLayer(zl.layer); } catch(e) {}
        });
        pwzZoneLayers = [];
        if (pwzMgr) { pwzMgr.destroy(); pwzMgr = null; }
        pwzPendingRing  = null;
        pwzPendingType  = null;
        pwzDrawVertices = 0;
        pwzIsSaving     = false;
    }

    // ── Load zones from server ────────────────────────────────────────
    // Returns a Promise so callers can chain .then() for post-load hints.
    function pwzLoadZones() {
        if (!PWZ_PROP_ID) {
            pwzSetHint('No property linked to this plan.', 'error');
            return Promise.resolve();
        }
        return fetch(PWZ_API + '?action=get_zones&property_id=' + PWZ_PROP_ID, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) throw new Error(data.error || 'Load failed');
                pwzRenderZonesOnMap(data.zones);
                pwzRenderPanel(data.zones);
                document.getElementById('pwz-add-border-btn').disabled = false;
                document.getElementById('pwz-add-zone-btn').disabled   = false;
            })
            .catch(function(err) {
                pwzSetHint('Could not load zones: ' + err.message, 'error');
            });
    }

    // ── Render zones on map ───────────────────────────────────────────
    function pwzRenderZonesOnMap(zones) {
        pwzZoneLayers.forEach(function(zl) {
            try { if (pwzMgr._map) pwzMgr._map.removeLayer(zl.layer); } catch(e) {}
        });
        pwzZoneLayers = [];
        if (pwzMgr) pwzMgr.setPolygon([]);

        var workZoneIdx = 0;
        var allLatLngs  = [];

        zones.forEach(function(zone) {
            var isBorder = zone.zone_type === 'arrival_border';
            var isPlanWz = zone.zone_type === 'work_zone' && zone.plan_id === PWZ_PLAN_ID;
            if (!isBorder && !isPlanWz) return;

            var ring = zone.ring;
            if (!ring || ring.length < 3) return;

            var color   = isBorder ? PWZ_BORDER_COLOR : PWZ_ZONE_COLORS[workZoneIdx++ % PWZ_ZONE_COLORS.length];
            var latLngs = ring.map(function(pt) { return L.latLng(pt[0], pt[1]); });

            var layer = L.polygon(latLngs, {
                color:       color,
                fillColor:   color,
                fillOpacity: 0.13,
                weight:      isBorder ? 2 : 2.5,
                dashArray:   isBorder ? '8 5' : null,
            }).addTo(pwzMgr._map);

            var tipLabel = zone.label || (isBorder ? 'Property Border' : 'Work Zone');
            layer.bindTooltip(tipLabel, { permanent: false, direction: 'center', className: 'mw-wz-tooltip' });

            pwzZoneLayers.push({ id: zone.id, type: zone.zone_type, layer: layer, color: color });
            allLatLngs = allLatLngs.concat(latLngs);
        });

        if (allLatLngs.length > 0 && pwzMgr._map) {
            try { pwzMgr._map.fitBounds(L.latLngBounds(allLatLngs).pad(0.15)); } catch(e) {}
        }
    }

    // ── Render zone list in panel ─────────────────────────────────────
    function pwzRenderPanel(zones) {
        var borderList = document.getElementById('pwz-border-list');
        var zoneList   = document.getElementById('pwz-zone-list');
        if (!borderList || !zoneList) return;

        var borders   = zones.filter(function(z) { return z.zone_type === 'arrival_border'; });
        var workZones = zones.filter(function(z) { return z.zone_type === 'work_zone' && z.plan_id === PWZ_PLAN_ID; });

        if (borders.length === 0) {
            borderList.innerHTML = '<div class="mw-wz-empty-zone">No border drawn yet</div>';
        } else {
            var bHtml = '';
            borders.forEach(function(zone, bi) {
                bHtml += pwzZoneCardHtml(zone, PWZ_BORDER_COLOR, bi > 0);
            });
            borderList.innerHTML = bHtml;
        }
        var borderBtn = document.getElementById('pwz-add-border-btn');
        if (borderBtn) borderBtn.textContent = borders.length > 0 ? '↺ Redraw Border' : '+ Draw Property Border';

        if (workZones.length === 0) {
            zoneList.innerHTML = '<div class="mw-wz-empty-zone">No work zones yet</div>';
        } else {
            var wHtml = '';
            workZones.forEach(function(zone, wi) {
                wHtml += pwzZoneCardHtml(zone, PWZ_ZONE_COLORS[wi % PWZ_ZONE_COLORS.length], false);
            });
            zoneList.innerHTML = wHtml;
        }
    }

    function pwzZoneCardHtml(zone, color, isOld) {
        var label     = zone.label || (zone.zone_type === 'arrival_border' ? 'Property Border' : 'Work Zone');
        var oldNote   = isOld ? ' <span class="text-muted small">(old)</span>' : '';
        var dotStyle  = 'background:' + color + ';' + (zone.zone_type === 'arrival_border' ? 'border:2px dashed #6c757d;' : '');
        return '<div class="mw-wz-zone-card">' +
            '<span class="mw-wz-zone-dot" style="' + dotStyle + '"></span>' +
            '<span class="mw-wz-zone-name">' + htmlEsc(label) + oldNote + '</span>' +
            '<button class="mw-wz-zone-del" onclick="pwzDeleteZone(' + zone.id + ')" title="Delete zone">&#x2715;</button>' +
            '</div>';
    }

    function htmlEsc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── Panel switching ───────────────────────────────────────────────
    function pwzShowPanel(mode) {
        document.getElementById('pwz-idle-panel').style.display  = mode === 'idle' ? 'block' : 'none';
        document.getElementById('pwz-draw-panel').style.display  = mode === 'draw' ? 'block' : 'none';
        document.getElementById('pwz-save-panel').style.display  = mode === 'save' ? 'block' : 'none';
    }

    // ── Draw actions ──────────────────────────────────────────────────
    window.pwzStartDraw = function(type) {
        if (!pwzMgr) return;
        pwzPendingType  = type;
        pwzPendingRing  = null;
        pwzDrawVertices = 0;

        pwzMgr.startDraw();
        pwzShowPanel('draw');

        var typeLabel = type === 'arrival_border' ? 'Property Border' : 'Work Zone';
        document.getElementById('pwz-drawing-label').textContent = 'Drawing ' + typeLabel;
        document.getElementById('pwz-draw-label-row').style.display  = type === 'work_zone' ? 'block' : 'none';
        document.getElementById('pwz-draw-label-input').value        = '';

        var cEl  = document.getElementById('pwz-vertex-count');
        var fBtn = document.getElementById('pwz-finish-draw-btn');
        if (cEl)  { cEl.style.display = 'none'; document.getElementById('pwz-vertex-num').textContent = '0'; }
        if (fBtn) fBtn.disabled = true;

        pwzSetHint('Click the map to add corner points. Double-click (or Finish) to close the shape.', 'draw');
    };

    window.pwzFinishDraw = function() {
        if (!pwzMgr) return;
        pwzMgr.finishDraw();
    };

    window.pwzCancelDraw = function() {
        if (!pwzMgr) return;
        var savedType   = pwzPendingType;
        pwzPendingType  = null;
        pwzPendingRing  = null;
        pwzDrawVertices = 0;
        pwzMgr.cancelDraw();
        pwzMgr.setPolygon([]);
        pwzShowPanel('idle');
        pwzSetHint('Draw cancelled.', 'info');
    };

    window.pwzCancelPending = function() {
        if (!pwzMgr) return;
        var type        = pwzPendingType;
        pwzPendingRing  = null;
        pwzDrawVertices = 0;
        pwzMgr.setPolygon([]);
        // Restart draw for the same type
        if (type) {
            pwzMgr.startDraw();
            pwzShowPanel('draw');
            var fBtn = document.getElementById('pwz-finish-draw-btn');
            var cEl  = document.getElementById('pwz-vertex-count');
            if (fBtn) fBtn.disabled = true;
            if (cEl)  { cEl.style.display = 'none'; document.getElementById('pwz-vertex-num').textContent = '0'; }
            pwzSetHint('Restarting — click the map to add corner points.', 'draw');
        } else {
            pwzShowPanel('idle');
        }
    };

    // ── Save pending zone ─────────────────────────────────────────────
    window.pwzSavePending = function() {
        if (!pwzPendingRing || pwzIsSaving) return;
        pwzIsSaving = true;

        var label = '';
        if (pwzPendingType === 'work_zone') {
            label = (document.getElementById('pwz-save-label-input').value || '').trim();
        }

        var spinner = document.getElementById('pwz-save-spinner');
        var saveBtn = document.getElementById('pwz-confirm-save-btn');
        if (spinner) spinner.style.display = 'inline';
        if (saveBtn) saveBtn.disabled = true;

        pwzSetHint('Saving zone…', 'info');

        var body = {
            action:      'save_zone',
            csrf_token:  PWZ_CSRF,
            property_id: PWZ_PROP_ID,
            zone_type:   pwzPendingType,
            plan_id:     pwzPendingType === 'work_zone' ? PWZ_PLAN_ID : null,
            ring:        pwzPendingRing,
            label:       label || null,
        };

        fetch(PWZ_API, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify(body),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            pwzIsSaving = false;
            if (spinner) spinner.style.display = 'none';
            if (saveBtn) saveBtn.disabled = false;
            if (!data.success) throw new Error(data.error || 'Save failed');

            var tLabel    = pwzPendingType === 'arrival_border' ? 'Property border' : 'Work zone';
            pwzPendingRing = null;
            pwzPendingType = null;
            pwzShowPanel('idle');
            // Reload zones first, THEN show the success hint so it is never overwritten
            pwzLoadZones().then(function() {
                pwzSetHint(tLabel + ' saved! Add another zone or close this panel.', 'success');
            });
        })
        .catch(function(err) {
            pwzIsSaving = false;
            if (spinner) spinner.style.display = 'none';
            if (saveBtn) saveBtn.disabled = false;
            pwzSetHint('Save failed: ' + err.message, 'error');
        });
    };

    // ── Delete zone ───────────────────────────────────────────────────
    window.pwzDeleteZone = function(zoneId) {
        if (!confirm('Delete this zone? This cannot be undone.')) return;

        fetch(PWZ_API, {
            method:      'POST',
            headers:     { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body:        JSON.stringify({ action: 'delete_zone', csrf_token: PWZ_CSRF, geofence_id: zoneId }),
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) throw new Error(data.error || 'Delete failed');
            pwzSetHint('Zone deleted.', 'info');
            pwzLoadZones();
        })
        .catch(function(err) {
            pwzSetHint('Delete failed: ' + err.message, 'error');
        });
    };

    // ── Hint bar ──────────────────────────────────────────────────────
    function pwzSetHint(text, variant) {
        var el   = document.getElementById('pwz-hint');
        var span = document.getElementById('pwz-hint-text');
        if (!el || !span) return;
        span.textContent = text;
        el.className = 'mw-wz-hint mw-wz-hint--' + (variant || 'info');
    }

    function pwzSafeFeather() {
        if (typeof feather !== 'undefined') feather.replace();
    }
})();
</script>
<?php endif; ?>

<script src="/crm/js/profit-risk-octagon.js?v=<?php echo filemtime(dirname(__DIR__) . '/js/profit-risk-octagon.js'); ?>"></script>
<?php if ($profitability['has_data'] ?? false): ?>
<script>
(function () {
    var body = document.getElementById('mwInlineRiskBody');
    if (!body) return;
    var sevColor = { critical: '#ef4444', high: '#f97316', medium: '#f59e0b', low: '#22c55e' };
    fetch('/crm/api/profit-risk-factors.php?plan_id=<?php echo (int)$planId; ?>', {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (resp) {
        if (!resp.success || !resp.has_data) {
            body.innerHTML = '<div class="text-center text-muted py-4 px-3"><p class="mb-0">' + (resp.message || 'No data available.') + '</p></div>';
            return;
        }
        var d = resp.data;
        var rows = d.factors.map(function (f) {
            var c = sevColor[f.severity] || '#64748b';
            var sev = f.severity.charAt(0).toUpperCase() + f.severity.slice(1);
            return '<tr><td class="mw-irt-factor">' + f.label + '</td>' +
                '<td class="mw-irt-value">' + f.raw_value + '</td>' +
                '<td class="mw-irt-sev" style="color:' + c + '">' + sev + '</td></tr>';
        }).join('');
        var recHtml = '';
        if (d.recommendations && d.recommendations.length) {
            recHtml = '<div class="mw-irt-recs"><div class="mw-irt-recs-title">Recommendations</div>' +
                d.recommendations.map(function (r) {
                    return '<div class="mw-irt-rec"><span class="mw-irt-rec-key">' + r.key + '</span> ' + r.text + '</div>';
                }).join('') + '</div>';
        }
        body.innerHTML =
            '<div class="mw-irt-grid">' +
                '<div class="mw-irt-chart" id="mwIrtOctWrap"></div>' +
                '<div class="mw-irt-table-wrap">' +
                    '<table class="mw-irt-table"><thead><tr>' +
                        '<th>Factor</th><th>Value</th><th>Risk</th>' +
                    '</tr></thead><tbody>' + rows + '</tbody></table>' +
                '</div>' +
            '</div>' + recHtml;
        var oct = new ProfitRiskOctagon(document.getElementById('mwIrtOctWrap'), { size: 260, maxR: 78, labelR: 106, centerR: 40 });
        oct.render(d);
    })
    .catch(function () {
        body.innerHTML = '<div class="text-center text-muted py-4"><p class="mb-0">Error loading risk data.</p></div>';
    });
}());
</script>
<?php endif; ?>
<?php include dirname(__DIR__) . '/includes/appstack_footer.php'; ?>
