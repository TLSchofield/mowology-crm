<?php
declare(strict_types=1);

/**
 * app/Modules/Schedule/Api/invoice-prefill.php
 *
 * Mobile Invoice Prefill API — JWT authenticated, admin/manager only.
 *
 * GET /api/schedule/invoice-prefill?visit_id=N
 *
 * Returns all data needed to pre-populate the iOS invoice compose sheet:
 * line items, client info, service address, GST totals, and recipients.
 *
 * Response 200:
 * {
 *   "success": true,
 *   "data": { visit_id, client_display_name, service_address, ...,
 *             line_items: [...], extras_line_item: {...}|null,
 *             subtotal, tax_rate, tax_amount, total,
 *             issue_date, default_due_date,
 *             recipients: [...], crew_notes, existing_invoice_id }
 * }
 *
 * Response 409 (already invoiced):
 * { "success": false, "existing_invoice_id": N, "message": "..." }
 *
 * Response 403: not admin/manager
 * Response 404: visit not found or not completed
 */

if (!defined('APP_ROOT')) {
    $__dir = __DIR__;
    for ($__i = 0; $__i < 6; $__i++) {
        $__dir = dirname($__dir);
        if (is_file($__dir . '/app/Core/paths.php')) {
            require_once $__dir . '/app/Core/paths.php';
            break;
        }
    }
    unset($__dir, $__i);
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once APP_ROOT . '/Core/config.php';
require_once APP_ROOT . '/Core/Auth/JwtAuth.php';
require_once APP_ROOT . '/Services/CrmFunctions.php';

$jwtUser = requireJwt();
$isAdmin = jwtIsAdmin($jwtUser['role']);

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.']);
    exit;
}

$visitId = (int)($_GET['visit_id'] ?? 0);
if ($visitId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'visit_id is required.']);
    exit;
}

