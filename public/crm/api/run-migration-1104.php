<?php
/**
 * Migration 1104 — backfill job_plans.pricing_model for plans linked to a
 * formal, non-per-visit contract. Mirrors database/migrations/1104_backfill_contract_plan_pricing_model.sql.
 * Admin-only, idempotent DML (safe to re-run).
 */
declare(strict_types=1);
header('Content-Type: application/json');

$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) { require_once $__dir . '/app/Core/paths.php'; break; }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
if (!isAdmin()) { http_response_code(403); echo json_encode(['error' => 'Admin only']); exit; }

$db = getDB();

try {
    $affected = $db->exec("
        UPDATE job_plans jp
        JOIN contracts c ON jp.contract_id = c.id
        SET jp.pricing_model = CASE c.billing_cycle
                WHEN 'monthly'   THEN 'monthly_flat'
                WHEN 'seasonal'  THEN 'seasonal'
                WHEN 'per_visit' THEN 'per_visit'
                ELSE 'custom'
            END
        WHERE jp.pricing_model = 'per_visit'
          AND c.billing_cycle <> 'per_visit'
    ");
    echo json_encode([
        'ok'        => true,
        'migration' => '1104',
        'rows_updated' => $affected,
    ], JSON_PRETTY_PRINT);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'migration' => '1104', 'error' => $e->getMessage()]);
}
