<?php
/**
 * Run Migration 942 — Compound performance indexes
 *
 * Adds compound indexes on hot query paths to eliminate table scans:
 *   - communication_log(contact_id, created_at)   — contact activity feeds
 *   - activity_log(user_id, created_at)            — user activity filtered by date
 *   - invoices(company_id, status, due_date)        — invoice list view filters
 *   - job_visits(assigned_crew_id, scheduled_date, status) — schedule queries
 *   - service_orders(scheduled_date, status)        — weekly schedule queries
 *   - client_feedback(job_id, created_at)           — feedback lookup by job
 *
 * Admin-only. Safe to re-run — duplicate index errors are caught and reported.
 */
$__dir = __DIR__;
for ($__i = 0; $__i < 5; $__i++) {
    $__dir = dirname($__dir);
    if (is_file($__dir . '/app/Core/paths.php')) {
        require_once $__dir . '/app/Core/paths.php';
        break;
    }
}
unset($__dir, $__i);

require_once PUBLIC_ROOT . '/loginAuth/auth.php';
requireLogin();
session_write_close();
requirePermission('admin');

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
$results = [];

$statements = [
    "CREATE INDEX idx_comm_contact_created   ON communication_log(contact_id, created_at)",
    "CREATE INDEX idx_activity_user_created  ON activity_log(user_id, created_at)",
    "CREATE INDEX idx_invoice_company_status ON invoices(company_id, status, due_date)",
    "CREATE INDEX idx_visit_crew_date_status ON job_visits(assigned_crew_id, scheduled_date, status)",
    "CREATE INDEX idx_svcorder_date_status   ON service_orders(scheduled_date, status)",
    "CREATE INDEX idx_feedback_job_created   ON client_feedback(job_id, created_at)",
];

foreach ($statements as $i => $sql) {
    try {
        $db->exec($sql);
        $results[] = "OK  [$i] " . substr(trim($sql), 0, 80);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate key name') !== false ||
            strpos($msg, '1061') !== false ||
            strpos($msg, 'already exists') !== false) {
            $results[] = "SKIP[$i] Index already exists — " . substr(trim($sql), 0, 60);
        } else {
            $results[] = "ERR [$i] " . $msg . ' — SQL: ' . substr(trim($sql), 0, 60);
        }
    }
}

echo "Migration 942 — Compound Performance Indexes\n";
echo str_repeat('=', 60) . "\n";
foreach ($results as $line) {
    echo $line . "\n";
}
echo "\nDone.\n";
