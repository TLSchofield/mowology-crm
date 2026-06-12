<?php
/**
 * Proof of Work — Visit Actions API
 * ───────────────────────────────────
 * Handles all PoW state transitions and data saves via AJAX POST.
 *
 * Actions (sent as JSON body or form POST):
 *  start_visit       — Mark visit in_progress, record GPS arrival
 *  save_checklist    — Save checklist JSON to visit
 *  save_materials    — Save materials JSON to visit
 *  save_service_data — Save service-type-specific fields
 *  save_notes        — Add a crew note to the visit
 *  save_signature    — Save base64 signature image
 *  end_visit         — Mark visit completed, record GPS departure
 *  generate_pdf      — Generate (or regenerate) the PoW PDF
 *  lock_visit        — Admin: lock the visit (makes PDF final)
 *  unlock_visit      — Admin: unlock for edits (logged in audit)
 *  upload_photo      — Save a photo to visit_photos, generate variants
 *  complete_stop     — Desktop: mark all visits for a stop complete + optional invoice
 *  reopen_visit      — Desktop: revert a completed visit to scheduled (admin or same-day crew)
 *
 * Returns: { "success": bool, "error"?: string, ... }
 */
declare(strict_types=1);
header('Content-Type: application/json');

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 5; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

try {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once CRM_INCLUDES . '/functions.php';
    // PowPdfGenerator is loaded inside the generate_pdf action only — not here

    requireLogin();
    $user = getCurrentUser();
    $db   = getDB();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }

    // Accept JSON body or form POST
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    // CSRF verification must happen before session_write_close() — closing the
    // session clears $_SESSION from memory, making verifyCSRFToken() always fail.
    $token = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verifyCSRFToken($token)) {
        http_response_code(403);
        echo json_encode([
            'success'   => false,
            'error'     => 'Your session expired. Please reload the page and try again.',
            'code'      => 'CSRF_INVALID',
            'retryable' => false,
        ]);
        exit;
    }

    // Release session lock after CSRF is verified — photo uploads + GD processing can take several seconds.
    session_write_close();

    $action  = $input['action'] ?? '';
    $visitId = isset($input['visit_id']) ? (int)$input['visit_id'] : 0;
    $isAdmin = ($user['role'] ?? '') === 'admin' || userHasPermission('jobs.edit');
    $ip      = $_SERVER['REMOTE_ADDR'] ?? null;

    // ── Actions that use stop_id instead of visit_id ──────────────────────
    if ($action === 'complete_stop') {
        $stopId      = isset($input['stop_id']) ? (int)$input['stop_id'] : 0;
        $withInvoice = !empty($input['invoice']);
        $notes       = trim($input['notes'] ?? '');
        $extrasMins  = max(0, (int)($input['extras_minutes'] ?? 0));

        if ($stopId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'stop_id is required']);
            exit;
        }

        // Auth: admin, or crew assigned to this stop
        if (!$isAdmin) {
            $stopCrewStmt = $db->prepare("
                SELECT 1 FROM calendar_stops
                WHERE id = ? AND (crew_id = ? OR id IN (
                    SELECT stop_id FROM calendar_stop_crew WHERE user_id = ?
                ))
            ");
            $stopCrewStmt->execute([$stopId, (int)$user['id'], (int)$user['id']]);
            if (!$stopCrewStmt->fetch()) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Not authorized for this stop.']);
                exit;
            }
        }

        // Fetch all completable visits for this stop (matched by stop_id)
        $vsStmt = $db->prepare("
            SELECT jv.id AS visit_id, jv.plan_id, jv.assigned_crew_id,
                   jv.invoice_id, jv.scheduled_date,
                   jp.price_per_visit, jp.title AS plan_title, jp.service_type,
                   p.address AS service_address, p.city AS service_city,
                   p.province AS service_province, p.postal_code AS service_postal,
                   p.id AS property_id, ct.id AS contact_id,
                   CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
            FROM job_visits jv
            JOIN job_plans jp ON jv.plan_id = jp.id
            JOIN calendar_stops cs ON jv.stop_id = cs.id
            JOIN properties p ON cs.property_id = p.id
            LEFT JOIN contacts ct ON p.site_contact_id = ct.id
            WHERE jv.stop_id = ? AND jv.status IN ('scheduled','in_progress','skipped')
        ");
        $vsStmt->execute([$stopId]);
        $completableVisits = $vsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: if no visits found by stop_id (e.g. crew reassignment created a new stop
        // but the visit is still linked to the old stop), find visits for the same
        // property + date regardless of stop_id linkage.
        if (empty($completableVisits)) {
            $fbStmt = $db->prepare("
                SELECT jv.id AS visit_id, jv.plan_id, jv.assigned_crew_id,
                       jv.invoice_id, jv.scheduled_date, jv.stop_id AS stop_id_old,
                       jp.price_per_visit, jp.title AS plan_title, jp.service_type,
                       p.address AS service_address, p.city AS service_city,
                       p.province AS service_province, p.postal_code AS service_postal,
                       p.id AS property_id, ct.id AS contact_id,
                       CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN properties p ON jp.property_id = p.id
                LEFT JOIN contacts ct ON p.site_contact_id = ct.id
                WHERE jp.property_id = (SELECT property_id FROM calendar_stops WHERE id = ?)
                  AND jv.scheduled_date = (SELECT stop_date FROM calendar_stops WHERE id = ?)
                  AND jv.status IN ('scheduled','in_progress','skipped')
                  AND jv.invoice_id IS NULL
                  AND jv.plan_id NOT IN (
                      -- Skip plans that already have a completed visit today (avoid double-completing)
                      SELECT DISTINCT plan_id FROM job_visits
                      WHERE scheduled_date = (SELECT stop_date FROM calendar_stops WHERE id = ?)
                        AND status = 'completed'
                  )
                ORDER BY jv.plan_id, jv.sequence_index ASC
            ");
            $fbStmt->execute([$stopId, $stopId, $stopId]);
            $allFbVisits = $fbStmt->fetchAll(PDO::FETCH_ASSOC);

            // Deduplicate: keep only ONE visit per plan (lowest sequence_index wins)
            $seenPlans = [];
            $completableVisits = [];
            foreach ($allFbVisits as $fbv) {
                if (!isset($seenPlans[(int)$fbv['plan_id']])) {
                    $seenPlans[(int)$fbv['plan_id']] = true;
                    $completableVisits[] = $fbv;
                }
            }

            // Re-link the found visits to this stop and retire orphaned old stops
            if (!empty($completableVisits)) {
                $oldStopIds = [];
                $relinkStmt = $db->prepare("UPDATE job_visits SET stop_id = ? WHERE id = ?");
                foreach ($completableVisits as $cv) {
                    // Collect the old stop_id before overwriting it
                    if (!empty($cv['stop_id_old'])) {
                        $oldStopIds[] = (int)$cv['stop_id_old'];
                    }
                    $relinkStmt->execute([$stopId, (int)$cv['visit_id']]);
                }
                // Mark any orphaned stops (ones the visits were moved FROM) as completed
                // so they stop showing as green/pending on the schedule
                if (!empty($oldStopIds)) {
                    $ph = implode(',', array_fill(0, count($oldStopIds), '?'));
                    $db->prepare("UPDATE calendar_stops SET status = 'completed', updated_at = NOW() WHERE id IN ($ph) AND status = 'scheduled'")
                       ->execute($oldStopIds);
                }
            }
        }

        // Third path: visit was completed via mobile PoW (end_visit) but invoice not yet created.
        // The first two queries only look for non-completed statuses, so they return nothing
        // even though the visit exists and is done. Check for completed+uninvoiced visits.
        $alreadyCompletedByField = false;
        if (empty($completableVisits)) {
            $cdStmt = $db->prepare("
                SELECT jv.id AS visit_id, jv.plan_id, jv.assigned_crew_id,
                       jv.invoice_id, jv.scheduled_date,
                       jp.price_per_visit, jp.title AS plan_title, jp.service_type,
                       p.address AS service_address, p.city AS service_city,
                       p.province AS service_province, p.postal_code AS service_postal,
                       p.id AS property_id, ct.id AS contact_id,
                       CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
                FROM job_visits jv
                JOIN job_plans jp ON jv.plan_id = jp.id
                JOIN calendar_stops cs ON jv.stop_id = cs.id
                JOIN properties p ON cs.property_id = p.id
                LEFT JOIN contacts ct ON p.site_contact_id = ct.id
                WHERE jv.stop_id = ? AND jv.status = 'completed' AND jv.invoice_id IS NULL
            ");
            $cdStmt->execute([$stopId]);
            $cdVisits = $cdStmt->fetchAll(PDO::FETCH_ASSOC);

            // Also try property+date fallback for already-completed visits
            if (empty($cdVisits)) {
                $cdFbStmt = $db->prepare("
                    SELECT jv.id AS visit_id, jv.plan_id, jv.assigned_crew_id,
                           jv.invoice_id, jv.scheduled_date,
                           jp.price_per_visit, jp.title AS plan_title, jp.service_type,
                           p.address AS service_address, p.city AS service_city,
                           p.province AS service_province, p.postal_code AS service_postal,
                           p.id AS property_id, ct.id AS contact_id,
                           CONCAT(ct.first_name, ' ', ct.last_name) AS contact_name
                    FROM job_visits jv
                    JOIN job_plans jp ON jv.plan_id = jp.id
                    JOIN properties p ON jp.property_id = p.id
                    LEFT JOIN contacts ct ON p.site_contact_id = ct.id
                    WHERE jp.property_id = (SELECT property_id FROM calendar_stops WHERE id = ?)
                      AND jv.scheduled_date = (SELECT stop_date FROM calendar_stops WHERE id = ?)
                      AND jv.status = 'completed'
                      AND jv.invoice_id IS NULL
                ");
                $cdFbStmt->execute([$stopId, $stopId]);
                $cdVisits = $cdFbStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (!empty($cdVisits)) {
                $completableVisits = $cdVisits;
                $alreadyCompletedByField = true;
            }
        }

        // Nothing found at all — check if stop is truly done+invoiced already
        if (empty($completableVisits)) {
            $doneCheckStmt = $db->prepare("
                SELECT COUNT(*) FROM job_visits jv
                JOIN calendar_stops cs ON jv.stop_id = cs.id
                WHERE cs.id = ? AND jv.status = 'completed' AND jv.invoice_id IS NOT NULL
            ");
            $doneCheckStmt->execute([$stopId]);
            $alreadyFullyDone = (int)$doneCheckStmt->fetchColumn() > 0;

            $errMsg = $alreadyFullyDone
                ? 'This stop was already completed and invoiced.'
                : 'No completable visits found for this stop.';
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }

        // If the visit is already done by field crew with no invoice requested, just confirm success
        if ($alreadyCompletedByField && !$withInvoice) {
            $db->prepare("UPDATE calendar_stops SET status = 'completed', updated_at = NOW() WHERE id = ?")
               ->execute([$stopId]);
            echo json_encode(['success' => true, 'already_completed' => true]);
            exit;
        }

        // Extras rate
        $extrasAmount = 0.00;
        if ($extrasMins > 0) {
            try {
                $rateRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $ratePerBlock = floatval($rateRow['setting_value'] ?? 5.00);
                $blocks = (int)ceil($extrasMins / 5);
                $extrasAmount = round($blocks * $ratePerBlock, 2);
            } catch (Exception $e) {}
        }

        $completedVisitIds = [];
        foreach ($completableVisits as $cv) {
            $cvId = (int)$cv['visit_id'];
            if (!$alreadyCompletedByField) {
                // Visit not yet completed — mark it done now
                $db->prepare("
                    UPDATE job_visits SET
                        status = 'completed',
                        completed_at = NOW(),
                        gps_confirmed_at = NOW(),
                        completion_notes = CASE WHEN ? != '' THEN ? ELSE completion_notes END,
                        extras_minutes = ?,
                        extras_amount  = ?
                    WHERE id = ?
                ")->execute([$notes, $notes, $extrasMins, $extrasAmount, $cvId]);
                addAuditLog($db, $cvId, (int)$user['id'], 'complete', null, $ip);

                $vcService = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
                if (file_exists($vcService)) {
                    require_once $vcService;
                    VisitCompletionService::capture($cvId, (int)$user['id']);
                }
            }
            $completedVisitIds[] = $cvId;
        }

        // Propagate stop status
        $db->prepare("UPDATE calendar_stops SET status = 'completed', updated_at = NOW() WHERE id = ?")
           ->execute([$stopId]);

        $invoiceId     = null;
        $invoiceNumber = null;

        if ($withInvoice && !empty($completableVisits)) {
            require_once APP_ROOT . '/Services/CrmFunctions.php';

            // Idempotency: if any visit already has an invoice (created via a
            // different path), return that invoice instead of creating a duplicate.
            $existingInvoiceIds = array_filter(array_column($completableVisits, 'invoice_id'));
            if (!empty($existingInvoiceIds)) {
                $existingInvoiceId = (int)reset($existingInvoiceIds);
                $existingInvStmt = $db->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
                $existingInvStmt->execute([$existingInvoiceId]);
                $existingInvData = $existingInvStmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success'        => true,
                    'invoice_id'     => $existingInvoiceId,
                    'invoice_number' => $existingInvData['invoice_number'] ?? '',
                ]);
                exit;
            }

            $firstVisit = $completableVisits[0];
            $contactId  = (int)($firstVisit['contact_id'] ?? 0);
            $propertyId = (int)($firstVisit['property_id'] ?? 0);
            $subtotal   = round(array_sum(array_column($completableVisits, 'price_per_visit')), 2);
            if ($subtotal <= 0) $subtotal = 0.00;

            $taxRate   = 0.05; // GST default
            try {
                $taxRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'tax_rate' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($taxRow) $taxRate = (float)$taxRow['setting_value'];
            } catch (Exception $e) {}

            $taxAmount  = round($subtotal * $taxRate, 2);
            $total      = round($subtotal + $taxAmount, 2);
            $invNumber  = generateInvoiceNumber();
            $accToken   = generateAccessToken();
            $dueDate    = date('Y-m-d', strtotime('+14 days'));

            // Link the invoice back to its plan + contract so the contract view
            // billing summary can find it. plan_id comes from the visit; contract_id
            // is looked up from job_plans.
            $invPlanId     = (int)($firstVisit['plan_id'] ?? 0);
            $invContractId = 0;
            if ($invPlanId) {
                $cidStmt = $db->prepare("SELECT contract_id FROM job_plans WHERE id = ?");
                $cidStmt->execute([$invPlanId]);
                $invContractId = (int)($cidStmt->fetchColumn() ?: 0);
            }

            $db->prepare("
                INSERT INTO invoices
                    (invoice_number, contact_id, property_id, plan_id, contract_id,
                     invoice_date, issue_date, due_date,
                     subtotal, tax_rate, tax_amount, total_amount, total, balance_due,
                     status, access_token, token_expires_at,
                     service_address, service_city, service_province, service_postal_code, created_by)
                VALUES
                    (?, ?, ?, ?, ?,
                     CURDATE(), CURDATE(), ?,
                     ?, ?, ?, ?, ?, ?,
                     'draft', ?, DATE_ADD(NOW(), INTERVAL 90 DAY),
                     ?, ?, ?, ?, ?)
            ")->execute([
                $invNumber, $contactId, $propertyId ?: null,
                $invPlanId ?: null, $invContractId ?: null,
                $dueDate,
                $subtotal, $taxRate, $taxAmount, $total, $total, $total,
                $accToken,
                $firstVisit['service_address'] ?? '', $firstVisit['service_city'] ?? '',
                $firstVisit['service_province'] ?? 'BC', $firstVisit['service_postal'] ?? '',
                (int)$user['id'],
            ]);
            $invoiceId = (int)$db->lastInsertId();
            $invoiceNumber = $invNumber;

            // Line items — one per visit. visit_id + service_date make the
            // line a self-contained billing record (so the PDF/view can show
            // when the work was performed without re-joining job_visits).
            foreach ($completableVisits as $cv) {
                $lineDesc  = $cv['plan_title'] ?: ucfirst(str_replace('_', ' ', $cv['service_type'] ?? 'Service'));
                $linePrice = round((float)($cv['price_per_visit'] ?? 0), 2);
                $db->prepare("
                    INSERT INTO invoice_line_items (invoice_id, description, quantity, unit_price, line_total, visit_id, service_date)
                    VALUES (?, ?, 1, ?, ?, ?, ?)
                ")->execute([
                    $invoiceId, $lineDesc, $linePrice, $linePrice,
                    (int)$cv['visit_id'] ?: null,
                    $cv['scheduled_date'] ?? null,
                ]);
            }

            // Link visits to invoice
            $db->prepare("
                UPDATE job_visits SET is_invoiced = 1, invoice_id = ?
                WHERE id IN (" . implode(',', array_fill(0, count($completedVisitIds), '?')) . ")
            ")->execute(array_merge([$invoiceId], $completedVisitIds));

            addAuditLog($db, $completedVisitIds[0], (int)$user['id'], 'invoice_created', ['invoice_id' => $invoiceId], $ip);
        }

        echo json_encode([
            'success'        => true,
            'invoice_id'     => $invoiceId,
            'invoice_number' => $invoiceNumber,
        ]);
        exit;
    }

    // ── Reopen Visit ─────────────────────────────────────────────────────────
    if ($action === 'reopen_visit') {
        if ($visitId < 1) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'visit_id is required']);
            exit;
        }
        $rvVisit = $db->prepare("
            SELECT jv.id, jv.status, jv.locked_at, jv.invoice_id, jv.stop_id,
                   jv.assigned_crew_id, cs.stop_date
            FROM job_visits jv
            LEFT JOIN calendar_stops cs ON jv.stop_id = cs.id
            WHERE jv.id = ?
        ");
        $rvVisit->execute([$visitId]);
        $rvRow = $rvVisit->fetch(PDO::FETCH_ASSOC);

        if (!$rvRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Visit not found.']);
            exit;
        }
        if ($rvRow['locked_at'] !== null) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'This visit is locked and cannot be reopened. Ask an admin to unlock it first.']);
            exit;
        }

        // Auth: admin always; crew only if stop_date = today
        $isSameDayCrew = (int)($rvRow['assigned_crew_id'] ?? 0) === (int)$user['id']
                         && $rvRow['stop_date'] === date('Y-m-d');
        if (!$isAdmin && !$isSameDayCrew) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only admins can reopen visits from previous days.']);
            exit;
        }

        // Unlink visit from invoice (leave invoice intact as draft for manual review)
        $db->prepare("
            UPDATE job_visits SET
                status = 'scheduled',
                completed_at = NULL,
                started_at = NULL,
                proof_complete = 0,
                gps_departure_lat = NULL,
                gps_departure_lng = NULL,
                is_invoiced = 0,
                invoice_id = NULL
            WHERE id = ?
        ")->execute([$visitId]);
        addAuditLog($db, $visitId, (int)$user['id'], 'reopen', null, $ip);

        // Recalculate stop status
        $stopId = (int)($rvRow['stop_id'] ?? 0);
        if ($stopId) {
            $pendingStmt = $db->prepare("
                SELECT COUNT(*) FROM job_visits
                WHERE stop_id = ? AND status NOT IN ('completed','skipped','cancelled')
            ");
            $pendingStmt->execute([$stopId]);
            $pending = (int)$pendingStmt->fetchColumn();
            if ($pending > 0) {
                $db->prepare("UPDATE calendar_stops SET status = 'scheduled', updated_at = NOW() WHERE id = ?")
                   ->execute([$stopId]);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // All other actions require a valid visit_id
    if ($visitId < 1) {
        throw new InvalidArgumentException('visit_id is required');
    }

    // Load visit and check authorization
    $visit = loadVisit($db, $visitId);
    if (!$visit) {
        http_response_code(404);
        echo json_encode([
            'success'   => false,
            'error'     => 'That visit is no longer available.',
            'code'      => 'VISIT_NOT_FOUND',
            'retryable' => false,
        ]);
        exit;
    }

    $isCrew  = (int)($visit['assigned_crew_id'] ?? 0) === (int)$user['id'];

    if (!$isAdmin && !$isCrew) {
        http_response_code(403);
        echo json_encode([
            'success'   => false,
            'error'     => 'This visit is assigned to another crew member.',
            'code'      => 'NOT_AUTHORIZED',
            'retryable' => false,
        ]);
        exit;
    }

    // Lock guard — most write actions blocked on locked visits
    $lockGuarded = ['save_checklist','save_materials','save_service_data','save_notes','save_signature','end_visit','upload_photo'];
    if (in_array($action, $lockGuarded) && $visit['locked_at'] !== null && !$isAdmin) {
        http_response_code(409);
        echo json_encode([
            'success'   => false,
            'error'     => 'This visit is locked. Ask an admin to unlock it before making changes.',
            'code'      => 'VISIT_LOCKED',
            'retryable' => false,
        ]);
        exit;
    }

    switch ($action) {

        // ── Start Visit ───────────────────────────────────────────────────
        case 'start_visit':
            if ($visit['status'] !== 'scheduled') {
                echo json_encode(['success' => false, 'error' => 'Visit is not in scheduled state']);
                break;
            }
            $lat = isset($input['lat']) ? (float)$input['lat'] : null;
            $lng = isset($input['lng']) ? (float)$input['lng'] : null;

            $svcType = $input['service_type'] ?? $visit['service_type'] ?? null;

            $db->prepare("
                UPDATE job_visits SET
                    status = 'in_progress',
                    started_at = NOW(),
                    gps_arrival_lat = ?,
                    gps_arrival_lng = ?,
                    service_type = COALESCE(?, service_type)
                WHERE id = ?
            ")->execute([$lat, $lng, $svcType, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'start', null, $ip);

            // Mark calendar_stop as in_progress (drives amber styling on Schedule)
            try {
                $stopId = $visit['stop_id'] ?? null;
                if ($stopId) {
                    $db->prepare("
                        UPDATE calendar_stops SET status = 'in_progress', updated_at = NOW()
                        WHERE id = ? AND status = 'scheduled'
                    ")->execute([$stopId]);
                }
            } catch (Throwable $e) {
                error_log('[pow-actions] calendar_stops start propagation error: ' . $e->getMessage());
            }

            echo json_encode(['success' => true, 'started_at' => date('c')]);
            break;

        // ── Save Checklist ─────────────────────────────────────────────────
        case 'save_checklist':
            $items = $input['items'] ?? [];
            if (!is_array($items)) {
                throw new InvalidArgumentException('items must be an array');
            }
            $sanitized = [];
            foreach ($items as $item) {
                $sanitized[] = [
                    'item'    => substr(strip_tags((string)($item['item'] ?? '')), 0, 255),
                    'checked' => !empty($item['checked']),
                    'note'    => substr(strip_tags((string)($item['note'] ?? '')), 0, 500),
                ];
            }
            $json = json_encode($sanitized);
            $db->prepare("
                UPDATE job_visits SET
                    checklist_json = ?,
                    checklist_completed_at = NOW(),
                    checklist_completed_by = ?,
                    checklist_completed = ?
                WHERE id = ?
            ")->execute([$json, $user['id'], $json, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'checklist_saved', ['count' => count($sanitized)], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Save Materials ─────────────────────────────────────────────────
        case 'save_materials':
            $items = $input['items'] ?? [];
            if (!is_array($items)) throw new InvalidArgumentException('items must be an array');
            $sanitized = [];
            foreach ($items as $m) {
                $sanitized[] = [
                    'name'           => substr(strip_tags((string)($m['name'] ?? '')), 0, 255),
                    'qty'            => is_numeric($m['qty'] ?? '') ? round((float)$m['qty'], 4) : null,
                    'unit'           => substr(strip_tags((string)($m['unit'] ?? '')), 0, 50),
                    'rate_per_unit'  => is_numeric($m['rate_per_unit'] ?? '') ? round((float)$m['rate_per_unit'], 2) : null,
                    'note'           => substr(strip_tags((string)($m['note'] ?? '')), 0, 500),
                ];
            }
            $db->prepare("UPDATE job_visits SET materials_json = ? WHERE id = ?")
               ->execute([json_encode($sanitized), $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'materials_saved', ['count' => count($sanitized)], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Save Service Data ──────────────────────────────────────────────
        case 'save_service_data':
            $svcData = $input['data'] ?? [];
            if (!is_array($svcData)) throw new InvalidArgumentException('data must be an object');
            // Sanitize all string values
            array_walk_recursive($svcData, function(&$v) {
                if (is_string($v)) $v = substr(strip_tags($v), 0, 1000);
            });
            $db->prepare("UPDATE job_visits SET service_data_json = ? WHERE id = ?")
               ->execute([json_encode($svcData), $visitId]);
            echo json_encode(['success' => true]);
            break;

        // ── Save Note ─────────────────────────────────────────────────────
        case 'save_notes':
            $content  = trim($input['content'] ?? '');
            $noteType = $input['note_type'] ?? 'general';
            $visible  = !empty($input['visible_to_customer']) ? 1 : 0;
            $allowed  = ['general','customer_request','issue','follow_up','internal'];
            if (!in_array($noteType, $allowed)) $noteType = 'general';
            if (strlen($content) < 1) throw new InvalidArgumentException('Note content required');
            $db->prepare("
                INSERT INTO visit_notes (visit_id, note_type, content, is_visible_to_customer, created_by)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$visitId, $noteType, $content, $visible, $user['id']]);
            echo json_encode(['success' => true]);
            break;

        // ── Save Signature ─────────────────────────────────────────────────
        case 'save_signature':
            $dataUrl = $input['signature'] ?? '';
            if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
                throw new InvalidArgumentException('Invalid signature format');
            }
            $imgData = base64_decode(substr($dataUrl, 22));
            if ($imgData === false) throw new InvalidArgumentException('Invalid base64');

            $sigDir = PUBLIC_ROOT . '/uploads/pow/signatures/';
            if (!is_dir($sigDir)) mkdir($sigDir, 0755, true);

            $filename = 'sig_' . $visitId . '_' . time() . '.png';
            file_put_contents($sigDir . $filename, $imgData);

            $sigPath = '/uploads/pow/signatures/' . $filename;
            $db->prepare("UPDATE job_visits SET signature_path = ? WHERE id = ?")
               ->execute([$sigPath, $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'signature_saved', null, $ip);
            echo json_encode(['success' => true, 'path' => $sigPath]);
            break;

        // ── End Visit ─────────────────────────────────────────────────────
        case 'end_visit':
            if (!in_array($visit['status'], ['in_progress', 'scheduled'])) {
                echo json_encode(['success' => false, 'error' => 'Visit cannot be ended from current status']);
                break;
            }
            $lat          = isset($input['lat'])   ? (float)$input['lat']   : null;
            $lng          = isset($input['lng'])   ? (float)$input['lng']   : null;
            $notes        = trim($input['notes'] ?? '');
            $extrasMins   = max(0, (int)($input['extras_minutes'] ?? 0));
            $extrasNote   = substr(trim($input['extras_note'] ?? ''), 0, 500);

            // Calculate extras amount from ops_settings rate
            $extrasAmount = 0.00;
            if ($extrasMins > 0) {
                try {
                    $rateRow = $db->query("SELECT setting_value FROM ops_settings WHERE setting_key = 'extras_rate_per_5min' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    $ratePerBlock = floatval($rateRow['setting_value'] ?? 5.00);
                    $blocks = (int)ceil($extrasMins / 5); // always round up to 5-min block
                    $extrasAmount = round($blocks * $ratePerBlock, 2);
                } catch (Exception $e) { /* use 0 if setting unavailable */ }
            }

            // ── Photo compliance check ────────────────────────────────────
            $planCompStmt = $db->prepare("
                SELECT photo_types_required, photos_block_completion
                FROM job_plans WHERE id = ?
            ");
            $planCompStmt->execute([$visit['plan_id']]);
            $planCompliance = $planCompStmt->fetch(PDO::FETCH_ASSOC);

            if ($planCompliance && (int)$planCompliance['photos_block_completion'] === 1 && !empty($planCompliance['photo_types_required'])) {
                $required = json_decode($planCompliance['photo_types_required'], true) ?: [];
                if (!empty($required)) {
                    $uploadedStmt = $db->prepare("
                        SELECT DISTINCT photo_type FROM visit_photos
                        WHERE visit_id = ? AND deleted_at IS NULL
                    ");
                    $uploadedStmt->execute([$visitId]);
                    $uploaded = array_column($uploadedStmt->fetchAll(PDO::FETCH_ASSOC), 'photo_type');
                    $missing  = array_values(array_diff($required, $uploaded));
                    if (!empty($missing)) {
                        echo json_encode([
                            'success'       => false,
                            'error'         => 'Required photos missing before visit can be completed.',
                            'missing_types' => $missing,
                        ]);
                        break;
                    }
                }
            }
            // ─────────────────────────────────────────────────────────────

            $db->prepare("
                UPDATE job_visits SET
                    status = 'completed',
                    completed_at = NOW(),
                    gps_departure_lat = ?,
                    gps_departure_lng = ?,
                    gps_confirmed_at = NOW(),
                    completion_notes = CASE WHEN ? != '' THEN ? ELSE completion_notes END,
                    extras_minutes = ?,
                    extras_amount  = ?,
                    extras_note    = CASE WHEN ? != '' THEN ? ELSE extras_note END
                WHERE id = ?
            ")->execute([$lat, $lng, $notes, $notes, $extrasMins, $extrasAmount, $extrasNote, $extrasNote, $visitId]);

            addAuditLog($db, $visitId, (int)$user['id'], 'complete', null, $ip);

            // Capture labor cost + margin snapshot at completion time
            $vcService = APP_ROOT . '/Modules/Jobs/Services/VisitCompletionService.php';
            if (file_exists($vcService)) {
                require_once $vcService;
                VisitCompletionService::capture($visitId, (int)$user['id']);
            }

            // Send Google Review request to customer (fire-and-forget, never blocks)
            $rrService = APP_ROOT . '/Modules/Reviews/Services/ReviewRequestService.php';
            if (file_exists($rrService)) {
                require_once $rrService;
                ReviewRequestService::maybeSend($visitId, $db);
            }

            // Send photo-portfolio consent request (fire-and-forget, 90-day cooldown)
            $pcService = APP_ROOT . '/Modules/Photos/Services/PhotoConsentRequestService.php';
            if (file_exists($pcService)) {
                require_once $pcService;
                PhotoConsentRequestService::maybeSend($visitId, $db);
            }

            // Award referral reward if this is a referred client's first completed visit
            $refService = APP_ROOT . '/Modules/Referrals/Services/ReferralRewardService.php';
            if (file_exists($refService)) {
                require_once $refService;
                ReferralRewardService::maybeAwardCompletion($visitId, $db, (int)$user['id']);
            }

            // Propagate to calendar_stops: if ALL visits in the same stop
            // are now completed/skipped/cancelled, mark the stop as completed.
            // This drives the green "done" styling on the Schedule page.
            try {
                $stopId = $visit['stop_id'] ?? null;
                if ($stopId) {
                    $pendingStmt = $db->prepare("
                        SELECT COUNT(*) FROM job_visits
                        WHERE stop_id = ? AND status NOT IN ('completed', 'skipped', 'cancelled')
                    ");
                    $pendingStmt->execute([$stopId]);
                    $pending = (int)$pendingStmt->fetchColumn();
                    if ($pending === 0) {
                        $db->prepare("
                            UPDATE calendar_stops SET status = 'completed', updated_at = NOW()
                            WHERE id = ? AND status != 'completed'
                        ")->execute([$stopId]);
                    } else {
                        // At least one visit is done — mark stop as in_progress
                        // if it's still in 'scheduled' state.
                        $db->prepare("
                            UPDATE calendar_stops SET status = 'in_progress', updated_at = NOW()
                            WHERE id = ? AND status = 'scheduled'
                        ")->execute([$stopId]);
                    }
                }
            } catch (Throwable $e) {
                error_log('[pow-actions] calendar_stops status propagation error: ' . $e->getMessage());
            }

            echo json_encode([
                'success'       => true,
                'completed_at'  => date('c'),
                'extras_minutes'=> $extrasMins,
                'extras_amount' => $extrasAmount,
            ]);
            break;

        // ── Skip Visit ────────────────────────────────────────────────────
        case 'skip_visit':
            require_once CRM_INCLUDES . '/plan-functions.php';
            $result = updateVisitStatus($visitId, 'skipped', (int)$user['id']);
            echo json_encode(['success' => $result]);
            break;

        // ── Generate PDF ───────────────────────────────────────────────────
        case 'generate_pdf':
            if (!$isAdmin && !$isCrew) {
                http_response_code(403);
                echo json_encode(['error' => 'Not authorized to generate PDF']);
                break;
            }
            $forceRegen = !empty($input['force']);
            require_once APP_ROOT . '/Services/Pow/MapSnapshotService.php';
            $gen = new PowPdfGenerator(
                $db,
                PUBLIC_ROOT,
                defined('SITE_URL') ? SITE_URL : 'https://mowology.ca'
            );
            $result = $gen->generate($visitId, $forceRegen);
            if ($result['success']) {
                $action_name = $forceRegen ? 'regenerate_pdf' : 'generate_pdf';
                addAuditLog($db, $visitId, (int)$user['id'], $action_name, ['path' => $result['path']], $ip);
            }
            echo json_encode($result);
            break;

        // ── Lock Visit (admin) ─────────────────────────────────────────────
        case 'lock_visit':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                break;
            }
            $db->prepare("UPDATE job_visits SET locked_at = NOW(), locked_by = ?, proof_complete = 1 WHERE id = ?")
               ->execute([$user['id'], $visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'lock', null, $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Unlock Visit (admin) ───────────────────────────────────────────
        case 'unlock_visit':
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['error' => 'Admin only']);
                break;
            }
            $reason = substr(strip_tags($input['reason'] ?? ''), 0, 500);
            $db->prepare("UPDATE job_visits SET locked_at = NULL, locked_by = NULL WHERE id = ?")
               ->execute([$visitId]);
            addAuditLog($db, $visitId, (int)$user['id'], 'unlock', ['reason' => $reason], $ip);
            echo json_encode(['success' => true]);
            break;

        // ── Upload Photo ───────────────────────────────────────────────────
        case 'upload_photo':
            if (empty($_FILES['photo'])) {
                throw new InvalidArgumentException('No photo file received');
            }
            $file     = $_FILES['photo'];
            $allowed  = ['image/jpeg','image/png','image/gif','image/webp','image/heic'];
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($realMime, $allowed)) {
                throw new InvalidArgumentException('Invalid image type');
            }
            if ($file['size'] > 20 * 1024 * 1024) {
                throw new InvalidArgumentException('Photo exceeds 20MB limit');
            }

            $photoType = $input['photo_type'] ?? 'after';
            $allowed_types = ['before','after','additional','during','issue','other'];
            if (!in_array($photoType, $allowed_types)) $photoType = 'other';

            $caption   = substr(strip_tags($input['caption'] ?? ''), 0, 255);
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;

            $uploadDir = PUBLIC_ROOT . '/uploads/photos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg');
            $base     = 'pow_' . $visitId . '_' . $photoType . '_' . bin2hex(random_bytes(8));
            $filename = $base . '.' . $ext;
            $fullPath = $uploadDir . $filename;
            move_uploaded_file($file['tmp_name'], $fullPath);

            // ── Generate image variants ──────────────────────────────────
            $variants = generatePhotoVariants($fullPath, $visitId, $base, $realMime);

            // Look up property + service type for denormalization
            $propStmt = $db->prepare("
                SELECT jp.property_id, jp.service_type
                FROM job_visits jv
                JOIN job_plans jp ON jp.id = jv.plan_id
                WHERE jv.id = ? LIMIT 1
            ");
            $propStmt->execute([$visitId]);
            $propRow = $propStmt->fetch(PDO::FETCH_ASSOC);
            $photoPropertyId = $propRow ? (int)$propRow['property_id'] : null;
            $photoServiceType = $propRow['service_type'] ?? null;

            // Compute file hash for duplicate detection
            $photoSha256 = hash_file('sha256', $fullPath);

            // Auto-tag photo to the work zone the crew is currently in (if any)
            $photoWorkZoneId = null;
            if (isset($input['lat']) && isset($input['lng'])) {
                $photoLat = (float)$input['lat'];
                $photoLng = (float)$input['lng'];
                $geoModel = APP_ROOT . '/Modules/Geofence/Models/GeofenceModel.php';
                if (is_file($geoModel)) {
                    require_once $geoModel;
                    if ($propRow && function_exists('geofenceGetWorkZones')) {
                        $zones = geofenceGetWorkZones($photoPropertyId);
                        if (!empty($zones) && function_exists('geofenceClassifyPingAgainstWorkZones')) {
                            $photoWorkZoneId = geofenceClassifyPingAgainstWorkZones($photoLat, $photoLng, $zones);
                        }
                    }
                }
            }

            $db->prepare("
                INSERT INTO visit_photos
                    (visit_id, photo_type, filename, original_filename, file_size,
                     mime_type, caption, sort_order, thumb_path, grid_path, view_path,
                     uploaded_by, work_zone_id,
                     property_id, uploaded_by_name, service_type, sha256)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $visitId, $photoType, $filename, $file['name'],
                $file['size'], $realMime, $caption, $sortOrder,
                $variants['thumb_path'], $variants['grid_path'], $variants['view_path'],
                $user['id'], $photoWorkZoneId,
                $photoPropertyId, $user['full_name'] ?? $user['name'] ?? null,
                $photoServiceType, $photoSha256,
            ]);
            $photoId = (int)$db->lastInsertId();

            addAuditLog($db, $visitId, (int)$user['id'], 'photo_upload', ['type' => $photoType, 'file' => $filename], $ip);

            echo json_encode([
                'success'    => true,
                'photo_id'   => $photoId,
                'filename'   => $filename,
                'thumb_url'  => $variants['thumb_path'] ?? ('/uploads/photos/' . $filename),
                'grid_url'   => $variants['grid_path']  ?? ('/uploads/photos/' . $filename),
                'view_url'   => $variants['view_path']  ?? ('/uploads/photos/' . $filename),
                'orig_url'   => '/uploads/photos/' . $filename,
                'photo_type' => $photoType,
            ]);
            break;

        default:
            throw new InvalidArgumentException('Unknown action: ' . $action);
    }

} catch (PDOException $e) {
    // DB errors on the visit path are usually connection blips or lock
    // timeouts — both retryable. Give the client enough to show an
    // actionable message and a retry button.
    error_log('PoW Actions API DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'error'     => 'We could not save that just now. Please try again in a moment.',
        'code'      => 'DB_ERROR',
        'retryable' => true,
    ]);
} catch (InvalidArgumentException $e) {
    // Bad request / invalid state — not retryable without user action.
    http_response_code(400);
    echo json_encode([
        'success'   => false,
        'error'     => $e->getMessage(),
        'code'      => 'BAD_REQUEST',
        'retryable' => false,
    ]);
} catch (Throwable $e) {
    // Unknown server-side error — surface a friendly message, log the
    // detail, keep the raw exception text out of the client.
    error_log('PoW Actions API error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'error'     => 'Something went wrong on our side. Please try again, or contact support if it keeps happening.',
        'code'      => 'SERVER_ERROR',
        'retryable' => true,
    ]);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function loadVisit(PDO $db, int $visitId): ?array
{
    $stmt = $db->prepare("SELECT * FROM job_visits WHERE id = ?");
    $stmt->execute([$visitId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function addAuditLog(PDO $db, int $visitId, int $userId, string $action, ?array $payload, ?string $ip): void
{
    $db->prepare("
        INSERT INTO visit_audit_log (visit_id, user_id, action, payload_json, ip_address)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([
        $visitId, $userId, $action,
        $payload ? json_encode($payload) : null,
        $ip ? substr($ip, 0, 45) : null,
    ]);
}

/**
 * Generate thumb (256px sq), grid (512px), and view (1280px) variants.
 * Auto-corrects EXIF orientation. Strips EXIF by re-encoding through GD.
 * Generates WebP if imagewebp() is available, otherwise JPEG.
 *
 * @return array ['thumb_path' => string|null, 'grid_path' => string|null, 'view_path' => string|null]
 *               Paths are web-relative (e.g. '/uploads/photos/t/42/pow_42_before_abc_t.webp').
 */
function generatePhotoVariants(string $srcPath, int $visitId, string $baseName, string $mime): array
{
    $result = ['thumb_path' => null, 'grid_path' => null, 'view_path' => null];

    // Only process formats GD can open
    $gd = null;
    switch ($mime) {
        case 'image/jpeg': $gd = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $gd = @imagecreatefrompng($srcPath);  break;
        case 'image/webp': if (function_exists('imagecreatefromwebp')) $gd = @imagecreatefromwebp($srcPath); break;
        case 'image/gif':  $gd = @imagecreatefromgif($srcPath);  break;
        default: return $result; // HEIC, etc. — skip variants
    }

    if (!$gd) {
        error_log("generatePhotoVariants: GD could not open {$srcPath}");
        return $result;
    }

    // Auto-orient via EXIF (JPEG only)
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($srcPath);
        $orientation = (int)($exif['Orientation'] ?? 1);
        $gd = gdAutoOrient($gd, $orientation);
    }

    $origW = imagesx($gd);
    $origH = imagesy($gd);

    // Decide output format
    $canWebP = function_exists('imagewebp');
    $outExt  = $canWebP ? 'webp' : 'jpg';

    // Create variant subdirectories
    $tDir = PUBLIC_ROOT . '/uploads/photos/t/' . $visitId . '/';
    $gDir = PUBLIC_ROOT . '/uploads/photos/g/' . $visitId . '/';
    $vDir = PUBLIC_ROOT . '/uploads/photos/v/' . $visitId . '/';
    foreach ([$tDir, $gDir, $vDir] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }

    // ── Thumb: 256×256 square center-crop ────────────────────────────────
    $thumbSize = 256;
    $shortSide = min($origW, $origH);
    $cropX = (int)(($origW - $shortSide) / 2);
    $cropY = (int)(($origH - $shortSide) / 2);
    $thumb = imagecreatetruecolor($thumbSize, $thumbSize);
    gdFillTransparent($thumb, $thumbSize, $thumbSize);
    imagecopyresampled($thumb, $gd, 0, 0, $cropX, $cropY, $thumbSize, $thumbSize, $shortSide, $shortSide);
    $tFile = $baseName . '_t.' . $outExt;
    gdSave($thumb, $tDir . $tFile, $outExt, 65); // 65 quality — thumbs
    imagedestroy($thumb);
    $result['thumb_path'] = '/uploads/photos/t/' . $visitId . '/' . $tFile;

    // ── Grid: 512px max (contain, no upscale) ────────────────────────────
    [$gridW, $gridH] = gdScaleDimensions($origW, $origH, 512);
    $grid = imagecreatetruecolor($gridW, $gridH);
    gdFillTransparent($grid, $gridW, $gridH);
    imagecopyresampled($grid, $gd, 0, 0, 0, 0, $gridW, $gridH, $origW, $origH);
    $gFile = $baseName . '_g.' . $outExt;
    gdSave($grid, $gDir . $gFile, $outExt, 70); // 70 quality — grid
    imagedestroy($grid);
    $result['grid_path'] = '/uploads/photos/g/' . $visitId . '/' . $gFile;

    // ── View: 1280px max (contain, no upscale) ───────────────────────────
    [$viewW, $viewH] = gdScaleDimensions($origW, $origH, 1280);
    $view = imagecreatetruecolor($viewW, $viewH);
    gdFillTransparent($view, $viewW, $viewH);
    imagecopyresampled($view, $gd, 0, 0, 0, 0, $viewW, $viewH, $origW, $origH);
    $vFile = $baseName . '_v.' . $outExt;
    gdSave($view, $vDir . $vFile, $outExt, 78); // 78 quality — view
    imagedestroy($view);
    $result['view_path'] = '/uploads/photos/v/' . $visitId . '/' . $vFile;

    imagedestroy($gd);
    return $result;
}

/** Auto-orient a GD resource based on EXIF orientation value (1–8). */
function gdAutoOrient($gd, int $orientation)
{
    switch ($orientation) {
        case 2: imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 3: $gd = imagerotate($gd, 180, 0); break;
        case 4: imageflip($gd, IMG_FLIP_VERTICAL); break;
        case 5: $gd = imagerotate($gd, -90, 0); imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 6: $gd = imagerotate($gd, -90, 0); break;
        case 7: $gd = imagerotate($gd, 90, 0);  imageflip($gd, IMG_FLIP_HORIZONTAL); break;
        case 8: $gd = imagerotate($gd, 90, 0);  break;
    }
    return $gd;
}

/** Fill a GD canvas with transparency (for PNG/WebP). */
function gdFillTransparent($gd, int $w, int $h): void
{
    imagealphablending($gd, false);
    imagesavealpha($gd, true);
    $transparent = imagecolorallocatealpha($gd, 0, 0, 0, 127);
    imagefilledrectangle($gd, 0, 0, $w - 1, $h - 1, $transparent);
    imagealphablending($gd, true);
}

/**
 * Scale dimensions to fit within maxSide, never upscale.
 * @return int[] [width, height]
 */
function gdScaleDimensions(int $w, int $h, int $maxSide): array
{
    if ($w <= $maxSide && $h <= $maxSide) {
        return [$w, $h];
    }
    $scale = min($maxSide / $w, $maxSide / $h);
    return [(int)round($w * $scale), (int)round($h * $scale)];
}

/** Save GD image to a path. $format is 'webp' or 'jpg'. */
function gdSave($gd, string $path, string $format, int $quality): void
{
    if ($format === 'webp' && function_exists('imagewebp')) {
        imagewebp($gd, $path, $quality);
    } else {
        imagejpeg($gd, $path, $quality);
    }
}