try {
    $db = getDB();

    // ── Load visit + plan + property + contact ──────────────────────────────
    // Try the extended query first (includes billing/timing/clock columns added
    // in the May 2026 migration). Fall back to the base query if any of those
    // columns don't yet exist on this database instance.
    $baseSql = "
        SELECT
            jv.id              AS visit_id,
            jv.visit_number,
            jv.actual_amount,
            jv.plan_id,
            jv.scheduled_date,
            jv.invoice_id      AS existing_invoice_id,
            jv.extras_minutes,
            jv.extras_amount,
            jv.extras_note,
            jv.completion_notes,
            jp.plan_number,
            jp.title           AS plan_title,
            jp.price_per_visit,
            jp.estimated_amount,
            jp.property_id,
            jp.company_id,
            COALESCE(comp.company_name, '') AS company_name,
            p.address,
            p.city,
            p.province,
            p.postal_code,
            p.site_contact_id,
            COALESCE(con.first_name, '')    AS contact_first,
            COALESCE(con.last_name, '')     AS contact_last,
            COALESCE(con.full_name, '')     AS contact_full_name,
            COALESCE(con.email, '')         AS contact_email,
            COALESCE(con.mobile, '')        AS contact_mobile,
            COALESCE(con.receive_sms, 0)   AS contact_receive_sms
        FROM job_visits jv
        JOIN  job_plans   jp   ON jv.plan_id    = jp.id
        LEFT  JOIN companies  comp ON jp.company_id  = comp.id
        LEFT  JOIN properties p    ON jp.property_id = p.id
        LEFT  JOIN contacts   con  ON p.site_contact_id = con.id
        WHERE jv.id = ?
          AND jv.status = 'completed'
    ";

    $extendedSql = "
        SELECT
            jv.id              AS visit_id,
            jv.visit_number,
            jv.actual_amount,
            jv.plan_id,
            jv.scheduled_date,
            jv.invoice_id      AS existing_invoice_id,
            jv.extras_minutes,
            jv.extras_amount,
            jv.extras_note,
            jv.completion_notes,
            jv.actual_duration_minutes,
            jv.labor_rate_snapshot,
            jp.plan_number,
            jp.title           AS plan_title,
            jp.price_per_visit,
            jp.estimated_amount,
            jp.estimated_duration_minutes,
            jp.pricing_model,
            jp.invoice_timing,
            jp.property_id,
            jp.company_id,
            COALESCE(comp.company_name, '') AS company_name,
            p.address,
            p.city,
            p.province,
            p.postal_code,
            p.site_contact_id,
            COALESCE(con.first_name, '')    AS contact_first,
            COALESCE(con.last_name, '')     AS contact_last,
            COALESCE(con.full_name, '')     AS contact_full_name,
            COALESCE(con.email, '')         AS contact_email,
            COALESCE(con.mobile, '')        AS contact_mobile,
            COALESCE(con.receive_sms, 0)   AS contact_receive_sms
        FROM job_visits jv
        JOIN  job_plans   jp   ON jv.plan_id    = jp.id
        LEFT  JOIN companies  comp ON jp.company_id  = comp.id
        LEFT  JOIN properties p    ON jp.property_id = p.id
        LEFT  JOIN contacts   con  ON p.site_contact_id = con.id
        WHERE jv.id = ?
          AND jv.status = 'completed'
    ";

    $visit = null;
    try {
        $stmt = $db->prepare($extendedSql);
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $colErr) {
        // One or more billing/clock columns not yet on this DB — use base query
        error_log('[invoice-prefill] extended query failed, using base: ' . $colErr->getMessage());
        $stmt = $db->prepare($baseSql);
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$visit) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Visit not found or not yet completed.']);
        exit;
    }

    // ── Already invoiced? (return 200 so the iOS client can decode the response) ─
    if (!empty($visit['existing_invoice_id'])) {
        echo json_encode([
            'success'             => false,
            'existing_invoice_id' => (int)$visit['existing_invoice_id'],
            'message'             => 'This visit has already been invoiced.',
        ]);
        exit;
    }

    // ── Business settings (GST) ──────────────────────────────────────────────
    $bsStmt  = $db->query("SELECT gst_rate, gst_registration FROM business_settings LIMIT 1");
    $bs      = $bsStmt->fetch(PDO::FETCH_ASSOC);
    $taxRate = round(floatval($bs['gst_rate'] ?? 5.00) / 100, 4);
    $gstNum  = $bs['gst_registration'] ?? null;

    // ── Plan line items ──────────────────────────────────────────────────────
    $lineItems = [];
    if (!empty($visit['plan_id'])) {
        $pliStmt = $db->prepare("
            SELECT description, quantity, unit_price, line_total, sort_order
            FROM   plan_line_items
            WHERE  plan_id = ?
            ORDER  BY sort_order, id
        ");
        $pliStmt->execute([$visit['plan_id']]);
        $rows = $pliStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $idx => $r) {
            $lineItems[] = [
                'description' => $r['description'],
                'quantity'    => round((float)$r['quantity'],    2),
                'unit_price'  => round((float)$r['unit_price'],  2),
                'line_total'  => round((float)$r['line_total'],  2),
                'sort_order'  => (int)($r['sort_order'] ?? $idx),
                'is_extras'   => false,
            ];
        }
    }

    // ── Fallback: single line from plan price if no plan_line_items ──────────
    if (empty($lineItems)) {
        $amount = (float)($visit['actual_amount'] ?: $visit['price_per_visit'] ?: $visit['estimated_amount'] ?: 0);
        $label  = $visit['plan_title'];
        if (!empty($visit['scheduled_date'])) {
            $label .= ' — ' . date('M j, Y', strtotime($visit['scheduled_date']));
        }
        $lineItems[] = [
            'description' => $label,
            'quantity'    => 1.0,
            'unit_price'  => round($amount, 2),
            'line_total'  => round($amount, 2),
            'sort_order'  => 1,
            'is_extras'   => false,
        ];
    }

    // ── Extras line item ─────────────────────────────────────────────────────
    $extrasLineItem = null;
    $extrasAmt = round((float)($visit['extras_amount'] ?? 0), 2);
    if ($extrasAmt > 0) {
        $extrasMins = (int)($visit['extras_minutes'] ?? 0);
        $extrasNote = trim($visit['extras_note'] ?? '');
        if ($extrasNote !== '') {
            $extrasDesc = $extrasNote;
        } elseif ($extrasMins > 0) {
            $extrasDesc = 'Additional time on site — ' . $extrasMins . ' min';
        } else {
            $extrasDesc = 'Additional charges';
        }
        $extrasLineItem = [
            'description'    => $extrasDesc,
            'quantity'       => 1.0,
            'unit_price'     => $extrasAmt,
            'line_total'     => $extrasAmt,
            'sort_order'     => 999,
            'is_extras'      => true,
            'auto_calculated'=> false,
        ];
    }

    // ── Auto-extras from clock data (when no manual extras recorded) ─────────
    // If actual time on site exceeded the estimated duration and no extras_amount
    // was manually entered, auto-populate an extras line from the labor rate
    // snapshot captured at completion. Shown as editable in the compose sheet.
    if ($extrasLineItem === null) {
        $actualMins    = (int)($visit['actual_duration_minutes'] ?? 0);
        $estimatedMins = (int)($visit['estimated_duration_minutes'] ?? 0);
        $laborRate     = (float)($visit['labor_rate_snapshot'] ?? 0);
        if ($actualMins > 0 && $estimatedMins > 0 && $actualMins > $estimatedMins && $laborRate > 0) {
            $overageMins = $actualMins - $estimatedMins;
            $extrasCost  = round(($overageMins / 60) * $laborRate, 2);
            if ($extrasCost > 0) {
                $extrasLineItem = [
                    'description'    => 'Additional time on site — ' . $overageMins . ' min (auto)',
                    'quantity'       => 1.0,
                    'unit_price'     => $extrasCost,
                    'line_total'     => $extrasCost,
                    'sort_order'     => 999,
                    'is_extras'      => true,
                    'auto_calculated'=> true,
                ];
            }
        }
    }

    // ── Subtotal / tax / total ───────────────────────────────────────────────
    $subtotal = 0.0;
    foreach ($lineItems as $li) {
        $subtotal += (float)$li['line_total'];
    }
    if ($extrasLineItem !== null) {
        $subtotal += (float)$extrasLineItem['line_total'];
    }
    $taxAmount = round($subtotal * $taxRate, 2);
    $total     = round($subtotal + $taxAmount, 2);
    $subtotal  = round($subtotal, 2);

    // ── Dates ────────────────────────────────────────────────────────────────
    $issueDate   = date('Y-m-d');
    $dueDateTs   = strtotime('+30 days');
    $defaultDue  = $dueDateTs ? date('Y-m-d', $dueDateTs) : date('Y-m-d');

    // ── Client display name ──────────────────────────────────────────────────
    $contactFirst = trim($visit['contact_first']);
    $contactLast  = trim($visit['contact_last']);
    $contactName  = trim($contactFirst . ' ' . $contactLast);
    if ($contactName === '' && $visit['contact_full_name'] !== '') {
        $contactName = $visit['contact_full_name'];
    }
    $clientDisplayName = $visit['company_name'] !== '' ? $visit['company_name'] : ($contactName ?: 'Unknown Client');

    // ── Recipients ───────────────────────────────────────────────────────────
    // Use the site_contact_id directly (companies table is often empty on prod)
    $recipients = [];
    if (!empty($visit['site_contact_id']) && $visit['contact_email'] !== '') {
        $recipients[] = [
            'contact_id'   => (int)$visit['site_contact_id'],
            'contact_name' => $contactName ?: $clientDisplayName,
            'email_address'=> $visit['contact_email'],
            'contact_role' => 'primary_recipient',
            'receive_sms'  => (bool)$visit['contact_receive_sms'],
            'phone'        => $visit['contact_mobile'] !== '' ? $visit['contact_mobile'] : null,
        ];
    }

    // ── Crew notes ───────────────────────────────────────────────────────────
    $crewNotes = trim($visit['completion_notes'] ?? '');
    $crewNotes = $crewNotes !== '' ? $crewNotes : null;

    echo json_encode([
        'success' => true,
        'data'    => [
            'visit_id'             => (int)$visitId,
            'visit_number'         => $visit['visit_number'],
            'plan_id'              => $visit['plan_id'] ? (int)$visit['plan_id'] : null,
            'property_id'          => $visit['property_id'] ? (int)$visit['property_id'] : null,
            'company_id'           => $visit['company_id'] ? (int)$visit['company_id'] : null,
            'contact_id'           => $visit['site_contact_id'] ? (int)$visit['site_contact_id'] : null,
            'client_display_name'  => $clientDisplayName,
            'service_address'      => $visit['address'] ?? '',
            'service_city'         => $visit['city'] ?? '',
            'service_province'     => $visit['province'] ?? 'BC',
            'service_postal_code'  => $visit['postal_code'] ?? '',
            'line_items'           => $lineItems,
            'extras_line_item'     => $extrasLineItem,
            'subtotal'             => $subtotal,
            'tax_rate'             => $taxRate,
            'tax_amount'           => $taxAmount,
            'total'                => $total,
            'issue_date'           => $issueDate,
            'default_due_date'     => $defaultDue,
            'recipients'           => $recipients,
            'crew_notes'           => $crewNotes,
            'existing_invoice_id'  => null,
            'gst_number'           => $gstNum,
            'billing_type_label'   => [
                'per_visit'    => 'Per Visit',
                'monthly_flat' => 'Monthly Flat',
                'seasonal'     => 'Seasonal',
                'custom'       => 'Custom',
            ][$visit['pricing_model'] ?? 'per_visit'] ?? 'Per Visit',
            'invoice_timing'       => $visit['invoice_timing'] ?? 'after_visit',
        ],
    ]);

} catch (Throwable $e) {
    error_log('[schedule/invoice-prefill] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load invoice prefill.']);
}
