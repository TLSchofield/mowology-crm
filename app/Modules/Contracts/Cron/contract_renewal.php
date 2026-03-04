<?php
/**
 * Contract Auto-Renewal Cron
 *
 * Runs daily at 1 AM. For each active contract where:
 *   - auto_renew = 1
 *   - renewal_date is set and <= today
 *   - not already renewed for this period (last_renewed_at < renewal_date)
 *
 * It will:
 *   1. Apply renewal_increase_pct to billing_amount
 *   2. Push start_date, end_date, renewal_date forward by the contract period
 *   3. Recalculate price_per_visit on all linked active plans
 *   4. Push plan_start_date / plan_end_date forward on linked plans
 *   5. Log the renewal to activity_log
 *
 * Also expires active contracts with auto_renew = 0 and end_date in the past.
 *
 * cPanel cron:
 *   0 1 * * * /usr/local/bin/php /home/mowology/public_html/app/Modules/Contracts/Cron/contract_renewal.php
 */
declare(strict_types=1);

// ── Bootstrap: upward path search ───────────────────────────────────────────
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

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once PUBLIC_ROOT . '/crm/includes/functions.php';
    requireLogin();
    $user = getCurrentUser();
    if (($user['role'] ?? '') !== 'admin') { http_response_code(403); exit; }
    header('Content-Type: application/json; charset=utf-8');
} else {
    require_once PUBLIC_ROOT . '/loginAuth/auth.php';
    require_once PUBLIC_ROOT . '/crm/includes/functions.php';
}

$startMs  = (int)(microtime(true) * 1000);
$today    = date('Y-m-d');
$db       = getDB();
$renewed  = [];
$expired  = [];
$errors   = [];

// ── 1. Find contracts due for renewal ────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT id, contract_number, billing_amount, billing_cycle,
               start_date, end_date, renewal_date, renewal_increase_pct
        FROM contracts
        WHERE status = 'active'
          AND auto_renew = 1
          AND renewal_date IS NOT NULL
          AND renewal_date <= :today
          AND (last_renewed_at IS NULL OR last_renewed_at < renewal_date)
        ORDER BY renewal_date ASC
    ");
    $stmt->execute([':today' => $today]);
    $dueCtr = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'Failed to query due contracts: ' . $e->getMessage();
    $dueCtr   = [];
}

// ── 2. Process each due contract ─────────────────────────────────────────────
foreach ($dueCtr as $ctr) {
    try {
        $contractId     = (int)$ctr['id'];
        $oldAmount      = (float)$ctr['billing_amount'];
        $pct            = (float)$ctr['renewal_increase_pct'];
        $newAmount      = round($oldAmount * (1 + $pct / 100), 2);
        $billingCycle   = $ctr['billing_cycle'];

        // Compute the renewal period length
        $renewalDate = new DateTime($ctr['renewal_date']);
        if (!empty($ctr['start_date']) && !empty($ctr['end_date'])) {
            // Use the original term length as the renewal period
            $origStart    = new DateTime($ctr['start_date']);
            $origEnd      = new DateTime($ctr['end_date']);
            $origInterval = $origStart->diff($origEnd);
            $newStart     = clone $renewalDate;
            $newEnd       = (clone $renewalDate)->add($origInterval);
            $newRenewal   = clone $newEnd;
            $newRenewal->modify('+1 day');
        } else {
            // No end date: default to 1-year rolling
            $newStart   = clone $renewalDate;
            $newEnd     = null;
            $newRenewal = (clone $renewalDate)->modify('+1 year');
        }

        $newStartStr   = $newStart->format('Y-m-d');
        $newEndStr     = $newEnd   ? $newEnd->format('Y-m-d')   : null;
        $newRenewalStr = $newRenewal->format('Y-m-d');

        // Update contract record
        $db->prepare("
            UPDATE contracts
            SET billing_amount  = ?,
                start_date      = ?,
                end_date        = ?,
                renewal_date    = ?,
                last_renewed_at = NOW(),
                updated_at      = NOW()
            WHERE id = ?
        ")->execute([$newAmount, $newStartStr, $newEndStr, $newRenewalStr, $contractId]);

        // Recalculate plan prices from the new billing amount
        recalcContractPlanPrices($contractId, $newAmount, $billingCycle);

        // Push plan dates forward on linked active plans
        $db->prepare("
            UPDATE job_plans
            SET plan_start_date = ?,
                plan_end_date   = ?,
                updated_at      = NOW()
            WHERE contract_id = ?
              AND status != 'cancelled'
        ")->execute([$newStartStr, $newEndStr, $contractId]);

        // Log activity (user_id = 0 for system action)
        logActivityExtended(
            0,
            'contract_auto_renewed',
            json_encode([
                'contract_id'     => $contractId,
                'contract_number' => $ctr['contract_number'],
                'old_amount'      => $oldAmount,
                'new_amount'      => $newAmount,
                'increase_pct'    => $pct,
                'new_start'       => $newStartStr,
                'new_end'         => $newEndStr,
                'new_renewal'     => $newRenewalStr,
            ])
        );

        $renewed[] = $ctr['contract_number'] . ' ($' . number_format($newAmount, 2) . ')';

    } catch (Throwable $e) {
        $errors[] = ($ctr['contract_number'] ?? "CTR-{$ctr['id']}") . ': ' . $e->getMessage();
        error_log('[contract_renewal] Error renewing ' . ($ctr['contract_number'] ?? $ctr['id']) . ': ' . $e->getMessage());
    }
}

// ── 3. Expire overdue non-renewing contracts ─────────────────────────────────
try {
    $expStmt = $db->prepare("
        SELECT id, contract_number FROM contracts
        WHERE status = 'active'
          AND auto_renew = 0
          AND end_date IS NOT NULL
          AND end_date < :today
    ");
    $expStmt->execute([':today' => $today]);
    $expiring = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($expiring as $ctr) {
        $db->prepare("UPDATE contracts SET status = 'expired', updated_at = NOW() WHERE id = ?")
           ->execute([(int)$ctr['id']]);
        logActivityExtended(
            0,
            'contract_expired',
            json_encode(['contract_id' => (int)$ctr['id'], 'contract_number' => $ctr['contract_number']])
        );
        $expired[] = $ctr['contract_number'];
    }
} catch (Throwable $e) {
    $errors[] = 'Expiry scan failed: ' . $e->getMessage();
}

// ── 4. Record cron run ────────────────────────────────────────────────────────
$durationMs = (int)(microtime(true) * 1000) - $startMs;
$summary    = count($renewed) . ' renewed, ' . count($expired) . ' expired, ' . count($errors) . ' errors';
$status     = empty($errors) ? (empty($renewed) && empty($expired) ? 'success' : 'success') : 'warning';
$errorText  = empty($errors) ? null : implode('; ', array_slice($errors, 0, 5));

recordCronRun('contract_renewal', $status, $summary, $durationMs, $errorText, !$isCli);

// ── 5. Output ─────────────────────────────────────────────────────────────────
$result = [
    'success'  => empty($errors),
    'renewed'  => $renewed,
    'expired'  => $expired,
    'errors'   => $errors,
    'summary'  => $summary,
    'duration' => $durationMs . 'ms',
];

if ($isCli) {
    echo "Contract Renewal Cron — {$today}\n";
    echo "  Renewed:  " . (empty($renewed) ? 'none' : implode(', ', $renewed)) . "\n";
    echo "  Expired:  " . (empty($expired) ? 'none' : implode(', ', $expired)) . "\n";
    echo "  Errors:   " . (empty($errors)  ? 'none' : implode(', ', $errors))  . "\n";
    echo "  Duration: {$durationMs}ms\n";
} else {
    echo json_encode($result, JSON_PRETTY_PRINT);
}
